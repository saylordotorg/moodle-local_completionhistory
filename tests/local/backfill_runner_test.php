<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_completionhistory\local;

use advanced_testcase;
use local_completionhistory\external\get_backfill_run;
use local_completionhistory\external\request_backfill;
use local_completionhistory\task\run_backfill;

/**
 * Tests for backfills run through cron rather than the command line.
 *
 * The properties that were decisions rather than mechanics:
 *
 *   ONE AT A TIME PER JOB. Asking again while a job is queued or running returns that run.
 *
 *   A LOST TASK DOES NOT BLOCK. A run whose ad hoc task has gone is marked failed, not left queued.
 *
 *   A NEW PASS MARK REACHES EARLIER COMPLETIONS. The grade refresh sets the pass flag on ledger rows
 *   captured before "Grade to pass" existed, through the correction history, on the newest row for
 *   each learner and course only, and a preview writes nothing.
 *
 *   THE SIS NEEDS ITS OWN CAPABILITY. The integration capability alone cannot start a run.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_completionhistory\local\backfill_runner
 * @covers \local_completionhistory\task\run_backfill
 * @covers \local_completionhistory\external\request_backfill
 * @covers \local_completionhistory\external\get_backfill_run
 */
final class backfill_runner_test extends advanced_testcase {
    /** @var \stdClass A course with an 80% pass mark. */
    private \stdClass $course;

    /**
     * Enable the plugin and grade capture, as admin, with a CL601 course.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enabled', 1, 'local_completionhistory');
        set_config('capturegrades', 1, 'local_completionhistory');
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course(['idnumber' => 'CL601', 'shortname' => 'CL601']);
    }

    /**
     * Run every queued backfill task, swallowing its output.
     */
    private function run_tasks(): void {
        ob_start();
        $this->runAdhocTasks(run_backfill::class);
        ob_end_clean();
    }

    /**
     * Queued run_backfill tasks.
     *
     * @return int
     */
    private function queued_tasks(): int {
        return count(\core\task\manager::get_adhoc_tasks(run_backfill::class));
    }

