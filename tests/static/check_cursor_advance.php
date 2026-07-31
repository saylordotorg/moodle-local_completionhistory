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
 * Static check for the get_flagged_attempts paging cursor.
 *
 * WHAT THIS GUARDS. The first version of the endpoint paged on
 * `timetaken >= :since`. timetaken has one-second resolution and is not unique, so
 * when a full page of attempts shares one timestamp — simultaneous submissions, or
 * a bulk import via cli/normalize_exam_attempts.php — that predicate returns the
 * same lowest ids on every call. `truncated` stays true, the caller keeps calling,
 * and every attempt after the first page on that timestamp is NEVER REACHED. No
 * error, no empty response; just a sweep that runs forever and silently misses
 * flagged exams.
 *
 * The fix is a keyset cursor on (timetaken, id). This exercises the advance step
 * against the exact scenario that broke, plus the empty-page case where the cursor
 * must hold still rather than resetting to zero.
 *
 * Standalone by necessity as much as by choice: next_cursor() is pure, so it can be
 * verified with no Moodle bootstrap, no database and no PHPUnit — which is the only
 * way any of this is checkable on a bare checkout.
 *
 * Usage:  php tests/static/check_cursor_advance.php
 * Exit:   0 = all cases pass, 1 = a case failed.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$root = dirname(__DIR__, 2);
$src  = $root . '/classes/external/get_flagged_attempts.php';
if (!is_readable($src)) {
    fwrite(STDERR, "cannot read {$src}\n");
    exit(2);
}

// Extract next_cursor() and eval it in isolation. The file itself cannot be
// included — it extends core_external\external_api, which needs Moodle. Pulling
// out the one pure method keeps this runnable anywhere.
$code = file_get_contents($src);
if (!preg_match('/public static function next_cursor\(array \$rows, int \$sincets, int \$sinceid\): array \{(.+?)\n    \}/s',
        $code, $m)) {
    fwrite(STDERR, "next_cursor() not found in the expected shape; update this check rather than deleting it.\n");
    exit(2);
}
eval('function next_cursor(array $rows, int $sincets, int $sinceid): array {' . $m[1] . "\n}");

/** Build a row list from [id, timetaken] pairs, in query order. */
$rows = static function (array $pairs): array {
    $out = [];
    foreach ($pairs as [$id, $ts]) {
        $o = new stdClass();
        $o->id = $id;
        $o->timetaken = $ts;
        // Keyed by id, mirroring $DB->get_records_sql().
        $out[$id] = $o;
    }
    return $out;
};

$cases = [
    [
        'name'   => 'ordinary page advances to the last row',
        'rows'   => $rows([[10, 1000], [11, 1001], [12, 1002]]),
        'since'  => [0, 0],
        'expect' => [1002, 12],
    ],
    [
        'name'   => 'THE BUG: a full page sharing one timestamp still advances by id',
        'rows'   => $rows([[5, 1700000000], [6, 1700000000], [7, 1700000000]]),
        'since'  => [0, 0],
        'expect' => [1700000000, 7],
    ],
    [
        'name'   => 'continuing that page moves past the ids already seen',
        'rows'   => $rows([[8, 1700000000], [9, 1700000000]]),
        'since'  => [1700000000, 7],
        'expect' => [1700000000, 9],
    ],
    [
        'name'   => 'empty page holds the cursor still, never resets it',
        'rows'   => [],
        'since'  => [1700000000, 9],
        'expect' => [1700000000, 9],
    ],
    [
        'name'   => 'empty first page stays at the origin',
        'rows'   => [],
        'since'  => [0, 0],
        'expect' => [0, 0],
    ],
    [
        'name'   => 'single row advances to itself',
        'rows'   => $rows([[42, 1234]]),
        'since'  => [0, 0],
        'expect' => [1234, 42],
    ],
];

$failed = 0;
foreach ($cases as $c) {
    [$ts, $id] = next_cursor($c['rows'], $c['since'][0], $c['since'][1]);
    $ok = ([$ts, $id] === $c['expect']);
    printf("  %-58s %s\n", $c['name'], $ok ? 'PASS' : sprintf('FAIL (got [%d, %d], want [%d, %d])',
        $ts, $id, $c['expect'][0], $c['expect'][1]));
    if (!$ok) {
        $failed++;
    }
}

// The cursor is only correct if the SQL predicate is strictly lexicographic and
// the ORDER BY matches it. Checked textually, because a keyset cursor paired with
// an inclusive predicate is precisely the original bug.
echo "\nquery shape:\n";
$checks = [
    'strict > on timetaken'        => (bool) preg_match('/ea\.timetaken\s*>\s*:since\b/', $code),
    'id tie-break on equal ts'     => (bool) preg_match('/ea\.timetaken\s*=\s*:sincets\s+AND\s+ea\.id\s*>\s*:sinceid/i', $code),
    'no inclusive >= predicate'    => !preg_match('/ea\.timetaken\s*>=\s*:/', $code),
    'ORDER BY matches the cursor'  => (bool) preg_match('/ORDER BY ea\.timetaken ASC, ea\.id ASC/i', $code),
];
foreach ($checks as $label => $ok) {
    printf("  %-58s %s\n", $label, $ok ? 'PASS' : 'FAIL');
    if (!$ok) {
        $failed++;
    }
}

if ($failed) {
    echo "\nFAIL: {$failed} problem(s)\n";
    exit(1);
}
echo "\nPASS: cursor advances correctly, including a full page on one timestamp.\n";
exit(0);
