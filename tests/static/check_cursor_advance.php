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
 * Static check for the paging cursors on the SIS-facing read functions.
 *
 * WHAT THIS GUARDS. Two functions page through large tables, and the SAME CLASS of
 * defect shipped in both:
 *
 *   get_flagged_attempts paged on `timetaken >= :since`. timetaken is not unique, so
 *   a full page sharing one timestamp returns the same lowest ids forever — the sweep
 *   runs indefinitely and never reaches the rest.
 *
 *   get_user_activity sorted `lastaccess DESC` and returned the highest timestamp as
 *   the continuation bound. That cannot page at all: page one holds the newest users,
 *   so passing its maximum back as a lower bound filters out every older user and
 *   re-emits only the newest.
 *
 * Neither fails loudly. Both silently return an incomplete view of the data, which is
 * why they get a mechanical check rather than a careful read. Both functions are
 * covered here on purpose — a check over one file would have caught neither the
 * second time.
 *
 * Standalone by necessity as much as by choice: next_cursor() is pure, so it can be
 * verified with no Moodle bootstrap, no database and no PHPUnit.
 *
 * Usage:  php tests/static/check_cursor_advance.php
 * Exit:   0 = all checks pass, 1 = a check failed, 2 = the check itself is broken.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Warnings are FAILURES here. An earlier version of this file referenced an undefined
// variable, checked nothing at all, and still exited 0 — a check that reports PASS
// while doing no work is worse than no check.
set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
    fwrite(STDERR, "the check itself errored at {$file}:{$line}: {$msg}\n");
    exit(2);
});

$root = dirname(__DIR__, 2);

/** Every paging function, with the column its cursor is keyed on. */
$targets = [
    'get_flagged_attempts' => [
        'file' => $root . '/classes/external/get_flagged_attempts.php',
        'ts'   => 'timetaken',
    ],
    'get_user_activity' => [
        'file' => $root . '/classes/external/get_user_activity.php',
        'ts'   => 'lastaccess',
        // Scope promises the parameter contract makes and the SQL must keep. The
        // first version documented "confirmed, undeleted users" but filtered only on
        // deleted, so abandoned self-registrations would have entered the SIS
        // activity feed indistinguishable from enrolled students who never signed in.
        'extra' => [
            'excludes unconfirmed' => '/u\.confirmed\s*=\s*1/',
            'excludes deleted'     => '/u\.deleted\s*=\s*0/',
        ],
    ],
];

foreach ($targets as $name => $t) {
    if (!is_readable($t['file'])) {
        fwrite(STDERR, "cannot read {$t['file']}\n");
        exit(2);
    }
}

$failed = 0;

/* -- Part 1: the advance step, exercised ------------------------------- */

/** Build a row list from [id, ts] pairs, keyed by id like $DB->get_records_sql(). */
$rows = static function (array $pairs, string $field): array {
    $out = [];
    foreach ($pairs as [$id, $ts]) {
        $o = new stdClass();
        $o->id = $id;
        $o->{$field} = $ts;
        $out[$id] = $o;
    }
    return $out;
};

