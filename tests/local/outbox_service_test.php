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
use stdClass;

/**
 * Tests for outbox_service and the transactional enqueue from ledger_service.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class outbox_service_test extends advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Build a completion stub for capture_achievement.
     */
    private function make_completion(int $userid, int $courseid, ?int $time = null): stdClass {
        $completion = new stdClass();
        $completion->userid = $userid;
        $completion->course = $courseid;
        $completion->timecompleted = $time ?? (time() - 3600);
        return $completion;
    }

    public function test_enqueue_creates_pending_row(): void {
        global $DB;

        $payload = ['ledgeruuid' => 'abc-123', 'userid' => 7];
        $id = outbox_service::enqueue(outbox_service::ENTITY_ACHIEVEMENT, 42, $payload);

        $this->assertGreaterThan(0, $id);
        $row = $DB->get_record('local_completionhistory_outbox', ['id' => $id]);
        $this->assertEquals('achievement', $row->entitytype);
        $this->assertEquals(42, $row->entityid);
        $this->assertEquals('pending', $row->status);
        $this->assertEquals(0, (int) $row->retrycount);
        $this->assertEquals($payload, json_decode($row->payloadjson, true));
    }

    public function test_get_unsynced_returns_fifo(): void {
        $id1 = outbox_service::enqueue('achievement', 1, ['n' => 1]);
        $id2 = outbox_service::enqueue('achievement', 2, ['n' => 2]);

        $rows = outbox_service::get_unsynced(10);
        $this->assertEquals([$id1, $id2], array_keys($rows));
    }

    public function test_mark_sent_updates_status_and_hides_from_pending(): void {
        global $DB;

        $id = outbox_service::enqueue('achievement', 1, ['n' => 1]);
        $count = outbox_service::mark_sent([$id]);

        $this->assertEquals(1, $count);
        $this->assertEquals('sent', $DB->get_field('local_completionhistory_outbox', 'status', ['id' => $id]));
        $this->assertEmpty(outbox_service::get_unsynced(10));
    }

    public function test_mark_failed_increments_retry_and_stores_error(): void {
        global $DB;

        $id = outbox_service::enqueue('achievement', 1, ['n' => 1]);
        outbox_service::mark_sent([$id], outbox_service::STATUS_FAILED, 'connection refused');

        $row = $DB->get_record('local_completionhistory_outbox', ['id' => $id]);
        $this->assertEquals('failed', $row->status);
        $this->assertEquals(1, (int) $row->retrycount);
        $this->assertEquals('connection refused', $row->lasterror);
    }

    public function test_build_achievement_payload_shape(): void {
        global $CFG;

        /** @var \local_completionhistory_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('local_completionhistory');
        $a = $gen->create_achievement([
            'userid'                => 7,
            'useridnumber_snapshot' => 'STU-7',
            'email_snapshot'        => 'stu7@example.com',
            'coursename_snapshot'   => 'Intro to Testing',
            'exam_track'            => 'program_final',
            'attempts_used'         => 1,
            'attempts_allowed'      => 3,
            'grade_decimal'         => 88.5,
            'grade_passed'          => 1,
        ]);

        $payload = outbox_service::build_achievement_payload($a);

        $this->assertEquals($a->ledgeruuid, $payload['ledgeruuid']);
        $this->assertEquals(7, $payload['userid']);
        $this->assertEquals('STU-7', $payload['useridnumber']);
        $this->assertEquals('stu7@example.com', $payload['email']);
        $this->assertEquals('program_final', $payload['examtrack']);
        $this->assertEquals(1, $payload['attemptsused']);
        $this->assertEquals(3, $payload['attemptsallowed']);
        $this->assertEquals(88.5, $payload['grade']);
        $this->assertSame(1, $payload['gradepassed']);
        $this->assertSame('', $payload['artifacturl']);
        $this->assertSame('', $payload['artifactstorage']);
        $this->assertSame('', $payload['artifactcode']);
        // Unset/blank setting falls back to the site URL at build time.
        $this->assertSame($CFG->wwwroot, $payload['sourcesite']);
        $this->assertIsArray($payload['programs']);
    }

    public function test_build_achievement_payload_uses_sourcesite_setting(): void {
        /** @var \local_completionhistory_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('local_completionhistory');
        $a = $gen->create_achievement([]);

        set_config('sourcesite', 'prod-degrees', 'local_completionhistory');
        $payload = outbox_service::build_achievement_payload($a);
        $this->assertSame('prod-degrees', $payload['sourcesite']);

        set_config('sourcesite', '   ', 'local_completionhistory');
        global $CFG;
        $payload = outbox_service::build_achievement_payload($a);
        $this->assertSame($CFG->wwwroot, $payload['sourcesite']);
    }

    public function test_build_achievement_payload_includes_certificate_artifact(): void {
        /** @var \local_completionhistory_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('local_completionhistory');
        $a = $gen->create_achievement([
            'artifacturl' => 'https://degrees.example/admin/tool/certificate/view.php?code=ABC123',
            'artifactstorage' => 'tool_certificate:ABC123',
        ]);

        $payload = outbox_service::build_achievement_payload($a);

        $this->assertSame('https://degrees.example/admin/tool/certificate/view.php?code=ABC123', $payload['artifacturl']);
        $this->assertSame('tool_certificate:ABC123', $payload['artifactstorage']);
        $this->assertSame('ABC123', $payload['artifactcode']);
    }

    public function test_capture_enqueues_when_outbox_enabled(): void {
        global $DB;

        set_config('enabled', 1, 'local_completionhistory');
        set_config('autocapture', 1, 'local_completionhistory');
        set_config('enableoutbox', 1, 'local_completionhistory');

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();

        $achid = ledger_service::capture_achievement(
            $this->make_completion($user->id, $course->id),
            'core_completion',
            'test'
        );
        $this->assertNotNull($achid);

        $rows = $DB->get_records('local_completionhistory_outbox', [
            'entitytype' => 'achievement',
            'entityid'   => $achid,
        ]);
        $this->assertCount(1, $rows);

        $row = reset($rows);
        $this->assertEquals('pending', $row->status);
        $payload = json_decode($row->payloadjson, true);
        $this->assertEquals($achid, $payload['id']);
        $this->assertEquals($user->id, $payload['userid']);
        $this->assertEquals($course->fullname, $payload['coursename']);
    }

    public function test_capture_does_not_enqueue_when_outbox_disabled(): void {
        global $DB;

        set_config('enabled', 1, 'local_completionhistory');
        set_config('autocapture', 1, 'local_completionhistory');
        set_config('enableoutbox', 0, 'local_completionhistory');

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();

        ledger_service::capture_achievement(
            $this->make_completion($user->id, $course->id),
            'core_completion',
            'test'
        );

        $this->assertEquals(0, $DB->count_records('local_completionhistory_outbox'));
    }
}
