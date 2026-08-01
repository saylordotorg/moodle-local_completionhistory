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

/**
 * CLI: record historical quiz attempts that were never captured as exam attempts.
 *
 * The attempt observer fires on submission and declines any quiz that is not a tracked
 * exam for its course, so configuring a course's exam track captures nothing that
 * happened before the configuration existed. This walks the finished quiz attempts on
 * currently-tracked quizzes and records the ones that are missing.
 *
 * DEFAULTS TO A DRY RUN. Pass --commit to write.
 *
 * IMPORTANT, AND EASY TO MISS: backfilled rows carry HISTORICAL timetaken values but NEW
 * row ids, and the SIS pages this feed on a keyset cursor over (timetaken, id) with a
 * strictly-greater predicate. Once that cursor has advanced past a backfilled attempt's
 * timestamp, the incremental sweep will never return it. A backfill therefore has to be
 * followed by a FULL re-scan on the SIS side — `POST /exams/sync-attempts {"restart":true}`
 * — or the rows sit in Moodle and never reach the SIS. Ingestion is an upsert on the
 * attempt id, so the re-scan corrects rather than duplicates.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

$configpath = __DIR__ . '/../../../config.php';
if (!file_exists($configpath)) {
    // Fallback: walk up from the working directory.
    $configpath = getcwd();
    while ($configpath !== '/' && !file_exists($configpath . '/config.php')) {
        $configpath = dirname($configpath);
    }
    $configpath .= '/config.php';
}
require($configpath);
require_once($CFG->libdir . '/clilib.php');

use local_completionhistory\local\exam_backfill_service;

[$options, $unrecognised] = cli_get_params(
    [
        'commit'   => false,
        'courseid' => null,
        'userid'   => null,
        'limit'    => 0,
        'verbose'  => false,
        'help'     => false,
    ],
    [
        'c' => 'courseid',
        'u' => 'userid',
        'l' => 'limit',
        'v' => 'verbose',
        'h' => 'help',
    ]
);

if ($unrecognised) {
    $unrecognised = implode(PHP_EOL . '  ', $unrecognised);
    cli_error("Unrecognised options:\n  {$unrecognised}\n\nUse --help for usage information.");
}

if ($options['help']) {
    $help = <<<EOT
Record historical quiz attempts that were never captured as exam attempts.

Only attempts on quizzes that are CURRENTLY configured as a tracked exam for their
course are considered — the same condition the attempt observer applies. Grade, pass
threshold, track and duration are derived exactly as the observer derives them, so a
backfilled row is indistinguishable from one the observer wrote.

Dry run by default. Nothing is written unless --commit is passed.

Options:
      --commit          Actually write the rows (default: dry run)
  -c, --courseid=N      Limit to a single course ID
  -u, --userid=N        Limit to a single user ID
  -l, --limit=N         Process at most N candidate attempts (0 = all)
  -v, --verbose         Print a line per candidate
  -h, --help            Show this help

Examples:
  php backfill_exam_attempts.php --verbose
  php backfill_exam_attempts.php --courseid=3 --verbose
  php backfill_exam_attempts.php --courseid=3 --commit

AFTERWARDS, on the SIS side: run a FULL re-scan, not an incremental sweep.
Backfilled rows have old timestamps and new ids, and the SIS keyset cursor is
strictly-greater on (timetaken, id) — so an incremental sweep skips them for good.

  POST /exams/sync-attempts  {"restart": true}

Ingestion upserts on the attempt id, so a re-scan corrects rather than duplicates.

EOT;
    cli_writeln($help);
    exit(0);
}

$commit = (bool) $options['commit'];
$courseid = $options['courseid'] !== null ? (int) $options['courseid'] : null;
$userid = $options['userid'] !== null ? (int) $options['userid'] : null;
$limit = (int) $options['limit'];
$verbose = (bool) $options['verbose'];

cli_heading($commit ? 'Backfilling exam attempts (WRITING)' : 'Backfilling exam attempts (dry run)');

// A configuration table with no rows means nothing is a tracked exam, so there is
// nothing this can do. Say that plainly rather than reporting a successful no-op —
// "0 recorded" reads like "nothing was missing", which is a different claim.
$configured = $DB->count_records('local_completionhistory_course_exam_config');
if ($configured === 0) {
    cli_writeln('');
    cli_writeln('No course has an exam configuration, so no quiz is a tracked exam and');
    cli_writeln('there is nothing to back-fill. Configure a course exam first');
    cli_writeln('(Site administration > Plugins > Local > Completion history > Course exams),');
    cli_writeln('then run this again.');
    exit(0);
}
cli_writeln("courses with an exam configuration: {$configured}");

$log = $verbose ? function ($msg) { cli_writeln('  ' . $msg); } : null;
$result = exam_backfill_service::run($commit ? false : true, $courseid, $userid, $limit, $log);

cli_writeln('');
cli_writeln(sprintf('scanned:  %d finished attempts on tracked exam quizzes', $result['scanned']));
cli_writeln(sprintf('%s: %d', $commit ? 'recorded' : 'would record', $result['recorded']));
cli_writeln(sprintf('skipped:  %d (already recorded, or no submission time)', $result['skipped']));
cli_writeln(sprintf('no grade: %d of those recorded had no usable grade (stored as null, not zero)',
    $result['nograde']));

if (!$commit && $result['recorded'] > 0) {
    cli_writeln('');
    cli_writeln('Dry run — nothing written. Re-run with --commit to record these.');
}
if ($commit && $result['recorded'] > 0) {
    cli_writeln('');
    cli_writeln('NOW RE-SCAN ON THE SIS SIDE, or these rows will never reach it:');
    cli_writeln('  POST /exams/sync-attempts  {"restart": true}');
    cli_writeln('Backfilled rows carry old timestamps with new ids, and the SIS cursor is');
    cli_writeln('strictly-greater on (timetaken, id) — an incremental sweep skips them.');
}

exit(0);
