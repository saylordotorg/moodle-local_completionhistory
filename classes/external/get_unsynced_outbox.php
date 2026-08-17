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
 * External function to fetch unsynced outbox rows for SIS draining.
 *
 * The SIS calls this to pull the durable, ordered sync queue, processes each
 * row's payloadjson, then acknowledges via mark_outbox_sent.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_unsynced_outbox extends external_api {

    /** Hard ceiling on rows per call. */
    private const MAX_LIMIT = 1000;

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'limit'  => new external_value(PARAM_INT, 'Maximum rows to return', VALUE_DEFAULT, 500),
            'status' => new external_value(PARAM_ALPHA, 'Outbox status to fetch', VALUE_DEFAULT, 'pending'),
        ]);
    }

    public static function execute(int $limit = 500, string $status = 'pending'): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'limit'  => $limit,
            'status' => $status,
        ]);

        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);
        \local_completionhistory\local\security::require_enabled();
        require_capability('local/completionhistory:integrate', $systemcontext);

        $limit = max(1, min(self::MAX_LIMIT, (int) $params['limit']));
        $statuses = [
            outbox_service::STATUS_PENDING,
            outbox_service::STATUS_FAILED,
            outbox_service::STATUS_SENT,
            outbox_service::STATUS_CANCELLED,
        ];
        if (!in_array($params['status'], $statuses, true)) {
            throw new \invalid_parameter_exception('Unknown outbox status.');
        }

        $rows = outbox_service::get_unsynced($limit, $params['status']);

        $result = [];
        foreach ($rows as $r) {
            $result[] = [
                'id'          => (int) $r->id,
                'entitytype'  => $r->entitytype,
                'entityid'    => (int) $r->entityid,
                'payloadjson' => (string) ($r->payloadjson ?? ''),
                'status'      => $r->status,
                'retrycount'  => (int) $r->retrycount,
                'timecreated' => (int) $r->timecreated,
            ];
        }

        return $result;
    }

    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'id'          => new external_value(PARAM_INT, 'Outbox row id (pass to mark_outbox_sent to acknowledge)'),
                'entitytype'  => new external_value(PARAM_RAW, 'Entity type, e.g. achievement'),
                'entityid'    => new external_value(PARAM_INT, 'Source entity id'),
                'payloadjson' => new external_value(PARAM_RAW, 'JSON-encoded canonical payload'),
                'status'      => new external_value(PARAM_ALPHA, 'Delivery status'),
                'retrycount'  => new external_value(PARAM_INT, 'Delivery attempts so far'),
                'timecreated' => new external_value(PARAM_INT, 'Enqueue timestamp'),
            ])
        );
    }
}
