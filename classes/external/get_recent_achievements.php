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
use local_completionhistory\local\outbox_service;

/**
 * External function to get recent achievements across all users.
 *
 * Intended for SIS sync — returns achievements created since a given timestamp.
 * The row shape is the canonical achievement payload (see
 * outbox_service::build_achievement_payload), shared with the outbox queue.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_recent_achievements extends external_api {

    /** Hard ceiling on rows per call. */
    private const MAX_LIMIT = 1000;

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
        \local_completionhistory\local\security::require_enabled();
        require_capability('local/completionhistory:integrate', $systemcontext);

        $since = max(0, (int) $params['since']);
        $limit = max(1, min(self::MAX_LIMIT, (int) $params['limit']));

        $achievements = $DB->get_records_select(
            'local_completionhistory_achievement',
            'timecreated > :since',
            ['since' => $since],
            'timecreated ASC',
            '*',
            0,
            $limit
        );

        $result = [];
        foreach ($achievements as $a) {
            // Use the shared canonical builder so the pull API and the outbox
            // queue always emit an identical payload shape.
            $result[] = outbox_service::build_achievement_payload($a);
        }

        return $result;
    }

    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'id'              => new external_value(PARAM_INT, 'Achievement id'),
                'ledgeruuid'      => new external_value(PARAM_RAW, 'Stable external UUID'),
                'userid'          => new external_value(PARAM_INT, 'Moodle user id (0 if anonymized)'),
                'useridnumber'    => new external_value(PARAM_RAW, 'User id number snapshot'),
                'firstname'       => new external_value(PARAM_RAW, 'First name snapshot'),
                'lastname'        => new external_value(PARAM_RAW, 'Last name snapshot'),
                'email'           => new external_value(PARAM_RAW, 'Email snapshot'),
                'courseid'        => new external_value(PARAM_INT, 'Course id (0 if deleted)'),
                'courseidnumber'  => new external_value(PARAM_RAW, 'Course id number snapshot'),
                'courseshortname' => new external_value(PARAM_RAW, 'Course short name snapshot'),
                'coursename'      => new external_value(PARAM_RAW, 'Course full name snapshot'),
                'completiontime'  => new external_value(PARAM_INT, 'Completion timestamp'),
                'enrolledtime'    => new external_value(PARAM_INT, 'Earliest enrolment timestamp (0 if unknown)'),
                'grade'           => new external_value(PARAM_FLOAT, 'Final grade', VALUE_OPTIONAL),
                'gradepassed'     => new external_value(PARAM_INT, '1=passed, 0=failed', VALUE_OPTIONAL),
                'gradesource'     => new external_value(PARAM_RAW, 'Grade source'),
                'examtrack'       => new external_value(PARAM_RAW, 'Exam track: program_final|direct_credit|certificate'),
                'attemptsused'    => new external_value(PARAM_INT, 'Attempts used on completing track', VALUE_OPTIONAL),
                'attemptsallowed' => new external_value(PARAM_INT, 'Attempts allowed (0 = unlimited)', VALUE_OPTIONAL),
                'artifacturl'     => new external_value(PARAM_RAW, 'Certificate/transcript URL'),
                'artifactstorage' => new external_value(PARAM_RAW, 'Artifact storage marker'),
                'artifactcode'    => new external_value(PARAM_RAW, 'Certificate code when the artifact is a Moodle certificate'),
                'sourcecomponent' => new external_value(PARAM_RAW, 'Capture source component'),
                'sourceevent'     => new external_value(PARAM_RAW, 'Capture source event'),
                'sourcesite'      => new external_value(PARAM_RAW, 'Identifier of the Moodle site that produced this record'),
                'timecreated'     => new external_value(PARAM_INT, 'Record creation timestamp'),
                'programs'        => new external_multiple_structure(
                    new external_single_structure([
                        'programid'       => new external_value(PARAM_INT, 'Program id'),
                        'programname'     => new external_value(PARAM_RAW, 'Program name snapshot'),
                        'programidnumber' => new external_value(PARAM_RAW, 'Program id number snapshot'),
                    ])
                ),
            ])
        );
    }
}
