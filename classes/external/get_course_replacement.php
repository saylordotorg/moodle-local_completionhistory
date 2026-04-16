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
use local_completionhistory\local\replacement_service;

/**
 * External function to get course replacement mapping.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_course_replacement extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'The old/retired course ID'),
        ]);
    }

    public static function execute(int $courseid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);

        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);
        require_capability('local/completionhistory:viewown', $systemcontext);

        $mapping = replacement_service::get_replacement($params['courseid']);

        if (!$mapping) {
            return [
                'found' => false,
                'oldcourseid' => $params['courseid'],
                'newcourseid' => 0,
                'oldcoursename' => '',
                'newcoursename' => '',
                'migrationrule' => '',
                'active' => 0,
            ];
        }

        return [
            'found' => true,
            'oldcourseid' => (int) $mapping->oldcourseid,
            'newcourseid' => (int) ($mapping->newcourseid ?? 0),
            'oldcoursename' => $mapping->oldcoursename_snapshot,
            'newcoursename' => $mapping->newcoursename_snapshot,
            'migrationrule' => $mapping->migrationrule,
            'active' => (int) $mapping->active,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'found' => new external_value(PARAM_BOOL, 'Whether a mapping was found'),
            'oldcourseid' => new external_value(PARAM_INT, 'Old course ID'),
            'newcourseid' => new external_value(PARAM_INT, 'Replacement course ID'),
            'oldcoursename' => new external_value(PARAM_RAW, 'Old course name snapshot'),
            'newcoursename' => new external_value(PARAM_RAW, 'New course name snapshot'),
            'migrationrule' => new external_value(PARAM_RAW, 'Migration rule'),
            'active' => new external_value(PARAM_INT, 'Whether mapping is active'),
        ]);
    }
}
