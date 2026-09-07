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
 * Static check that the integration's capability story is told consistently in
 * all three places it has to be told.
 *
 * WHAT THIS GUARDS. A capability declared with 'archetypes' => [] is granted to
 * NOBODY by a Moodle upgrade — that is the point of it, and it is also how this
 * integration has now gone down twice:
 *
 *   2026-08-21  local/completionhistory:integrate was introduced. Every one of
 *               the sixteen SIS endpoints started returning nopermissions and
 *               the ingest failed every cycle.
 *   2026-08-24  The five fine-grained write capabilities (enrolusers,
 *               provisionusers, resetpasswords, createloginkeys, updateprofiles)
 *               reached the site with 0.7.0 ungranted. Reads kept working, so
 *               nothing looked broken until a student could not get into a
 *               course she had just enrolled in.
 *
 * Neither outage was a code defect. Both were a deploy that did not grant a new
 * capability — so the durable guard is not a unit test of the endpoint, it is
 * making the three sources of truth agree:
 *
 *   the CODE      classes/external/*.php   require_capability(...)
 *   the METADATA  db/services.php          'capabilities' => '...'
 *   the RUNBOOK   README.md                the table an operator grants from
 *
 * The metadata is what an administrator reads on the "Authorised users" screen
 * when deciding what a token may do, and it is what the companion live check
 * (cli/check_service_capabilities.php) verifies the service account actually
 * holds. If the code requires something the metadata does not name, that live
 * check has a blind spot exactly the shape of the next outage.
 *
 * This runs against the source tree only — no database, no Moodle bootstrap:
 *
 *   php tests/static/check_service_capability_declarations.php
 *
 * Per the house standard, every check below was made to FAIL against a
 * deliberately broken copy before being trusted.
 */

/**
 * Capabilities a function requires only on a CONDITIONAL branch, so db/services.php
 * names the base capability and not this one.
 *
 * Each entry is a deliberate decision rather than a suppression. Adding one means
 * you have satisfied yourself that a token holding only the DECLARED capability
 * still gets a correct answer on the path it is meant to use — where a refusal is
 * a correct answer — instead of a 403 on its ordinary work. get_user_achievements
 * qualifies: reading your OWN history needs viewown, which every authenticated
 * account has by archetype, and the viewall branch is reached only by asking for
 * somebody else's, which a service token has no business doing unattended.
 */
$branchonly = [
    'local_completionhistory_get_user_achievements' => ['local/completionhistory:viewall'],
];

$root = dirname(__DIR__, 2);
$servicessrc = file_get_contents($root . '/db/services.php');
$accesssrc = file_get_contents($root . '/db/access.php');
$readme = file_get_contents($root . '/README.md');

$problems = [];
$note = static function (string $msg) use (&$problems): void {
    $problems[] = $msg;
};

// ---------------------------------------------------------------------------
// Parse the three sources.
// ---------------------------------------------------------------------------

// db/access.php: capability => archetypes-are-empty?
$declaredcaps = [];
preg_match_all(
    "/'(local\/completionhistory:\w+)'\s*=>\s*\[(.*?)\n    \],/s",
    $accesssrc,
    $capmatches,
    PREG_SET_ORDER
);
foreach ($capmatches as $m) {
    $declaredcaps[$m[1]] = (bool) preg_match("/'archetypes'\s*=>\s*\[\s*\]/", $m[2]);
}

// db/services.php: function => ['classname' => ..., 'capabilities' => [...]].
$functions = [];
preg_match_all(
    "/'(local_completionhistory_\w+)'\s*=>\s*\[(.*?)\n    \],/s",
    $servicessrc,
    $fnmatches,
    PREG_SET_ORDER
);
foreach ($fnmatches as $m) {
    preg_match("/'classname'\s*=>\s*'([^']+)'/", $m[2], $cm);
    preg_match("/'capabilities'\s*=>\s*'([^']*)'/", $m[2], $capm);
    $caps = array_values(array_filter(array_map('trim', explode(',', $capm[1] ?? ''))));
    // Read as TEXT, not evaluated: some entries escape the namespace separator
    // ('a\\b') and some do not ('a\b'), which PHP cannot tell apart but a regex
    // over the file very much can. Collapse to the value PHP would have seen.
    $classname = str_replace('\\\\', '\\', $cm[1] ?? '');
    $functions[$m[1]] = ['classname' => $classname, 'capabilities' => $caps];
}

// The SIS service's function list — the set an operator has to authorise a token for.
preg_match(
    "/'shortname'\s*=>\s*'completionhistory_sis'/",
    $servicessrc,
    $sismarker
);
preg_match(
    "/'Completion History SIS'\s*=>\s*\[\s*'functions'\s*=>\s*\[(.*?)\]/s",
    $servicessrc,
    $sm
);
preg_match_all("/'(local_completionhistory_\w+)'/", $sm[1] ?? '', $sfn);
$sisfunctions = $sfn[1] ?? [];

// ---------------------------------------------------------------------------
// Sanity: the parsers found something. A silent zero-match regex would turn
// every check below into a vacuous PASS, which is the failure mode a
// last-line-of-defence check can least afford.
// ---------------------------------------------------------------------------

