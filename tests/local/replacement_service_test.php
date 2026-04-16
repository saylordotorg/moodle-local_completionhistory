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

/**
 * Tests for replacement_service.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_completionhistory\local\replacement_service
 */
class replacement_service_test extends advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    public function test_add_and_get_mapping(): void {
        $oldcourse = $this->getDataGenerator()->create_course();
        $newcourse = $this->getDataGenerator()->create_course();

        $id = replacement_service::add_mapping($oldcourse->id, $newcourse->id, 'redirect_incomplete', 'Test note');
        $this->assertGreaterThan(0, $id);

        $mapping = replacement_service::get_replacement($oldcourse->id);
        $this->assertNotNull($mapping);
        $this->assertEquals($newcourse->id, $mapping->newcourseid);
        $this->assertEquals($newcourse->fullname, $mapping->newcoursename_snapshot);
        $this->assertEquals('redirect_incomplete', $mapping->migrationrule);
        $this->assertEquals(1, $mapping->active);
    }

    public function test_deactivate_mapping(): void {
        $oldcourse = $this->getDataGenerator()->create_course();
        $newcourse = $this->getDataGenerator()->create_course();

        $id = replacement_service::add_mapping($oldcourse->id, $newcourse->id);
        replacement_service::deactivate_mapping($id);

        $mapping = replacement_service::get_replacement($oldcourse->id);
        $this->assertNull($mapping); // Inactive mapping not returned.
    }

    public function test_get_recommendation_skips_completed_user(): void {
        $user = $this->getDataGenerator()->create_user();
        $oldcourse = $this->getDataGenerator()->create_course();
        $newcourse = $this->getDataGenerator()->create_course();

        replacement_service::add_mapping($oldcourse->id, $newcourse->id);

        // User hasn't completed new course — should get recommendation.
        $rec = replacement_service::get_recommendation_for_user($user->id, $oldcourse->id);
        $this->assertNotNull($rec);

        // Now simulate the user having completed the new course.
        $generator = $this->getDataGenerator()->get_plugin_generator('local_completionhistory');
        $generator->create_achievement([
            'userid' => $user->id,
            'courseid' => $newcourse->id,
            'coursename_snapshot' => $newcourse->fullname,
        ]);

        // Should no longer get a recommendation.
        $rec = replacement_service::get_recommendation_for_user($user->id, $oldcourse->id);
        $this->assertNull($rec);
    }

    public function test_chain_follows_replacements(): void {
        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();
        $course3 = $this->getDataGenerator()->create_course();

        replacement_service::add_mapping($course1->id, $course2->id);
        replacement_service::add_mapping($course2->id, $course3->id);

        $chain = replacement_service::get_chain($course1->id);
        $this->assertCount(2, $chain);
        $this->assertEquals($course2->id, $chain[0]->newcourseid);
        $this->assertEquals($course3->id, $chain[1]->newcourseid);
    }

    public function test_no_mapping_returns_null(): void {
        $mapping = replacement_service::get_replacement(99999);
        $this->assertNull($mapping);
    }
}