    /**
     * Give a learner a course total of $grade on the CL601 gradebook, with no event fired.
     *
     * @param int $userid Learner.
     * @param float $grade Course total.
     */
    private function course_total(int $userid, float $grade): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        $item = \grade_item::fetch_course_item($this->course->id);
        $DB->set_field('grade_items', 'gradepass', 80, ['id' => $item->id]);
        $DB->insert_record('grade_grades', (object) [
            'itemid' => $item->id,
            'userid' => $userid,
            'rawgrade' => $grade,
            'finalgrade' => $grade,
            'timemodified' => time(),
        ]);
    }

    /**
     * A ledger row for a learner in CL601, captured before the pass mark existed.
     *
     * @param int $userid Learner.
     * @param float $grade Grade captured.
     * @param int $completed Completion time.
     * @return \stdClass
     */
    private function achievement(int $userid, float $grade, int $completed): \stdClass {
        return $this->getDataGenerator()->get_plugin_generator('local_completionhistory')->create_achievement([
            'userid' => $userid,
            'courseid' => $this->course->id,
            'completiontime' => $completed,
            'grade_decimal' => $grade,
            'grade_passed' => null,
        ]);
    }

    /**
     * A run's counts.
     *
     * @param int $runid Run.
     * @return array
     */
    private function summary(int $runid): array {
        global $DB;
        return json_decode((string) $DB->get_field(backfill_runner::TABLE, 'summaryjson', ['id' => $runid]), true) ?? [];
    }

    /**
     * A second request for a job that is still queued returns the first run.
     */
    public function test_a_second_request_returns_the_queued_run(): void {
        global $USER;
        [$first, $existing] = backfill_runner::request('grades', true, null, 'web', (int) $USER->id);
        $this->assertFalse($existing);
        $this->assertSame('queued', $first->status);
        $this->assertSame(1, $this->queued_tasks());

        [$again, $existing] = backfill_runner::request('grades', false, null, 'web', (int) $USER->id);
        $this->assertTrue($existing);
        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, $this->queued_tasks());

        [, $existing] = backfill_runner::request('exams', true, null, 'web', (int) $USER->id);
        $this->assertFalse($existing, 'another job is not blocked');
    }

    /**
     * A run whose task has gone is marked failed and does not block the next request.
     */
    public function test_a_run_whose_task_has_gone_does_not_block(): void {
        global $DB, $USER;
        [$lost] = backfill_runner::request('grades', true, null, 'web', (int) $USER->id);
        $DB->delete_records('task_adhoc');
        [$next, $existing] = backfill_runner::request('grades', true, null, 'web', (int) $USER->id);
        $this->assertFalse($existing);
        $this->assertNotEquals($lost->id, $next->id);
        $this->assertSame('failed', $DB->get_field(backfill_runner::TABLE, 'status', ['id' => $lost->id]));
    }

    /**
     * The grade refresh sets pass flags from the new pass mark: a preview counts, a run writes.
     */
    public function test_the_grade_refresh_sets_pass_flags_from_a_new_pass_mark(): void {
        global $DB, $USER;
        $gen = $this->getDataGenerator();
        $passer = $gen->create_user();
        $failer = $gen->create_user();
        $this->course_total((int) $passer->id, 85);
        $this->course_total((int) $failer->id, 70);
        $older = $this->achievement((int) $passer->id, 60, time() - 10 * DAYSECS);
        $passed = $this->achievement((int) $passer->id, 85, time() - DAYSECS);
        $failed = $this->achievement((int) $failer->id, 70, time() - DAYSECS);

        [$preview] = backfill_runner::request('grades', true, null, 'web', (int) $USER->id);
        $this->run_tasks();
        $this->assertSame('done', $DB->get_field(backfill_runner::TABLE, 'status', ['id' => $preview->id]));
        $counts = $this->summary((int) $preview->id);
        $this->assertSame(2, $counts['scanned'], 'the newest row per learner and course only');
        $this->assertSame(2, $counts['changed']);
        $this->assertSame(2, $counts['passflagsset']);
        $this->assertNull($DB->get_field('local_completionhistory_achievement', 'grade_passed', ['id' => $passed->id]));

        [$run] = backfill_runner::request('grades', false, null, 'web', (int) $USER->id);
        $this->run_tasks();
        $this->assertSame(2, $this->summary((int) $run->id)['changed']);
        $this->assertEquals(1, $DB->get_field('local_completionhistory_achievement', 'grade_passed', ['id' => $passed->id]));
        $this->assertEquals(0, $DB->get_field('local_completionhistory_achievement', 'grade_passed', ['id' => $failed->id]));
        $this->assertNull(
            $DB->get_field('local_completionhistory_achievement', 'grade_passed', ['id' => $older->id]),
            'an earlier completion of the same course is left as captured'
        );
        $this->assertTrue($DB->record_exists('local_completionhistory_ach_revision', [
            'achievementid' => $passed->id,
            'fieldname' => 'grade_passed',
        ]), 'the change is in the correction history');

        [$again] = backfill_runner::request('grades', false, null, 'web', (int) $USER->id);
        $this->run_tasks();
        $this->assertSame(0, $this->summary((int) $again->id)['changed']);
        $this->assertSame(2, $this->summary((int) $again->id)['unchanged']);
    }

    /**
     * A job that cannot run is recorded as failed with its reason, not retried.
     */
    public function test_a_failed_job_records_why(): void {
        global $DB, $USER;
        set_config('capturegrades', 0, 'local_completionhistory');
        [$run] = backfill_runner::request('grades', false, null, 'web', (int) $USER->id);
        $this->run_tasks();
        $row = $DB->get_record(backfill_runner::TABLE, ['id' => $run->id]);
        $this->assertSame('failed', $row->status);
        $this->assertStringContainsString('Grade capture is off', $row->log);
        $this->assertSame(0, $this->queued_tasks(), 'not left for cron to retry');
    }

    /**
     * The exam backfill reports counts without the per-attempt rows.
     */
    public function test_the_exam_backfill_reports_counts_only(): void {
        global $USER;
        [$run] = backfill_runner::request('exams', true, null, 'web', (int) $USER->id);
        $this->run_tasks();
        $counts = $this->summary((int) $run->id);
        $this->assertArrayHasKey('recorded', $counts);
        $this->assertArrayNotHasKey('rows', $counts);
        $this->assertFalse($counts['sisrescanneeded']);
    }

    /**
     * A course is named by ID number or short name, and an unknown one is refused.
     */
    public function test_a_course_is_found_by_idnumber_or_shortname(): void {
        $this->assertSame((int) $this->course->id, backfill_runner::resolve_course('CL601'));
        $this->assertNull(backfill_runner::resolve_course('  '));
        $this->expectException(\moodle_exception::class);
        backfill_runner::resolve_course('NOPE999');
    }

    /**
     * The SIS asks for a run and reads it back through the web services.
     */
    public function test_the_sis_requests_and_reads_a_run(): void {
        $requested = \core_external\external_api::clean_returnvalue(
            request_backfill::execute_returns(),
            request_backfill::execute('grades', false, 'CL601')
        );
        $this->assertTrue($requested['dryrun']);
        $this->assertSame('CL601', $requested['course']);
        $this->assertSame('queued', $requested['status']);
        $this->assertFalse($requested['existing']);

        $read = \core_external\external_api::clean_returnvalue(
            get_backfill_run::execute_returns(),
            get_backfill_run::execute($requested['id'])
        );
        $this->assertSame($requested['id'], $read['id']);

        $this->run_tasks();
        $done = get_backfill_run::execute($requested['id']);
        $this->assertSame('done', $done['status']);
        $this->assertNotSame('', $done['summary']);
    }

    /**
     * The integration capability alone cannot start a run: runbackfill is required.
     */
    public function test_the_runbackfill_capability_is_required(): void {
        $integrator = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/completionhistory:integrate', CAP_ALLOW, $roleid, \context_system::instance()->id);
        role_assign($roleid, $integrator->id, \context_system::instance()->id);
        $this->setUser($integrator);
        $this->expectException(\required_capability_exception::class);
        request_backfill::execute('grades');
    }
}
