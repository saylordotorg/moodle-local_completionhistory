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
 * External function: how far each learner is through each course, as Moodle itself measures it (SIS-246).
 *
 * WHY THIS EXISTS. The SIS sends a mid-course survey once a student passes half of a course. Nothing
 * it ingested could say "half": the completion ledger records finished courses, the in-progress call
 * answers yes or no, and the gradebook feed has a row only for items already graded, with no count of
 * how many items a course has. Moodle's own course progress — the percentage on the student's
 * dashboard — is the share of the course's completion-tracked activities the student has completed.
 * That is the measure Saylor chose ("activities completed"), so this returns exactly that number,
 * computed by core (`\core_completion\progress::get_course_progress_percentage`) rather than
 * re-derived here, so the SIS and the student's dashboard can never disagree.
 *
 * WHAT DRIVES THE FEED. A learner's percentage changes when one of their activity completion states
 * changes, which is a row in `course_modules_completion`. So the feed is one row per (learner, course)
 * that has completion rows, ordered by the newest of them (`changed_at`), with a keyset cursor on
 * (changed_at, userid, courseid). A learner who has completed nothing has no row and no progress,
 * which is the truth: zero is not halfway.
 *
 * ONLY SETTLED SECONDS ARE PAGED. A pair whose newest change is in the last SETTLE_SECONDS is held
 * back until the next call. Without that, a page ending at second T could be followed by another
 * learner's completion in that same second T with a lower user id, or by a completion committed late
 * with an earlier timestamp — both sort before the cursor and would never be returned.
 *
 * WHAT IT CANNOT SEE, so callers re-read periodically: a teacher ADDING or removing a tracked activity
 * changes every learner's percentage without touching their completion rows. A full re-read
 * (`since = 0`) picks that up; at the SIS's scale it is a few pages.
 *
 * `activities_tracked` IS RETURNED ON PURPOSE. Moodle's percentage is null when a course tracks no
 * activities, and a course whose activities carry no completion criteria is a configuration fact the
 * SIS should be able to report rather than mistake for a student who has done nothing. Only courses
 * with completion enabled are returned at all.
 *
 * NOT RETURNED: names, emails or which activities were completed. The SIS resolves on userid, as it
 * does for every other feed, and needs the measure, not the detail.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_course_progress extends external_api {
    /** Hard ceiling on rows per call. Each row builds the learner's course modinfo, so it stays modest. */
    private const MAX_LIMIT = 500;

    /** Changes newer than this many seconds are left for the next call (see the class docblock). */
    private const SETTLE_SECONDS = 120;

    /**
     * Advance the paging cursor past a page of rows.
     *
     * Pure and static so the ordering can be tested without a Moodle bootstrap. Rows arrive ordered by
     * (changed_at, userid, courseid); the cursor is the LAST row's triple, because many learners share
     * a second and one learner can move in several courses in the same second.
     *
     * @param array $rows Rows in query order, each with ->changed_at, ->userid and ->courseid.
     * @param int $sincets Current cursor timestamp, returned unchanged when empty.
     * @param int $sinceuserid Current cursor user id, returned unchanged when empty.
     * @param int $sincecourseid Current cursor course id, returned unchanged when empty.
     * @return array{0:int,1:int,2:int} [next_since, next_since_userid, next_since_courseid]
     */
    public static function next_cursor(array $rows, int $sincets, int $sinceuserid, int $sincecourseid): array {
        if (empty($rows)) {
            return [$sincets, $sinceuserid, $sincecourseid];
        }
        $last = end($rows);
        return [(int) $last->changed_at, (int) $last->userid, (int) $last->courseid];
    }

    /**
     * Normalise Moodle's percentage for the wire.
     *
     * Null stays null: core returns null when the course tracks no activities, and 0 would claim the
     * student has done nothing in a course that cannot measure it. Anything else is clamped to 0..100
     * and rounded to 2 places.
     *
     * @param float|int|null $percent What core returned.
     * @return float|null
     */
    public static function normalise_percent($percent): ?float {
        if ($percent === null || !is_numeric($percent)) {
            return null;
        }
        return round(max(0.0, min(100.0, (float) $percent)), 2);
    }

    /**
     * Describe the parameters accepted by execute().
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'since' => new external_value(
                PARAM_INT,
                'Return learner-course pairs whose newest completion change is AFTER this timestamp (0 for all). '
                . 'Pass back next_since.',
                VALUE_DEFAULT,
                0
            ),
            'since_userid' => new external_value(
                PARAM_INT,
                'Tie-break within since. Pass back next_since_userid.',
                VALUE_DEFAULT,
                0
            ),
            'since_courseid' => new external_value(
                PARAM_INT,
                'Tie-break within since and since_userid. Pass back next_since_courseid.',
                VALUE_DEFAULT,
                0
            ),
            'limit' => new external_value(
                PARAM_INT,
                'Maximum rows to return (capped at 500)',
                VALUE_DEFAULT,
                200
            ),
        ]);
    }

    /**
     * Return a page of per-learner, per-course progress after a keyset cursor.
     *
     * @param int $since         Exclusive lower bound on changed_at.
     * @param int $sinceuserid   Tie-break user id within $since.
     * @param int $sincecourseid Tie-break course id within ($since, $sinceuserid).
     * @param int $limit         Maximum rows.
     * @return array
     */
    public static function execute(int $since = 0, int $sinceuserid = 0, int $sincecourseid = 0, int $limit = 200): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'since'          => $since,
            'since_userid'   => $sinceuserid,
            'since_courseid' => $sincecourseid,
            'limit'          => $limit,
        ]);

        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);
        \local_completionhistory\local\security::require_enabled();
        require_capability('local/completionhistory:integrate', $systemcontext);
        $since         = max(0, (int) $params['since']);
        $sinceuserid   = max(0, (int) $params['since_userid']);
        $sincecourseid = max(0, (int) $params['since_courseid']);
        $limit         = max(1, min(self::MAX_LIMIT, (int) $params['limit']));

        // Site-wide completion off means no course can be measured; say so with an empty page rather
        // than a page of nulls.
        if (empty($CFG->enablecompletion)) {
            return [
                'progress'            => [],
                'count'               => 0,
                'next_since'          => $since,
                'next_since_userid'   => $sinceuserid,
                'next_since_courseid' => $sincecourseid,
                'truncated'           => false,
                'site_completion_enabled' => false,
            ];
        }

        // The inner filter (>= since) keeps the aggregate to recent rows. It is exact: a pair whose
        // newest change is at or after `since` has that newest row inside the filter, so its MAX is
        // unchanged, and a pair whose newest change is older cannot pass the outer cursor anyway.
        $sql = "SELECT p.userid, p.courseid, p.changed_at,
                       c.idnumber AS course_idnumber, c.shortname AS course_shortname
                  FROM (SELECT cmc.userid, cm.course AS courseid, MAX(cmc.timemodified) AS changed_at
                          FROM {course_modules_completion} cmc
                          JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                         WHERE cm.deletioninprogress = 0
                           AND cmc.timemodified >= :sinceinner
                      GROUP BY cmc.userid, cm.course) p
                  JOIN {course} c ON c.id = p.courseid
                  JOIN {user} u ON u.id = p.userid
                 WHERE c.enablecompletion = 1
                   AND u.deleted = 0
                   AND p.changed_at <= :settled
                   AND (p.changed_at > :since
                        OR (p.changed_at = :sincets
                            AND (p.userid > :sinceuserid
                                 OR (p.userid = :sinceuserid2 AND p.courseid > :sincecourseid))))
              ORDER BY p.changed_at ASC, p.userid ASC, p.courseid ASC";
        $sqlparams = [
            'sinceinner'    => $since,
            'settled'       => time() - self::SETTLE_SECONDS,
            'since'         => $since,
            'sincets'       => $since,
            'sinceuserid'   => $sinceuserid,
            'sinceuserid2'  => $sinceuserid,
            'sincecourseid' => $sincecourseid,
        ];

        // A recordset, for the reason get_grade_items gives: get_records_sql keys by the first column,
        // and userid repeats across courses here.
        $rs = $DB->get_recordset_sql($sql, $sqlparams, 0, $limit);
        $rows = [];
        foreach ($rs as $r) {
            $rows[] = $r;
        }
        $rs->close();

        $courses = [];
        $tracked = [];
        $out = [];
        foreach ($rows as $r) {
            $courseid = (int) $r->courseid;
            if (!isset($courses[$courseid])) {
                $courses[$courseid] = get_course($courseid);
                $tracked[$courseid] = count((new \completion_info($courses[$courseid]))->get_activities());
            }
            $percent = \core_completion\progress::get_course_progress_percentage($courses[$courseid], (int) $r->userid);
            $out[] = [
                'userid'             => (int) $r->userid,
                'courseid'           => $courseid,
                'course_idnumber'    => (string) ($r->course_idnumber ?? ''),
                'course_shortname'   => (string) ($r->course_shortname ?? ''),
                'progress'           => self::normalise_percent($percent === false ? null : $percent),
                'activities_tracked' => $tracked[$courseid],
                'changed_at'         => (int) $r->changed_at,
            ];
        }

        [$nextsince, $nextuserid, $nextcourseid] = self::next_cursor($rows, $since, $sinceuserid, $sincecourseid);

        return [
            'progress'            => $out,
            'count'               => count($out),
            'next_since'          => $nextsince,
            'next_since_userid'   => $nextuserid,
            'next_since_courseid' => $nextcourseid,
            // The page filled, so there may be more.
            'truncated'           => count($out) >= $limit,
            'site_completion_enabled' => true,
        ];
    }

    /**
     * Describe the structure execute() returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'progress' => new external_multiple_structure(new external_single_structure([
                'userid'             => new external_value(PARAM_INT, 'Moodle user id'),
                'courseid'           => new external_value(PARAM_INT, 'Moodle course id'),
                'course_idnumber'    => new external_value(PARAM_RAW, 'Course idnumber (the SIS course key; may be empty)'),
                'course_shortname'   => new external_value(PARAM_RAW, 'Course short name'),
                'progress'           => new external_value(
                    PARAM_FLOAT,
                    'Percentage of the course\'s tracked activities completed, as on the Moodle dashboard; '
                    . 'null when the course tracks no activities — never read null as 0',
                    VALUE_REQUIRED,
                    null,
                    NULL_ALLOWED
                ),
                'activities_tracked' => new external_value(PARAM_INT, 'Activities in the course with completion tracking'),
                'changed_at'         => new external_value(PARAM_INT, 'Newest completion change for this pair; the cursor value'),
            ])),
            'count'               => new external_value(PARAM_INT, 'Rows returned'),
            'next_since'          => new external_value(PARAM_INT, 'Pass as since on the next call'),
            'next_since_userid'   => new external_value(PARAM_INT, 'Pass as since_userid on the next call'),
            'next_since_courseid' => new external_value(PARAM_INT, 'Pass as since_courseid on the next call'),
            'truncated'           => new external_value(PARAM_BOOL, 'The page filled; call again for more'),
            'site_completion_enabled' => new external_value(PARAM_BOOL, 'Site-level completion tracking is on'),
        ]);
    }
}
