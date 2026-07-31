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
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_user_activity extends external_api {

    /** Hard ceiling on users per call, whatever the caller asks for. */
    private const MAX_LIMIT = 1000;

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Moodle user id'),
                'Restrict to these users; empty means all confirmed, undeleted users',
                VALUE_DEFAULT, []
            ),
            'since' => new external_value(PARAM_INT,
                'Only users whose lastaccess is at or after this timestamp (0 for all)', VALUE_DEFAULT, 0),
            'limit' => new external_value(PARAM_INT,
                'Maximum users to return (capped at 1000)', VALUE_DEFAULT, 500),
            'includecourses' => new external_value(PARAM_BOOL,
                'Include per-course last access (heavier; off by default)', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * @param array $userids        Restrict to these user ids.
     * @param int   $since          Lower bound on lastaccess.
     * @param int   $limit          Maximum users.
     * @param bool  $includecourses Include per-course last access.
     * @return array
     */
    public static function execute(array $userids = [], int $since = 0, int $limit = 500,
            bool $includecourses = false): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'userids'        => $userids,
            'since'          => $since,
            'limit'          => $limit,
            'includecourses' => $includecourses,
        ]);

        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);
        require_capability('local/completionhistory:viewall', $systemcontext);

        $since = max(0, (int) $params['since']);
        $limit = max(1, min(self::MAX_LIMIT, (int) $params['limit']));
        $ids   = array_values(array_unique(array_map('intval', $params['userids'])));

        // Guests and deleted users are not learners and would only add noise.
        $where  = ['u.deleted = 0', 'u.username <> :guest'];
        $sqlparams = ['guest' => 'guest'];

        if ($ids) {
            [$insql, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'uid');
            $where[] = "u.id {$insql}";
            $sqlparams += $inparams;
        }
        if ($since > 0) {
            // Strictly at-or-after, and only users who have ever accessed: a
            // lastaccess of 0 means "never signed in", which is information, but not
            // information that belongs in a "changed since" page.
            $where[] = 'u.lastaccess >= :since AND u.lastaccess > 0';
            $sqlparams['since'] = $since;
        }

        $users = $DB->get_records_sql(
            'SELECT u.id, u.firstaccess, u.lastaccess, u.lastlogin, u.currentlogin, u.suspended
               FROM {user} u
              WHERE ' . implode(' AND ', $where) . '
           ORDER BY u.lastaccess DESC, u.id ASC',
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
                $inparams
            );
            foreach ($rows as $r) {
                $percourse[(int) $r->userid][] = [
                    'courseid'   => (int) $r->courseid,
                    'timeaccess' => (int) $r->timeaccess,
                ];
            }
        }

        $out = [];
        $maxseen = $since;
        foreach ($users as $u) {
            $maxseen = max($maxseen, (int) $u->lastaccess);
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

        return [
            'users'          => $out,
            'count'          => count($out),
            'max_lastaccess' => $maxseen,
            'truncated'      => count($out) >= $limit,
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
                'Users, most recently active first'
            ),
            'count'          => new external_value(PARAM_INT,  'Users returned'),
            'max_lastaccess' => new external_value(PARAM_INT,  'Highest lastaccess seen; pass back as `since`'),
            'truncated'      => new external_value(PARAM_BOOL, 'True when more users remain beyond this page'),
        ]);
    }
}
