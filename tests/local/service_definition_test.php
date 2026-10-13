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
 * Tests for the deployment checker's handling of contributed service functions.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_completionhistory\local\service_definition
 */
final class service_definition_test extends advanced_testcase {
    /**
     * Reset configuration changes between tests.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Only the function, component and service explicitly declared together are accepted.
     */
    public function test_only_explicit_service_contributions_are_accepted(): void {
        // Forum uses the same cross-plugin contribution mechanism as the optional
        // proctoring SIS export, and is present on every supported Moodle version.
        $function = 'mod_forum_get_forums_by_courses';
        $this->assertTrue(service_definition::is_declared_contribution('mod_forum', $function, MOODLE_OFFICIAL_MOBILE_SERVICE));
        $this->assertFalse(service_definition::is_declared_contribution('mod_forum', $function, 'completionhistory_sis'));
        $this->assertFalse(service_definition::is_declared_contribution('mod_quiz', $function, MOODLE_OFFICIAL_MOBILE_SERVICE));
        $this->assertFalse(service_definition::is_declared_contribution(
            'mod_forum',
            'removed_function',
            MOODLE_OFFICIAL_MOBILE_SERVICE
        ));
        $this->assertFalse(service_definition::is_declared_contribution('', $function, MOODLE_OFFICIAL_MOBILE_SERVICE));
    }

    /**
     * Source files remaining after uninstall are not proof of an installed contribution.
     */
    public function test_uninstalled_component_is_refused(): void {
        unset_config('version', 'mod_forum');
        $this->assertFalse(service_definition::is_declared_contribution(
            'mod_forum',
            'mod_forum_get_forums_by_courses',
            MOODLE_OFFICIAL_MOBILE_SERVICE
        ));
    }

    /**
     * A stale installation record cannot authorise a component whose source is missing.
     */
    public function test_missing_component_source_is_refused(): void {
        set_config('version', 2026101200, 'local_missingcontributor');
        $this->assertFalse(service_definition::is_declared_contribution(
            'local_missingcontributor',
            'removed_function',
            'completionhistory_sis'
        ));
    }
}