if (!$declaredcaps) {
    $note('parsed no capabilities out of db/access.php');
}
if (!$functions) {
    $note('parsed no functions out of db/services.php');
}
if (!$sisfunctions) {
    $note('parsed no function list for the completionhistory_sis service');
}
if (!$sismarker) {
    $note('db/services.php no longer declares the completionhistory_sis shortname');
}

// ---------------------------------------------------------------------------
// A. Every capability the CODE requires is named in the METADATA.
// ---------------------------------------------------------------------------

foreach ($functions as $fnname => $fn) {
    $relative = str_replace(
        ['local_completionhistory\\external\\', '\\'],
        ['classes/external/', '/'],
        $fn['classname']
    ) . '.php';
    $path = $root . '/' . $relative;
    if (!is_readable($path)) {
        $note("{$fnname}: classname does not resolve to a readable file ({$relative})");
        continue;
    }
    preg_match_all(
        "/require_capability\(\s*'(local\/completionhistory:\w+)'/",
        file_get_contents($path),
        $rm
    );
    $required = array_unique($rm[1]);
    $exempt = $branchonly[$fnname] ?? [];
    foreach ($required as $cap) {
        if (in_array($cap, $fn['capabilities'], true) || in_array($cap, $exempt, true)) {
            continue;
        }
        $note("{$fnname}: requires {$cap} in code but db/services.php does not declare it");
    }
    foreach ($fn['capabilities'] as $cap) {
        if (!in_array($cap, $required, true)) {
            $note("{$fnname}: db/services.php declares {$cap} but the code never requires it");
        }
    }
}

// ---------------------------------------------------------------------------
// B. Every capability the METADATA names actually exists in db/access.php.
// A typo here is invisible: Moodle stores the string verbatim and the
// endpoint keeps working, so only the operator reading it is misled.
// ---------------------------------------------------------------------------

foreach ($functions as $fnname => $fn) {
    foreach ($fn['capabilities'] as $cap) {
        if (!isset($declaredcaps[$cap])) {
            $note("{$fnname}: declares {$cap}, which db/access.php does not define");
        }
    }
}

// ---------------------------------------------------------------------------
// C. Every capability an operator MUST grant by hand is in the RUNBOOK, and the
// runbook names nothing that no longer exists.
//
// "Must grant by hand" is the precise set: required by a function in the SIS
// service AND declared with no archetypes, so no role inherits it. That is
// the set whose omission takes the integration down, and it is exactly what
// drifted — viewcertificates was needed and undocumented, setdeadlines was
// documented and gone.
// ---------------------------------------------------------------------------

$mustgrant = [];
foreach ($sisfunctions as $fnname) {
    foreach ($functions[$fnname]['capabilities'] ?? [] as $cap) {
        if (!empty($declaredcaps[$cap])) {
            $mustgrant[$cap] = true;
        }
    }
}
foreach (array_keys($mustgrant) as $cap) {
    if (strpos($readme, $cap) === false) {
        $note("README.md does not document {$cap}, which the SIS service requires and nothing grants by archetype");
    }
}

preg_match_all("/local\/completionhistory:\w+/", $readme, $rmcaps);
foreach (array_unique($rmcaps[0]) as $cap) {
    if (!isset($declaredcaps[$cap])) {
        $note("README.md documents {$cap}, which db/access.php no longer defines");
    }
}

// ---------------------------------------------------------------------------
// D. Every INTEGRATION function is actually exposed by the SIS service.
//
// A function declared in $functions but missing from the service's function
// list is registered on the site and callable by nobody: the SIS token
// authorises one service, so the endpoint answers "invalid parameter"-shaped
// nothing and the feature is simply absent. Same failure class as an
// ungranted capability, same invisibility, and one more line to forget.
//
// Scoped to functions requiring :integrate on purpose. That capability is the
// marker for "server-to-server only", so those and only those must be reachable
// by the SIS token; the browser-facing reads (viewown, ajax) are a separate set
// whose membership here is a judgement rather than a rule.
// ---------------------------------------------------------------------------

foreach ($functions as $fnname => $fn) {
    if (!in_array('local/completionhistory:integrate', $fn['capabilities'], true)) {
        continue;
    }
    if (!in_array($fnname, $sisfunctions, true)) {
        $note("{$fnname}: requires :integrate but the completionhistory_sis service does not expose it, "
            . 'so no SIS token can call it');
    }
}

// ---------------------------------------------------------------------------

echo "SIS service capability declarations:\n";
printf("  %-48s %d\n", 'capabilities defined in db/access.php', count($declaredcaps));
printf("  %-48s %d\n", 'external functions in db/services.php', count($functions));
printf("  %-48s %d\n", 'functions in the completionhistory_sis service', count($sisfunctions));
printf("  %-48s %d\n", 'capabilities an operator must grant by hand', count($mustgrant));

if ($problems) {
    echo "\n";
    foreach ($problems as $p) {
        echo "  FAIL  {$p}\n";
    }
    echo "\nFAIL: " . count($problems) . " problem(s)\n";
    echo "The live counterpart is cli/check_service_capabilities.php.\n";
    exit(1);
}

echo "\nPASS: code, db/services.php and README.md name the same capabilities.\n";
exit(0);
