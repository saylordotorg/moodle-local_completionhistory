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
 * Static check for get_course_progress (SIS-246): its three-part paging cursor and its null rule.
 *
 * WHY A SEPARATE CHECK. check_cursor_advance.php covers the (timestamp, id) cursors. This feed has
 * no row id — a row is a (learner, course) pair — so its cursor is (changed_at, userid, courseid),
 * and the defect class is the same one that shipped twice before: a page that cannot advance past a
 * second shared by many rows. Many learners complete activities in the same second.
 *
 * THE NULL RULE. Moodle returns null progress for a course that tracks no activities. Turning that
 * into 0 would tell the SIS a student has done nothing in a course that cannot measure it, so the
 * normaliser must keep null as null.
 *
 * Usage:  php tests/static/check_course_progress.php
 * Exit:   0 = all checks pass, 1 = a check failed, 2 = the check itself is broken.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.MoodleInternal.MoodleInternalGlobalState -- standalone check, no Moodle bootstrap (see header).
set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
    fwrite(STDERR, "the check itself errored at {$file}:{$line}: {$msg}\n");
    exit(2);
});

$file = dirname(__DIR__, 2) . '/classes/external/get_course_progress.php';
if (!is_readable($file)) {
    fwrite(STDERR, "cannot read {$file}\n");
    exit(2);
}
$source = file_get_contents($file);
$failed = 0;
$report = static function (string $label, bool $ok) use (&$failed): void {
    printf("  %-62s %s\n", $label, $ok ? 'PASS' : 'FAIL');
    if (!$ok) {
        $failed++;
    }
};

/* -- Part 1: the pure functions, exercised ----------------------------- */

$extract = static function (string $signature) use ($source): string {
    if (!preg_match('/' . preg_quote($signature, '/') . ' \{(.+?)\n    \}/s', $source, $m)) {
        fwrite(STDERR, "not found in the expected shape: {$signature}; update this check rather than deleting it.\n");
        exit(2);
    }
    return $m[1];
};
// The bodies evaluated are this repository's own source; see check_cursor_advance.php for why.
// phpcs:ignore moodle.PHP.ForbiddenTokens.Found -- evaluates our own extracted source.
eval('function cp_next_cursor(array $rows, int $sincets, int $sinceuserid, int $sincecourseid): array {'
    . $extract('public static function next_cursor(array $rows, int $sincets, int $sinceuserid, int $sincecourseid): array')
    . "\n}");
// phpcs:ignore moodle.PHP.ForbiddenTokens.Found -- evaluates our own extracted source.
eval('function cp_normalise_percent($percent): ?float {'
    . $extract('public static function normalise_percent($percent): ?float') . "\n}");

$rows = static function (array $triples): array {
    return array_map(static function (array $t): stdClass {
        $o = new stdClass();
        [$o->changed_at, $o->userid, $o->courseid] = $t;
        return $o;
    }, $triples);
};

echo "cursor advance:\n";
$cases = [
    ['ordinary page advances to the last row', [[100, 1, 5], [101, 2, 5]], [0, 0, 0], [101, 2, 5]],
    ['a full page on ONE second still advances', [[500, 3, 9], [500, 4, 2], [500, 4, 7]], [0, 0, 0], [500, 4, 7]],
    ['one learner, two courses, same second: course breaks the tie', [[500, 4, 9]], [500, 4, 7], [500, 4, 9]],
    ['empty page holds the cursor still', [], [500, 4, 9], [500, 4, 9]],
];
foreach ($cases as [$label, $page, $since, $want]) {
    $report($label, cp_next_cursor($rows($page), ...$since) === $want);
}

echo "\nnull is not zero:\n";
$report('null stays null (course tracks no activities)', cp_normalise_percent(null) === null);
$report('a non-number is null, not 0', cp_normalise_percent('n/a') === null);
$report('0 stays 0.0', cp_normalise_percent(0) === 0.0);
$report('50 stays 50.0', cp_normalise_percent(50) === 50.0);
$report('rounded to 2 places', cp_normalise_percent(33.33333) === 33.33);
$report('clamped to 100', cp_normalise_percent(140) === 100.0);

/* -- Part 2: the query shape ------------------------------------------- */

$code = '';
foreach (token_get_all($source) as $tok) {
    if (is_array($tok)) {
        if (!in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            $code .= $tok[1];
        }
    } else {
        $code .= $tok;
    }
}

echo "\nquery shape:\n";
// Label => pattern the source must match. One table rather than one call per check, so every
// check reads the same way and adding one is a line.
$mustmatch = [
    'ascending three-part ORDER BY' => '/ORDER BY p\.changed_at ASC, p\.userid ASC, p\.courseid ASC/',
    'strict > on changed_at' => '/p\.changed_at > :since\b/',
    'userid tie-break on an equal second' => '/p\.changed_at = :sincets\s+AND \(p\.userid > :sinceuserid/',
    'courseid tie-break on an equal learner' => '/p\.userid = :sinceuserid2 AND p\.courseid > :sincecourseid/',
    'only completion-enabled courses' => '/c\.enablecompletion = 1/',
    'skips modules being deleted' => '/cm\.deletioninprogress = 0/',
    'excludes deleted users' => '/u\.deleted = 0/',
    'progress comes from core, not re-derived' => '/\\\\core_completion\\\\progress::get_course_progress_percentage\(/',
    'returns the full cursor triple' => "/'next_since_courseid'/",
];
foreach ($mustmatch as $label => $pattern) {
    $report($label, (bool) preg_match($pattern, $code));
}
$report('returns no names or emails', !preg_match('/u\.(email|firstname|lastname|username)/', $code));

if ($failed) {
    echo "\nFAIL: {$failed} problem(s)\n";
    exit(1);
}
echo "\nPASS: get_course_progress pages on a full keyset and never turns unknown progress into 0.\n";
exit(0);
