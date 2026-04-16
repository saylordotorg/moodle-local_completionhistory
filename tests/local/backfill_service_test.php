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
 * Tests for backfill_service.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_completionhistory\local\backfill_service
 */
class backfill_service_test extends advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enabled', 1, 'local_completionhistory');
        set_config('capturegrades', 0, 'local_completionhistory');
    }

    /**
     * Helper to insert a course_completions row directly.
     */
    private function insert_completion(int $userid, int $courseid, int $timecompleted): void {
        global $DB;
        $record = new stdClass();
        $record->userid = $userid;
        $record->course = $courseid;
        $record->timecompleted = $timecompleted;
        $record->timeenrolled = $timecompleted - 86400;
        $record->timestarted = $timecompleted - 43200;
        $record->reaggregate = 0;
        $DB->insert_record('course_completions', $record);
    }

    public function test_backfill_inserts_missing_records(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();

        $this->insert_completion($user->id, $course1->id, time() - 7200);
        $this->insert_completion($user->id, $course2->id, time() - 3600);

        $stats = backfill_service::scan_and_backfill(1000, false);

        $this->assertEquals(2, $stats->scanned);
        $this->assertEquals(2, $stats->inserted);
        $this->assertEquals(0, $stats->skipped);
        $this->assertEquals(0, $stats->errors);

        $this->assertEquals(2, $DB->count_records('local_completionhistory_achievement'));
    }

    public function test_backfill_is_idempotent(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();

        $this->insert_completion($user->id, $course->id, time() - 3600);

        // First run.
        $stats1 = backfill_service::scan_and_backfill(1000, false);
        $this->assertEquals(1, $stats1->inserted);

        // Second run — should skip.
        $stats2 = backfill_service::scan_and_backfill(1000, false);
        $this->assertEquals(0, $stats2->inserted);
        $this->assertEquals(1, $stats2->skipped);

        // Still only 1 record.
        $this->assertEquals(1, $DB->count_records('local_completionhistory_achievement'));
    }

    public function test_dry_run_does_not_insert(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();

        $this->insert_completion($user->id, $course->id, time() - 3600);

        $stats = backfill_service::scan_and_backfill(1000, true);

        $this->assertEquals(1, $stats->scanned);
        $this->assertEquals(1, $stats->inserted); // Would be inserted.
        $this->assertEquals(0, $DB->count_records('local_completionhistory_achievement')); // But nothing actually inserted.
    }

    public function test_backfill_respects_userid_filter(): void {
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();

        $this->insert_completion($user1->id, $course->id, time() - 3600);
        $this->insert_completion($user2->id, $course->id, time() - 3600);

        $stats = backfill_service::scan_and_backfill(1000, false, $user1->id);

        $this->assertEquals(1, $stats->scanned);
        $this->assertEquals(1, $stats->inserted);
    }
}
