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
 * Deploy-time check: can the accounts authorised for this plugin's external
 * services actually CALL the functions those services expose?
 *
 * WHY THIS EXISTS. A capability declared with 'archetypes' => [] is granted to
 * nobody by an upgrade — deliberately, because these capabilities provision
 * accounts, mint login sessions and set passwords. The cost of that design is
 * that adding one is a silent outage: the code is correct, the service is
 * registered, the token is valid, and every call returns nopermissions.
 *
 * It has happened twice.
 *
 *   2026-08-21  :integrate arrived and took all sixteen endpoints down at once.
 *               Loud, because the ingest failed on its next five-minute cycle.
 *   2026-08-24  0.7.0 installed the five fine-grained write capabilities. The
 *               reads still worked, so the ingest stayed green and the portal
 *               looked healthy for two days — until a student activated three
 *               courses, was told she was enrolled, and could not get into any
 *               of them. The SIS wraps the enrolment call in a try/catch and
 *               degrades to a soft warning, which is right for a student and
 *               terrible for detection.
 *
 * Neither was a code defect and no test could have failed on either, because in
 * both cases the source tree was correct and the SITE was not. So this runs
 * against the site, after the upgrade, and answers the only question that
 * matters at that moment: is there an account that can still do the work?
 *
 * Run from the Moodle root:
 *
 *   php local/completionhistory/cli/check_service_capabilities.php
 *   php local/completionhistory/cli/check_service_capabilities.php --service=completionhistory_sis
 *
 * Exits 0 when every authorised account holds everything it needs, 1 otherwise.
 *
 * IT DOES NOT GRANT ANYTHING, on purpose. An account can hold a capability
 * through any of several roles, so "grant the missing one" has no single right
 * answer this script could pick — and a checker that repairs what it finds gets
 * run reflexively instead of read. It prints the role each account actually
 * holds and the exact statement to run, and leaves the decision with a person.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params(
    ['help' => false, 'service' => ''],
    ['h' => 'help', 's' => 'service']
);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognised)));
}

if ($options['help']) {
    echo "Check that accounts authorised for this plugin's web services hold the\n";
    echo "capabilities those services' functions require.\n\n";
    echo "Options:\n";
    echo "  -h, --help             Print this help.\n";
    echo "  -s, --service=SHORT    Check only this service shortname.\n\n";
    echo "Exit status is 1 if any authorised account is missing a capability.\n";
    exit(0);
}

$syscontext = context_system::instance();

$conditions = ['component' => 'local_completionhistory'];
if ($options['service'] !== '') {
    $conditions['shortname'] = $options['service'];
}
$services = $DB->get_records('external_services', $conditions, 'shortname');

if (!$services) {
    cli_error($options['service'] !== ''
        ? "No service '{$options['service']}' is registered for local_completionhistory."
        : 'No external services are registered for local_completionhistory. Has the upgrade run?');
}

/**
 * The capabilities db/services.php declares in the SOURCE TREE, per function.
 *
 * Read as text rather than by including the file, because the point of the
 * comparison is to catch the case where the file on disk has moved on and the
 * DATABASE has not — the failure mode of editing services.php without bumping
 * version.php, where the site keeps serving the previously registered
 * definition and nothing says so.
 *
 * @return array function name => list of capability strings
 */
function local_completionhistory_source_capabilities(): array {
    $src = file_get_contents(__DIR__ . '/../db/services.php');
    $out = [];
    preg_match_all("/'(local_completionhistory_\w+)'\s*=>\s*\[(.*?)\n    \],/s", $src, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        preg_match("/'capabilities'\s*=>\s*'([^']*)'/", $m[2], $capm);
        $out[$m[1]] = array_values(array_filter(array_map('trim', explode(',', $capm[1] ?? ''))));
    }
    return $out;
}

/**
 * The roles an account holds at system context, for the remedy line. A service
 * account normally has exactly one; anything else is worth seeing.
 *
 * @param int $userid
 * @return array role id => shortname
 */
function local_completionhistory_system_roles(int $userid): array {
    global $DB;
    $sql = "SELECT r.id, r.shortname
              FROM {role_assignments} ra
              JOIN {role} r ON r.id = ra.roleid
             WHERE ra.userid = :userid AND ra.contextid = :contextid";
    $roles = $DB->get_records_sql($sql, ['userid' => $userid, 'contextid' => context_system::instance()->id]);
    return array_map(static fn($r) => $r->shortname, $roles);
}

$sourcecaps = local_completionhistory_source_capabilities();
$failures = [];
$warnings = [];
$protocols = array_filter(explode(',', (string) ($CFG->webserviceprotocols ?? '')));

