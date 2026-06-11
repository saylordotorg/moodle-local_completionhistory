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
 * CLI: reconcile anonymization for achievement rows of deleted users.
 *
 * Finds achievement rows where the referenced Moodle user is flagged
 * deleted=1 (or the user record is gone entirely) and scrubs their PII.
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

use local_completionhistory\local\ledger_service;

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
Reconcile anonymization for achievement rows of deleted users.

Finds rows where the referenced user is flagged deleted or fully purged,
and scrubs userid + PII snapshot fields. Academic payload is preserved.

Options:
  -h, --help    Show this help
      --dryrun  Report what would change without writing

EOT);
    exit(0);
}

global $DB;

// Dry run: count without writing.
if ($options['dryrun']) {
    $sql = "SELECT COUNT(DISTINCT a.userid)
              FROM {local_completionhistory_achievement} a
         LEFT JOIN {user} u ON u.id = a.userid
             WHERE a.userid > 0
               AND (u.id IS NULL OR u.deleted = 1)";
    $users = $DB->count_records_sql($sql);

    $sql = "SELECT COUNT(a.id)
              FROM {local_completionhistory_achievement} a
         LEFT JOIN {user} u ON u.id = a.userid
             WHERE a.userid > 0
               AND (u.id IS NULL OR u.deleted = 1)";
    $rows = $DB->count_records_sql($sql);

    cli_writeln("DRY RUN — no changes written.");
    cli_writeln("Deleted users with achievement rows: {$users}");
    cli_writeln("Achievement rows that would be anonymized: {$rows}");
    exit(0);
}

$stats = ledger_service::reconcile_deleted_users();
cli_writeln("Deleted users with achievement rows: {$stats->candidates}");
cli_writeln("Achievement rows anonymized: {$stats->anonymized}");
