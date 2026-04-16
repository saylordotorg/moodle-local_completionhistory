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
 * CLI script to audit achievement records against course_completions.
 *
 * Reports:
 * - Orphaned achievements (user or course no longer exists)
 * - Missing achievements (completions with no matching ledger entry)
 * - Hash integrity
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

// Resolve config.php path — handles symlinked plugin directories.
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

use local_completionhistory\local\ledger_service;

[$options, $unrecognised] = cli_get_params(
    [
        'help' => false,
    ],
    [
        'h' => 'help',
    ]
);

if ($options['help']) {
    $help = <<<EOT
Audit achievement records against course_completions.

Reports orphaned achievements, missing achievements, and hash integrity.

Options:
  -h, --help    Show this help

EOT;
    cli_writeln($help);
    exit(0);
}

cli_writeln(get_string('cli_audit_started', 'local_completionhistory'));
cli_writeln('');

// Count total achievements.
$totalachievements = $DB->count_records('local_completionhistory_achievement');
cli_writeln("Total achievement records: {$totalachievements}");

// Count total completions.
$totalcompletions = $DB->count_records_select('course_completions', 'timecompleted IS NOT NULL');
cli_writeln("Total course completions (with timecompleted): {$totalcompletions}");
cli_writeln('');

// Orphaned achievements: user no longer exists.
$sql = "SELECT COUNT(a.id)
          FROM {local_completionhistory_achievement} a
     LEFT JOIN {user} u ON u.id = a.userid
         WHERE u.id IS NULL AND a.userid != 0";
$orphanedusers = $DB->count_records_sql($sql);
cli_writeln("Achievements with deleted users (userid != 0, user gone): {$orphanedusers}");

// Achievements with anonymized users.
$anonymized = $DB->count_records('local_completionhistory_achievement', ['userid' => 0]);
cli_writeln("Achievements with anonymized users (userid = 0): {$anonymized}");

// Orphaned achievements: course no longer exists.
$sql = "SELECT COUNT(a.id)
          FROM {local_completionhistory_achievement} a
     LEFT JOIN {course} c ON c.id = a.courseid
         WHERE a.courseid IS NOT NULL AND c.id IS NULL";
$orphanedcourses = $DB->count_records_sql($sql);
cli_writeln("Achievements with deleted courses (courseid set, course gone): {$orphanedcourses}");

cli_writeln('');

// Missing achievements: completions without matching ledger entries.
$sql = "SELECT COUNT(cc.id)
          FROM {course_completions} cc
         WHERE cc.timecompleted IS NOT NULL
           AND NOT EXISTS (
               SELECT 1 FROM {local_completionhistory_achievement} a
                WHERE a.userid = cc.userid
                  AND a.courseid = cc.course
                  AND a.completiontime = cc.timecompleted
           )";
$missing = $DB->count_records_sql($sql);
cli_writeln("Completions missing from ledger: {$missing}");

// Duplicate hash check.
$sql = "SELECT source_event_hash, COUNT(*) AS cnt
          FROM {local_completionhistory_achievement}
      GROUP BY source_event_hash
        HAVING COUNT(*) > 1";
$duplicates = $DB->get_records_sql($sql);
$duplicatecount = count($duplicates);
cli_writeln("Duplicate event hashes: {$duplicatecount}");

cli_writeln('');

// Purge audit summary.
$purgecount = $DB->count_records('local_completionhistory_purge_audit');
cli_writeln("Purge audit records: {$purgecount}");

// Program association stats.
$progcount = $DB->count_records('local_completionhistory_ach_program');
cli_writeln("Program association records: {$progcount}");

$achievementswithprograms = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT achievementid) FROM {local_completionhistory_ach_program}"
);
cli_writeln("Achievements with program associations: {$achievementswithprograms}");

cli_writeln('');
cli_writeln(get_string('cli_audit_complete', 'local_completionhistory'));
