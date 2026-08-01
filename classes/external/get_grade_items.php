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
 * External function: per-grade-item grades, for SIS grade ingestion (SIS-42).
 *
 * WHY THIS EXISTS RATHER THAN THE CORE FUNCTION. SIS-42 asks for grades via
 * `gradereport_user_get_grade_items`. That function is core, and the SIS web-service token
 * is scoped to this plugin's own service, so calling it returns accessexception — the same
 * wall the course catalog hit for weeks while its sync reported success. This exposes the
 * same information over an authorised path, shaped for a resumable sweep rather than for a
 * report screen.
 *
 * THE CURSOR CANNOT BE `timemodified` ALONE, and this is the whole reason the design looks
 * the way it does. Moodle leaves `grade_grades.timemodified` NULL on a large share of rows
 * — on the dev site, 834 of 3,170, with `timecreated` also NULL on 2,025 and BOTH null on
 * 834. Paging on `timemodified` would silently mis-order or skip a quarter of the data. So
 * the sort key is `coalesce(timemodified, timecreated, 0)`, exposed as `changed_at` so the
 * SIS stores exactly the value it cursors on.
 *
 * That leaves every both-null row sharing changed_at = 0 in one block at the front of the
 * ordering, drained once by id — the same shape as the never-accessed block in
 * get_user_activity. A row that is later regraded gets a real timemodified and re-enters
 * the feed naturally, which is how "handle regrades/updates" is satisfied. But a row
 * CREATED with a null timemodified after the cursor has left the zero block would be
 * missed, so callers are expected to run a full re-read periodically. At this size that is
 * cheap: the whole table is a handful of pages.
 *
 * DELIBERATELY NOT RETURNED: `feedback`. It is instructor-authored free text on a
 * student's grade and can contain anything from a mark scheme to a personal remark. The
 * SIS needs the number and its provenance, not the commentary — the same reason
 * get_flagged_attempts returns flag TYPES rather than admin-authored flag names.
 *
 * ALSO NOT RETURNED: names or emails. Identity stays with provisioning, and the SIS
 * resolves on userid, exactly as get_user_activity does.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_grade_items extends external_api {

    /** Hard ceiling on rows per call, whatever the caller asks for. */
    private const MAX_LIMIT = 1000;

    /**
     * Advance the paging cursor past a page of rows.
     *
     * Pure and static so the ordering can be tested without a Moodle bootstrap. Rows
     * arrive ordered by (changed_at, id); the cursor is the LAST row's pair, because a
     * timestamp alone cannot separate rows that share it — and here a quarter of them
     * share the value 0.
     *
     * @param array $rows Rows in query order, each with ->id and ->changed_at.
     * @param int $sincets Current cursor timestamp, returned unchanged when empty.
     * @param int $sinceid Current cursor id, returned unchanged when empty.
     * @return array{0:int,1:int} [next_since, next_since_id]
     */
    public static function next_cursor(array $rows, int $sincets, int $sinceid): array {
        if (empty($rows)) {
            return [$sincets, $sinceid];
        }
        $last = end($rows);
        return [(int) $last->changed_at, (int) $last->id];
    }

    /**
     * Normalise a grade to a percentage of its item's maximum.
     *
     * Null when there is no grade, or when the item has no positive maximum — a
     * percentage of zero is not zero percent, it is undefined, and returning 0 would put
     * an invented failure on an academic record.
     *
     * @param float|null $finalgrade
     * @param float|null $grademax
     * @param float|null $grademin
     * @return float|null
     */
    public static function percentage_of(?float $finalgrade, ?float $grademax, ?float $grademin): ?float {
        if ($finalgrade === null || $grademax === null) {
            return null;
        }
        $min = $grademin ?? 0.0;
        $span = (float) $grademax - (float) $min;
        if ($span <= 0) {
            return null;
        }
        // Rounded to 5 places, matching how exam_attempt_service stores its grades. Without
        // it a 9.79/10 arrives as 97.89999999999999, which is not wrong but invites a
        // reader to think the SIS mangled it.
        return round(((((float) $finalgrade - $min) / $span) * 100.0), 5);
    }

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'since' => new external_value(PARAM_INT,
                'Return grades changed AFTER this timestamp (0 for all). Pass back next_since.',
                VALUE_DEFAULT, 0),
            'since_id' => new external_value(PARAM_INT,
                'Tie-break within `since`: include grades at that timestamp only if id is greater. '
                . 'Load-bearing here, because a large share of rows share changed_at = 0.',
                VALUE_DEFAULT, 0),
            'limit' => new external_value(PARAM_INT,
                'Maximum rows to return (capped at 1000)', VALUE_DEFAULT, 500),
            'onlygraded' => new external_value(PARAM_BOOL,
                'Only rows with a final grade. On by default: an ungraded row is not a grade, '
                . 'and including them roughly doubles the feed.', VALUE_DEFAULT, true),
            'includehidden' => new external_value(PARAM_BOOL,
                'Include grades hidden from the student. Off by default.', VALUE_DEFAULT, false),
            'itemtypes' => new external_multiple_structure(
                new external_value(PARAM_ALPHA, 'grade_items.itemtype'),
                'Restrict to these item types (course, mod, manual, category). Empty means all.',
                VALUE_DEFAULT, []
            ),
            'courseids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Moodle course id'),
                'Restrict to these courses. Empty means all.', VALUE_DEFAULT, []
            ),
        ]);
    }

    /**
     * @param int   $since         Exclusive lower bound on changed_at.
     * @param int   $sinceid       Tie-break id within $since.
     * @param int   $limit         Maximum rows.
     * @param bool  $onlygraded    Only rows with a final grade.
     * @param bool  $includehidden Include grades hidden from the student.
     * @param array $itemtypes     Restrict to these grade_items.itemtype values.
     * @param array $courseids     Restrict to these courses.
     * @return array
     */
    public static function execute(int $since = 0, int $sinceid = 0, int $limit = 500,
            bool $onlygraded = true, bool $includehidden = false,
            array $itemtypes = [], array $courseids = []): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'since'         => $since,
            'since_id'      => $sinceid,
            'limit'         => $limit,
            'onlygraded'    => $onlygraded,
            'includehidden' => $includehidden,
            'itemtypes'     => $itemtypes,
            'courseids'     => $courseids,
        ]);

        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);
        require_capability('local/completionhistory:viewall', $systemcontext);

        $since   = max(0, (int) $params['since']);
        $sinceid = max(0, (int) $params['since_id']);
        $limit   = max(1, min(self::MAX_LIMIT, (int) $params['limit']));

        // The effective change time. See the class docblock: timemodified is NULL on a
        // large share of rows, so it cannot be the sort key on its own.
        $changed = 'COALESCE(gg.timemodified, gg.timecreated, 0)';

        $where = ['u.deleted = 0'];
        $sqlparams = [];

        if ($params['onlygraded']) {
            $where[] = 'gg.finalgrade IS NOT NULL';
        }
        if (!$params['includehidden']) {
            // Both the item and the individual grade can be hidden; either hides it.
            $where[] = 'gg.hidden = 0';
            $where[] = 'gi.hidden = 0';
        }

        $types = array_values(array_unique(array_filter(array_map('strval', $params['itemtypes']))));
        if ($types) {
            [$insql, $inparams] = $DB->get_in_or_equal($types, SQL_PARAMS_NAMED, 'it');
            $where[] = "gi.itemtype {$insql}";
            $sqlparams += $inparams;
        }

        $cids = array_values(array_unique(array_map('intval', $params['courseids'])));
        if ($cids) {
            [$insql, $inparams] = $DB->get_in_or_equal($cids, SQL_PARAMS_NAMED, 'cid');
            $where[] = "gi.courseid {$insql}";
            $sqlparams += $inparams;
        }

        // Keyset cursor, strictly lexicographic, matching the ORDER BY exactly.
        $where[] = "({$changed} > :since OR ({$changed} = :sincets AND gg.id > :sinceid))";
        $sqlparams['since']   = $since;
        $sqlparams['sincets'] = $since;
        $sqlparams['sinceid'] = $sinceid;

        $sql = "SELECT gg.id, gg.itemid, gg.userid, gg.rawgrade, gg.finalgrade,
                       gg.overridden, gg.excluded, gg.hidden AS gradehidden, gg.locked AS gradelocked,
                       gg.timecreated, gg.timemodified,
                       {$changed} AS changed_at,
                       gi.courseid, gi.itemtype, gi.itemmodule, gi.iteminstance, gi.itemnumber,
                       gi.itemname, gi.grademax, gi.grademin, gi.gradepass,
                       c.idnumber AS course_idnumber, c.shortname AS course_shortname
                  FROM {grade_grades} gg
                  JOIN {grade_items} gi ON gi.id = gg.itemid
                  JOIN {course} c ON c.id = gi.courseid
                  JOIN {user} u ON u.id = gg.userid
                 WHERE " . implode(' AND ', $where) . "
              ORDER BY {$changed} ASC, gg.id ASC";

        // get_recordSET, not get_records_sql: the latter keys its array by the first
        // selected column, and although gg.id is unique here, a later edit to the select
        // list would silently collapse rows. This project has been bitten by that four
        // times; the recordset form cannot be broken that way.
        $rs = $DB->get_recordset_sql($sql, $sqlparams, 0, $limit);
        $rows = [];
        foreach ($rs as $r) {
            $rows[] = $r;
        }
        $rs->close();

        $out = [];
        foreach ($rows as $r) {
            $finalgrade = $r->finalgrade === null ? null : (float) $r->finalgrade;
            $grademax   = $r->grademax === null ? null : (float) $r->grademax;
            $grademin   = $r->grademin === null ? null : (float) $r->grademin;
            $gradepass  = $r->gradepass === null ? null : (float) $r->gradepass;

            // Pass/fail only where the item actually carries a threshold. Null is a third
            // state — "no pass mark set" — and collapsing it to a fail would invent one.
            $passed = null;
            if ($finalgrade !== null && $gradepass !== null && $gradepass > 0) {
                $passed = $finalgrade >= $gradepass;
            }

            $out[] = [
                // grade_grades.id: the idempotency key. One SIS row per grade, however
                // often the sweep runs, and a regrade updates in place.
                'gradeid'          => (int) $r->id,
                'itemid'           => (int) $r->itemid,
                'userid'           => (int) $r->userid,
                'courseid'         => (int) $r->courseid,
                'course_idnumber'  => (string) ($r->course_idnumber ?? ''),
                'course_shortname' => (string) ($r->course_shortname ?? ''),
                'itemtype'         => (string) $r->itemtype,
                'itemmodule'       => (string) ($r->itemmodule ?? ''),
                'iteminstance'     => (int) ($r->iteminstance ?? 0),
                'itemnumber'       => (int) ($r->itemnumber ?? 0),
                'itemname'         => (string) ($r->itemname ?? ''),
                'grademax'         => $grademax,
                'grademin'         => $grademin,
                'gradepass'        => $gradepass,
                'rawgrade'         => $r->rawgrade === null ? null : (float) $r->rawgrade,
                'finalgrade'       => $finalgrade,
                'percentage'       => self::percentage_of($finalgrade, $grademax, $grademin),
                'passed'           => $passed,
                // Provenance. `overridden` is a TIMESTAMP in Moodle, not a flag: nonzero
                // means a human replaced the calculated grade, which is exactly the kind
                // of thing a registrar gets asked about later.
                'overridden'       => ((int) $r->overridden) !== 0,
                'overridden_at'    => (int) $r->overridden,
                'excluded'         => ((int) $r->excluded) !== 0,
                'hidden'           => ((int) $r->gradehidden) !== 0,
                'locked'           => ((int) $r->gradelocked) !== 0,
                'timecreated'      => (int) ($r->timecreated ?? 0),
                'timemodified'     => (int) ($r->timemodified ?? 0),
                // The value the cursor is built from. Returned so the SIS stores the same
                // number it pages on rather than deriving its own and drifting.
                'changed_at'       => (int) $r->changed_at,
            ];
        }

        [$nextsince, $nextsinceid] = self::next_cursor($rows, $since, $sinceid);

        return [
            'grades'        => $out,
            'count'         => count($out),
            'next_since'    => $nextsince,
            'next_since_id' => $nextsinceid,
            // The page filled, so there may be more. A caller drains a backlog by calling
            // again while this is true.
            'truncated'     => count($out) >= $limit,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'grades' => new external_multiple_structure(new external_single_structure([
                'gradeid'          => new external_value(PARAM_INT, 'grade_grades.id — the idempotency key'),
                'itemid'           => new external_value(PARAM_INT, 'grade_items.id'),
                'userid'           => new external_value(PARAM_INT, 'Moodle user id'),
                'courseid'         => new external_value(PARAM_INT, 'Moodle course id'),
                'course_idnumber'  => new external_value(PARAM_RAW, 'Course idnumber (the SIS course key; may be empty)'),
                'course_shortname' => new external_value(PARAM_RAW, 'Course short name'),
                'itemtype'         => new external_value(PARAM_ALPHA, 'course | mod | manual | category'),
                'itemmodule'       => new external_value(PARAM_RAW, 'Activity module for itemtype=mod (e.g. quiz)'),
                'iteminstance'     => new external_value(PARAM_INT, 'Module instance id'),
                'itemnumber'       => new external_value(PARAM_INT, 'Item number within the module'),
                'itemname'         => new external_value(PARAM_RAW, 'Item name; empty for course and category totals'),
                'grademax'         => new external_value(PARAM_FLOAT, 'Item maximum', VALUE_OPTIONAL),
                'grademin'         => new external_value(PARAM_FLOAT, 'Item minimum', VALUE_OPTIONAL),
                'gradepass'        => new external_value(PARAM_FLOAT, 'Pass threshold; 0 or null means none set', VALUE_OPTIONAL),
                'rawgrade'         => new external_value(PARAM_FLOAT, 'Raw grade before adjustment', VALUE_OPTIONAL),
                'finalgrade'       => new external_value(PARAM_FLOAT, 'Authoritative grade', VALUE_OPTIONAL),
                'percentage'       => new external_value(PARAM_FLOAT,
                    'finalgrade as a percentage of the item span; null when undefined', VALUE_OPTIONAL),
                'passed'           => new external_value(PARAM_BOOL,
                    'Null when the item has no pass threshold — never read null as a fail', VALUE_OPTIONAL),
                'overridden'       => new external_value(PARAM_BOOL, 'A human replaced the calculated grade'),
                'overridden_at'    => new external_value(PARAM_INT, 'When it was overridden (0 = never)'),
                'excluded'         => new external_value(PARAM_BOOL, 'Excluded from aggregation'),
                'hidden'           => new external_value(PARAM_BOOL, 'Hidden from the student'),
                'locked'           => new external_value(PARAM_BOOL, 'Locked against recalculation'),
                'timecreated'      => new external_value(PARAM_INT, 'grade_grades.timecreated (0 when null)'),
                'timemodified'     => new external_value(PARAM_INT, 'grade_grades.timemodified (0 when null)'),
                'changed_at'       => new external_value(PARAM_INT,
                    'coalesce(timemodified, timecreated, 0) — the value the cursor pages on'),
            ])),
            'count'         => new external_value(PARAM_INT, 'Rows returned'),
            'next_since'    => new external_value(PARAM_INT, 'Pass as `since` on the next call'),
            'next_since_id' => new external_value(PARAM_INT, 'Pass as `since_id` on the next call'),
            'truncated'     => new external_value(PARAM_BOOL, 'The page filled; call again for more'),
        ]);
    }
}
