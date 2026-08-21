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
 * Static check for the security boundary around get_user_certificates.
 *
 * WHAT THIS GUARDS, AND WHY STATICALLY. The function reads OTHER USERS' data by
 * email, and a certificate code is a bearer credential for the public verify page.
 * Three properties keep that safe, and every one of them is the kind of thing a
 * refactor deletes without any test noticing, because the happy path still works:
 *
 *   NO AJAX. Exposed to page JavaScript, any logged-in session could enumerate
 *   another user's codes. The services entry must say ajax => false, forever.
 *
 *   ITS OWN CAPABILITY. Reusing viewall or integrate would mean a certificate
 *   token unlocks provisioning-adjacent reads. The entry must require
 *   viewcertificates and the capability must exist as a read with RISK_PERSONAL.
 *
 *   THE AMBIGUITY-REFUSING RESOLVER. Email lookup must go through
 *   security::get_unique_local_user_by_email — a hand-rolled get_record would
 *   pick an arbitrary account when duplicates are allowed.
 *
 * And one property of the function body: it must be READ-ONLY. It is the only
 * function in this plugin intended for a site the SIS does not own, so a write
 * creeping in would be a write on somebody else's production Moodle.
 *
 * Per the house standard, every check below was made to FAIL against a
 * deliberately broken copy before being trusted.
 */

$root = dirname(__DIR__, 2);
$external = file_get_contents($root . '/classes/external/get_user_certificates.php');
$services = file_get_contents($root . '/db/services.php');
$access = file_get_contents($root . '/db/access.php');

// The services entry for this one function, extracted so assertions about
// ajax/capabilities cannot accidentally match a neighbouring entry.
preg_match(
    "/'local_completionhistory_get_user_certificates'\s*=>\s*\[(.*?)\],/s",
    $services,
    $m
);
$entry = $m[1] ?? '';

$checks = [
    'services entry exists' => $entry !== '',
    'ajax stays false' =>
        (bool) preg_match("/'ajax'\s*=>\s*false/", $entry),
    'registered as read' =>
        (bool) preg_match("/'type'\s*=>\s*'read'/", $entry),
    'requires its own capability' =>
        strpos($entry, 'local/completionhistory:viewcertificates') !== false,
    'capability declared read' =>
        (bool) preg_match(
            "/'local\/completionhistory:viewcertificates'\s*=>\s*\[[^\]]*'captype'\s*=>\s*'read'/s",
            $access
        ),
    'capability carries RISK_PERSONAL' =>
        (bool) preg_match(
            "/'local\/completionhistory:viewcertificates'\s*=>\s*\[[^\]]*RISK_PERSONAL/s",
            $access
        ),
    'email resolved by the ambiguity-refusing helper' =>
        strpos($external, 'get_unique_local_user_by_email') !== false,
    'capability checked in the body' =>
        (bool) preg_match(
            "/require_capability\('local\/completionhistory:viewcertificates'/",
            $external
        ),
    'enabled gate present' =>
        strpos($external, 'security::require_enabled()') !== false,
    'body is read-only' =>
        !preg_match('/\$DB->(insert|update|delete|set_field|execute)/', $external),
    'absence is reported, not conflated with none' =>
        (bool) preg_match("/'available'\s*=>\s*false/", $external),
];

$failed = 0;
echo "get_user_certificates boundary:\n";
foreach ($checks as $label => $ok) {
    printf("  %-48s %s\n", $label, $ok ? 'PASS' : 'FAIL');
    if (!$ok) {
        $failed++;
    }
}

if ($failed) {
    echo "\nFAIL: {$failed} problem(s)\n";
    exit(1);
}
echo "\nPASS: the certificate read keeps its boundary.\n";
exit(0);
