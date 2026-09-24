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
 * The user fields the SIS field mapping may target, and how each value is checked.
 *
 * ONE LIST FOR BOTH SIDES OF THE CONTRACT. get_profile_fields describes exactly these fields to
 * the SIS console, and set_user_fields writes exactly these and refuses everything else, so the
 * console can never offer a target the writer would reject.
 *
 * WHAT IS NOT HERE, AND WHY. username, email, auth and password are identity and access, not
 * profile data; a mapping that could rewrite them would let a mis-set dropdown lock a student out
 * or move their account. firstname and lastname are left to provisioning and update_user_profile,
 * which already own them, so there is exactly one writer of a learner's name.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class profile_field_catalogue {
    /**
     * Standard user-table fields: name => [label, PARAM type, max length].
     *
     * Lengths are Moodle's own column limits.
     */
    public const STANDARD = [
        'idnumber'      => ['ID number', PARAM_ALPHANUMEXT, 255],
        'institution'   => ['Institution', PARAM_NOTAGS, 255],
        'department'    => ['Department', PARAM_NOTAGS, 255],
        'phone1'        => ['Phone', PARAM_NOTAGS, 20],
        'phone2'        => ['Mobile phone', PARAM_NOTAGS, 20],
        'address'       => ['Address', PARAM_NOTAGS, 255],
        'city'          => ['City/town', PARAM_NOTAGS, 120],
        'country'       => ['Country', PARAM_ALPHA, 2],
        'middlename'    => ['Middle name', PARAM_NOTAGS, 255],
        'alternatename' => ['Alternate name', PARAM_NOTAGS, 255],
    ];

    /** Prefix a custom profile field's shortname carries in a mapping, as Moodle's own forms do. */
    public const CUSTOM_PREFIX = 'profile_field_';

    /** Custom field types whose values the writer knows how to check. */
    public const CUSTOM_TYPES = ['text', 'textarea', 'menu', 'checkbox', 'datetime', 'social'];

    /**
     * Every custom profile field on the site, keyed by the mapping name (profile_field_<shortname>).
     *
     * @return array<string, \stdClass> The user_info_field rows.
     */
    public static function custom_fields(): array {
        global $DB;

        $out = [];
        foreach ($DB->get_records('user_info_field', null, 'sortorder ASC, id ASC') as $field) {
            $out[self::CUSTOM_PREFIX . $field->shortname] = $field;
        }
        return $out;
    }

    /**
     * The options of a menu field, one per line of param1, as Moodle stores them.
     *
     * @param \stdClass $field The user_info_field row.
     * @return string[]
     */
    public static function menu_options(\stdClass $field): array {
        $options = array_map('trim', explode("\n", str_replace("\r", '', (string) $field->param1)));
        return array_values(array_filter($options, static fn(string $o): bool => $o !== ''));
    }

    /**
     * Check and normalise a value for a standard field.
     *
     * @param string $name The field name, a key of STANDARD.
     * @param string $raw The value the SIS sent.
     * @return array{0: ?string, 1: string} The value to store (null when refused) and the reason.
     */
    public static function standard_value(string $name, string $raw): array {
        [, $type, $max] = self::STANDARD[$name];
        $value = trim($raw);
        if (clean_param($value, $type) !== $value) {
            return [null, 'contains characters this field does not allow'];
        }
        if (\core_text::strlen($value) > $max) {
            return [null, "longer than the field's {$max}-character limit"];
        }
        if ($name === 'country') {
            $value = \core_text::strtoupper($value);
            if (!array_key_exists($value, get_string_manager()->get_list_of_countries(true))) {
                return [null, 'not an ISO 3166-1 country code Moodle recognizes'];
            }
        }
        return [$value, ''];
    }

    /**
     * Check a value for a custom field and shape it the way that field type's edit_save_data expects.
     *
     * @param \stdClass $field The user_info_field row.
     * @param string $raw The value the SIS sent.
     * @return array{0: mixed, 1: string, 2: string} The value for edit_save_data (null when refused),
     *     the reason, and the value as it will read back from user_info_data for comparison.
     */
    public static function custom_value(\stdClass $field, string $raw): array {
        $value = trim($raw);
        switch ($field->datatype) {
            case 'text':
            case 'social':
                $max = (int) ($field->param2 ?: 2048);
                if (\core_text::strlen($value) > $max) {
                    return [null, "longer than the field's {$max}-character limit", ''];
                }
                $clean = clean_param($value, PARAM_TEXT);
                return [$clean, '', $clean];
            case 'textarea':
                $clean = clean_param($value, PARAM_TEXT);
                return [['text' => $clean, 'format' => FORMAT_PLAIN], '', $clean];
            case 'menu':
                if (!in_array($value, self::menu_options($field), true)) {
                    return [null, 'not one of the menu options defined in Moodle', ''];
                }
                return [$value, '', $value];
            case 'checkbox':
                $truthy = ['1', 'true', 'yes'];
                $falsy = ['0', 'false', 'no'];
                $lower = \core_text::strtolower($value);
                if (!in_array($lower, array_merge($truthy, $falsy), true)) {
                    return [null, 'a checkbox takes true or false', ''];
                }
                $bit = in_array($lower, $truthy, true) ? '1' : '0';
                return [$bit, '', $bit];
            case 'datetime':
                // The SIS sends ISO dates (YYYY-MM-DD) or a Unix timestamp.
                if (ctype_digit($value)) {
                    $ts = (int) $value;
                } else if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                    $ts = (int) (new \DateTimeImmutable($value . ' 12:00:00', new \DateTimeZone('UTC')))->getTimestamp();
                } else {
                    return [null, 'a date must be YYYY-MM-DD', ''];
                }
                return [$ts, '', (string) $ts];
            default:
                return [null, "custom fields of type {$field->datatype} are not supported", ''];
        }
    }
}
