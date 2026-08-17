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

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * External function: courses a user has STARTED but not yet completed —
 * actively enrolled + has accessed the course, with no completion recorded.
 * The SIS requirements engine uses this to distinguish "in progress" from
 * "not started" so teach-out vs. redirect can be applied on course updates
 * (SIS-66).
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_user_inprogress_courses extends external_api {

    /** Defensive ceiling for pathological enrolment sets. */
    private const MAX_COURSES = 1000;

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Moodle user id'),
        ]);
    }

    public static function execute(int $userid): array {
        global $DB, $CFG;

        $params = self::validate_parameters(self::execute_parameters(), ['userid' => $userid]);

        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);
        \local_completionhistory\local\security::require_enabled();
        require_capability('local/completionhistory:integrate', $systemcontext);
        // Started = actively enrolled + has a course last-access row; in
        // progress = started AND not completed.
        //
        // ACTIVE means more than status = 0. An enrolment carries its own window, and
        // status alone treats a not-yet-started or already-expired enrolment as
        // current — so a course the learner cannot open would have been reported as in
        // progress, and the SIS makes teach-out and pacing decisions on this answer.
        // The timestart/timeend predicates mirror Moodle's own active-enrolment logic
        // (0 means unbounded on either end).
        $now = time();
        $sql = "SELECT DISTINCT c.id, c.idnumber, c.shortname, c.fullname
                  FROM {course} c
                  JOIN {enrol} e ON e.courseid = c.id AND e.status = 0
                  JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.userid = :uid1 AND ue.status = 0
                       AND (ue.timestart = 0 OR ue.timestart <= :now1)
                       AND (ue.timeend = 0 OR ue.timeend > :now2)
                  JOIN {user_lastaccess} la ON la.courseid = c.id AND la.userid = :uid2
                 WHERE c.id <> :siteid
                   AND NOT EXISTS (
                       SELECT 1 FROM {course_completions} cc
                        WHERE cc.course = c.id AND cc.userid = :uid3 AND cc.timecompleted IS NOT NULL
                   )
              ORDER BY c.id ASC";
        $records = $DB->get_records_sql($sql, [
            'uid1'   => $params['userid'],
            'uid2'   => $params['userid'],
            'uid3'   => $params['userid'],
            'now1'   => $now,
            'now2'   => $now,
            'siteid' => SITEID,
        ], 0, self::MAX_COURSES + 1);
        if (count($records) > self::MAX_COURSES) {
            throw new \moodle_exception('inprogresscoursestoolarge', 'local_completionhistory');
        }

        $courses = [];
        foreach ($records as $c) {
            $courses[] = [
                'courseid'  => (int) $c->id,
                'idnumber'  => (string) $c->idnumber,
                'shortname' => (string) $c->shortname,
                'fullname'  => (string) $c->fullname,
            ];
        }
        return ['courses' => $courses];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courses' => new external_multiple_structure(new external_single_structure([
                'courseid'  => new external_value(PARAM_INT, 'Moodle course id'),
                'idnumber'  => new external_value(PARAM_RAW, 'Course idnumber'),
                'shortname' => new external_value(PARAM_RAW, 'Course short name'),
                'fullname'  => new external_value(PARAM_RAW, 'Course full name'),
            ])),
        ]);
    }
}
