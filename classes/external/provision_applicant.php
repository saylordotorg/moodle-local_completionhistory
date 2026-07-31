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
 * applicant's Moodle account and allocate it to a program (enrol_programs)
 * by program idnumber — e.g. the degree's Pre-Master's on approval, or the
 * degree program itself on matriculation.
 *
 * Idempotent: existing users are matched by email; existing allocations are
 * reported rather than duplicated. A generated password is returned only
 * when the account was created in this call.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provision_applicant extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'email'           => new external_value(PARAM_EMAIL, 'Applicant email (identity key)'),
            'firstname'       => new external_value(PARAM_TEXT, 'First name'),
            'lastname'        => new external_value(PARAM_TEXT, 'Last name'),
            'programidnumber' => new external_value(PARAM_RAW, 'enrol_programs program idnumber to allocate', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(string $email, string $firstname, string $lastname, string $programidnumber = ''): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/lib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'email'           => $email,
            'firstname'       => $firstname,
            'lastname'        => $lastname,
            'programidnumber' => $programidnumber,
        ]);

        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);
        require_capability('local/completionhistory:manage', $systemcontext);

        $warning = '';
        $usernamewarning = '';
        $created = false;
        $password = '';

        // 1) Find or create the user by email. An existing account is ADOPTED,
        //    never duplicated and never password-reset: the email is the
        //    identity key, so a match means a returning learner whose history
        //    should attach to this application.
        $emaillower = \core_text::strtolower($params['email']);
        $user = $DB->get_record('user', ['email' => $emaillower, 'deleted' => 0, 'mnethostid' => $CFG->mnet_localhost_id]);
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
                    $username = $base . (++$suffix);
                }
                $usernamewarning = 'Username ' . $base . ' was taken by another account; created as ' . $username . '.';
            }

            $password = bin2hex(random_bytes(6)) . 'Aa1!';
            $new = new \stdClass();
            $new->username = $username;
            $new->firstname = $params['firstname'] !== '' ? $params['firstname'] : 'Applicant';
            $new->lastname = $params['lastname'] !== '' ? $params['lastname'] : 'User';
            $new->email = $emaillower;
            $new->auth = 'manual';
            $new->confirmed = 1;
            $new->mnethostid = $CFG->mnet_localhost_id;
            $new->password = $password;
            $userid = user_create_user($new, true, true);
            set_user_preference('auth_forcepasswordchange', 1, $userid);
            $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
            $created = true;
        }

        // 2) Allocate to the requested program (optional).
        $allocated = false;
        if ($params['programidnumber'] !== '') {
            $program = $DB->get_record('enrol_programs_programs', ['idnumber' => $params['programidnumber']]);
            if (!$program) {
                $warning = 'Program not found: ' . $params['programidnumber'];
            } else if ($DB->record_exists('enrol_programs_allocations', ['programid' => $program->id, 'userid' => $user->id, 'archived' => 0])) {
                $allocated = true; // Already allocated — idempotent success.
            } else if (!class_exists('\\enrol_programs\\local\\source\\manual')) {
                $warning = 'enrol_programs manual source unavailable.';
            } else {
                $source = $DB->get_record('enrol_programs_sources', ['programid' => $program->id, 'type' => 'manual']);
                if (!$source) {
                    $warning = 'Program has no manual allocation source: ' . $params['programidnumber'];
                } else {
                    \enrol_programs\local\source\manual::allocate_users($program->id, $source->id, [$user->id]);
                    $allocated = true;
                }
            }
        }

        return [
            'userid'    => (int) $user->id,
            'username'  => $user->username,
            'created'   => $created,
            'allocated' => $allocated,
            'password'  => $password,
            'warning'   => trim($usernamewarning . ' ' . $warning),
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'userid'    => new external_value(PARAM_INT, 'Moodle user id'),
            'username'  => new external_value(PARAM_RAW, 'Moodle username'),
            'created'   => new external_value(PARAM_BOOL, 'Whether the account was created by this call'),
            'allocated' => new external_value(PARAM_BOOL, 'Whether the user is allocated to the requested program'),
            'password'  => new external_value(PARAM_RAW, 'Initial password (only when created; force-change on first login)'),
            'warning'   => new external_value(PARAM_RAW, 'Non-fatal warning, if any'),
        ]);
    }
}