foreach ($services as $service) {
    echo "\nService: {$service->name} ({$service->shortname})\n";
    echo '  enabled: ' . ($service->enabled ? 'yes' : 'NO')
        . '   restricted to authorised users: ' . ($service->restrictedusers ? 'yes' : 'no') . "\n";

    if (!$service->enabled) {
        $warnings[] = "{$service->shortname}: the service is disabled, so no token can call it.";
    }

    // What the site believes each function needs. This is the copy the upgrade
    // installed, and therefore the copy the operator is actually working from.
    $sql = "SELECT f.name, f.capabilities
              FROM {external_services_functions} sf
              JOIN {external_functions} f ON f.name = sf.functionname
             WHERE sf.externalserviceid = :sid
          ORDER BY f.name";
    $functions = $DB->get_records_sql($sql, ['sid' => $service->id]);

    $required = [];
    foreach ($functions as $fn) {
        $installed = array_values(array_filter(array_map('trim', explode(',', (string) $fn->capabilities))));
        foreach ($installed as $cap) {
            $required[$cap][] = $fn->name;
        }
        // Installed definition vs the file on disk: a mismatch means the upgrade
        // has not caught up with the source, and every check below is being made
        // against the wrong list.
        if (isset($sourcecaps[$fn->name])) {
            $insorted = $installed;
            $srcsorted = $sourcecaps[$fn->name];
            sort($insorted);
            sort($srcsorted);
            if ($insorted !== $srcsorted) {
                $failures[] = "{$fn->name}: the registered capabilities ("
                    . (implode(', ', $installed) ?: 'none') . ') do not match db/services.php ('
                    . (implode(', ', $sourcecaps[$fn->name]) ?: 'none')
                    . '). Bump version.php and run the upgrade - a file change alone re-registers nothing.';
            }
        }
    }

    printf("  %d function(s), %d distinct capability requirement(s)\n", count($functions), count($required));

    if (!$required) {
        $warnings[] = "{$service->shortname}: no function declares a capability requirement, which is unlikely to be true.";
    }

    // Who may call it: every account holding a token, plus every account on the
    // authorised list when the service is restricted. Both, because a token for
    // an account missing from the authorised list fails too, and an authorised
    // account with no token cannot call anything.
    $accounts = [];
    $tokenusers = $DB->get_records('external_tokens', ['externalserviceid' => $service->id], '', 'DISTINCT userid');
    foreach ($tokenusers as $t) {
        $accounts[$t->userid]['token'] = true;
    }
    if ($service->restrictedusers) {
        foreach ($DB->get_records('external_services_users', ['externalserviceid' => $service->id]) as $u) {
            $accounts[$u->userid]['authorised'] = true;
        }
    }

    if (!$accounts) {
        $warnings[] = "{$service->shortname}: no account holds a token or authorisation for this service.";
        continue;
    }

    foreach ($accounts as $userid => $how) {
        $user = $DB->get_record('user', ['id' => $userid], 'id, username, suspended, deleted');
        if (!$user) {
            $failures[] = "{$service->shortname}: token belongs to user id {$userid}, which does not exist.";
            continue;
        }
        $roles = local_completionhistory_system_roles((int) $user->id);
        echo "  account: {$user->username} (id {$user->id})"
            . '  roles at system context: ' . (implode(', ', $roles) ?: 'NONE') . "\n";

        if ($user->deleted || $user->suspended) {
            $failures[] = "{$service->shortname}: {$user->username} is "
                . ($user->deleted ? 'deleted' : 'suspended') . ' and cannot authenticate.';
        }
        if ($service->restrictedusers && empty($how['authorised'])) {
            $failures[] = "{$service->shortname}: {$user->username} holds a token but is not on the "
                . 'authorised-users list of a restricted service.';
        }

        $missing = [];
        foreach (array_keys($required) as $cap) {
            if (!has_capability($cap, $syscontext, $user->id)) {
                $missing[] = $cap;
            }
        }
        // A token also needs the protocol capability, which is easy to miss
        // because it is a core capability on a plugin's service.
        foreach ($protocols as $protocol) {
            $protocolcap = "webservice/{$protocol}:use";
            if (get_capability_info($protocolcap) && !has_capability($protocolcap, $syscontext, $user->id)) {
                $missing[] = $protocolcap;
            }
        }

        foreach ($missing as $cap) {
            $endpoints = $required[$cap] ?? [];
            $detail = $endpoints
                ? ' - blocks ' . count($endpoints) . ' endpoint(s): ' . implode(', ', array_slice($endpoints, 0, 4))
                    . (count($endpoints) > 4 ? ', …' : '')
                : '';
            $failures[] = "{$service->shortname}: {$user->username} lacks {$cap}{$detail}";
        }

        printf("    %-44s %s\n", 'holds every required capability', $missing ? 'NO - ' . count($missing) . ' missing' : 'yes');
    }
}

echo "\n";

foreach ($warnings as $w) {
    echo "  WARN  {$w}\n";
}

if (!$failures) {
    echo "\nPASS: every authorised account can call every function of its service.\n";
    exit(0);
}

foreach ($failures as $f) {
    echo "  FAIL  {$f}\n";
}

echo "\nFAIL: " . count($failures) . " problem(s)\n";
echo "\nTo grant a capability to the service role, either use\n";
echo "  Site administration > Users > Permissions > Define roles > (role) > Edit\n";
echo "or run, from the Moodle root:\n";
echo "  php -r \"define('CLI_SCRIPT',true); require('config.php');\n";
echo "  assign_capability('THE/CAPABILITY', CAP_ALLOW, \\\$ROLEID, context_system::instance()->id, true);\"\n";
echo "  php admin/cli/purge_caches.php\n";
echo "\nThe source-tree counterpart is tests/static/check_service_capability_declarations.php.\n";
exit(1);
