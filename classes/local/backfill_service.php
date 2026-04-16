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

namespace local_completionhistory\local;

use stdClass;

/**
 * Service for backfilling achievement records from historical course completions.
 *
 * Scans course_completions for records that have no matching achievement row
 * and inserts them idempotently via ledger_service.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backfill_service {

    /**
     * Scan historical completions and backfill missing achievement rows.
     *
     * @param int $batchsize Maximum records to process.
     * @param bool $dryrun If true, count but do not insert.
     * @param int|null $userid Optionally limit to a single user.
     * @param int|null $courseid Optionally limit to a single course.
     * @param callable|null $progress Optional callback for progress reporting: function(string $message).
     * @return stdClass Stats object: scanned, inserted, skipped, errors, ambiguous_programs.
     */
    public static function scan_and_backfill(
        int $batchsize = 1000,
        bool $dryrun = false,
        ?int $userid = null,
        ?int $courseid = null,
        ?callable $progress = null
    ): stdClass {
        global $DB;

        $stats = new stdClass();
        $stats->scanned = 0;
        $stats->inserted = 0;
        $stats->skipped = 0;
        $stats->errors = 0;
        $stats->ambiguous_programs = 0;

        // Build the query for completions without matching achievements.
        $conditions = ['cc.timecompleted IS NOT NULL'];
        $params = [];

        if ($userid !== null) {
            $conditions[] = 'cc.userid = :userid';
            $params['userid'] = $userid;
        }

        if ($courseid !== null) {
            $conditions[] = 'cc.course = :courseid';
            $params['courseid'] = $courseid;
        }

        $where = implode(' AND ', $conditions);

        // Use core_completion as source_component so the hash matches what the
        // observer would have produced, ensuring proper deduplication.
        $sourcecomponent = 'core_completion';

        $sql = "SELECT cc.id, cc.userid, cc.course, cc.timecompleted, cc.timeenrolled, cc.timestarted
                  FROM {course_completions} cc
                 WHERE {$where}
              ORDER BY cc.timecompleted ASC";

        $completions = $DB->get_recordset_sql($sql, $params, 0, $batchsize);

        foreach ($completions as $completion) {
            $stats->scanned++;

            if ($dryrun) {
                // Check if it would be a new insert.
                $hash = ledger_service::compute_event_hash(
                    (int) $completion->userid,
                    (int) $completion->course,
                    (int) $completion->timecompleted,
                    $sourcecomponent
                );
                if ($DB->record_exists('local_completionhistory_achievement', ['source_event_hash' => $hash])) {
                    $stats->skipped++;
                } else {
                    $stats->inserted++; // Would be inserted.
                }
                continue;
            }

            try {
                $result = ledger_service::capture_achievement(
                    $completion,
                    $sourcecomponent,
                    'cli_backfill'
                );

                if ($result === null) {
                    $stats->skipped++;
                } else {
                    $stats->inserted++;
                    if ($progress) {
                        $progress("Captured achievement #{$result} for user {$completion->userid}, course {$completion->course}");
                    }
                }
            } catch (\Exception $e) {
                $stats->errors++;
                if ($progress) {
                    $progress("ERROR: user {$completion->userid}, course {$completion->course}: " . $e->getMessage());
                }
            }
        }

        $completions->close();

        return $stats;
    }
}
