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
 * Tests for closing a learner's course access when they leave the course in the SIS.
 *
 * The properties worth pinning are the ones that were design decisions rather than
 * mechanics:
 *
 *   ACCESS CLOSES. The course drops out of the learner's active enrolments, which is
 *   the whole complaint this endpoint answers — a student pressed "Leave course" in
 *   mySaylor and the course was still sitting in Moodle, open.
 *
 *   THE WORK SURVIVES. Suspension rather than unenrol_user() exists precisely so a
 *   grade cannot be destroyed by a pacing decision. The gradebook row is the thing
 *   Moodle's own unenrol path deletes, so that is what this asserts on.
 *
 *   COMING BACK NEEDS NO REPAIR. enrol_user_in_course must reactivate the suspended
 *   row rather than seeing an existing enrolment and doing nothing — the failure mode
 *   would be a student who can never re-enter a course they left.
 *
 *   IT DOES NOT UNDO SOMEBODY ELSE'S ENROLMENT, and says so when one is what is
 *   keeping the course open, because the SIS is about to tell the student in plain
 *   words that their access is closed.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class unenrol_user_from_course_test extends advanced_testcase {

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
     * Leaving closes access and keeps the grade.
     */
    public function test_suspends_the_enrolment_and_keeps_the_grade(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['idnumber' => 'PSYCH101']);
        $user = $this->getDataGenerator()->create_user(['auth' => 'manual']);
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student', 'manual');

        // A grade in the gradebook, which is what Moodle's unenrol_user() removes.
        $item = new \grade_item($this->getDataGenerator()->create_grade_item([
            'courseid' => $course->id,
            'itemname' => 'Final exam',
        ]), false);
        $item->update_final_grade($user->id, 84.0);

        $context = \context_course::instance($course->id);
        $this->assertTrue(is_enrolled($context, $user, '', true));

        $result = unenrol_user_from_course::execute($user->email, 'PSYCH101');

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['changed']);
        $this->assertSame('', $result['warning']);
        $this->assertEquals($course->id, $result['courseid']);

        // Access is closed...
        $this->assertFalse(is_enrolled($context, $user, '', true));
        // ...but the enrolment row is suspended, not deleted, and the grade is intact.
        $ue = $DB->get_record_sql(
            "SELECT ue.status
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.courseid = :courseid AND ue.userid = :userid",
            ['courseid' => $course->id, 'userid' => $user->id]
        );
        $this->assertNotEmpty($ue, 'the enrolment must be suspended, not removed');
        $this->assertEquals(ENROL_USER_SUSPENDED, (int) $ue->status);
        $grade = $DB->get_field_sql(
            "SELECT gg.finalgrade
               FROM {grade_grades} gg
               JOIN {grade_items} gi ON gi.id = gg.itemid
              WHERE gi.courseid = :courseid AND gg.userid = :userid AND gi.itemtype = 'manual'",
            ['courseid' => $course->id, 'userid' => $user->id]
        );
        $this->assertEquals(84.0, (float) $grade, 'leaving a course must never cost a student a grade');
    }

    /**
     * Re-enrolling reactivates the same row, so a student can carry on where they left off.
     */
    public function test_re_enrolling_reopens_the_course(): void {
        $course = $this->getDataGenerator()->create_course(['idnumber' => 'PSYCH101']);
        $user = $this->getDataGenerator()->create_user(['auth' => 'manual']);
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student', 'manual');
        $context = \context_course::instance($course->id);

        unenrol_user_from_course::execute($user->email, 'PSYCH101');
        $this->assertFalse(is_enrolled($context, $user, '', true));

        $again = enrol_user_in_course::execute($user->email, 'PSYCH101');
        $this->assertTrue($again['ok']);
        $this->assertFalse($again['already'], 'a suspended enrolment is not an active one');
        $this->assertTrue(is_enrolled($context, $user, '', true));
    }

    /**
     * Idempotent: a retry, or a course that was never open, is success that changed nothing.
     *
     * The SIS calls this after it has already committed the slot release, so a second
     * call from a stale tab or a retried request must not become an error the student sees.
     */
    public function test_is_idempotent(): void {
        $course = $this->getDataGenerator()->create_course(['idnumber' => 'PSYCH101']);
        $user = $this->getDataGenerator()->create_user(['auth' => 'manual']);
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student', 'manual');

        $this->assertTrue(unenrol_user_from_course::execute($user->email, 'PSYCH101')['changed']);
        $second = unenrol_user_from_course::execute($user->email, 'PSYCH101');
        $this->assertTrue($second['ok']);
        $this->assertFalse($second['changed']);

        $never = $this->getDataGenerator()->create_course(['idnumber' => 'ART101']);
        $result = unenrol_user_from_course::execute($user->email, 'ART101');
        $this->assertTrue($result['ok']);
        $this->assertFalse($result['changed']);
        $this->assertSame('', $result['warning']);
        $this->assertEquals($never->id, $result['courseid']);
    }

    /**
     * An enrolment this integration did not create is left alone, and reported.
     */
    public function test_leaves_other_enrolment_methods_alone(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['idnumber' => 'PSYCH101']);
        $user = $this->getDataGenerator()->create_user(['auth' => 'manual']);
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student', 'manual');

        // A second, self-enrolment. Built by hand rather than through the generator: a new course
        // carries a self instance that is DISABLED by default, and enrolling into a disabled
        // instance produces an enrolment Moodle does not count as active — which would make this
        // test pass for the wrong reason, proving nothing about the warning.
        $selfplugin = enrol_get_plugin('self');
        $self = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'self']);
        if (!$self) {
            $selfid = $selfplugin->add_instance($course, $selfplugin->get_instance_defaults());
            $self = $DB->get_record('enrol', ['id' => $selfid], '*', MUST_EXIST);
        }
        $selfplugin->update_status($self, ENROL_INSTANCE_ENABLED);
        $self = $DB->get_record('enrol', ['id' => $self->id], '*', MUST_EXIST);
        $selfplugin->enrol_user($self, $user->id,
            (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST));

        $result = unenrol_user_from_course::execute($user->email, 'PSYCH101');

        $this->assertTrue($result['changed'], 'the manual enrolment is still suspended');
        $this->assertStringContainsString('self', $result['warning']);
        $selfue = $DB->get_record('user_enrolments', ['enrolid' => $self->id, 'userid' => $user->id]);
        $this->assertEquals(ENROL_USER_ACTIVE, (int) $selfue->status,
            'an enrolment the SIS did not create is not the SIS\'s to withdraw');
        $this->assertTrue(is_enrolled(\context_course::instance($course->id), $user, '', true));
    }

    /**
     * Staff accounts are not learners, and are refused before anything is changed.
     */
    public function test_staff_account_is_refused(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['idnumber' => 'PSYCH101']);
        $teacher = $this->getDataGenerator()->create_user(['auth' => 'manual']);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher', 'manual');

        $this->expectException(\moodle_exception::class);
        try {
            unenrol_user_from_course::execute($teacher->email, 'PSYCH101');
        } finally {
            $ue = $DB->get_record_sql(
                "SELECT ue.status
                   FROM {user_enrolments} ue
                   JOIN {enrol} e ON e.id = ue.enrolid
                  WHERE e.courseid = :courseid AND ue.userid = :userid",
                ['courseid' => $course->id, 'userid' => $teacher->id]
            );
            $this->assertEquals(ENROL_USER_ACTIVE, (int) $ue->status);
        }
    }

    /**
     * The plugin's own kill switch is honoured.
     */
    public function test_disabled_plugin_refuses(): void {
        set_config('enabled', 0, 'local_completionhistory');
        $course = $this->getDataGenerator()->create_course(['idnumber' => 'PSYCH101']);
        $user = $this->getDataGenerator()->create_user(['auth' => 'manual']);
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student', 'manual');

        $this->expectException(\moodle_exception::class);
        unenrol_user_from_course::execute($user->email, 'PSYCH101');
    }
}
