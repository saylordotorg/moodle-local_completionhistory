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
 * External function returning login and last-access timestamps for learners.
 *
 * WHY THIS EXISTS. "Last date of login" and "last date of academic activity" are
 * two different questions, and the SIS could only answer the second. It derives
 * academic activity from the completion ledger, which is authoritative — but a
 * student can log in for weeks without completing anything, and a student can have
 * an old completion date while being active daily. Using completions as a proxy for
 * engagement therefore misreports both directions, and the SIS console's "gone
 * quiet" queue was built on the proxy because nothing better existed.
 *
 * The data lives in Moodle: `user.lastaccess` (site-wide), `user.lastlogin` /
 * `currentlogin`, `user.firstaccess`, and `user_lastaccess.timeaccess` per course.
 * `core_user_get_users` would expose it, but the SIS token is scoped to the
 * `completionhistory_sis` service and is deliberately not authorised for core user
 * reads — a token that can enumerate core user records can do considerably more
 * than this job needs. So this wrapper returns exactly the timestamps, and nothing
 * else about the user.
 *
 * DELIBERATELY NOT RETURNED: name, email, city, country, roles, preferences,
 * profile fields. The SIS already holds identity from provisioning and resolves it
 * on `moodle_userid`. Returning identity here would duplicate a system of record
 * for no benefit and widen what a leaked token exposes.
 *
 * PAGING IS ASCENDING, AND THAT IS THE WHOLE POINT. The first version sorted
 * `lastaccess DESC` and returned the highest timestamp as the continuation bound.
 * That cannot work: page one holds the NEWEST users, so passing its maximum back as
 * a lower bound filters out every older user and re-emits only those at the newest
 * timestamp. The sweep either stops immediately or loops on the same rows, and the
 * users it was meant to find are never returned. This is the same defect the
 * sibling get_flagged_attempts shipped and had corrected; ordering by recency reads
 * nicer and is simply incompatible with a resumable cursor.
 *
 * So: ascending `(lastaccess, id)` with a strictly lexicographic keyset predicate,
 * matching the ORDER BY exactly. `lastaccess` is not unique — thousands of users can
 * share a timestamp, and a whole page of them easily can — so the id is part of the
 * cursor rather than a tiebreak afterthought.
 *
 * Users who have NEVER accessed the site (`lastaccess = 0`) are included from the
 * origin cursor rather than filtered. "Enrolled but never signed in" is exactly the
 * engagement signal this function exists to surface, and excluding them silently
 * would hide the worst case.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_user_activity extends external_api {

    /** Hard ceiling on users per call, whatever the caller asks for. */
    private const MAX_LIMIT = 1000;

    /** Maximum per-course access rows included in one response. */
    private const MAX_COURSE_ROWS = 10000;

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Moodle user id'),
                'Restrict to these users; empty means all confirmed, undeleted users',
                VALUE_DEFAULT, []
            ),
            'since' => new external_value(PARAM_INT,
                'Return users whose lastaccess is AFTER this timestamp (0 for all). Pass back next_since.',
                VALUE_DEFAULT, 0),
            'since_id' => new external_value(PARAM_INT,
                'Tie-break within `since`: include users at that timestamp only if id is greater. Pass back next_since_id.',
                VALUE_DEFAULT, 0),
            'limit' => new external_value(PARAM_INT,
                'Maximum users to return (capped at 1000)', VALUE_DEFAULT, 500),
            'includecourses' => new external_value(PARAM_BOOL,
                'Include per-course last access (heavier; off by default)', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Advance the paging cursor past a page of rows.
     *
     * Pure and static so the ordering bug this replaces is testable without a Moodle
     * bootstrap — see tests/static/check_cursor_advance.php. Rows arrive ordered by
     * (lastaccess, id); the cursor is the LAST row's pair, because a timestamp alone
     * cannot separate rows that share it.
     *
     * @param array $rows Rows in query order, each with ->id and ->lastaccess.
     * @param int $sincets Current cursor timestamp, returned unchanged when empty.
     * @param int $sinceid Current cursor id, returned unchanged when empty.
     * @return array{0:int,1:int} [next_since, next_since_id]
     */
    public static function next_cursor(array $rows, int $sincets, int $sinceid): array {
        if (empty($rows)) {
            return [$sincets, $sinceid];
        }
        $last = end($rows);
        return [(int) $last->lastaccess, (int) $last->id];
    }

    /**
     * @param array $userids        Restrict to these user ids.
     * @param int   $since          Exclusive lower bound on lastaccess.
     * @param int   $sinceid        Tie-break id within $since.
     * @param int   $limit          Maximum users.
     * @param bool  $includecourses Include per-course last access.
     * @return array
     */
    public static function execute(array $userids = [], int $since = 0, int $sinceid = 0,
            int $limit = 500, bool $includecourses = false): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'userids'        => $userids,
            'since'          => $since,
            'since_id'       => $sinceid,
            'limit'          => $limit,
            'includecourses' => $includecourses,
        ]);

        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);
        \local_completionhistory\local\security::require_enabled();
        require_capability('local/completionhistory:integrate', $systemcontext);

        $since   = max(0, (int) $params['since']);
        $sinceid = max(0, (int) $params['since_id']);
        $limit   = max(1, min(self::MAX_LIMIT, (int) $params['limit']));
        $ids = array_slice(
            array_values(array_unique(array_filter(array_map('intval', $params['userids'])))),
            0,
            self::MAX_LIMIT
        );

        // Guests, deleted accounts and UNCONFIRMED accounts are not learners.
        // Unconfirmed matters: abandoned self-registrations would otherwise enter the
        // SIS activity feed as real people who have simply never been active, which is
        // indistinguishable from an enrolled student who never signed in — the one
        // signal this function exists to surface.
        $where = ['u.deleted = 0', 'u.confirmed = 1', 'u.username <> :guest'];
        $sqlparams = ['guest' => 'guest'];

        if ($ids) {
            [$insql, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'uid');
            $where[] = "u.id {$insql}";
            $sqlparams += $inparams;
        }

        // Keyset cursor, strictly lexicographic, matching the ORDER BY exactly. From
        // the origin (0, 0) this admits every user including those with lastaccess = 0.
        $where[] = '(u.lastaccess > :since OR (u.lastaccess = :sincets AND u.id > :sinceid))';
        $sqlparams['since']   = $since;
        $sqlparams['sincets'] = $since;
        $sqlparams['sinceid'] = $sinceid;

        $users = $DB->get_records_sql(
            'SELECT u.id, u.firstaccess, u.lastaccess, u.lastlogin, u.currentlogin, u.suspended
               FROM {user} u
              WHERE ' . implode(' AND ', $where) . '
           ORDER BY u.lastaccess ASC, u.id ASC',
            $sqlparams,
            0,
            $limit
        );

        $percourse = [];
        if ($params['includecourses'] && $users) {
            [$insql, $inparams] = $DB->get_in_or_equal(array_keys($users), SQL_PARAMS_NAMED, 'lu');
            $rows = $DB->get_records_sql(
                "SELECT ul.id, ul.userid, ul.courseid, ul.timeaccess
                   FROM {user_lastaccess} ul
                  WHERE ul.userid {$insql}
               ORDER BY ul.timeaccess DESC",
                $inparams,
                0,
                self::MAX_COURSE_ROWS + 1
            );
            if (count($rows) > self::MAX_COURSE_ROWS) {
                throw new \moodle_exception('activitydetailtoolarge', 'local_completionhistory');
            }
            foreach ($rows as $r) {
                $percourse[(int) $r->userid][] = [
                    'courseid'   => (int) $r->courseid,
                    'timeaccess' => (int) $r->timeaccess,
                ];
            }
        }

        $out = [];
        foreach ($users as $u) {
            $out[] = [
                'userid'       => (int) $u->id,
                'firstaccess'  => (int) ($u->firstaccess ?? 0),
                // 0 means never — the caller must distinguish that from "long ago".
                'lastaccess'   => (int) ($u->lastaccess ?? 0),
                'lastlogin'    => (int) ($u->lastlogin ?? 0),
                'currentlogin' => (int) ($u->currentlogin ?? 0),
                'suspended'    => (int) ($u->suspended ?? 0),
                'courses'      => $percourse[(int) $u->id] ?? [],
            ];
        }

        // Advances past every row SCANNED, so a page that yields nothing useful still
        // moves forward instead of being re-read on the next call.
        [$nextsince, $nextsinceid] = self::next_cursor($users, $since, $sinceid);

        return [
            'users'         => $out,
            'count'         => count($out),
            'next_since'    => $nextsince,
            'next_since_id' => $nextsinceid,
            'truncated'     => count($out) >= $limit,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'users' => new external_multiple_structure(
                new external_single_structure([
                    'userid'       => new external_value(PARAM_INT, 'Moodle user id'),
                    'firstaccess'  => new external_value(PARAM_INT, 'First site access, 0 if never'),
                    'lastaccess'   => new external_value(PARAM_INT, 'Last site access, 0 if never — NOT the same as academic activity'),
                    'lastlogin'    => new external_value(PARAM_INT, 'Previous login, 0 if never'),
                    'currentlogin' => new external_value(PARAM_INT, 'Most recent login, 0 if never'),
                    'suspended'    => new external_value(PARAM_INT, '1 if the Moodle account is suspended'),
                    'courses'      => new external_multiple_structure(
                        new external_single_structure([
                            'courseid'   => new external_value(PARAM_INT, 'Course id'),
                            'timeaccess' => new external_value(PARAM_INT, 'Last access to that course'),
                        ]),
                        'Per-course last access; empty unless includecourses was set',
                        VALUE_OPTIONAL
                    ),
                ]),
                'Users, LEAST recently active first — ascending, so the cursor is resumable'
            ),
            'count'         => new external_value(PARAM_INT,  'Users returned'),
            'next_since'    => new external_value(PARAM_INT,  'Pass back as `since` on the next call'),
            'next_since_id' => new external_value(PARAM_INT,  'Pass back as `since_id` on the next call'),
            'truncated'     => new external_value(PARAM_BOOL, 'True when more users remain beyond this page'),
        ]);
    }
}
