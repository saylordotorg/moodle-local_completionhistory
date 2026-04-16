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
use local_completionhistory\privacy\provider;

/**
 * Tests for the privacy provider.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_completionhistory\privacy\provider
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

    public function test_delete_data_anonymizes(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_completionhistory');
        $achievement = $generator->create_achievement([
            'userid' => $user->id,
            'useridnumber_snapshot' => 'STU-001',
        ]);

        $contextlist = provider::get_contexts_for_userid($user->id);
        $approvedcontextlist = new approved_contextlist($user, 'local_completionhistory', $contextlist->get_contextids());

        provider::delete_data_for_user($approvedcontextlist);

        // Achievement still exists but is anonymized.
        $record = $DB->get_record('local_completionhistory_achievement', ['id' => $achievement->id]);
        $this->assertEquals(0, $record->userid);
        $this->assertNull($record->useridnumber_snapshot);
        // Course snapshot preserved.
        $this->assertNotEmpty($record->coursename_snapshot);
    }
}
