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
 * Apply a SIS-side profile correction to the Moodle account (SIS-33).
 *
 * WHY THIS EXISTS RATHER THAN core_user_update_users. That core function can change a user's
 * authentication method, password, username and roles — everything needed to take over an
 * account. Granting it to the SIS token so the registrar can fix a mailing address would trade
 * a very wide capability for a very narrow need, and the token is long-lived. This function
 * writes SIX contact fields and nothing else, which is the plugin's stated purpose: a curated
 * surface so the SIS never needs the core write API.
 *
 * WHAT IT DELIBERATELY REFUSES:
 *
 *   username, auth, password, idnumber, roles — absent from the whitelist entirely, so a
 *   compromised SIS token cannot escalate through this path.
 *
 *   email — accepted as the IDENTITY KEY but never as a value to write. The email is also the
 *   Moodle username under the SIS account policy and the SIS's own unique learner key, so
 *   changing it is an identity operation with merge implications, not a contact correction. The
 *   SIS refuses it at its own endpoint too.
 *
 *   PRIVILEGED ACCOUNTS — a site admin, or a holder of site:config / role:assign /
 *   user:loginas, is refused outright, matching create_login_key. Anyone who can already grant
 *   themselves anything should not have their profile rewritten by an integration token, and
 *   the asymmetry is what makes the refusal cheap to reason about.
 *
 * IDEMPOTENT AND HONEST. Only fields whose value actually differs are written, and the response
 * names them, so the SIS can record what really changed rather than assuming its patch applied.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_user_profile extends external_api {

    /**
     * The only fields this function will ever write, with the PARAM type each is cleaned to.
     *
     * Lengths are Moodle's own column limits — address varchar(255), city varchar(120),
     * country char(2), phone1 varchar(20), firstname/lastname varchar(100) — enforced here as
     * well as by the caller, because a truncation surprise is better caught at the boundary
     * that owns the column than discovered in the database.
     */
    private const WRITABLE = [
        'firstname' => [PARAM_TEXT, 100],
        'lastname'  => [PARAM_TEXT, 100],
        'phone1'    => [PARAM_NOTAGS, 20],
        'address'   => [PARAM_NOTAGS, 255],
        'city'      => [PARAM_NOTAGS, 120],
        'country'   => [PARAM_ALPHA, 2],
    ];

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'email'  => new external_value(PARAM_EMAIL, 'Account email — the identity key. NOT writable.'),
            'fields' => new external_single_structure([
                'firstname' => new external_value(PARAM_TEXT, 'Given name', VALUE_OPTIONAL),
                'lastname'  => new external_value(PARAM_TEXT, 'Family name', VALUE_OPTIONAL),
                'phone1'    => new external_value(PARAM_NOTAGS, 'Phone', VALUE_OPTIONAL),
                'address'   => new external_value(PARAM_NOTAGS, 'Street address, flattened by the SIS', VALUE_OPTIONAL),
                'city'      => new external_value(PARAM_NOTAGS, 'City', VALUE_OPTIONAL),
                'country'   => new external_value(PARAM_ALPHA, 'ISO 3166-1 alpha-2 country code', VALUE_OPTIONAL),
            ], 'Contact fields to correct; omit what should not change'),
        ]);
    }

    /**
     * @param string $email Identity key.
     * @param array $fields Whitelisted contact fields.
     * @return array
     */
    public static function execute(string $email, array $fields): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/lib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'email'  => $email,
            'fields' => $fields,
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
            return ['success' => false, 'updated' => '', 'warning' => 'No account was found for that email.'];
        }

        // Same refusal as create_login_key, for the same reason: an integration token must not
        // reach an account that can already grant itself anything.
        $usercontext = \context_user::instance($user->id);
        if (is_siteadmin($user)
                || has_capability('moodle/site:config', $systemcontext, $user)
                || has_capability('moodle/role:assign', $systemcontext, $user)
                || has_capability('moodle/user:loginas', $systemcontext, $user)) {
            return [
                'success' => false,
                'updated' => '',
                'warning' => 'This account is privileged; its profile cannot be changed through the integration.',
            ];
        }

        $update = new \stdClass();
        $update->id = $user->id;
        $changed = [];
        foreach (self::WRITABLE as $key => [$type, $max]) {
            if (!array_key_exists($key, $params['fields'])) {
                continue; // Omitted means "leave alone" — not "clear".
            }
            $value = clean_param((string) $params['fields'][$key], $type);
            $value = \core_text::substr($value, 0, $max);
            if ($key === 'country') {
                $value = \core_text::strtoupper($value);
            }
            /*
             * An EMPTY value is skipped rather than written. A blank in Moodle is
             * indistinguishable from "cleared", so pushing one would let a partially-filled SIS
             * profile erase a detail the student had entered in Moodle directly. Clearing a
             * Moodle field is therefore not something this path can do, which is the safer
             * asymmetry — and the SIS says the same thing at its end.
             */
            if ($value === '') {
                continue;
            }
            if ((string) ($user->$key ?? '') === $value) {
                continue; // Idempotent: an unchanged value is not an update.
            }
            $update->$key = $value;
            $changed[] = $key;
        }

        if (!$changed) {
            return ['success' => true, 'updated' => '', 'warning' => ''];
        }

        // user_update_user handles the event, the cache purge and the timemodified stamp.
        // Password is never touched here, hence false for the second argument.
        user_update_user($update, false, true);

        return ['success' => true, 'updated' => implode(',', $changed), 'warning' => ''];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the account was reachable and writable'),
            'updated' => new external_value(PARAM_RAW, 'Comma-separated fields actually changed; empty when nothing differed'),
            'warning' => new external_value(PARAM_RAW, 'Failure reason, if any'),
        ]);
    }
}