foreach ($targets as $name => $t) {
    $code = file_get_contents($t['file']);
    if (!preg_match(
        '/public static function next_cursor\(array \$rows, int \$sincets, int \$sinceid\): array \{(.+?)\n    \}/s',
        $code,
        $m
    )) {
        fwrite(STDERR, "{$name}: next_cursor() not found in the expected shape; "
            . "update this check rather than deleting it.\n");
        exit(2);
    }
    // Uniquely named so both functions can be evaluated in one process.
    $fn = "next_cursor_{$name}";
    eval("function {$fn}(array \$rows, int \$sincets, int \$sinceid): array {" . $m[1] . "\n}");

    $cases = [
        [
            'name'   => 'ordinary page advances to the last row',
            'rows'   => $rows([[10, 1000], [11, 1001], [12, 1002]], $t['ts']),
            'since'  => [0, 0],
            'expect' => [1002, 12],
        ],
        [
            'name'   => 'THE BUG: a full page on ONE timestamp still advances by id',
            'rows'   => $rows([[5, 1700000000], [6, 1700000000], [7, 1700000000]], $t['ts']),
            'since'  => [0, 0],
            'expect' => [1700000000, 7],
        ],
        [
            'name'   => 'continuing that page moves past the ids already seen',
            'rows'   => $rows([[8, 1700000000], [9, 1700000000]], $t['ts']),
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
            // Matters most for get_user_activity: a learner who has never signed in
            // carries ts 0, and must still advance the cursor rather than pin it.
            'name'   => 'a zero timestamp still advances by id',
            'rows'   => $rows([[41, 0], [42, 0]], $t['ts']),
            'since'  => [0, 0],
            'expect' => [0, 42],
        ],
    ];

    echo "{$name} - cursor advance:\n";
    foreach ($cases as $c) {
        [$ts, $id] = $fn($c['rows'], $c['since'][0], $c['since'][1]);
        $ok = ([$ts, $id] === $c['expect']);
        printf("  %-58s %s\n", $c['name'], $ok
            ? 'PASS'
            : sprintf('FAIL (got [%d, %d], want [%d, %d])', $ts, $id, $c['expect'][0], $c['expect'][1]));
        if (!$ok) {
            $failed++;
        }
    }
}

/* -- Part 2: the query shape ------------------------------------------- */

// A correct advance is worthless if the SQL disagrees with it. Descending order with
// a lower-bound cursor is unpageable however carefully the advance is written.
echo "\nquery shape:\n";
/**
 * Strip comments before matching, so prose ABOUT a fixed bug is not mistaken for the
 * bug itself. The first version of this check failed get_flagged_attempts because its
 * own docblock explains that the old predicate was `timetaken >= :since`. A check
 * that flags its own documentation is a check people switch off.
 */
$stripcomments = static function (string $php): string {
    $out = '';
    foreach (token_get_all($php) as $tok) {
        if (is_array($tok)) {
            if (in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= $tok[1];
        } else {
            $out .= $tok;
        }
    }
    return $out;
};

foreach ($targets as $name => $t) {
    $code = $stripcomments(file_get_contents($t['file']));
    $ts = preg_quote($t['ts'], '/');
    $checks = [
        'ascending ORDER BY'        => (bool) preg_match('/ORDER BY [a-z]+\.' . $ts . ' ASC/i', $code),
        'no DESC on the cursor col' => !preg_match('/ORDER BY [a-z]+\.' . $ts . ' DESC/i', $code),
        'strict > lower bound'      => (bool) preg_match('/' . $ts . '\s*>\s*:since\b/', $code),
        'id tie-break on equal ts'  => (bool) preg_match(
            '/' . $ts . '\s*=\s*:sincets\s+AND\s+[a-z]+\.id\s*>\s*:sinceid/i',
            $code
        ),
        'no inclusive >= predicate' => !preg_match('/' . $ts . '\s*>=\s*:/', $code),
        'returns a cursor PAIR'     => (bool) preg_match("/'next_since_id'/", $code),
        'no unresumable max_*'      => !preg_match("/'max_(lastaccess|timetaken)'\s*=>/", $code),
    ];
    foreach (($t['extra'] ?? []) as $label => $re) {
        $checks[$label] = (bool) preg_match($re, $code);
    }
    echo "  {$name}:\n";
    foreach ($checks as $label => $ok) {
        printf("    %-28s %s\n", $label, $ok ? 'PASS' : 'FAIL');
        if (!$ok) {
            $failed++;
        }
    }
}

if ($failed) {
    echo "\nFAIL: {$failed} problem(s)\n";
    exit(1);
}
echo "\nPASS: both paging functions ascend, use a keyset predicate, and advance correctly.\n";
exit(0);
