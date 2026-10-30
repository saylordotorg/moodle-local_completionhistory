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
 * Tests for the per-course progress feed behind the SIS mid-course survey (SIS-246).
 *
 * The properties pinned are the ones the SIS relies on:
 *
 *   THE NUMBER IS MOODLE'S. Half of the tracked activities completed reads as 50, the figure the
 *   student sees on their own dashboard.
 *
 *   THE FEED PAGES. Two learners moving in the same second are both delivered across pages.
 *
 *   SITE COMPLETION OFF IS SAID, not shown as a page of zeros.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_completionhistory\external\get_course_progress
 */
final class get_course_progress_test extends advanced_testcase {
    /**
     * Set up test state.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->libdir . '/completionlib.php');
        $this->resetAfterTest();
        set_config('enabled', 1, 'local_completionhistory');
        set_config('enablecompletion', 1);
        $this->setAdminUser();
    }

    /**
     * A course with two manually-completed activities and the given students enrolled.
     *
     * @param int $students How many students to enrol.
     * @return array{0:\stdClass,1:array,2:array} [course, course modules, users]
     */
    private function course_with_two_activities(int $students): array {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['enablecompletion' => 1, 'idnumber' => 'MBA601']);
        $cms = [];
        foreach ([1, 2] as $n) {
            $page = $gen->create_module('page', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
            $cms[] = get_coursemodule_from_id('page', $page->cmid);
        }
        $users = [];
        for ($i = 0; $i < $students; $i++) {
            $users[] = $user = $gen->create_user();
            $gen->enrol_user($user->id, $course->id, 'student');
        }
        return [$course, $cms, $users];
    }

    /**
     * Half of the tracked activities completed is 50%, with the tracked count alongside.
     */
    public function test_half_the_activities_is_fifty_percent(): void {
        [$course, $cms, [$user]] = $this->course_with_two_activities(1);
        (new \completion_info($course))->update_state($cms[0], COMPLETION_COMPLETE, $user->id);

        $result = \core_external\external_api::clean_returnvalue(
            get_course_progress::execute_returns(),
            get_course_progress::execute(0, 0, 0, 50)
        );

        $this->assertCount(1, $result['progress']);
        $row = $result['progress'][0];
        $this->assertSame((int) $user->id, $row['userid']);
        $this->assertSame('MBA601', $row['course_idnumber']);
        $this->assertEquals(50.0, $row['progress']);
        $this->assertSame(2, $row['activities_tracked']);
        $this->assertTrue($result['site_completion_enabled']);
    }

    /**
     * Two learners in the same second are both delivered when the page holds one.
     */
    public function test_pages_through_learners_sharing_a_second(): void {
        global $DB;
        [$course, $cms, $users] = $this->course_with_two_activities(2);
        $completion = new \completion_info($course);
        foreach ($users as $user) {
            $completion->update_state($cms[0], COMPLETION_COMPLETE, $user->id);
        }
        $DB->set_field('course_modules_completion', 'timemodified', 1700000000);

        $first = get_course_progress::execute(0, 0, 0, 1);
        $this->assertCount(1, $first['progress']);
        $this->assertTrue($first['truncated']);
        $second = get_course_progress::execute(
            $first['next_since'],
            $first['next_since_userid'],
            $first['next_since_courseid'],
            1
        );
        $this->assertCount(1, $second['progress']);
        $seen = [$first['progress'][0]['userid'], $second['progress'][0]['userid']];
        sort($seen);
        $want = [(int) $users[0]->id, (int) $users[1]->id];
        sort($want);
        $this->assertSame($want, $seen);

        $third = get_course_progress::execute(
            $second['next_since'],
            $second['next_since_userid'],
            $second['next_since_courseid'],
            1
        );
        $this->assertCount(0, $third['progress']);
    }

    /**
     * With site completion off, the page is empty and says why.
     */
    public function test_site_completion_off_is_reported(): void {
        [$course, $cms, [$user]] = $this->course_with_two_activities(1);
        (new \completion_info($course))->update_state($cms[0], COMPLETION_COMPLETE, $user->id);
        set_config('enablecompletion', 0);

        $result = get_course_progress::execute();
        $this->assertSame([], $result['progress']);
        $this->assertFalse($result['site_completion_enabled']);
    }
}
