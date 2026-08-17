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
 * Tests for shared integration security checks.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class security_test extends advanced_testcase {
    /**
     * Set up test state.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Email identity lookups must refuse an ambiguous match.
     */
    public function test_duplicate_email_is_rejected(): void {
        global $DB;

        $first = $this->getDataGenerator()->create_user(['email' => 'first@example.test']);
        $second = $this->getDataGenerator()->create_user(['email' => 'second@example.test']);
        $DB->set_field('user', 'email', 'shared@example.test', ['id' => $first->id]);
        $DB->set_field('user', 'email', 'SHARED@example.test', ['id' => $second->id]);

        try {
            security::get_unique_local_user_by_email('shared@example.test');
            $this->fail('An ambiguous email lookup must not select an arbitrary account.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('ambiguousemail', $exception->errorcode);
        }
    }

    /**
     * Student assignments remain eligible, while staff assignments are refused.
     */
    public function test_learner_check_rejects_staff_role(): void {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $this->assertTrue(security::is_learner_account($student));
        $this->assertFalse(security::is_learner_account($teacher));
    }

    /**
     * Site managers are privileged even when they are not site administrators.
     */
    public function test_manager_is_privileged(): void {
        global $DB;

        $manager = $this->getDataGenerator()->create_user();
        $managerroleid = $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($managerroleid, $manager->id, \context_system::instance()->id);

        $this->assertTrue(security::is_privileged_user($manager));
        $this->assertFalse(security::is_learner_account($manager));
    }
}
