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
 * Static check: does every observer registered in db/events.php point at a method that exists?
 *
 * WHY A DEDICATED CHECK. An observer naming a missing method is invisible until the event fires.
 * `db/events.php` is a plain array literal — nothing resolves the callback at install time, so the
 * plugin upgrades cleanly, the observer list looks complete, and the failure surfaces later as an
 * exception in a teacher's face while they save a grade. It cannot be caught by unit tests either:
 * a test calls the callback directly and so proves nothing about the string in the array pointing
 * at it. This repository has already shipped that state once — during the `user_graded` work the
 * registration landed while the append to callbacks.php failed, and `php -l` passed on both files
 * because neither was syntactically wrong.
 *
 * Also checks the reverse direction, loosely: an event class name that has drifted (a typo, or a
 * core event that moved namespace) is the same silent nothing.
 *
 * Deliberately standalone: no Moodle bootstrap, no database, no PHPUnit — it reads two files and
 * compares them, so it runs on a checkout with no Moodle tree. Same pattern as
 * check_flag_field_coverage.php.
 *
 * Usage:  php tests/static/check_observer_callbacks.php
 * Exit:   0 = every observer resolves, 1 = at least one observer is dead.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$root      = dirname(__DIR__, 2);
$eventspath = $root . '/db/events.php';

if (!is_readable($eventspath)) {
    fwrite(STDERR, "cannot read {$eventspath}\n");
    exit(2);
}
$events = file_get_contents($eventspath);

// Pair each eventname with the callback that follows it, so a mismatch can be reported against the
// event a maintainer recognises rather than against a bare method name.
preg_match_all(
    "/'eventname'\s*=>\s*'([^']+)'\s*,\s*'callback'\s*=>\s*'([^']+)'/",
    $events,
    $m,
    PREG_SET_ORDER
);

if (!$m) {
    fwrite(STDERR, "db/events.php yielded no observers; this check needs updating rather than "
        . "deleting.\n");
    exit(2);
}

// Guard against the parse silently covering only part of the file: every 'callback' key in the file
// must have been paired. Losing observers to a regex would make this check pass by seeing nothing.
$declared = preg_match_all("/'callback'\s*=>/", $events);
if ($declared !== count($m)) {
    fwrite(STDERR, "parsed " . count($m) . " observers but db/events.php declares {$declared}; "
        . "the eventname/callback shape has changed and this check needs updating.\n");
    exit(2);
}

$failures = [];
$bodies   = [];

echo "observers registered in db/events.php:\n";

foreach ($m as $obs) {
    [, $eventname, $callback] = $obs;

    // '\local_completionhistory\callbacks::course_completed' -> class, method.
    if (!str_contains($callback, '::')) {
        $failures[] = "{$eventname}: callback '{$callback}' is not a Class::method reference";
        continue;
    }
    [$class, $method] = explode('::', $callback, 2);

    // Namespace to path, using this plugin's own root for its own namespace.
    $class = ltrim($class, '\\');
    if (!str_starts_with($class, 'local_completionhistory\\')) {
        $failures[] = "{$eventname}: callback '{$callback}' is outside this plugin's namespace";
        continue;
    }
    $relative = str_replace('\\', '/', substr($class, strlen('local_completionhistory\\')));
    $classpath = $root . '/classes/' . $relative . '.php';

    if (!is_readable($classpath)) {
        $failures[] = "{$eventname}: {$callback} — no such file " . basename($classpath);
        continue;
    }

    if (!isset($bodies[$classpath])) {
        $bodies[$classpath] = file_get_contents($classpath);
    }

    // Must be callable the way Moodle calls it: public and static. A method that exists but is
    // private fails at dispatch exactly like one that does not exist at all.
    $found = (bool) preg_match(
        '/public\s+static\s+function\s+' . preg_quote($method, '/') . '\s*\(/',
        $bodies[$classpath]
    );

    // Distinguish "missing" from "not public static", because the fix differs.
    $existsatall = (bool) preg_match(
        '/function\s+' . preg_quote($method, '/') . '\s*\(/',
        $bodies[$classpath]
    );

    printf("  %-46s %s\n", $eventname, $found ? 'resolves' : '*** DEAD ***');

    if (!$found) {
        $failures[] = $existsatall
            ? "{$eventname}: {$callback} exists but is not `public static`, so dispatch will fail"
            : "{$eventname}: {$callback} does not exist — the observer is dead";
    }

    // A core event class name is also a string nobody resolves. Only shape is checkable without a
    // Moodle tree, and a wrong shape is always wrong.
    if (!preg_match('/^\\\\[a-z0-9_]+(\\\\[a-z0-9_]+)*\\\\event\\\\[a-z0-9_]+$/i', $eventname)) {
        $failures[] = "{$eventname}: not a \\component\\event\\name event class reference";
    }
}

if ($failures) {
    echo "\nFAIL — " . count($failures) . " dead or unreachable observer(s):\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    echo "\nAn observer registered against a method that cannot be dispatched is silent until the\n"
        . "event fires in production. Fix the registration or add the method.\n";
    exit(1);
}

echo "\nOK — all " . count($m) . " observers resolve to a public static method.\n";
exit(0);
