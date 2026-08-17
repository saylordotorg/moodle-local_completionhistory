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
 * External function to get purge audit records.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_purge_audit extends external_api {

    /** Hard ceiling on rows per call. */
    private const MAX_LIMIT = 500;
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'since' => new external_value(PARAM_INT, 'Return records created after this timestamp', VALUE_DEFAULT, 0),
            'limit' => new external_value(PARAM_INT, 'Maximum records', VALUE_DEFAULT, 100),
        ]);
    }

    public static function execute(int $since = 0, int $limit = 100): array {
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

        $records = $DB->get_records_select(
            'local_completionhistory_purge_audit',
            'timecreated > :since',
            ['since' => $since],
            'timecreated DESC',
            '*',
            0,
            $limit
        );
        $result = [];
        foreach ($records as $r) {
            $result[] = [
                'id' => (int) $r->id,
                'userid' => (int) $r->userid,
                'programid' => (int) ($r->programid ?? 0),
                'reason' => $r->reason,
                'detailsjson' => $r->detailsjson ?? '',
                'timecreated' => (int) $r->timecreated,
            ];
        }

        return $result;
    }

    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Audit record ID'),
                'userid' => new external_value(PARAM_INT, 'Affected user ID'),
                'programid' => new external_value(PARAM_INT, 'Program ID (0 if not applicable)'),
                'reason' => new external_value(PARAM_RAW, 'Purge reason'),
                'detailsjson' => new external_value(PARAM_RAW, 'JSON detail blob'),
                'timecreated' => new external_value(PARAM_INT, 'When the purge occurred'),
            ])
        );
    }
}
