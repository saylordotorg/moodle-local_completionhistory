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

namespace local_completionhistory\external;

use advanced_testcase;

/**
 * Tests for giving a learner a fresh set of program final exam attempts (SIS-264 Part B).
 *
 * The properties that were decisions rather than mechanics:
 *
 *   A FULL ALLOWANCE AGAIN. Three attempts used on a three-attempt exam becomes six: the learner can
 *   sit it three more times, and the three earlier attempts stay on the record.
 *
 *   AN ACCOMMODATION SURVIVES. A learner's existing override (a longer time limit) keeps its other
 *   settings; only the attempt limit is written.
 *
 *   NEVER LOWERED, NEVER DOUBLED. A second call changes nothing, and an override already allowing
 *   more is left alone.
 *
 *   NOTHING WHEN NOTHING IS NEEDED. A learner with no attempts on this quiz (they failed under an old
 *   course code) gets no override at all.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_completionhistory\external\grant_exam_attempts
 */
final class grant_exam_attempts_test extends advanced_testcase {
    /** @var \stdClass The CL601 course. */
    private \stdClass $course;

    /** @var \stdClass Its program final quiz (3 attempts). */
    private \stdClass $quiz;

    /** @var \stdClass The learner. */
    private \stdClass $learner;

    /**
     * Set up a course with a configured three-attempt program final quiz, and a learner.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enabled', 1, 'local_completionhistory');
        $this->setAdminUser();

        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course(['idnumber' => 'CL601', 'shortname' => 'CL601']);
        $this->quiz = $gen->create_module('quiz', ['course' => $this->course->id, 'attempts' => 3]);
        $this->learner = $gen->create_user(['email' => 'learner@example.test']);
        $gen->enrol_user($this->learner->id, $this->course->id, 'student');

        $config = \local_completionhistory\local\course_config_service::get_config((int) $this->course->id);
        $config->course_type = 'standard';
        $config->program_final_quizid = $this->quiz->id;
        $config->program_attempts_allowed = 3;
        \local_completionhistory\local\course_config_service::save_config($config);
    }

    /**
     * Record $n finished attempts by the learner on the quiz.
     *
     * @param int $n Number of attempts.
     */
    private function attempts(int $n): void {
        global $DB;
        for ($i = 1; $i <= $n; $i++) {
            $DB->insert_record('quiz_attempts', (object) [
                'quiz' => $this->quiz->id, 'userid' => $this->learner->id, 'attempt' => $i,
                'uniqueid' => 900000 + $i, 'layout' => '1,0', 'currentpage' => 0, 'preview' => 0,
                'state' => 'finished', 'timestart' => time() - 3600, 'timefinish' => time() - 3000,
                'timemodified' => time() - 3000, 'sumgrades' => 0,
            ]);
        }
    }

    /**
     * Call the endpoint through its external_api contract.
     *
     * @param string $course Course idnumber.
     * @return array
     */
    private function grant(string $course = 'CL601'): array {
        $result = grant_exam_attempts::execute('learner@example.test', $course);
        return \core_external\external_api::clean_returnvalue(grant_exam_attempts::execute_returns(), $result);
    }

    /**
     * Three attempts used on a three-attempt exam become six allowed; a second call changes nothing.
     */
    public function test_three_used_becomes_six_and_a_second_call_changes_nothing(): void {
        global $DB;
        $this->attempts(3);
        $result = $this->grant();
        $this->assertSame('granted', $result['status']);
        $this->assertSame(3, $result['attemptsmade']);
        $this->assertSame(6, $result['attempts']);
        $override = $DB->get_record('quiz_overrides', ['quiz' => $this->quiz->id, 'userid' => $this->learner->id], '*', MUST_EXIST);
        $this->assertEquals(6, $override->attempts);
        $this->assertEquals(3, $DB->count_records('quiz_attempts', ['quiz' => $this->quiz->id, 'userid' => $this->learner->id]),
            'the earlier attempts stay on the record');

        $again = $this->grant();
        $this->assertSame('already', $again['status']);
        $this->assertSame((int) $override->id, $again['overrideid']);
        $this->assertEquals(1, $DB->count_records('quiz_overrides', ['quiz' => $this->quiz->id]));
    }

    /**
     * An existing override keeps its time limit; only the attempt limit is written.
     */
    public function test_an_existing_accommodation_keeps_its_time_limit(): void {
        global $DB;
        $this->attempts(3);
        $id = $DB->insert_record('quiz_overrides', (object) [
            'quiz' => $this->quiz->id, 'userid' => $this->learner->id, 'timelimit' => 7200, 'attempts' => null,
        ]);
        $result = $this->grant();
        $this->assertSame('granted', $result['status']);
        $this->assertSame($id, $result['overrideid'], 'the same override is updated, not a second one added');
        $override = $DB->get_record('quiz_overrides', ['id' => $id], '*', MUST_EXIST);
        $this->assertEquals(6, $override->attempts);
        $this->assertEquals(7200, $override->timelimit);
    }

    /**
     * An override already allowing more attempts is never lowered.
     */
    public function test_an_override_already_allowing_more_is_left_alone(): void {
        global $DB;
        $this->attempts(3);
        $id = $DB->insert_record('quiz_overrides', (object) ['quiz' => $this->quiz->id, 'userid' => $this->learner->id, 'attempts' => 10]);
        $result = $this->grant();
        $this->assertSame('already', $result['status']);
        $this->assertSame(10, $result['attempts']);
        $this->assertEquals(10, $DB->get_field('quiz_overrides', 'attempts', ['id' => $id]));
    }

    /**
     * A learner with no attempts on this quiz gets no override.
     */
    public function test_no_attempts_on_this_quiz_needs_no_override(): void {
        global $DB;
        $result = $this->grant();
        $this->assertSame('not_needed', $result['status']);
        $this->assertSame(0, $result['overrideid']);
        $this->assertFalse($DB->record_exists('quiz_overrides', ['quiz' => $this->quiz->id]));
    }

    /**
     * A course with no configured program final quiz is refused.
     */
    public function test_a_course_without_a_program_final_quiz_is_refused(): void {
        $this->getDataGenerator()->create_course(['idnumber' => 'CL699', 'shortname' => 'CL699']);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('CL699 has no program final quiz configured');
        $this->grant('CL699');
    }

    /**
     * The integration capability alone is not enough: grantattempts is required.
     */
    public function test_the_grant_capability_is_required(): void {
        $integrator = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/completionhistory:integrate', CAP_ALLOW, $roleid, \context_system::instance()->id);
        role_assign($roleid, $integrator->id, \context_system::instance()->id);
        $this->setUser($integrator);
        $this->attempts(3);
        $this->expectException(\required_capability_exception::class);
        $this->grant();
    }
}
