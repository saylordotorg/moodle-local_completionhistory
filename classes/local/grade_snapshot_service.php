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

use stdClass;

/**
 * Snapshots the course total grade for a user.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade_snapshot_service {

    /**
     * Get the course total grade for a user.
     *
     * @param int $userid
     * @param int $courseid
     * @return stdClass|null Object with finalgrade, grademax, gradepass, passed; or null if unavailable.
     */
    public static function get_course_total(int $userid, int $courseid): ?stdClass {
        global $DB;

        // Find the course total grade item.
        $gradeitem = $DB->get_record('grade_items', [
            'courseid' => $courseid,
            'itemtype' => 'course',
        ]);

        if (!$gradeitem) {
            return null;
        }

        // Get the user's grade for this item.
        $grade = $DB->get_record('grade_grades', [
            'itemid' => $gradeitem->id,
            'userid' => $userid,
        ]);

        if (!$grade || $grade->finalgrade === null) {
            return null;
        }

        $result = new stdClass();
        $result->finalgrade = (float) $grade->finalgrade;
        $result->grademax = (float) $gradeitem->grademax;
        $result->gradepass = (float) $gradeitem->gradepass;

        // Determine pass/fail.
        if ($gradeitem->gradepass > 0) {
            $result->passed = ($grade->finalgrade >= $gradeitem->gradepass) ? 1 : 0;
        } else {
            $result->passed = null; // No pass threshold defined.
        }

        return $result;
    }
}
