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

namespace local_completionhistory\external;

use advanced_testcase;

/**
 * Tests for the one-time integration password setup endpoint.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class set_password_test extends advanced_testcase {
    /**
     * Set up test state.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enabled', 1, 'local_completionhistory');
        $this->setAdminUser();
    }

    /**
     * Setup succeeds once and cannot later act as a general password reset.
     */
    public function test_password_setup_is_one_time(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user(['auth' => 'manual']);
        set_user_preference('auth_forcepasswordchange', 1, $user->id);
        $password = 'A-secure-initial-password-73!';

        $result = set_password::execute($user->email, $password);
        $this->assertTrue($result['success']);
        $storeduser = $DB->get_record('user', ['id' => $user->id], '*', MUST_EXIST);
        $this->assertTrue(validate_internal_user_password($storeduser, $password));
        $this->assertFalse((bool) get_user_preferences('auth_forcepasswordchange', false, $user->id));

        $second = set_password::execute($user->email, 'A-different-secure-password-84!');
        $this->assertFalse($second['success']);
        $storeduser = $DB->get_record('user', ['id' => $user->id], '*', MUST_EXIST);
        $this->assertTrue(validate_internal_user_password($storeduser, $password));
    }

    /**
     * A staff account cannot be targeted even with the one-time preference set.
     */
    public function test_staff_account_is_refused(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user(['auth' => 'manual', 'password' => 'Original-password-45!']);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        set_user_preference('auth_forcepasswordchange', 1, $teacher->id);

        $result = set_password::execute($teacher->email, 'Replacement-password-56!');
        $this->assertFalse($result['success']);
        $storeduser = $DB->get_record('user', ['id' => $teacher->id], '*', MUST_EXIST);
        $this->assertTrue(validate_internal_user_password($storeduser, 'Original-password-45!'));
    }

    /**
     * The base integration capability alone cannot change a password.
     */
    public function test_dedicated_password_capability_is_required(): void {
        $caller = $this->getDataGenerator()->create_user();
        $roleid = create_role('Integration reader', 'integrationreader', '');
        assign_capability(
            'local/completionhistory:integrate',
            CAP_ALLOW,
            $roleid,
            \context_system::instance()->id
        );
        role_assign($roleid, $caller->id, \context_system::instance()->id);
        $this->setUser($caller);

        $target = $this->getDataGenerator()->create_user(['auth' => 'manual']);
        set_user_preference('auth_forcepasswordchange', 1, $target->id);

        $this->expectException(\required_capability_exception::class);
        set_password::execute($target->email, 'A-secure-initial-password-73!');
    }
}
