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
 * Ask for a backfill or grade refresh to be run by cron, from the SIS.
 *
 * The same jobs as the admin page's buttons (see backfill_runner): the site's command line is not
 * needed, and the run's progress is read back with get_backfill_run. Asking for a job already queued
 * or running returns that run, flagged as existing, rather than queueing another.
 *
 * Capabilities: the integration capability and local/completionhistory:runbackfill, both at system
 * context.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class request_backfill extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'kind'   => new external_value(PARAM_ALPHA, 'achievements, exams or grades'),
            'commit' => new external_value(PARAM_BOOL, 'Write; false counts only (a dry run)', VALUE_DEFAULT, false),
            'course' => new external_value(PARAM_RAW, 'Course ID number or short name; empty for every course', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Queue the run, or return the one already queued or running.
     *
     * @param string $kind achievements, exams or grades.
     * @param bool $commit Write rather than count.
     * @param string $course Course ID number or short name, or empty.
     * @return array
     */
    public static function execute(string $kind, bool $commit = false, string $course = ''): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'kind'   => $kind,
            'commit' => $commit,
            'course' => $course,
        ]);
        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);
        \local_completionhistory\local\security::require_enabled();
        require_capability('local/completionhistory:integrate', $systemcontext);
        require_capability('local/completionhistory:runbackfill', $systemcontext);

        $courseid = backfill_runner::resolve_course($params['course']);
        [$run, $existing] = backfill_runner::request(
            $params['kind'],
            !$params['commit'],
            $courseid,
            'sis',
            (int) $USER->id
        );
        return backfill_runner::export($run) + ['existing' => $existing];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure(get_backfill_run::run_fields() + [
            'existing' => new external_value(PARAM_BOOL, 'An earlier run of this job, still queued or running, not a new one'),
        ]);
    }
}
