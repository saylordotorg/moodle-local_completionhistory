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
 * Describe the user fields the SIS field mapping may write, custom profile fields included.
 *
 * Read by the SIS console so an administrator picks a target from what this site actually has,
 * rather than typing a shortname that may not exist. Returns field DEFINITIONS only, never any
 * user's data. The list is exactly what set_user_fields accepts.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_profile_fields extends external_api {
    /**
     * Describe the parameters accepted by execute().
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * List the writable standard fields and every custom profile field.
     *
     * @return array The fields.
     */
    public static function execute(): array {
        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);
        \local_completionhistory\local\security::require_enabled();
        require_capability('local/completionhistory:integrate', $systemcontext);

        $out = [];
        foreach (profile_field_catalogue::STANDARD as $name => [$label, , $max]) {
            $out[] = [
                'name' => $name,
                'label' => $label,
                'kind' => 'standard',
                'datatype' => $name === 'country' ? 'country' : 'text',
                'maxlength' => $max,
                'options' => [],
                'supported' => true,
            ];
        }
        foreach (profile_field_catalogue::custom_fields() as $name => $field) {
            $out[] = [
                'name' => $name,
                'label' => format_string($field->name, true, ['context' => $systemcontext]),
                'kind' => 'custom',
                'datatype' => (string) $field->datatype,
                'maxlength' => in_array($field->datatype, ['text', 'social'], true) ? (int) ($field->param2 ?: 2048) : 0,
                'options' => $field->datatype === 'menu' ? profile_field_catalogue::menu_options($field) : [],
                'supported' => in_array($field->datatype, profile_field_catalogue::CUSTOM_TYPES, true),
            ];
        }
        return ['fields' => $out];
    }

    /**
     * Describe the structure execute() returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'fields' => new external_multiple_structure(
                new external_single_structure([
                    'name'      => new external_value(PARAM_ALPHANUMEXT, 'Field name to map to'),
                    'label'     => new external_value(PARAM_TEXT, 'Human name'),
                    'kind'      => new external_value(PARAM_ALPHA, 'standard | custom'),
                    'datatype'  => new external_value(PARAM_ALPHA, 'text, country, textarea, menu, checkbox, datetime, social…'),
                    'maxlength' => new external_value(PARAM_INT, 'Maximum length, 0 when not applicable'),
                    'options'   => new external_multiple_structure(new external_value(PARAM_RAW, 'Menu option')),
                    'supported' => new external_value(PARAM_BOOL, 'Whether set_user_fields can write this field'),
                ])
            ),
        ]);
    }
}
