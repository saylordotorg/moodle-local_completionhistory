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
 * CLI: normalize aggregated exam attempt rows into per-attempt rows.
 *
 * Some seeded / hand-inserted rows store only a single summary row per
 * (userid, courseid, exam_track) with attempt_number = N (e.g. 3 of ∞).
 * The exam attempt log expects one DB row per actual attempt.
 *
 * For every group (userid, courseid, exam_track) this script inspects the
 * set of existing attempt_number values and inserts synthetic predecessor
 * rows for any missing numbers in 1..max. Predecessors are marked as
 * failed (grade_passed = 0) with null grade_decimal and backdated
 * timetaken one day earlier per step. The original top row is left
 * untouched, so its grade/achievement link is preserved.
 *
 * Use --dryrun to preview without writing.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

$configpath = __DIR__ . '/../../../config.php';
if (!file_exists($configpath)) {
    $configpath = getcwd();
    while ($configpath !== '/' && !file_exists($configpath . '/config.php')) {
        $configpath = dirname($configpath);
    }
    $configpath .= '/config.php';
}
require($configpath);
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params(
    [
        'help'   => false,
        'dryrun' => false,
    ],
    [
        'h' => 'help',
    ]
);

if ($options['help']) {
    cli_writeln(<<<EOT
Normalize aggregated exam attempt rows into per-attempt rows.

For every (userid, courseid, exam_track) group, inserts synthetic
predecessor rows for any missing attempt_number values in 1..max.

Options:
  -h, --help    Show this help
      --dryrun  Report what would be inserted without writing

EOT);
    exit(0);
}

global $DB;

$dryrun = (bool) $options['dryrun'];
if ($dryrun) {
    cli_writeln("DRY RUN — no rows will be inserted.");
}

// One row per distinct (userid, courseid, exam_track) group with the max
// attempt_number in that group. Filter to groups where max > 1 — groups of
// just one row where attempt_number = 1 are already correctly shaped.
$sql = "SELECT userid, courseid, exam_track, MAX(attempt_number) AS maxn
          FROM {local_completionhistory_exam_attempt}
      GROUP BY userid, courseid, exam_track
        HAVING MAX(attempt_number) > 1";
$groups = $DB->get_records_sql($sql);

$stats = [
    'groups_scanned' => 0,
    'groups_fixed'   => 0,
    'rows_inserted'  => 0,
];

$day = 86400;
$now = time();

foreach ($groups as $g) {
    $stats['groups_scanned']++;
    $userid    = (int) $g->userid;
    $courseid  = (int) $g->courseid;
    $track     = $g->exam_track;
    $maxn      = (int) $g->maxn;

    // Load existing attempt_numbers + template row (use the max one as template).
    $rows = $DB->get_records('local_completionhistory_exam_attempt',
        ['userid' => $userid, 'courseid' => $courseid, 'exam_track' => $track],
        'attempt_number ASC'
    );
    $existing = [];
    $template = null;
    foreach ($rows as $r) {
        $existing[(int) $r->attempt_number] = true;
        if ((int) $r->attempt_number === $maxn) {
            $template = $r;
        }
    }
    if (!$template) {
        continue; // Should not happen given the HAVING clause, but safe.
    }

    $toinsert = [];
    for ($i = 1; $i < $maxn; $i++) {
        if (!isset($existing[$i])) {
            $toinsert[] = $i;
        }
    }
    if (empty($toinsert)) {
        continue;
    }

    $stats['groups_fixed']++;

    foreach ($toinsert as $n) {
        $row                         = new stdClass();
        $row->userid                 = $userid;
        $row->courseid               = $courseid;
        $row->quizid                 = $template->quizid;
        $row->exam_track             = $track;
        $row->attempt_number         = $n;
        $row->attempts_allowed       = $template->attempts_allowed;
        $row->grade_decimal          = null;
        $row->grade_passed           = 0; // Must have failed — otherwise no retry.
        $row->resulted_in_completion = 0;
        $row->achievementid          = null;
        $row->timetaken              = max(0, (int) $template->timetaken - ($maxn - $n) * $day);
        $row->timecreated            = $now;

        if ($dryrun) {
            cli_writeln("  Would insert: userid={$userid} course={$courseid} track={$track} attempt={$n}");
        } else {
            $DB->insert_record('local_completionhistory_exam_attempt', $row);
        }
        $stats['rows_inserted']++;
    }
}

cli_writeln('');
cli_writeln("Groups scanned (max attempt > 1): {$stats['groups_scanned']}");
cli_writeln("Groups with gaps:                 {$stats['groups_fixed']}");
cli_writeln($dryrun
    ? "Rows that WOULD be inserted:       {$stats['rows_inserted']}"
    : "Rows inserted:                    {$stats['rows_inserted']}");
