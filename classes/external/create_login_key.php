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
 * Mint a single-use key that logs one student into Moodle in a browser (SIS-29).
 *
 * WHY THIS EXISTS. mySaylor already authenticates students AGAINST Moodle — the SIS
 * posts their username and password to login/token.php and mints its own session only
 * if Moodle accepts them. So a signed-in mySaylor student has already proved Moodle
 * identity. What they do not have is a Moodle BROWSER session, which is why "Open in
 * Moodle" lands them on a course page asking them to log in again.
 *
 * This is the standard auth_userkey pattern, built on Moodle core's own
 * create_user_key / validate_user_key rather than on anything invented here.
 *
 * WHAT MAKES IT SAFE TO HAND OUT A LOGIN KEY:
 *
 *   SINGLE USE. local/completionhistory/sso.php deletes the key before it logs anyone
 *   in, so a key captured from a URL — browser history, a referrer header, a shoulder —
 *   is already spent by the time anyone else tries it.
 *
 *   SIXTY SECONDS. Long enough for a redirect, short enough that a leaked key is
 *   almost always expired before it can be used.
 *
 *   IP-BOUND. The key only works from the address that asked for it, so a key that
 *   escapes to another machine is inert. This is the check that survives when the other
 *   two fail.
 *
 *   ONE LIVE KEY PER USER. Minting deletes any previous key for this script, so a
 *   student clicking twice cannot leave a spare valid key behind them.
 *
 * WHAT THIS FUNCTION IS, PLAINLY. Anyone holding a token with both the base integration
 * capability and local/completionhistory:createloginkeys can obtain a browser session as an
 * eligible learner. That is a real escalation over the functions which only read and write
 * records, and it is why the service definition marks it 'write' and refuses AJAX. It is NOT
 * mitigated by taking an email instead of a user id — a caller can name any email as easily
 * as any id — so the parameter is the user id, which is what the SIS session authoritatively
 * holds and what avoids a second lookup that could resolve to the wrong person.
 *
 * "Eligible learner" is doing real work there. An earlier version accepted any user id at
 * all, which meant a leaked service token could name an administrator and be handed an
 * administrator session — a service credential escalating to full site control. Site admins,
 * accounts holding high-risk capabilities, and accounts assigned staff-role archetypes are
 * now refused outright.
 *
 * The thing that actually constrains it is the caller: the SIS route passes only the
 * `uid` claim from the student's own signed session, never a value from the request body.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_login_key extends external_api {

    /** Script name the key is scoped to. Keys minted here work nowhere else. */
    public const SCRIPT = 'local_completionhistory/sso';

    /** Seconds a key remains valid. A redirect takes one; a leaked key should not survive. */
    public const TTL = 60;

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Moodle user id to mint a key for'),
            'ip'     => new external_value(PARAM_RAW, 'Browser IP the key will be restricted to'),
        ]);
    }

    public static function execute(int $userid, string $ip): array {
        global $CFG, $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
            'ip'     => $ip,
        ]);

        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);
        \local_completionhistory\local\security::require_enabled();
        require_capability('local/completionhistory:integrate', $systemcontext);
        require_capability('local/completionhistory:createloginkeys', $systemcontext);
        // An empty IP restriction would mean "valid from anywhere", which is exactly the
        // protection this relies on when a key leaks. Refused rather than defaulted —
        // and PARAM_RAW above is deliberate, because PARAM_TEXT would silently mangle a
        // malformed value into something that might still pass this check.
        $ip = trim($params['ip']);
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return ['key' => '', 'expiresin' => 0, 'warning' => 'A valid browser IP is required.'];
        }

        $user = $DB->get_record('user', [
            'id'         => $params['userid'],
            'deleted'    => 0,
            'mnethostid' => $CFG->mnet_localhost_id,
        ]);
        if (!$user) {
            return ['key' => '', 'expiresin' => 0, 'warning' => 'No local account was found.'];
        }
        // Anything that stops this account using the front door must stop it using this
        // one. Checked here so the SIS gets a reason it can show, and checked AGAIN in
        // sso.php because a minute is enough time for an administrator to suspend someone.
        if (!empty($user->suspended)) {
            return ['key' => '', 'expiresin' => 0, 'warning' => 'This account is suspended.'];
        }
        if (empty($user->confirmed)) {
            return ['key' => '', 'expiresin' => 0, 'warning' => 'This account is not confirmed.'];
        }
        if ($user->auth === 'nologin' || !is_enabled_auth($user->auth)) {
            return ['key' => '', 'expiresin' => 0, 'warning' => 'This account cannot sign in.'];
        }

        // PRIVILEGED ACCOUNTS ARE NOT VALID TARGETS. Without this, a token holding
        // the login-key capabilities could name an administrator's user id and receive
        // a browser session as them — turning a restricted service credential into a full
        // administrator login. That is a categorically different power from the record
        // access the rest of this service grants, and nothing about "student SSO" implies it.
        //
        // Checked by capabilities and assigned role archetypes rather than role names,
        // because a site can call its administrative or teaching roles anything.
        if (!\local_completionhistory\local\security::is_learner_account($user)) {
            return ['key' => '', 'expiresin' => 0, 'warning' => 'This account is not eligible for single sign-on.'];
        }
        // At most one live key per user. Serialize delete + create so concurrent
        // requests cannot both create a key after each has observed an empty set.
        $lockfactory = \core\lock\lock_config::get_lock_factory('local_completionhistory_sso_mint');
        $lock = $lockfactory->get_lock('user_' . (int) $user->id, 5);
        if (!$lock) {
            return ['key' => '', 'expiresin' => 0, 'warning' => 'Single sign-on is already being prepared.'];
        }

        try {
            delete_user_key(self::SCRIPT, $user->id);
            $key = create_user_key(
                self::SCRIPT,
                $user->id,
                null,
                $ip,
                time() + self::TTL
            );
        } finally {
            $lock->release();
        }

        return ['key' => $key, 'expiresin' => self::TTL, 'warning' => ''];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'key'       => new external_value(PARAM_ALPHANUM, 'Single-use login key, or empty on failure'),
            'expiresin' => new external_value(PARAM_INT, 'Seconds until the key expires'),
            'warning'   => new external_value(PARAM_RAW, 'Failure reason, if any'),
        ]);
    }
}
