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
use local_completionhistory\local\profile_field_catalogue;

/**
 * Write the SIS's mapped fields to a learner's Moodle profile.
 *
 * The SIS keeps a mapping of its own fields (student number, programme, level…) to Moodle user
 * fields, standard or custom, and is the authority for every one of them: a value it sends
 * OVERWRITES what Moodle holds. That includes the user ID number, which update_user_profile
 * deliberately refuses; this function exists so that write goes through one narrow, audited path
 * with its own guards rather than by widening that one.
 *
 * GUARDS, IN THE ORDER THEY APPLY:
 *
 *   - Only fields in profile_field_catalogue — the same list get_profile_fields shows the console.
 *   - Non-learner accounts are refused outright, as every write in this plugin does.
 *   - An EMPTY value is skipped, never written: a blank in Moodle is indistinguishable from
 *     "cleared", so a gap in the SIS record must not erase something the student entered.
 *   - An ID number another live account already holds is refused for this account, so no two
 *     users ever share a student number.
 *   - Each value is checked against its field's type (menu options, checkbox, date, length).
 *
 * ONE FIELD'S REFUSAL NEVER BLOCKS THE REST, and every field gets its own outcome back, so the
 * SIS can record exactly which values reached Moodle rather than assuming the whole patch did.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_user_fields extends external_api {
    /**
     * Describe the parameters accepted by execute().
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'email'  => new external_value(PARAM_EMAIL, 'Account email, the identity key. Not writable.'),
            'fields' => new external_multiple_structure(
                new external_single_structure([
                    'name'  => new external_value(PARAM_ALPHANUMEXT, 'A standard field name, or profile_field_<shortname>'),
                    'value' => new external_value(PARAM_RAW, 'The value the SIS holds'),
                ]),
                'Fields to write'
            ),
        ]);
    }

    /**
     * Write each mapped field, reporting a per-field outcome.
     *
     * @param string $email Identity key.
     * @param array $fields List of ['name' => string, 'value' => string].
     * @return array Whether the account was writable, a warning, and one result per field.
     */
    public static function execute(string $email, array $fields): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->dirroot . '/user/profile/lib.php');

        $params = self::validate_parameters(self::execute_parameters(), ['email' => $email, 'fields' => $fields]);

        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);
        \local_completionhistory\local\security::require_enabled();
        require_capability('local/completionhistory:integrate', $systemcontext);
        require_capability('local/completionhistory:updateprofiles', $systemcontext);

        $user = \local_completionhistory\local\security::get_unique_local_user_by_email($params['email']);
        if (!$user) {
            return ['success' => false, 'userid' => 0, 'warning' => 'No account was found for that email.', 'results' => []];
        }
        if (!\local_completionhistory\local\security::is_learner_account($user)) {
            return [
                'success' => false,
                'userid' => 0,
                'warning' => 'This account is not an eligible learner; its profile cannot be changed through the integration.',
                'results' => [],
            ];
        }

        $custom = profile_field_catalogue::custom_fields();
        $formfields = [];
        foreach (profile_get_user_fields_with_data((int) $user->id) as $formfield) {
            $formfields[profile_field_catalogue::CUSTOM_PREFIX . $formfield->field->shortname] = $formfield;
        }

        $results = [];
        $standardupdate = new \stdClass();
        $standardupdate->id = (int) $user->id;
        $standardchanged = [];
        $customchanged = false;
        $seen = [];

        foreach ($params['fields'] as $item) {
            $name = $item['name'];
            $raw = (string) $item['value'];
            $report = static function (string $status, string $message = '') use (&$results, $name): void {
                $results[] = ['name' => $name, 'status' => $status, 'message' => $message];
            };

            if (isset($seen[$name])) {
                $report('refused', 'sent more than once; the first value was used');
                continue;
            }
            $seen[$name] = true;

            if (trim($raw) === '') {
                $report('skipped', 'the SIS holds no value, so Moodle was left alone');
                continue;
            }

            if (array_key_exists($name, profile_field_catalogue::STANDARD)) {
                [$value, $why] = profile_field_catalogue::standard_value($name, $raw);
                if ($value === null) {
                    $report('refused', $why);
                    continue;
                }
                if ($name === 'idnumber' && $DB->record_exists_select(
                    'user',
                    'idnumber = :idnumber AND deleted = 0 AND id <> :userid',
                    ['idnumber' => $value, 'userid' => (int) $user->id]
                )) {
                    $report('refused', 'another account already holds this ID number');
                    continue;
                }
                if ((string) ($user->$name ?? '') === $value) {
                    $report('unchanged');
                    continue;
                }
                $standardupdate->$name = $value;
                $standardchanged[] = $name;
                $report('changed');
                continue;
            }

            if (isset($custom[$name], $formfields[$name])) {
                $field = $custom[$name];
                if (!in_array($field->datatype, profile_field_catalogue::CUSTOM_TYPES, true)) {
                    $report('refused', "custom fields of type {$field->datatype} are not supported");
                    continue;
                }
                [$prepared, $why, $comparable] = profile_field_catalogue::custom_value($field, $raw);
                if ($prepared === null) {
                    $report('refused', $why);
                    continue;
                }
                $formfield = $formfields[$name];
                $current = (string) ($formfield->data ?? '');
                $same = $field->datatype === 'datetime'
                    // A stored date is re-derived through the calendar, so compare to within a day.
                    ? ($current !== '' && abs((int) $current - (int) $comparable) < 36 * 3600)
                    : $current === $comparable;
                if ($same) {
                    $report('unchanged');
                    continue;
                }
                $formfield->edit_save_data((object) ['id' => (int) $user->id, $formfield->inputname => $prepared]);
                $customchanged = true;
                $report('changed');
                continue;
            }

            $report('refused', 'not a field this integration may write, or no such custom profile field exists');
        }

        if ($standardchanged) {
            // user_update_user fires user_updated, purges caches and stamps timemodified.
            user_update_user($standardupdate, false, true);
        } else if ($customchanged) {
            \core\event\user_updated::create_from_userid((int) $user->id)->trigger();
        }

        return ['success' => true, 'userid' => (int) $user->id, 'warning' => '', 'results' => $results];
    }

    /**
     * Describe the structure execute() returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the account was reachable and writable'),
            'userid'  => new external_value(PARAM_INT, 'Moodle user id, 0 when refused'),
            'warning' => new external_value(PARAM_RAW, 'Why the whole call was refused, if it was'),
            'results' => new external_multiple_structure(
                new external_single_structure([
                    'name'    => new external_value(PARAM_ALPHANUMEXT, 'Field name as sent'),
                    'status'  => new external_value(PARAM_ALPHA, 'changed | unchanged | skipped | refused'),
                    'message' => new external_value(PARAM_RAW, 'Why, when not changed'),
                ])
            ),
        ]);
    }
}
