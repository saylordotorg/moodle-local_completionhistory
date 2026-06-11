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
use core_external\external_single_structure;
use core_external\external_value;

/**
 * External function used by the SIS welcome flow: set a manual-auth user's
 * password (identified by email) and clear the force-password-change flag, so
 * a newly provisioned applicant can sign in to both the degrees platform and
 * mySaylor immediately — without the "change it in Moodle first" step.
 *
 * The SIS backend gates this behind a signed, expiring set-password token; the
 * site password policy is still enforced here as defence in depth.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_password extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'email'    => new external_value(PARAM_EMAIL, 'User email (identity key)'),
            'password' => new external_value(PARAM_RAW, 'New password to set'),
        ]);
    }

    public static function execute(string $email, string $password): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/lib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'email'    => $email,
            'password' => $password,
        ]);

        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);
        require_capability('local/completionhistory:manage', $systemcontext);

        $user = $DB->get_record('user', [
            'email'      => \core_text::strtolower($params['email']),
            'deleted'    => 0,
            'mnethostid' => $CFG->mnet_localhost_id,
        ]);
        if (!$user) {
            return ['success' => false, 'warning' => 'No account was found for that email.'];
        }
        if ($user->auth !== 'manual') {
            return ['success' => false, 'warning' => 'This account is not managed by mySaylor.'];
        }

        // Enforce the site password policy server-side.
        $errmsg = '';
        if (!check_password_policy($params['password'], $errmsg, $user)) {
            return ['success' => false, 'warning' => trim(html_to_text($errmsg, 0, false))];
        }

        update_internal_user_password($user, $params['password']);
        // Clear the force-change flag so login/token.php accepts the account.
        unset_user_preference('auth_forcepasswordchange', $user);

        return ['success' => true, 'warning' => ''];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the password was set'),
            'warning' => new external_value(PARAM_RAW, 'Failure reason, if any'),
        ]);
    }
}
