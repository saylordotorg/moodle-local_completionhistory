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

/**
 * Shared security checks for browser and integration entry points.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class security {
    /**
     * Enforce the plugin's master switch at every externally callable boundary.
     */
    public static function require_enabled(): void {
        if (!get_config('local_completionhistory', 'enabled')) {
            throw new \moodle_exception('plugindisabled', 'local_completionhistory');
        }
    }

    /**
     * Find exactly one undeleted local account by case-insensitive email.
     *
     * Moodle can be configured to allow duplicate email addresses. An integration
     * must never pick an arbitrary account when an email is therefore ambiguous.
     *
     * @param string $email Email address.
     * @return \stdClass|null The account, or null when there is no match.
     */
    public static function get_unique_local_user_by_email(string $email): ?\stdClass {
        global $CFG, $DB;

        $select = $DB->sql_equal('email', ':email', false)
            . ' AND deleted = 0 AND mnethostid = :mnethostid';
        $users = $DB->get_records_select(
            'user',
            $select,
            [
                'email' => \core_text::strtolower(trim($email)),
                'mnethostid' => $CFG->mnet_localhost_id,
            ],
            'id ASC',
            '*',
            0,
            2
        );

        if (count($users) > 1) {
            throw new \moodle_exception('ambiguousemail', 'local_completionhistory');
        }

        return $users ? reset($users) : null;
    }

    /**
     * Whether an account carries site-level administrative powers.
     *
     * @param int|\stdClass $user User id or record.
     * @return bool
     */
    public static function is_privileged_user(int|\stdClass $user): bool {
        $userid = is_object($user) ? (int) $user->id : $user;
        if ($userid <= 0 || is_siteadmin($userid)) {
            return true;
        }

        $systemcontext = \context_system::instance();
        $capabilities = [
            'moodle/site:config',
            'moodle/role:assign',
            'moodle/user:loginas',
            'moodle/user:create',
            'moodle/user:update',
            'moodle/user:delete',
            'moodle/course:create',
            'local/completionhistory:viewall',
            'local/completionhistory:manage',
            'local/completionhistory:managecoursemap',
            'local/completionhistory:runbackfill',
            'local/completionhistory:integrate',
            'local/completionhistory:provisionusers',
            'local/completionhistory:resetpasswords',
            'local/completionhistory:createloginkeys',
            'local/completionhistory:updateprofiles',
            'local/completionhistory:enrolusers',
            'local/completionhistory:setdeadlines',
        ];
        foreach ($capabilities as $capability) {
            if (has_capability($capability, $systemcontext, $userid)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an account is safe to enter through the learner SSO endpoint.
     *
     * In addition to site-level powers, refuse accounts with any explicit role
     * based on a non-learner archetype. This prevents a valid learner key from
     * becoming a teacher or manager session through a course-level assignment.
     *
     * @param int|\stdClass $user User id or record.
     * @return bool
     */
    public static function is_learner_account(int|\stdClass $user): bool {
        global $DB;

        $userid = is_object($user) ? (int) $user->id : $user;
        if (self::is_privileged_user($userid)) {
            return false;
        }

        $archetypes = $DB->get_fieldset_sql(
            'SELECT DISTINCT r.archetype
               FROM {role_assignments} ra
               JOIN {role} r ON r.id = ra.roleid
              WHERE ra.userid = :userid',
            ['userid' => $userid]
        );
        foreach ($archetypes as $archetype) {
            if (!in_array($archetype, ['student', 'user'], true)) {
                return false;
            }
        }

        return true;
    }
}
