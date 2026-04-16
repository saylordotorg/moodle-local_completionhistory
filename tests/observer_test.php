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

namespace local_completionhistory;

use advanced_testcase;
use stdClass;
use local_completionhistory\hook\course_completions_purged;
use local_completionhistory\local\ledger_service;

/**
 * Tests for event observers and hook callbacks.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_completionhistory\callbacks
 */
class observer_test extends advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enabled', 1, 'local_completionhistory');
        set_config('autocapture', 1, 'local_completionhistory');
        set_config('capturegrades', 0, 'local_completionhistory');
        set_config('enablepurgeaudit', 1, 'local_completionhistory');
    }

    /**
     * Test that the purge hook callback writes an audit row.
     */
    public function test_purge_hook_writes_audit(): void {
        global $DB;

        $hook = new course_completions_purged(
            userid: 42,
            reason: 'program_reset',
            purgedids: [100, 101, 102],
            courseid: 10,
            programid: 5,
        );

        callbacks::completions_purged($hook);

        $this->assertEquals(1, $DB->count_records('local_completionhistory_purge_audit'));
        $record = $DB->get_record('local_completionhistory_purge_audit', []);
        $this->assertEquals(42, $record->userid);
        $this->assertEquals(5, $record->programid);
        $this->assertEquals('program_reset', $record->reason);
    }

    /**
     * Test that the purge hook does NOT alter achievement records.
     */
    public function test_purge_hook_preserves_achievements(): void {
        global $DB;

        // Create an achievement first.
        $generator = $this->getDataGenerator()->get_plugin_generator('local_completionhistory');
        $achievement = $generator->create_achievement(['userid' => 42, 'courseid' => 10]);

        $countbefore = $DB->count_records('local_completionhistory_achievement');

        $hook = new course_completions_purged(
            userid: 42,
            reason: 'program_reset',
            purgedids: [100],
            courseid: 10,
        );
        callbacks::completions_purged($hook);

        // Achievement count unchanged.
        $this->assertEquals($countbefore, $DB->count_records('local_completionhistory_achievement'));
    }

    /**
     * Test that user_deleted anonymizes when configured.
     */
    public function test_user_deleted_anonymizes(): void {
        global $DB;

        set_config('gdpranonymize', 1, 'local_completionhistory');

        $user = $this->getDataGenerator()->create_user(['idnumber' => 'STU-999']);
        $generator = $this->getDataGenerator()->get_plugin_generator('local_completionhistory');
        $achievement = $generator->create_achievement([
            'userid' => $user->id,
            'useridnumber_snapshot' => 'STU-999',
            'artifacturl' => 'https://example.com/cert.pdf',
        ]);

        // Simulate user_deleted event callback directly.
        $event = \core\event\user_deleted::create([
            'objectid' => $user->id,
            'relateduserid' => $user->id,
            'context' => \context_system::instance(),
            'other' => [
                'username' => $user->username,
                'email' => $user->email,
                'idnumber' => $user->idnumber,
                'picture' => $user->picture,
                'mnethostid' => $user->mnethostid,
            ],
        ]);
        callbacks::user_deleted($event);

        $record = $DB->get_record('local_completionhistory_achievement', ['id' => $achievement->id]);
        $this->assertEquals(0, $record->userid);
        $this->assertNull($record->useridnumber_snapshot);
        $this->assertNull($record->artifacturl);
        // Course snapshot preserved.
        $this->assertNotEmpty($record->coursename_snapshot);
    }

    /**
     * Test that autocapture=0 prevents observer from capturing.
     */
    public function test_disabled_autocapture_skips(): void {
        global $DB;

        set_config('autocapture', 0, 'local_completionhistory');

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();

        // Simulate a course_completed event.
        $completion = new stdClass();
        $completion->userid = $user->id;
        $completion->course = $course->id;
        $completion->timecompleted = time();
        $DB->insert_record('course_completions', $completion);

        $event = \core\event\course_completed::create([
            'objectid' => $course->id,
            'relateduserid' => $user->id,
            'context' => \context_course::instance($course->id),
            'courseid' => $course->id,
        ]);
        callbacks::course_completed($event);

        $this->assertEquals(0, $DB->count_records('local_completionhistory_achievement'));
    }
}
