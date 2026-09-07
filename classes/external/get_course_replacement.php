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

use context_course;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_completionhistory\local\replacement_service;
use local_completionhistory\local\security;

/**
 * Tell a learner which course replaced one they were in.
 *
 * WHO MAY ASK. Until the 2026-09 security review this function answered anyone holding the default
 * `local/completionhistory:viewown` capability — which every authenticated user has — for ANY course
 * id, and returned the whole mapping row: both course-name snapshots, the migration rule and the
 * active flag. Mappings are configured for retired, hidden and deleted courses, so a logged-in user
 * could enumerate course ids and read staff-only replacement plans for courses they had never seen.
 *
 * Now the caller must have a relationship with the old course before anything is looked up: an
 * enrolment (active or not — a retired course is often one the learner was suspended from), or an
 * achievement ledger row for it. Staff holding `local/completionhistory:viewall` may ask about any
 * course. The relationship is checked BEFORE the mapping is read, so a refusal does not reveal
 * whether a mapping exists.
 *
 * WHAT IS RETURNED. Only what the learner-facing workflow needs: whether a replacement exists, and
 * the replacement course's id and name. The old course's name snapshot, the migration rule and the
 * active flag are staff configuration and stay on the mappings admin page. A replacement course
 * the caller may not see (hidden, or since deleted) is reported as no replacement, unless the
 * caller is staff.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_course_replacement extends external_api {
    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'The old/retired course ID'),
        ]);
    }

    /**
     * Look up the active replacement for a course the caller has a relationship with.
     *
     * @param int $courseid The old (retired) course id.
     * @return array found, oldcourseid, newcourseid, newcoursename.
     * @throws \moodle_exception When the caller has no relationship with the course and is not staff.
     */
    public static function execute(int $courseid): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);
        $oldcourseid = (int) $params['courseid'];

        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);
        security::require_enabled();
        require_capability('local/completionhistory:viewown', $systemcontext);

        $isstaff = has_capability('local/completionhistory:viewall', $systemcontext);
        if (!$isstaff && !self::caller_is_related_to_course((int) $USER->id, $oldcourseid)) {
            throw new \moodle_exception('replacementlookupnotallowed', 'local_completionhistory');
        }

        $none = [
            'found' => false,
            'oldcourseid' => $oldcourseid,
            'newcourseid' => 0,
            'newcoursename' => '',
        ];

        $mapping = replacement_service::get_replacement($oldcourseid);
        if (!$mapping || empty($mapping->newcourseid)) {
            return $none;
        }

        $newcourseid = (int) $mapping->newcourseid;
        $newcourse = $DB->get_record('course', ['id' => $newcourseid], 'id, fullname, visible') ?: null;

        if (!$isstaff) {
            // A learner is not told about a replacement they could not open: a deleted course, or a
            // hidden one they lack the capability to see.
            if (!$newcourse) {
                return $none;
            }
            $coursecontext = context_course::instance($newcourseid, IGNORE_MISSING);
            if (
                !$newcourse->visible
                    && (!$coursecontext || !has_capability('moodle/course:viewhiddencourses', $coursecontext))
            ) {
                return $none;
            }
        }

        $name = $newcourse
            ? format_string($newcourse->fullname, true, ['context' => $systemcontext])
            : format_string($mapping->newcoursename_snapshot, true, ['context' => $systemcontext]);

        return [
            'found' => true,
            'oldcourseid' => $oldcourseid,
            'newcourseid' => $newcourseid,
            'newcoursename' => $name,
        ];
    }

    /**
     * Does this user have a relationship with the old course that entitles them to ask about it?
     *
     * An enrolment of any status counts (a learner suspended from a retired course is exactly who
     * needs the answer), and so does an achievement ledger row — the course may have been deleted
     * since, taking its enrolments with it, while the ledger row survives by design.
     *
     * @param int $userid The caller.
     * @param int $courseid The old course.
     * @return bool
     */
    private static function caller_is_related_to_course(int $userid, int $courseid): bool {
        global $DB;

        if ($userid <= 0 || $courseid <= 0) {
            return false;
        }

        $enrolled = $DB->record_exists_sql(
            "SELECT 1
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.courseid = :courseid AND ue.userid = :userid",
            ['courseid' => $courseid, 'userid' => $userid]
        );
        if ($enrolled) {
            return true;
        }

        return $DB->record_exists('local_completionhistory_achievement', [
            'userid' => $userid,
            'courseid' => $courseid,
        ]);
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'found' => new external_value(PARAM_BOOL, 'Whether an active replacement exists that the caller may see'),
            'oldcourseid' => new external_value(PARAM_INT, 'Old course ID'),
            'newcourseid' => new external_value(PARAM_INT, 'Replacement course ID, 0 when none'),
            'newcoursename' => new external_value(PARAM_TEXT, 'Replacement course name, empty when none'),
        ]);
    }
}
