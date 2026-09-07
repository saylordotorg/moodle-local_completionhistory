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
use core_external\external_api;
use local_completionhistory\local\replacement_service;

/**
 * Authorization tests for the get_course_replacement external function.
 *
 * The 2026-09 security review found that any authenticated user could read the replacement mapping
 * of any course id, including staff-only migration rules for hidden and deleted courses. These tests
 * pin the boundary that closed it: a relationship with the old course is required before the mapping
 * is even looked up, staff may ask about anything, and a learner is only told about a replacement
 * course they could actually open.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_completionhistory\external\get_course_replacement
 */
final class get_course_replacement_test extends advanced_testcase {
    /** @var \stdClass The retired course. */
    private $oldcourse;

    /** @var \stdClass The course that replaced it. */
    private $newcourse;

    /**
     * Two courses and an active mapping between them.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enabled', 1, 'local_completionhistory');
        $this->oldcourse = $this->getDataGenerator()->create_course(['fullname' => 'Old Course']);
        $this->newcourse = $this->getDataGenerator()->create_course(['fullname' => 'New Course']);
        replacement_service::add_mapping($this->oldcourse->id, $this->newcourse->id);
    }

    /**
     * Call the function as the current user and clean the result the way the web service layer does.
     *
     * @param int $courseid The old course id to ask about.
     * @return array
     */
    private function call(int $courseid): array {
        return external_api::clean_returnvalue(
            get_course_replacement::execute_returns(),
            get_course_replacement::execute($courseid)
        );
    }

    /**
     * Grant a user a system role holding the viewall capability.
     *
     * @param \stdClass $user
     */
    private function make_staff(\stdClass $user): void {
        $roleid = create_role('Ledger staff', 'ledgerstaff', '');
        assign_capability('local/completionhistory:viewall', CAP_ALLOW, $roleid, \context_system::instance()->id);
        role_assign($roleid, $user->id, \context_system::instance()->id);
    }

    /**
     * A user with no relationship to the old course is refused before the mapping is read.
     */
    public function test_unrelated_user_is_refused(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('replacementlookupnotallowed', 'local_completionhistory'));
        get_course_replacement::execute($this->oldcourse->id);
    }

    /**
     * The refusal does not depend on whether a mapping exists, so it cannot be used as an oracle.
     */
    public function test_unrelated_user_is_refused_even_when_no_mapping_exists(): void {
        $unmapped = $this->getDataGenerator()->create_course();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        get_course_replacement::execute($unmapped->id);
    }

    /**
     * A learner enrolled in the old course gets the replacement, and only the fields the workflow needs.
     */
    public function test_enrolled_learner_gets_minimal_mapping(): void {
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $this->oldcourse->id, 'student');
        $this->setUser($learner);

        $result = $this->call($this->oldcourse->id);

        $this->assertTrue($result['found']);
        $this->assertSame((int) $this->oldcourse->id, $result['oldcourseid']);
        $this->assertSame((int) $this->newcourse->id, $result['newcourseid']);
        $this->assertSame('New Course', $result['newcoursename']);
        // Staff configuration must not leak through the learner endpoint.
        $this->assertSame(['found', 'oldcourseid', 'newcourseid', 'newcoursename'], array_keys($result));
    }

    /**
     * A suspended enrolment still counts: a retired course is often one the learner was suspended from.
     */
    public function test_suspended_enrolment_counts_as_a_relationship(): void {
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user(
            $learner->id,
            $this->oldcourse->id,
            'student',
            'manual',
            0,
            0,
            ENROL_USER_SUSPENDED
        );
        $this->setUser($learner);

        $this->assertTrue($this->call($this->oldcourse->id)['found']);
    }

    /**
     * An achievement ledger row for the old course is a relationship too — the enrolment may be gone.
     */
    public function test_ledger_row_counts_as_a_relationship(): void {
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->get_plugin_generator('local_completionhistory')->create_achievement([
            'userid' => $learner->id,
            'courseid' => $this->oldcourse->id,
        ]);
        $this->setUser($learner);

        $this->assertTrue($this->call($this->oldcourse->id)['found']);
    }

    /**
     * A learner is not told about a replacement course they cannot see; staff are.
     */
    public function test_hidden_replacement_is_invisible_to_learners_but_not_staff(): void {
        global $DB;
        $DB->set_field('course', 'visible', 0, ['id' => $this->newcourse->id]);

        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $this->oldcourse->id, 'student');
        $this->setUser($learner);
        $result = $this->call($this->oldcourse->id);
        $this->assertFalse($result['found']);
        $this->assertSame(0, $result['newcourseid']);
        $this->assertSame('', $result['newcoursename']);

        $staff = $this->getDataGenerator()->create_user();
        $this->make_staff($staff);
        $this->setUser($staff);
        $this->assertTrue($this->call($this->oldcourse->id)['found']);
    }

    /**
     * Staff may ask about a course they have no relationship with.
     */
    public function test_staff_need_no_relationship(): void {
        $staff = $this->getDataGenerator()->create_user();
        $this->make_staff($staff);
        $this->setUser($staff);

        $result = $this->call($this->oldcourse->id);
        $this->assertTrue($result['found']);
        $this->assertSame((int) $this->newcourse->id, $result['newcourseid']);
    }

    /**
     * A related learner asking about a course with no mapping simply gets "none".
     */
    public function test_related_learner_with_no_mapping_gets_none(): void {
        $unmapped = $this->getDataGenerator()->create_course();
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $unmapped->id, 'student');
        $this->setUser($learner);

        $result = $this->call($unmapped->id);
        $this->assertFalse($result['found']);
        $this->assertSame((int) $unmapped->id, $result['oldcourseid']);
    }

    /**
     * The plugin's master switch is honoured before anything else.
     */
    public function test_disabled_plugin_refuses(): void {
        set_config('enabled', 0, 'local_completionhistory');
        $staff = $this->getDataGenerator()->create_user();
        $this->make_staff($staff);
        $this->setUser($staff);

        $this->expectException(\moodle_exception::class);
        get_course_replacement::execute($this->oldcourse->id);
    }
}
