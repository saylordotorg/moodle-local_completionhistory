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
 * External function used by the SIS admissions service: create (or find) the
 * applicant's Moodle account. Account only — until SIS-165 this also allocated
 * the user to an enrol_programs programme, which granted whole-programme course
 * access and silently outranked the SIS learning-window pacer. Programme
 * placement is an SIS fact now; Moodle's share is the account and the
 * per-course manual enrolments the pacer creates (enrol_user_in_course).
 *
 * Idempotent: existing users are matched by email, never duplicated and never
 * password-reset. A generated password is returned only when the account was
 * created in this call.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provision_applicant extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'email'     => new external_value(PARAM_EMAIL, 'Applicant email (identity key)'),
            'firstname' => new external_value(PARAM_TEXT, 'First name'),
            'lastname'  => new external_value(PARAM_TEXT, 'Last name'),
        ]);
    }

    public static function execute(string $email, string $firstname, string $lastname): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/lib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'email'     => $email,
            'firstname' => $firstname,
            'lastname'  => $lastname,
        ]);

        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);
        \local_completionhistory\local\security::require_enabled();
        require_capability('local/completionhistory:integrate', $systemcontext);
        require_capability('local/completionhistory:provisionusers', $systemcontext);
        $warning = '';
        $usernamewarning = '';
        $created = false;
        $password = '';

        // 1) Find or create the user by email. An eligible existing learner is
        // adopted, never duplicated and never password-reset. Staff and other
        // non-learner accounts are refused even when the email matches.
        $emaillower = \core_text::strtolower(trim($params['email']));
        $lockfactory = \core\lock\lock_config::get_lock_factory('local_completionhistory_provision');
        $lock = $lockfactory->get_lock('email_' . hash('sha256', $emaillower), 5);
        if (!$lock) {
            throw new \moodle_exception('provisioninglockunavailable', 'local_completionhistory');
        }

        try {
            $user = \local_completionhistory\local\security::get_unique_local_user_by_email($emaillower);
            if (!$user) {
                // Username = the email address (SIS policy, 2026-06): one
                // identifier across degrees.saylor.org, mySaylor, and the future
                // Cognito SSO. clean_param may strip chars Moodle disallows
                // (e.g. "+" unless extendedusernamechars) — use what survives.
                $username = clean_param($emaillower, PARAM_USERNAME);
                if ($username === '') {
                    $username = 'sisuser';
                }
                if ($DB->record_exists('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id])) {
                    // The exact username belongs to a DIFFERENT account (no user
                    // has this email, or we would have adopted it above).
                    $base = $username;
                    $suffix = 1;
                    while ($DB->record_exists('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id])) {
                        $suffixtext = (string) (++$suffix);
                        $username = \core_text::substr($base, 0, 100 - strlen($suffixtext)) . $suffixtext;
                    }
                    $usernamewarning = 'Username ' . $base . ' was taken by another account; created as ' . $username . '.';
                }

                $password = bin2hex(random_bytes(16)) . 'Aa1!';
                $new = new \stdClass();
                $new->username = $username;
                $new->firstname = $params['firstname'] !== ''
                    ? \core_text::substr($params['firstname'], 0, 100)
                    : 'Applicant';
                $new->lastname = $params['lastname'] !== ''
                    ? \core_text::substr($params['lastname'], 0, 100)
                    : 'User';
                $new->email = $emaillower;
                $new->auth = 'manual';
                $new->confirmed = 1;
                $new->mnethostid = $CFG->mnet_localhost_id;
                $new->password = $password;
                $userid = user_create_user($new, true, true);
                set_user_preference('auth_forcepasswordchange', 1, $userid);
                $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
                $created = true;
            } else if (!\local_completionhistory\local\security::is_learner_account($user)) {
                return [
                    'userid' => 0,
                    'username' => '',
                    'created' => false,
                    'password' => '',
                    'warning' => 'A non-learner account cannot be adopted by the integration.',
                ];
            }

            return [
                'userid'    => (int) $user->id,
                'username'  => $user->username,
                'created'   => $created,
                'password'  => $password,
                'warning'   => trim($usernamewarning . ' ' . $warning),
            ];
        } finally {
            $lock->release();
        }
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'userid'    => new external_value(PARAM_INT, 'Moodle user id'),
            'username'  => new external_value(PARAM_RAW, 'Moodle username'),
            'created'   => new external_value(PARAM_BOOL, 'Whether the account was created by this call'),
            'password'  => new external_value(PARAM_RAW, 'Initial password (only when created; force-change on first login)'),
            'warning'   => new external_value(PARAM_RAW, 'Non-fatal warning, if any'),
        ]);
    }
}
