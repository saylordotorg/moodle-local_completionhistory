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
use local_completionhistory\local\flag_service;

/**
 * External function exposing exam attempts that match the configured system flags.
 *
 * WHY THIS EXISTS. The plugin already detects fast completions, duplicate
 * accounts, new-account-before-exam, suspicious score ranges and exact-duration
 * matches — but it detects them at RENDER TIME. flag_service::evaluate() runs
 * inside exam_attempts_table::col_flags(), so a match exists only for as long as
 * a staff member is looking at that page. The flag DEFINITIONS are stored
 * (local_completionhistory_flag_def holds the admin-configured rules); the
 * MATCHES never were.
 *
 * The consequence was that proctoring flags could not become work. The SIS
 * console had no way to learn about them, so its dashboard either omitted them or
 * — as it did before SIS-69 — invented them from fixtures, which on a work queue
 * is worse than showing nothing because somebody might act on it. This function
 * lets the SIS open exam_review cases from the real signal.
 *
 * DESIGN NOTES.
 *
 * Evaluation is not duplicated here. This calls the same flag_service the UI
 * calls, so the API and the screen can never disagree about what is flagged — a
 * second implementation would drift and the drift would be invisible.
 *
 * The row must carry EVERY field flag_service::matches() reads, or a flag type
 * silently stops matching rather than erroring: duration (fast_completion,
 * duration_exact), grade_decimal (score_range), timetaken plus user_timecreated
 * (new_account), and firstname/lastname/email (duplicate_account). Leaving out
 * user_timecreated in particular yields a function that appears to work and
 * simply never reports one of the five flag types.
 *
 * Paging is by `since` on timetaken rather than by offset. An offset walk over a
 * table that is still being appended to skips rows, and skipping a flagged exam
 * attempt is the one outcome this must not have.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_flagged_attempts extends external_api {

    /** Hard ceiling on rows per call, whatever the caller asks for. */
    private const MAX_LIMIT = 500;

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'since' => new external_value(PARAM_INT,
                'Return attempts taken at or after this Unix timestamp (0 for all)', VALUE_DEFAULT, 0),
            'limit' => new external_value(PARAM_INT,
                'Maximum attempts to scan (capped at 500)', VALUE_DEFAULT, 200),
            'onlyflagged' => new external_value(PARAM_BOOL,
                'When true (default) omit attempts with no matching flag', VALUE_DEFAULT, true),
        ]);
    }

    /**
     * @param int  $since       Unix timestamp lower bound on timetaken.
     * @param int  $limit       Maximum attempts to scan.
     * @param bool $onlyflagged Omit unflagged attempts.
     * @return array
     */
    public static function execute(int $since = 0, int $limit = 200, bool $onlyflagged = true): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'since'       => $since,
            'limit'       => $limit,
            'onlyflagged' => $onlyflagged,
        ]);

        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);
        // Same capability as get_recent_achievements: this is cross-user data and
        // carries names and email addresses.
        require_capability('local/completionhistory:viewall', $systemcontext);

        $since = max(0, (int) $params['since']);
        $limit = max(1, min(self::MAX_LIMIT, (int) $params['limit']));

        // Every field flag_service::matches() reads must be present — see the note
        // in the class docblock. u.timecreated is aliased to user_timecreated
        // because that is the property name the evaluator looks for.
        $sql = "SELECT ea.id,
                       ea.userid,
                       ea.courseid,
                       ea.quizid,
                       ea.exam_track,
                       ea.attempt_number,
                       ea.attempts_allowed,
                       ea.grade_decimal,
                       ea.grade_passed,
                       ea.resulted_in_completion,
                       ea.achievementid,
                       ea.timetaken,
                       ea.duration,
                       u.firstname   AS user_firstname,
                       u.lastname    AS user_lastname,
                       u.email       AS user_email,
                       u.idnumber    AS user_idnumber,
                       u.timecreated AS user_timecreated,
                       c.shortname   AS course_shortname,
                       c.fullname    AS course_fullname,
                       c.idnumber    AS course_idnumber
                  FROM {local_completionhistory_exam_attempt} ea
             LEFT JOIN {user} u   ON u.id = ea.userid
             LEFT JOIN {course} c ON c.id = ea.courseid
                 WHERE ea.timetaken >= :since
              ORDER BY ea.timetaken ASC, ea.id ASC";

        $rows = $DB->get_records_sql($sql, ['since' => $since], 0, $limit);

        // The duplicate-account check memoises per name key across a request; reset
        // so one call cannot inherit a cached answer from an earlier page.
        flag_service::reset_cache();

        $out      = [];
        $scanned  = 0;
        $maxseen  = $since;

        foreach ($rows as $row) {
            $scanned++;
            $maxseen = max($maxseen, (int) $row->timetaken);

            $matches = flag_service::evaluate($row);
            if ($onlyflagged && empty($matches)) {
                continue;
            }

            $flags = [];
            foreach ($matches as $def) {
                $flags[] = [
                    'id'          => (int) $def->id,
                    'name'        => (string) $def->name,
                    'flag_type'   => (string) $def->flag_type,
                    'severity'    => (string) $def->severity,
                    'description' => (string) ($def->description ?? ''),
                ];
            }

            $out[] = [
                'attemptid'              => (int) $row->id,
                'userid'                 => (int) $row->userid,
                'user_firstname'         => (string) ($row->user_firstname ?? ''),
                'user_lastname'          => (string) ($row->user_lastname ?? ''),
                'user_email'             => (string) ($row->user_email ?? ''),
                'user_idnumber'          => (string) ($row->user_idnumber ?? ''),
                'courseid'               => (int) $row->courseid,
                'course_shortname'       => (string) ($row->course_shortname ?? ''),
                'course_fullname'        => (string) ($row->course_fullname ?? ''),
                'course_idnumber'        => (string) ($row->course_idnumber ?? ''),
                'exam_track'             => (string) $row->exam_track,
                'attempt_number'         => (int) $row->attempt_number,
                'attempts_allowed'       => (int) $row->attempts_allowed,
                'grade_decimal'          => $row->grade_decimal === null ? null : (float) $row->grade_decimal,
                'grade_passed'           => $row->grade_passed === null ? null : (int) $row->grade_passed,
                'resulted_in_completion' => (int) $row->resulted_in_completion,
                'achievementid'          => $row->achievementid === null ? null : (int) $row->achievementid,
                'timetaken'              => (int) $row->timetaken,
                'duration'               => $row->duration === null ? null : (int) $row->duration,
                'flags'                  => $flags,
            ];
        }

        return [
            'scanned'   => $scanned,
            'flagged'   => count($out),
            // The caller passes this back as `since` next time. Reported even when
            // nothing was flagged, so a sweep that finds nothing still advances.
            'max_timetaken' => $maxseen,
            // True when the scan filled its page, so the caller knows to continue
            // rather than assuming it has seen everything.
            'truncated' => $scanned >= $limit,
            'attempts'  => $out,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'scanned'       => new external_value(PARAM_INT, 'Attempts examined'),
            'flagged'       => new external_value(PARAM_INT, 'Attempts returned with at least one flag'),
            'max_timetaken' => new external_value(PARAM_INT, 'Highest timetaken seen; pass back as `since`'),
            'truncated'     => new external_value(PARAM_BOOL, 'True when more attempts remain beyond this page'),
            'attempts'      => new external_multiple_structure(
                new external_single_structure([
                    'attemptid'              => new external_value(PARAM_INT,   'Exam attempt row id'),
                    'userid'                 => new external_value(PARAM_INT,   'Moodle user id'),
                    'user_firstname'         => new external_value(PARAM_TEXT,  'First name'),
                    'user_lastname'          => new external_value(PARAM_TEXT,  'Last name'),
                    'user_email'             => new external_value(PARAM_TEXT,  'Email'),
                    'user_idnumber'          => new external_value(PARAM_TEXT,  'User idnumber'),
                    'courseid'               => new external_value(PARAM_INT,   'Course id'),
                    'course_shortname'       => new external_value(PARAM_TEXT,  'Course shortname'),
                    'course_fullname'        => new external_value(PARAM_TEXT,  'Course fullname'),
                    'course_idnumber'        => new external_value(PARAM_TEXT,  'Course idnumber'),
                    'exam_track'             => new external_value(PARAM_TEXT,  'program_final | direct_credit | certificate'),
                    'attempt_number'         => new external_value(PARAM_INT,   'Attempt number within the track'),
                    'attempts_allowed'       => new external_value(PARAM_INT,   'Attempts allowed (0 = unlimited)'),
                    'grade_decimal'          => new external_value(PARAM_FLOAT, 'Grade', VALUE_OPTIONAL, null, NULL_ALLOWED),
                    'grade_passed'           => new external_value(PARAM_INT,   '1 passed, 0 failed, null no threshold', VALUE_OPTIONAL, null, NULL_ALLOWED),
                    'resulted_in_completion' => new external_value(PARAM_INT,   '1 if this attempt completed the course'),
                    'achievementid'          => new external_value(PARAM_INT,   'Achievement id if any', VALUE_OPTIONAL, null, NULL_ALLOWED),
                    'timetaken'              => new external_value(PARAM_INT,   'When the attempt was submitted'),
                    'duration'               => new external_value(PARAM_INT,   'Attempt duration in seconds', VALUE_OPTIONAL, null, NULL_ALLOWED),
                    'flags'                  => new external_multiple_structure(
                        new external_single_structure([
                            'id'          => new external_value(PARAM_INT,  'Flag definition id'),
                            'name'        => new external_value(PARAM_TEXT, 'Flag name as configured'),
                            'flag_type'   => new external_value(PARAM_TEXT, 'fast_completion | duration_exact | score_range | duplicate_account | new_account'),
                            'severity'    => new external_value(PARAM_TEXT, 'Configured severity'),
                            'description' => new external_value(PARAM_TEXT, 'Admin description'),
                        ]),
                        'Matching flag definitions'
                    ),
                ]),
                'Flagged exam attempts, oldest first'
            ),
        ]);
    }
}
