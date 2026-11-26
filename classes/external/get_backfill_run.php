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
use local_completionhistory\local\backfill_runner;

/**
 * Read one backfill run: its status, counts and last lines of output.
 *
 * Capabilities: the integration capability and local/completionhistory:runbackfill, both at system
 * context.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_backfill_run extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'runid' => new external_value(PARAM_INT, 'Run id, as request_backfill returned it'),
        ]);
    }

    /**
     * The run.
     *
     * @param int $runid Run id.
     * @return array
     */
    public static function execute(int $runid): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), ['runid' => $runid]);
        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);
        \local_completionhistory\local\security::require_enabled();
        require_capability('local/completionhistory:integrate', $systemcontext);
        require_capability('local/completionhistory:runbackfill', $systemcontext);

        $run = $DB->get_record(backfill_runner::TABLE, ['id' => $params['runid']], '*', MUST_EXIST);
        return backfill_runner::export($run);
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure(self::run_fields());
    }

    /**
     * The fields every run is returned with.
     *
     * @return array
     */
    public static function run_fields(): array {
        return [
            'id'           => new external_value(PARAM_INT, 'Run id'),
            'kind'         => new external_value(PARAM_ALPHA, 'achievements, exams or grades'),
            'dryrun'       => new external_value(PARAM_BOOL, 'Counted only, nothing written'),
            'course'       => new external_value(PARAM_RAW, 'Course ID number (or short name); empty for every course'),
            'status'       => new external_value(PARAM_ALPHA, 'queued, running, done or failed'),
            'summary'      => new external_value(PARAM_RAW, 'The counts, as JSON; empty until done'),
            'log'          => new external_value(PARAM_RAW, 'The last lines of output, or the error'),
            'timecreated'  => new external_value(PARAM_INT, 'When it was asked for'),
            'timestarted'  => new external_value(PARAM_INT, 'When cron started it; 0 if not yet'),
            'timefinished' => new external_value(PARAM_INT, 'When it finished; 0 if not yet'),
        ];
    }
}
