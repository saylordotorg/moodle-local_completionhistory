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
 * External function: set the end date (timeend) of a user's enrol_programs
 * allocation. The SIS owns time-to-completion policy (5y MBA / 4y others
 * from matriculation, SIS-66); this pushes the deadline into Moodle so the
 * allocation actually expires there too.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_program_deadline extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid'          => new external_value(PARAM_INT, 'Moodle user id'),
            'programidnumber' => new external_value(PARAM_RAW, 'enrol_programs program idnumber'),
            'timeend'         => new external_value(PARAM_INT, 'Unix timestamp the allocation should end'),
        ]);
    }

    public static function execute(int $userid, string $programidnumber, int $timeend): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'userid'          => $userid,
            'programidnumber' => $programidnumber,
            'timeend'         => $timeend,
        ]);

        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);
        require_capability('local/completionhistory:manage', $systemcontext);

        $program = $DB->get_record('enrol_programs_programs', ['idnumber' => $params['programidnumber']], '*', MUST_EXIST);
        $allocation = $DB->get_record('enrol_programs_allocations', [
            'programid' => $program->id,
            'userid'    => $params['userid'],
            'archived'  => 0,
        ]);
        if (!$allocation) {
            return ['ok' => false, 'warning' => 'No active allocation for this user/program.'];
        }

        $allocation->timeend = $params['timeend'];
        $allocation->timemodified = time();
        $DB->update_record('enrol_programs_allocations', $allocation);

        // Let enrol_programs recalculate the user's course enrolments if its API is present.
        if (class_exists('\\enrol_programs\\local\\allocation') && method_exists('\\enrol_programs\\local\\allocation', 'fix_user_enrolments')) {
            \enrol_programs\local\allocation::fix_user_enrolments($program->id, $params['userid']);
        }

        return ['ok' => true, 'warning' => ''];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok'      => new external_value(PARAM_BOOL, 'Whether the allocation end date was set'),
            'warning' => new external_value(PARAM_RAW, 'Non-fatal warning, if any'),
        ]);
    }
}
