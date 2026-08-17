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
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;
use local_completionhistory\local\outbox_service;
use local_completionhistory\privacy\provider;

/**
 * Tests for the privacy provider.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class privacy_provider_test extends advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    public function test_get_contexts_for_userid(): void {
        $user = $this->getDataGenerator()->create_user();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_completionhistory');
        $generator->create_achievement(['userid' => $user->id]);

        $contextlist = provider::get_contexts_for_userid($user->id);
        $this->assertNotEmpty($contextlist->get_contextids());
    }

    public function test_export_user_data(): void {
        $user = $this->getDataGenerator()->create_user();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_completionhistory');
        $generator->create_achievement(['userid' => $user->id]);

        $contextlist = provider::get_contexts_for_userid($user->id);
        $approvedcontextlist = new approved_contextlist($user, 'local_completionhistory', $contextlist->get_contextids());

        provider::export_user_data($approvedcontextlist);

        $writer = writer::with_context(\context_system::instance());
        $this->assertTrue($writer->has_any_data());
    }

    /**
     * Exam-only data must be discoverable and erasable without a ledger row.
     */
    public function test_exam_only_user_is_in_privacy_contexts_and_is_anonymized(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $attemptid = $DB->insert_record('local_completionhistory_exam_attempt', (object) [
            'userid' => $user->id,
            'courseid' => 42,
            'quizid' => null,
            'exam_track' => 'certificate',
            'attempt_number' => 1,
            'attempts_allowed' => 3,
            'grade_decimal' => 80,
            'grade_passed' => 1,
            'resulted_in_completion' => 0,
            'achievementid' => null,
            'timetaken' => time(),
            'duration' => 300,
            'timecreated' => time(),
        ]);

        $contextlist = provider::get_contexts_for_userid($user->id);
        $this->assertContainsEquals(\context_system::instance()->id, $contextlist->get_contextids());
        $approved = new approved_contextlist($user, 'local_completionhistory', $contextlist->get_contextids());
        provider::delete_data_for_user($approved);

        $attempt = $DB->get_record('local_completionhistory_exam_attempt', ['id' => $attemptid], '*', MUST_EXIST);
        $this->assertEquals(0, $attempt->userid);
    }

    public function test_delete_data_anonymizes(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_completionhistory');
        $achievement = $generator->create_achievement([
            'userid' => $user->id,
            'useridnumber_snapshot' => 'STU-001',
            'firstname_snapshot' => 'Private',
            'lastname_snapshot' => 'Learner',
            'email_snapshot' => 'private@example.test',
            'artifacturl' => 'https://example.test/private-certificate',
        ]);
        $programid = $DB->insert_record('local_completionhistory_ach_program', (object) [
            'achievementid' => $achievement->id,
            'allocationid' => 9876,
            'programid' => 123,
            'programidnumber_snapshot' => 'PROGRAM-123',
            'programname_snapshot' => 'Test Program',
            'timecreated' => time(),
        ]);
        set_config('enableoutbox', 1, 'local_completionhistory');
        $outboxid = outbox_service::enqueue_achievement($achievement);
        $DB->set_field('local_completionhistory_outbox', 'lasterror', 'Rejected private@example.test', ['id' => $outboxid]);

        $attemptid = $DB->insert_record('local_completionhistory_exam_attempt', (object) [
            'userid' => $user->id,
            'courseid' => 42,
            'quizid' => null,
            'exam_track' => 'certificate',
            'attempt_number' => 1,
            'attempts_allowed' => 3,
            'grade_decimal' => 85,
            'grade_passed' => 1,
            'resulted_in_completion' => 1,
            'achievementid' => $achievement->id,
            'timetaken' => time() - 60,
            'duration' => 300,
            'timecreated' => time(),
        ]);
        $generator->create_purge_audit(['userid' => $user->id]);
        $originalhash = $achievement->source_event_hash;

        $contextlist = provider::get_contexts_for_userid($user->id);
        $approvedcontextlist = new approved_contextlist($user, 'local_completionhistory', $contextlist->get_contextids());

        provider::delete_data_for_user($approvedcontextlist);

        // Achievement still exists but is anonymized.
        $record = $DB->get_record('local_completionhistory_achievement', ['id' => $achievement->id]);
        $this->assertEquals(0, $record->userid);
        $this->assertNull($record->useridnumber_snapshot);
        $this->assertNull($record->firstname_snapshot);
        $this->assertNull($record->lastname_snapshot);
        $this->assertNull($record->email_snapshot);
        $this->assertNull($record->artifacturl);
        $this->assertSame($originalhash, $record->source_event_hash);
        // Course snapshot preserved.
        $this->assertNotEmpty($record->coursename_snapshot);

        $program = $DB->get_record('local_completionhistory_ach_program', ['id' => $programid], '*', MUST_EXIST);
        $this->assertNull($program->allocationid);
        $this->assertSame('Test Program', $program->programname_snapshot);

        $attempt = $DB->get_record('local_completionhistory_exam_attempt', ['id' => $attemptid], '*', MUST_EXIST);
        $this->assertEquals(0, $attempt->userid);

        $outbox = $DB->get_record('local_completionhistory_outbox', ['id' => $outboxid], '*', MUST_EXIST);
        $payload = json_decode($outbox->payloadjson, true, 512, JSON_THROW_ON_ERROR);
        $this->assertEquals(0, $payload['userid']);
        $this->assertSame('', $payload['firstname']);
        $this->assertSame('', $payload['lastname']);
        $this->assertSame('', $payload['email']);
        $this->assertSame('', $payload['artifacturl']);
        $this->assertNull($outbox->lasterror);
        $this->assertFalse($DB->record_exists('local_completionhistory_purge_audit', ['userid' => $user->id]));
    }
}
