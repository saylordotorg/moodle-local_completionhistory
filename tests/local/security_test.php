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
 * @covers \local_completionhistory\local\security
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

    /**
     * A student archetype does not make a role with course-level staff powers a learner role.
     */
    public function test_student_archetype_with_staff_capability_is_refused(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $studentrole = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        assign_capability('moodle/grade:edit', CAP_ALLOW, $studentrole, \context_course::instance($course->id)->id);

        $this->assertFalse(has_capability('moodle/grade:edit', \context_system::instance(), $user));
        $this->assertTrue(security::is_privileged_user($user));
        $this->assertFalse(security::is_learner_account($user));
    }

    /**
     * A role defined with staff powers becomes privileged wherever that role is assigned.
     */
    public function test_staff_role_definition_is_checked_at_assignment_context(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $role = create_role('Student grader', 'studentgrader', '', 'student');
        assign_capability('moodle/grade:edit', CAP_ALLOW, $role, \context_system::instance()->id);
        role_assign($role, $user->id, \context_course::instance($course->id)->id);

        $this->assertFalse(has_capability('moodle/grade:edit', \context_system::instance(), $user));
        $this->assertFalse(security::is_learner_account($user));
    }

    /**
     * Staff powers granted below the role assignment, at activity level, are also refused.
     */
    public function test_module_override_is_refused_without_rejecting_unrelated_learners(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $staff = $this->getDataGenerator()->create_user();
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($staff->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($learner->id, $othercourse->id, 'student');
        $studentrole = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        assign_capability('mod/quiz:grade', CAP_ALLOW, $studentrole, \context_module::instance($quiz->cmid)->id);

        $this->assertFalse(security::is_learner_account($staff));
        $this->assertTrue(security::is_learner_account($learner));
    }

    /**
     * The certificate-only integration capability is privileged without the base integrate grant.
     */
    public function test_certificate_reader_is_not_a_learner(): void {
        $user = $this->getDataGenerator()->create_user();
        $role = create_role('Certificate reader', 'certificatereader', '', 'user');
        assign_capability('local/completionhistory:viewcertificates', CAP_ALLOW, $role, \context_system::instance()->id);
        role_assign($role, $user->id, \context_system::instance()->id);

        $this->assertTrue(security::is_privileged_user($user));
        $this->assertFalse(security::is_learner_account($user));
    }

    /**
     * Optional proctoring staff permissions are protected without requiring generic teaching powers.
     */
    public function test_proctoring_staff_permission_is_not_a_learner(): void {
        global $DB;

        $capability = 'quizaccess/proctoring:manageadminsettings';
        if (!$DB->record_exists('capabilities', ['name' => $capability])) {
            // Model the optional plugin's declaration without requiring that plugin in CI.
            $DB->insert_record('capabilities', (object) [
                'name' => $capability,
                'captype' => 'write',
                'contextlevel' => CONTEXT_SYSTEM,
                'component' => 'quizaccess_proctoring',
                'riskbitmask' => RISK_CONFIG,
            ]);
            \cache::make('core', 'capabilities')->delete('core_capabilities');
        }
        $user = $this->getDataGenerator()->create_user();
        $role = create_role('Proctoring staff', 'proctoringstaff', '', 'user');
        assign_capability($capability, CAP_ALLOW, $role, \context_system::instance()->id);
        role_assign($role, $user->id, \context_system::instance()->id);

        $this->assertFalse(security::is_learner_account($user));
    }
}
