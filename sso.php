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

/**
 * Send an unauthenticated visitor to the login form, keeping where they were going.
 *
 * THE DESTINATION MUST GO IN THE SESSION, not in the query string. Moodle's login flow
 * reads `$SESSION->wantsurl`; login/index.php only honours a `wantsurl` PARAMETER when
 * BEHAT_SITE_RUNNING is defined, so on a real site the obvious
 * `/login/index.php?wantsurl=...` is silently ignored and the student lands on the
 * dashboard after signing in. The redirect looked right, and lost the course.
 */
function local_completionhistory_sso_send_to_login(moodle_url $destination, string $reason): void {
    global $SESSION;
    debugging('local_completionhistory SSO key rejected: ' . $reason, DEBUG_DEVELOPER);
    $SESSION->wantsurl = $destination->out(false);
    redirect(
        new moodle_url('/login/index.php'),
        get_string('sso_linkexpired', 'local_completionhistory'),
        null,
        \core\output\notification::NOTIFY_INFO
    );
}

/**
 * CLAIM THE KEY UNDER A LOCK, so "single use" survives two simultaneous requests.
 *
 * validate_user_key() then delete_user_key() is a read followed by a write, and nothing
 * joins them. Two requests presenting the same key from the same permitted address can
 * both pass validation before either deletes, and both then get a session — which defeats
 * the guarantee in exactly the replay scenario it exists to stop. A stolen key is most
 * likely to be replayed immediately, so the race is the attack, not a curiosity.
 *
 * Moodle's lock API rather than SELECT ... FOR UPDATE: it is portable across database
 * families, whereas the SQL is not. The lock is named for the key VALUE, so it serialises
 * only the requests actually contending for the same key.
 *
 * The wait is short. A holder of this lock does a lookup and a delete, nothing more, and a
 * caller that cannot get in within a couple of seconds is better told to log in than left
 * waiting on a key that has 60 seconds to live.
 */
$lockfactory = \core\lock\lock_config::get_lock_factory('local_completionhistory_sso');
$lock = $lockfactory->get_lock('key_' . $keyvalue, 2);
if (!$lock) {
    local_completionhistory_sso_send_to_login($destination, 'lock unavailable');
}

try {
    // Throws on an unknown, expired, wrong-script or wrong-IP key.
    $key = validate_user_key($keyvalue, create_login_key::SCRIPT, null);
    $user = $DB->get_record('user', ['id' => $key->userid, 'deleted' => 0], '*', MUST_EXIST);

    // SPENT INSIDE THE LOCK, and before anything else can fail. If deletion came after the
    // login, a failure in between would leave the key alive for the rest of its minute —
    // having already been used once, and having already appeared in a URL. Deleting here
    // means the worst case is a student who has to click again, and it means the second of
    // two concurrent requests finds nothing to validate.
    delete_user_key(create_login_key::SCRIPT, $user->id);
} catch (moodle_exception $e) {
    // FAILING HERE IS ORDINARY, NOT SUSPICIOUS. The IP check in particular fires for honest
    // reasons: a dual-stack browser can reach the SIS over IPv6 and Moodle over IPv4, and a
    // phone can change address between the click and the redirect. So this degrades to the
    // login form carrying the same destination — "type your password, still land in your
    // course" — rather than to an error page. Nothing is granted on that path, so degrading
    // gracefully costs no safety. The specific reason goes to the developer log rather than
    // to the student, who cannot act on "ipmismatch".
    //
    // Note the key is NOT deleted on this path: burning a key that failed its IP check
    // would let anyone holding a stolen copy deny the legitimate student their own link.
    $lock->release();
    local_completionhistory_sso_send_to_login($destination, $e->errorcode ?? 'invalidkey');
}

// Held only for the claim. Everything below — the eligibility re-checks, the login, the
// event — concerns a key that is already deleted, so no other request can be contending.
$lock->release();

// Re-checked at USE time, not only at mint time. A minute is short, but an account
// suspended or an auth method disabled in between must not still be walked through this
// door by a key issued before it happened. is_enabled_auth covers the case where an
// administrator turns off a whole auth plugin — the front door would refuse them, and so
// does this one.
// is_siteadmin is repeated from the mint deliberately. It is the check whose failure is
// worst — a service token escalating to an administrator session — and a key minted before
// the mint-side check existed, or by any future caller that forgets it, still has to get
// past this door. Cheap, and it makes the guarantee a property of the endpoint rather than
// of every caller remembering.
if (!empty($user->suspended) || empty($user->confirmed)
        || $user->auth === 'nologin' || !is_enabled_auth($user->auth)
        || is_siteadmin($user->id)) {
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
