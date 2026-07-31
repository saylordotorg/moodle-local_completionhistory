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
 * Static check: does get_flagged_attempts' query supply every attempt-row field
 * that flag_service::matches() reads?
 *
 * WHY A DEDICATED CHECK. This is the one way the web service can be wrong without
 * being visibly broken. PHP resolves a missing property through `?? 0`, so a
 * dropped column does not warn — the affected flag type simply returns false for
 * every row, and the endpoint reports "0 flagged" indefinitely while looking
 * perfectly healthy. There is no error to notice and no test that fails, because
 * the shape of the response is unchanged.
 *
 * Deliberately standalone: no Moodle bootstrap, no database, no PHPUnit. It reads
 * two files and compares them, so it runs anywhere PHP does — including CI on a
 * checkout with no Moodle tree.
 *
 * Usage:  php tests/static/check_flag_field_coverage.php
 * Exit:   0 = every field covered, 1 = a flag type would silently never fire.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$root    = dirname(__DIR__, 2);
$svcPath = $root . '/classes/local/flag_service.php';
$extPath = $root . '/classes/external/get_flagged_attempts.php';

foreach ([$svcPath, $extPath] as $p) {
    if (!is_readable($p)) {
        fwrite(STDERR, "cannot read {$p}\n");
        exit(2);
    }
}

$svc = file_get_contents($svcPath);
$ext = file_get_contents($extPath);

// Scope strictly to the evaluation path: matches() through to get_presets().
// Sweeping the whole file yields false positives — enabled, timecreated and
// timemodified are ASSIGNED to a flag-definition row inside load_presets() and
// have nothing to do with attempt rows. A check that cries wolf gets ignored, so
// its scope matters as much as its logic.
$start = strpos($svc, 'public static function matches');
$end   = strpos($svc, 'public static function get_presets');
if ($start === false || $end === false || $end <= $start) {
    fwrite(STDERR, "flag_service.php no longer has the expected matches()..get_presets() shape; "
        . "this check needs updating rather than deleting.\n");
    exit(2);
}
$body = substr($svc, $start, $end - $start);

// Reads only. `$row->foo = ...` is a write and says nothing about what the query
// must supply.
preg_match_all('/\$row->([a-z_]+)/', $body, $m);
$needed = array_values(array_unique(array_filter($m[1], static function ($f) use ($body) {
    return !preg_match('/\$row->' . preg_quote($f, '/') . '\s*=[^=]/', $body);
})));
sort($needed);

// Fields the query makes available, as a plain ea.* column or an alias.
preg_match_all('/(?:AS\s+([a-z_]+))|(?:ea\.([a-z_]+))/i', $ext, $sm);
$provided = array_values(array_unique(array_filter(array_merge($sm[1], $sm[2]))));

$missing = array_values(array_diff($needed, $provided));

echo "flag_service::matches() reads these attempt-row fields:\n";
foreach ($needed as $f) {
    printf("  %-20s %s\n", $f, in_array($f, $provided, true) ? 'provided' : '*** MISSING ***');
}

// Per-type dependency map, stated explicitly. If a new flag type is added to
// flag_service without a line here, that omission is itself worth noticing.
$deps = [
    'fast_completion'   => ['duration'],
    'duration_exact'    => ['duration'],
    'score_range'       => ['grade_decimal'],
    'duplicate_account' => ['user_firstname', 'user_lastname', 'user_email'],
    'new_account'       => ['user_timecreated', 'timetaken'],
];

echo "\nper flag type:\n";
$broken = [];
foreach ($deps as $type => $fields) {
    $lack = array_diff($fields, $provided);
    printf("  %-20s %s\n", $type,
        $lack ? '*** would never fire: needs ' . implode(', ', $lack) . ' ***' : 'evaluable');
    if ($lack) {
        $broken[] = $type;
    }
}

// Every TYPE_ constant in flag_service should appear in the map above, or a new
// flag type could ship with no coverage check at all.
preg_match_all("/const TYPE_[A-Z_]+\s*=\s*'([a-z_]+)'/", $svc, $tm);
$untracked = array_diff($tm[1], array_keys($deps));
if ($untracked) {
    echo "\n*** flag types with no dependency entry in this check: "
        . implode(', ', $untracked) . " ***\n";
}

if ($missing || $broken || $untracked) {
    echo "\nFAIL\n";
    exit(1);
}
echo "\nPASS: every field the evaluator reads is selected, and all flag types are covered.\n";
exit(0);
