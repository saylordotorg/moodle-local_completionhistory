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

namespace local_completionhistory\task;

use local_completionhistory\local\ledger_service;

/**
 * Scheduled task: anonymize achievement rows whose user is already deleted.
 *
 * Closes gaps left by the user_deleted observer (late events, backfill against
 * stale course_completions, rows captured while gdpranonymize was disabled).
 *
 * Disabled by default — admins enable it in Site admin → Scheduled tasks.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reconcile_anonymization extends \core\task\scheduled_task {

    public function get_name(): string {
        return get_string('task_reconcile_anonymization', 'local_completionhistory');
    }

    public function execute(): void {
        if (!get_config('local_completionhistory', 'enabled')) {
            mtrace('local_completionhistory is disabled, skipping anonymization reconcile.');
            return;
        }
        if (!get_config('local_completionhistory', 'gdpranonymize')) {
            mtrace('gdpranonymize setting is off, skipping anonymization reconcile.');
            return;
        }

        mtrace('Reconciling anonymization for deleted users...');
        $stats = ledger_service::reconcile_deleted_users();
        mtrace("  Deleted users with academic rows: {$stats->candidates}");
        mtrace("  Achievement rows anonymized (exam attempts also scrubbed): {$stats->anonymized}");
    }
}
