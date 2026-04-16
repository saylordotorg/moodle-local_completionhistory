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
 * Tests for ledger_service.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_completionhistory\local\ledger_service
 */
class ledger_service_test extends advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enabled', 1, 'local_completionhistory');
        set_config('autocapture', 1, 'local_completionhistory');
        set_config('capturegrades', 1, 'local_completionhistory');
    }

    /**
     * Test that capture_achievement creates a ledger row.
     */
    public function test_capture_creates_row(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();

        $completion = new stdClass();
        $completion->userid = $user->id;
        $completion->course = $course->id;
        $completion->timecompleted = time() - 3600;

        $id = ledger_service::capture_achievement($completion, 'core_completion', 'test');

        $this->assertNotNull($id);
        $record = $DB->get_record('local_completionhistory_achievement', ['id' => $id]);
        $this->assertEquals($user->id, $record->userid);
        $this->assertEquals($course->id, $record->courseid);
        $this->assertEquals($course->fullname, $record->coursename_snapshot);
        $this->assertEquals($course->shortname, $record->courseshortname_snapshot);
        $this->assertEquals($completion->timecompleted, $record->completiontime);
        $this->assertEquals('core_completion', $record->source_component);
        $this->assertNotEmpty($record->ledgeruuid);
        $this->assertNotEmpty($record->source_event_hash);
    }

    /**
     * Test that duplicate calls are idempotent (same hash = skip).
     */
    public function test_duplicate_is_idempotent(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();

        $completion = new stdClass();
        $completion->userid = $user->id;
        $completion->course = $course->id;
        $completion->timecompleted = time() - 3600;

        $id1 = ledger_service::capture_achievement($completion, 'core_completion', 'test');
        $id2 = ledger_service::capture_achievement($completion, 'core_completion', 'test');

        $this->assertNotNull($id1);
        $this->assertNull($id2); // Duplicate skipped.
        $this->assertEquals(1, $DB->count_records('local_completionhistory_achievement'));
    }

    /**
     * Test that two different completions create two rows.
     */
    public function test_different_completions_create_separate_rows(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();

        $completion1 = new stdClass();
        $completion1->userid = $user->id;
        $completion1->course = $course->id;
        $completion1->timecompleted = time() - 7200;

        $completion2 = new stdClass();
        $completion2->userid = $user->id;
        $completion2->course = $course->id;
        $completion2->timecompleted = time() - 3600; // Different time.

        $id1 = ledger_service::capture_achievement($completion1, 'core_completion', 'test');
        $id2 = ledger_service::capture_achievement($completion2, 'core_completion', 'test');

        $this->assertNotNull($id1);
        $this->assertNotNull($id2);
        $this->assertNotEquals($id1, $id2);
        $this->assertEquals(2, $DB->count_records('local_completionhistory_achievement'));
    }

    /**
     * Test capture with a deleted course (course record missing).
     */
    public function test_capture_with_deleted_course(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();

        $completion = new stdClass();
        $completion->userid = $user->id;
        $completion->course = 99999; // Non-existent.
        $completion->timecompleted = time() - 3600;

        $id = ledger_service::capture_achievement($completion, 'core_completion', 'test');

        $this->assertNotNull($id);
        $record = $DB->get_record('local_completionhistory_achievement', ['id' => $id]);
        $this->assertEquals('[deleted]', $record->coursename_snapshot);
        $this->assertNull($record->courseshortname_snapshot);
    }

    /**
     * Test UUID generation produces valid format.
     */
    public function test_uuid_format(): void {
        $uuid = ledger_service::generate_uuid();
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid
        );
    }

    /**
     * Test event hash is deterministic.
     */
    public function test_event_hash_deterministic(): void {
        $hash1 = ledger_service::compute_event_hash(42, 10, 1700000000, 'core_completion');
        $hash2 = ledger_service::compute_event_hash(42, 10, 1700000000, 'core_completion');
        $this->assertEquals($hash1, $hash2);

        // Different input = different hash.
        $hash3 = ledger_service::compute_event_hash(42, 10, 1700000001, 'core_completion');
        $this->assertNotEquals($hash1, $hash3);
    }

    /**
     * Test purge audit recording.
     */
    public function test_record_purge_audit(): void {
        global $DB;

        $id = ledger_service::record_purge_audit(42, 5, 'test_purge', '{"detail": "test"}');

        $this->assertGreaterThan(0, $id);
        $record = $DB->get_record('local_completionhistory_purge_audit', ['id' => $id]);
        $this->assertEquals(42, $record->userid);
        $this->assertEquals(5, $record->programid);
        $this->assertEquals('test_purge', $record->reason);
        $this->assertEquals('{"detail": "test"}', $record->detailsjson);
    }

    /**
     * Test capture snapshots user idnumber.
     */
    public function test_capture_snapshots_user_idnumber(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user(['idnumber' => 'STU-12345']);
        $course = $this->getDataGenerator()->create_course(['idnumber' => 'CS101']);

        $completion = new stdClass();
        $completion->userid = $user->id;
        $completion->course = $course->id;
        $completion->timecompleted = time();

        $id = ledger_service::capture_achievement($completion, 'core_completion', 'test');
        $record = $DB->get_record('local_completionhistory_achievement', ['id' => $id]);

        $this->assertEquals('STU-12345', $record->useridnumber_snapshot);
        $this->assertEquals('CS101', $record->courseidnumber_snapshot);
    }
}
