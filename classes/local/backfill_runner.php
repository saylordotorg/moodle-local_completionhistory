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

use local_completionhistory\callbacks;
use local_completionhistory\task\run_backfill;
use stdClass;

/**
 * Backfills started from the admin page or by the SIS, and run by cron.
 *
 * WHY THIS EXISTS. The backfills were command-line scripts, and a hosted Moodle (degrees.saylor.org
 * is run by its host) gives the institution no command line. So a run is now a row in
 * local_completionhistory_backfill_run plus an ad hoc task: the admin page or the SIS asks, cron
 * does the work with no web timeout, and the row carries the counts and the last lines of output
 * back to whoever asked.
 *
 * THREE JOBS.
 *   achievements  Completions with no ledger row (the backfill_achievements CLI, every completion
 *                 rather than its first batch).
 *   exams         Finished attempts on tracked exam quizzes that were never recorded (the
 *                 backfill_exam_attempts CLI).
 *   grades        The newest ledger row for each learner and course brought into line with the
 *                 gradebook's course total, by the same rule and through the same write as the
 *                 user_graded observer. Needed after a course's "Grade to pass" is set: Moodle does
 *                 not regrade for a new pass mark, so no event ever revises the pass flags of
 *                 completions captured before it.
 *
 * ONE AT A TIME PER JOB. Asking for a job that is already queued or running returns that run
 * rather than queueing a second, so a double click or an SIS retry cannot run it twice at once.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backfill_runner {
    /** @var string[] The jobs a run can be. */
    public const KINDS = ['achievements', 'exams', 'grades'];

    /** @var string The run table. */
    public const TABLE = 'local_completionhistory_backfill_run';

    /** @var int How many of a run's last output lines are kept. */
    public const LOG_LINES = 200;

    /**
     * Ask for a run, or get the one of this job already queued or running.
     *
     * @param string $kind One of KINDS.
     * @param bool $dryrun Count only, write nothing.
     * @param int|null $courseid One course, or null for every course.
     * @param string $source 'web' or 'sis'.
     * @param int $userid Who asked.
     * @return array [stdClass $run, bool $existing]
     */
    public static function request(string $kind, bool $dryrun, ?int $courseid, string $source, int $userid): array {
        global $DB;
        if (!in_array($kind, self::KINDS, true)) {
            throw new \invalid_parameter_exception(get_string('backfillunknownkind', 'local_completionhistory', s($kind)));
        }
        $active = self::active_run($kind);
        if ($active) {
            return [$active, true];
        }
        $run = (object) [
            'kind' => $kind,
            'dryrun' => $dryrun ? 1 : 0,
            'courseid' => $courseid ?: null,
            'status' => 'queued',
            'source' => $source === 'sis' ? 'sis' : 'web',
            'requestedby' => $userid,
            'timecreated' => time(),
        ];
        $run->id = $DB->insert_record(self::TABLE, $run);

        $task = new run_backfill();
        $task->set_custom_data(['runid' => (int) $run->id]);
        \core\task\manager::queue_adhoc_task($task);
        return [$DB->get_record(self::TABLE, ['id' => $run->id], '*', MUST_EXIST), false];
    }

    /**
     * The run of this job that is queued or running, if its task still exists.
     *
     * A run whose ad hoc task has gone (deleted by hand, or lost with the database restore it was
     * queued before) would otherwise block its job for good, so it is marked failed instead.
     *
     * @param string $kind One of KINDS.
     * @return stdClass|null
     */
    public static function active_run(string $kind): ?stdClass {
        global $DB;
        $runs = $DB->get_records_select(
            self::TABLE,
            "kind = :kind AND status IN ('queued', 'running')",
            ['kind' => $kind],
            'id DESC'
        );
        if (!$runs) {
            return null;
        }
        $tasked = [];
        foreach (\core\task\manager::get_adhoc_tasks(run_backfill::class) as $task) {
            $tasked[(int) ($task->get_custom_data()->runid ?? 0)] = true;
        }
        $active = null;
        foreach ($runs as $run) {
            if (isset($tasked[(int) $run->id])) {
                $active = $active ?? $run;
                continue;
            }
            $DB->update_record(self::TABLE, (object) [
                'id' => $run->id,
                'status' => 'failed',
                'log' => trim(($run->log ?? '') . "\n" . 'Its task is no longer queued, so it will never run.'),
                'timefinished' => time(),
            ]);
        }
        return $active;
    }

    /**
     * Do a run. Called by the ad hoc task; a finished run is left alone.
     *
     * A failure is recorded on the run rather than thrown, so cron does not retry it behind the
     * back of whoever is watching the run; they can ask again.
     *
     * @param int $runid The run.
     */
    public static function execute(int $runid): void {
        global $DB;
        $run = $DB->get_record(self::TABLE, ['id' => $runid]);
        if (!$run || in_array($run->status, ['done', 'failed'], true)) {
            mtrace("Backfill run {$runid}: nothing to do.");
            return;
        }
        $DB->update_record(self::TABLE, (object) ['id' => $run->id, 'status' => 'running', 'timestarted' => time()]);

        $lines = [];
        $log = static function (string $message) use (&$lines): void {
            mtrace('  ' . $message);
            $lines[] = $message;
            if (count($lines) > self::LOG_LINES) {
                array_shift($lines);
            }
        };
        $dryrun = (bool) $run->dryrun;
        $courseid = $run->courseid ? (int) $run->courseid : null;
        mtrace("Backfill run {$run->id}: {$run->kind}" . ($dryrun ? ' (dry run)' : '') . ($courseid ? ", course {$courseid}" : ''));

        try {
            if (!get_config('local_completionhistory', 'enabled')) {
                throw new \moodle_exception('plugindisabled', 'local_completionhistory');
            }
            $summary = self::perform($run->kind, $dryrun, $courseid, $log);
            $status = 'done';
        } catch (\Throwable $e) {
            $log('ERROR: ' . $e->getMessage());
            $summary = null;
            $status = 'failed';
        }
        $DB->update_record(self::TABLE, (object) [
            'id' => $run->id,
            'status' => $status,
            'summaryjson' => $summary === null ? null : json_encode($summary),
            'log' => implode("\n", $lines),
            'timefinished' => time(),
        ]);
    }

    /**
     * Run one job and return its counts.
     *
     * @param string $kind One of KINDS.
     * @param bool $dryrun Count only.
     * @param int|null $courseid One course, or every course.
     * @param callable $log Line logger.
     * @return array Counts, by name.
     */
    private static function perform(string $kind, bool $dryrun, ?int $courseid, callable $log): array {
        switch ($kind) {
            case 'achievements':
                // Batch size 0: every completion, not just the oldest batch, which on a second run would
                // be the same rows again, all skipped.
                return (array) backfill_service::scan_and_backfill(0, $dryrun, null, $courseid, $log);
            case 'exams':
                $result = exam_backfill_service::run($dryrun, $courseid, null, 0, $log);
                unset($result['rows']);
                // Backfilled attempts carry old timestamps under new ids, which the SIS's incremental
                // sweep can never see: they arrive only on a full re-scan.
                $result['sisrescanneeded'] = !$dryrun && $result['recorded'] > 0;
                return $result;
            case 'grades':
                return self::refresh_grades($dryrun, $courseid, $log);
        }
        throw new \invalid_parameter_exception(get_string('backfillunknownkind', 'local_completionhistory', s($kind)));
    }

    /**
     * Bring the newest ledger row for each learner and course into line with the gradebook.
     *
     * The same decision (callbacks::grade_differs) and the same write
     * (callbacks::apply_grade_correction) as the user_graded observer, so the old value is kept
     * in the correction history and the change is queued for the SIS exactly as a regrade would be.
     * A course total that is now empty is left alone, as the observer leaves it.
     *
     * @param bool $dryrun Count only.
     * @param int|null $courseid One course, or every course.
     * @param callable|null $log Line logger.
     * @return array scanned, changed, passflagsset, unchanged, nograde, errors.
     */
    public static function refresh_grades(bool $dryrun, ?int $courseid = null, ?callable $log = null): array {
        global $DB;
        if (!get_config('local_completionhistory', 'capturegrades')) {
            throw new \moodle_exception('backfillgradecaptureoff', 'local_completionhistory');
        }
        $stats = ['scanned' => 0, 'changed' => 0, 'passflagsset' => 0, 'unchanged' => 0, 'nograde' => 0, 'errors' => 0];

        $where = 'a.userid > 0';
        $params = [];
        if ($courseid) {
            $where .= ' AND a.courseid = :courseid';
            $params['courseid'] = $courseid;
        }
        // Newest row per learner and course, as the observer picks it.
        $sql = "SELECT a.id, a.userid, a.courseid, a.grade_decimal, a.grade_passed
                  FROM {local_completionhistory_achievement} a
                 WHERE {$where}
                   AND NOT EXISTS (
                       SELECT 1
                         FROM {local_completionhistory_achievement} b
                        WHERE b.userid = a.userid
                          AND b.courseid = a.courseid
                          AND (b.completiontime > a.completiontime
                               OR (b.completiontime = a.completiontime AND b.id > a.id))
                   )
              ORDER BY a.id";
        $rows = $DB->get_recordset_sql($sql, $params);
        foreach ($rows as $row) {
            $stats['scanned']++;
            $snapshot = grade_snapshot_service::get_course_total((int) $row->userid, (int) $row->courseid);
            if ($snapshot === null) {
                $stats['nograde']++;
                continue;
            }
            if (!callbacks::grade_differs($row, $snapshot)) {
                $stats['unchanged']++;
                continue;
            }
            $stats['changed']++;
            if ($row->grade_passed === null && $snapshot->passed !== null) {
                $stats['passflagsset']++;
            }
            if ($log) {
                $log(sprintf(
                    'achievement %d (user %d, course %d): grade %s -> %s, passed %s -> %s',
                    $row->id,
                    $row->userid,
                    $row->courseid,
                    $row->grade_decimal === null ? 'none' : (float) $row->grade_decimal,
                    (float) $snapshot->finalgrade,
                    $row->grade_passed === null ? 'none' : (int) $row->grade_passed,
                    $snapshot->passed === null ? 'none' : (int) $snapshot->passed
                ));
            }
            if ($dryrun) {
                continue;
            }
            $transaction = $DB->start_delegated_transaction();
            try {
                callbacks::apply_grade_correction((int) $row->id, $snapshot);
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                // One learner's failure is counted and logged; the rest of the run carries on.
                $stats['errors']++;
                $stats['changed']--;
                if ($log) {
                    $log("ERROR: achievement {$row->id}: " . $e->getMessage());
                }
                try {
                    $transaction->rollback($e);
                } catch (\Throwable $rethrown) {
                    // A delegated rollback rethrows what it was given; it has been recorded above.
                    unset($rethrown);
                }
            }
        }
        $rows->close();
        return $stats;
    }

    /**
     * A course id from what a person or the SIS typed: its ID number, or failing that its short name.
     *
     * @param string $ref ID number or short name; blank for every course.
     * @return int|null
     */
    public static function resolve_course(string $ref): ?int {
        global $DB;
        $ref = trim($ref);
        if ($ref === '') {
            return null;
        }
        $id = $DB->get_field('course', 'id', ['idnumber' => $ref]);
        if (!$id) {
            $id = $DB->get_field('course', 'id', ['shortname' => $ref]);
        }
        if (!$id || (int) $id === SITEID) {
            throw new \moodle_exception('backfillunknowncourse', 'local_completionhistory', '', s($ref));
        }
        return (int) $id;
    }

    /**
     * A run as the web services return it.
     *
     * @param stdClass $run The row.
     * @return array
     */
    public static function export(stdClass $run): array {
        global $DB;
        $course = $run->courseid ? $DB->get_record('course', ['id' => $run->courseid], 'idnumber, shortname') : null;
        return [
            'id' => (int) $run->id,
            'kind' => $run->kind,
            'dryrun' => (bool) $run->dryrun,
            'course' => $course ? ($course->idnumber !== '' ? $course->idnumber : $course->shortname) : '',
            'status' => $run->status,
            'summary' => (string) ($run->summaryjson ?? ''),
            'log' => (string) ($run->log ?? ''),
            'timecreated' => (int) $run->timecreated,
            'timestarted' => (int) ($run->timestarted ?? 0),
            'timefinished' => (int) ($run->timefinished ?? 0),
        ];
    }
}
