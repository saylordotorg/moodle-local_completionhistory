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

use local_completionhistory\local\backfill_service;

/**
 * Scheduled task to reconcile the achievement ledger.
 *
 * Catches any completions that slipped through if the observer failed
 * or was temporarily disabled. Runs daily by default.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reconcile_ledger extends \core\task\scheduled_task {

    /**
     * Get task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_reconcile_ledger', 'local_completionhistory');
    }

    /**
     * Execute the task.
     */
    public function execute(): void {
        if (!get_config('local_completionhistory', 'enabled')) {
            mtrace('local_completionhistory is disabled, skipping reconciliation.');
            return;
        }

        $batchsize = (int) get_config('local_completionhistory', 'backfillbatchsize');
        if ($batchsize <= 0) {
            $batchsize = 500;
        }

        mtrace("Reconciling achievement ledger (batch size: {$batchsize})...");

        $progress = function (string $message) {
            mtrace("  " . $message);
        };

        $stats = backfill_service::scan_and_backfill($batchsize, false, null, null, $progress);

        mtrace("Reconciliation complete:");
        mtrace("  Scanned: {$stats->scanned}");
        mtrace("  Inserted: {$stats->inserted}");
        mtrace("  Skipped: {$stats->skipped}");
        mtrace("  Errors: {$stats->errors}");
    }
}
