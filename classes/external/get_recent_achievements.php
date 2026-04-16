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

/**
 * External function to get recent achievements across all users.
 *
 * Intended for SIS sync — returns achievements created since a given timestamp.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_recent_achievements extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'since' => new external_value(PARAM_INT, 'Return achievements created after this timestamp'),
            'limit' => new external_value(PARAM_INT, 'Maximum records', VALUE_DEFAULT, 500),
        ]);
    }

    public static function execute(int $since, int $limit = 500): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'since' => $since,
            'limit' => $limit,
        ]);

        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);
        require_capability('local/completionhistory:viewall', $systemcontext);

        $achievements = $DB->get_records_select(
            'local_completionhistory_achievement',
            'timecreated > :since',
            ['since' => $params['since']],
            'timecreated ASC',
            '*',
            0,
            $params['limit']
        );

        $result = [];
        foreach ($achievements as $a) {
            $programs = $DB->get_records('local_completionhistory_ach_program', ['achievementid' => $a->id]);
            $programdata = [];
            foreach ($programs as $p) {
                $programdata[] = [
                    'programid' => (int) $p->programid,
                    'programname' => $p->programname_snapshot,
                    'programidnumber' => $p->programidnumber_snapshot ?? '',
                ];
            }

            $result[] = [
                'id' => (int) $a->id,
                'ledgeruuid' => $a->ledgeruuid,
                'userid' => (int) $a->userid,
                'useridnumber' => $a->useridnumber_snapshot ?? '',
                'courseid' => (int) ($a->courseid ?? 0),
                'courseidnumber' => $a->courseidnumber_snapshot ?? '',
                'coursename' => $a->coursename_snapshot,
                'completiontime' => (int) $a->completiontime,
                'grade' => $a->grade_decimal !== null ? (float) $a->grade_decimal : null,
                'gradepassed' => $a->grade_passed !== null ? (int) $a->grade_passed : null,
                'sourcecomponent' => $a->source_component,
                'timecreated' => (int) $a->timecreated,
                'programs' => $programdata,
            ];
        }

        return $result;
    }

    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Achievement ID'),
                'ledgeruuid' => new external_value(PARAM_RAW, 'UUID'),
                'userid' => new external_value(PARAM_INT, 'User ID'),
                'useridnumber' => new external_value(PARAM_RAW, 'User ID number snapshot'),
                'courseid' => new external_value(PARAM_INT, 'Course ID'),
                'courseidnumber' => new external_value(PARAM_RAW, 'Course ID number snapshot'),
                'coursename' => new external_value(PARAM_RAW, 'Course name snapshot'),
                'completiontime' => new external_value(PARAM_INT, 'Completion timestamp'),
                'grade' => new external_value(PARAM_FLOAT, 'Final grade', VALUE_OPTIONAL),
                'gradepassed' => new external_value(PARAM_INT, '1=passed, 0=failed', VALUE_OPTIONAL),
                'sourcecomponent' => new external_value(PARAM_RAW, 'Source component'),
                'timecreated' => new external_value(PARAM_INT, 'Record creation timestamp'),
                'programs' => new external_multiple_structure(
                    new external_single_structure([
                        'programid' => new external_value(PARAM_INT, 'Program ID'),
                        'programname' => new external_value(PARAM_RAW, 'Program name snapshot'),
                        'programidnumber' => new external_value(PARAM_RAW, 'Program ID number snapshot'),
                    ])
                ),
            ])
        );
    }
}
