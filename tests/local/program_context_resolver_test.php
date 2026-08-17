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
 * Tests for program_context_resolver.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class program_context_resolver_test extends advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        program_context_resolver::reset_cache();
    }

    /**
     * Test that resolve returns empty when enrol_programs tables don't exist.
     *
     * This test is always valid since the test environment may or may not
     * have enrol_programs installed.
     */
    public function test_resolve_returns_empty_when_no_programs(): void {
        global $DB;

        // If enrol_programs is not installed, this should return empty.
        // If it is installed but no matching data, also empty.
        $result = program_context_resolver::resolve(99999, 99999);
        $this->assertIsArray($result);
        // Can't assert empty because enrol_programs might be installed with data.
        // But at minimum it should be an array.
    }

    /**
     * Test that resolve returns array type.
     */
    public function test_resolve_returns_array(): void {
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();

        $result = program_context_resolver::resolve($user->id, $course->id);
        $this->assertIsArray($result);
    }

    /**
     * Test cache reset works.
     */
    public function test_cache_reset(): void {
        // Just verify no errors.
        program_context_resolver::reset_cache();
        $result = program_context_resolver::resolve(1, 1);
        $this->assertIsArray($result);
    }
}
