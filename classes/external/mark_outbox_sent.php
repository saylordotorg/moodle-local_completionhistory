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
 * External function to acknowledge outbox rows after the SIS has consumed them.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mark_outbox_sent extends external_api {

    /** Maximum acknowledgements accepted in one request. */
    private const MAX_IDS = 1000;

    /** Maximum retained delivery-error length. */
    private const MAX_ERROR_LENGTH = 4000;

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'ids'    => new external_multiple_structure(
                new external_value(PARAM_INT, 'Outbox row id')
            ),
            'status' => new external_value(PARAM_ALPHA, 'New status: sent, failed, or cancelled', VALUE_DEFAULT, 'sent'),
            'error'  => new external_value(PARAM_RAW, 'Error message to store (failed status only)', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(array $ids, string $status = 'sent', string $error = ''): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'ids'    => $ids,
            'status' => $status,
            'error'  => $error,
        ]);

        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);
        \local_completionhistory\local\security::require_enabled();
        require_capability('local/completionhistory:integrate', $systemcontext);

        if (count($params['ids']) > self::MAX_IDS) {
            throw new \invalid_parameter_exception('Too many outbox ids; maximum is ' . self::MAX_IDS . '.');
        }
        if (!in_array($params['status'], [
            outbox_service::STATUS_SENT,
            outbox_service::STATUS_FAILED,
            outbox_service::STATUS_CANCELLED,
        ], true)) {
            throw new \invalid_parameter_exception('Unknown outbox status.');
        }
        $error = \core_text::substr((string) $params['error'], 0, self::MAX_ERROR_LENGTH);

        $updated = outbox_service::mark_sent(
            $params['ids'],
            $params['status'],
            ($error !== '') ? $error : null
        );

        return ['updated' => $updated];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'updated' => new external_value(PARAM_INT, 'Number of outbox rows updated'),
        ]);
    }
}
