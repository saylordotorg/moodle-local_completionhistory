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

use local_completionhistory\local\backfill_runner;

/**
 * Runs one requested backfill or grade refresh (see backfill_runner).
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class run_backfill extends \core\task\adhoc_task {
    /**
     * Task name shown in the task logs.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_run_backfill', 'local_completionhistory');
    }

    /**
     * Do the run named in the custom data.
     */
    public function execute(): void {
        backfill_runner::execute((int) ($this->get_custom_data()->runid ?? 0));
    }
}
