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
 * CLI script to backfill achievement records from historical course completions.
 *
 * Usage:
 *   php backfill_achievements.php --dry-run
 *   php backfill_achievements.php --batch-size=500
 *   php backfill_achievements.php --userid=42
 *   php backfill_achievements.php --courseid=10 --verbose
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

// Resolve config.php path — handles symlinked plugin directories.
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

use local_completionhistory\local\backfill_service;

[$options, $unrecognised] = cli_get_params(
    [
        'dry-run'    => false,
        'batch-size' => 1000,
        'userid'     => null,
        'courseid'   => null,
        'verbose'    => false,
        'help'       => false,
    ],
    [
        'd' => 'dry-run',
        'b' => 'batch-size',
        'u' => 'userid',
        'c' => 'courseid',
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
Backfill achievement records from historical course completions.

Options:
  -d, --dry-run         Count records without inserting (default: false)
  -b, --batch-size=N    Maximum records to process (default: 1000)
  -u, --userid=N        Limit to a single user ID
  -c, --courseid=N      Limit to a single course ID
  -v, --verbose         Print each captured achievement
  -h, --help            Show this help

Examples:
  php backfill_achievements.php --dry-run
  php backfill_achievements.php --batch-size=500
  php backfill_achievements.php --userid=42 --verbose

EOT;
    cli_writeln($help);
    exit(0);
}

if (!get_config('local_completionhistory', 'enabled')) {
    cli_error('Completion History plugin is disabled. Enable it in Site Administration > Plugins > Local plugins > Completion History.');
}

$dryrun = (bool) $options['dry-run'];
$batchsize = (int) $options['batch-size'];
$userid = $options['userid'] !== null ? (int) $options['userid'] : null;
$courseid = $options['courseid'] !== null ? (int) $options['courseid'] : null;
$verbose = (bool) $options['verbose'];

cli_writeln(get_string('cli_backfill_started', 'local_completionhistory'));
if ($dryrun) {
    cli_writeln(get_string('cli_backfill_dryrun', 'local_completionhistory'));
}
cli_writeln("Batch size: {$batchsize}");
if ($userid !== null) {
    cli_writeln("Filtering by userid: {$userid}");
}
if ($courseid !== null) {
    cli_writeln("Filtering by courseid: {$courseid}");
}
cli_writeln('');

$progress = null;
if ($verbose) {
    $progress = function (string $message) {
        cli_writeln("  " . $message);
    };
}

$stats = backfill_service::scan_and_backfill($batchsize, $dryrun, $userid, $courseid, $progress);

cli_writeln('');
cli_writeln(get_string('cli_backfill_complete', 'local_completionhistory', $stats));
cli_writeln("  Scanned:            {$stats->scanned}");
cli_writeln("  Inserted:           {$stats->inserted}");
cli_writeln("  Skipped (existing): {$stats->skipped}");
cli_writeln("  Errors:             {$stats->errors}");
cli_writeln("  Ambiguous programs: {$stats->ambiguous_programs}");
