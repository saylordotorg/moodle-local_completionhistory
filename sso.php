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

/**
 * Consume a single-use key minted by the SIS and log the student in (SIS-29).
 *
 * The browser half of the pattern described in classes/external/create_login_key.php.
 * A student clicks "Open in Moodle" in mySaylor; the SIS mints a key for THEIR OWN
 * account, bound to their IP and valid for a minute, and redirects here; this logs them
 * in and drops them on the course rather than on a login form.
 *
 * THREE THINGS THIS FILE MUST GET RIGHT, in the order they can bite:
 *
 *   NO SESSION IS REQUIRED TO REACH IT. That is the point — the visitor is logged out.
 *   So the key is the only credential, and every guarantee rests on validate_user_key:
 *   it enforces the script scope, the expiry and the IP restriction, and throws
 *   otherwise. Nothing here second-guesses it or falls back when it fails.
 *
 *   THE KEY IS DELETED BEFORE THE LOGIN, not after. If deletion followed
 *   complete_user_login and anything in between failed, the key would survive its own
 *   use and stay valid for the rest of its minute. Spending it first means the worst
 *   case is a student who has to click again, rather than a live key in a URL bar.
 *
 *   THE REDIRECT TARGET IS PARAM_LOCALURL. `wantsurl` arrives in a link that has been
 *   through an email client, a browser and a student's clipboard. Accepting an absolute
 *   URL would turn a Moodle login into an open redirect — sign in legitimately, land on
 *   somebody else's site, with the trust of having just authenticated. PARAM_LOCALURL is
 *   what refuses that, and it is why the parameter is not read with PARAM_URL.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_completionhistory\external\create_login_key;

$keyvalue = required_param('key', PARAM_ALPHANUM);
// PARAM_LOCALURL strips anything that is not local to this Moodle. See above.
$wantsurl = optional_param('wantsurl', '', PARAM_LOCALURL);

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/completionhistory/sso.php'));

$destination = new moodle_url($wantsurl !== '' ? $wantsurl : '/my/');

// FAILING HERE IS ORDINARY, NOT SUSPICIOUS. validate_user_key throws on an unknown,
// expired, wrong-script or wrong-IP key, and the IP check in particular fires for honest
// reasons: a dual-stack browser can reach the SIS over IPv6 and Moodle over IPv4, and a
// phone can change address between the click and the redirect.
//
// So the failure path sends them to the login form CARRYING THE SAME DESTINATION, and the
// worst case becomes "type your password, still land in your course" instead of a Moodle
// error page. Nothing is granted on this path — it is the login form, not a session — so
// degrading gracefully costs no safety. The specific reason goes to the developer log
// rather than to the student, who cannot act on "ipmismatch".
try {
    $key = validate_user_key($keyvalue, create_login_key::SCRIPT, null);
} catch (moodle_exception $e) {
    debugging('local_completionhistory SSO key rejected: ' . $e->errorcode, DEBUG_DEVELOPER);
    redirect(
        new moodle_url('/login/index.php', ['wantsurl' => $destination->out(false)]),
        get_string('sso_linkexpired', 'local_completionhistory'),
        null,
        \core\output\notification::NOTIFY_INFO
    );
}

$user = $DB->get_record('user', ['id' => $key->userid, 'deleted' => 0], '*', MUST_EXIST);

// SPEND THE KEY BEFORE ANYTHING ELSE CAN FAIL. If deletion came after the login, then a
// failure in between would leave the key alive for the rest of its minute — having
// already been used once, and having already appeared in a URL. Deleting first means the
// worst case is a student who has to click again.
delete_user_key(create_login_key::SCRIPT, $user->id);

// Re-checked at USE time, not only at mint time. A minute is short, but an account
// suspended or an auth method disabled in between must not still be walked through this
// door by a key issued before it happened. is_enabled_auth covers the case where an
// administrator turns off a whole auth plugin — the front door would refuse them, and so
// does this one.
if (!empty($user->suspended) || empty($user->confirmed)
        || $user->auth === 'nologin' || !is_enabled_auth($user->auth)) {
    throw new moodle_exception('invaliduser', 'error');
}

// Triggers \core\event\user_loggedin itself, so this file does not log that again.
complete_user_login($user);

// What core's event cannot say: that no password was typed. See the event class.
\local_completionhistory\event\sso_login::create([
    'objectid'      => $user->id,
    'relateduserid' => $user->id,
    'context'       => context_system::instance(),
])->trigger();

redirect($destination);
