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
 * WHAT COUNTS AS BROKEN, AND WHAT IS JUST NARROW. A service account is not
 * required to be able to call everything — the security model in README.md says
 * the opposite, that each capability is granted only when that operation is
 * required, and a site using this plugin only to answer certificate queries is
 * expected to grant exactly viewcertificates. So coverage is judged one function
 * at a time, and the discriminator is PARTIAL provisioning:
 *
 *   holds NONE of a function's capabilities  -> not provisioned for it. Narrow
 *                                               on purpose. Reported, not failed.
 *   holds ALL of them                        -> fine.
 *   holds SOME but not all                   -> failure. This is the shape of
 *                                               both outages above: an account
 *                                               that reaches the endpoint on its
 *                                               ordinary work and is refused at
 *                                               the last check.
 *
 * An operator who deliberately withholds one capability from an otherwise
 * fully-provisioned account says so once with --optional, which turns that
 * specific gap into a reported fact instead of a failure. The default stays
 * strict, because "I meant to do that" should be written down somewhere a
 * reader can see rather than assumed by a checker.
 *
 * Run from the Moodle root:
 *
 *   php local/completionhistory/cli/check_service_capabilities.php
 *   php local/completionhistory/cli/check_service_capabilities.php --service=completionhistory_sis
 *   php local/completionhistory/cli/check_service_capabilities.php \
 *       --optional=local/completionhistory:resetpasswords
 *
 * Exits 0 when every authorised account can do the work it is provisioned for,
 * 1 otherwise.
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
    ['help' => false, 'service' => '', 'optional' => ''],
    ['h' => 'help', 's' => 'service', 'o' => 'optional']
);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognised)));
}

if ($options['help']) {
    echo "Check that accounts authorised for this plugin's web services can call the\n";
    echo "functions they are provisioned for.\n\n";
    echo "Options:\n";
    echo "  -h, --help             Print this help.\n";
    echo "  -s, --service=SHORT    Check only this service shortname.\n";
    echo "  -o, --optional=CAPS    Comma-separated capabilities this site deliberately\n";
    echo "                         withholds. A function blocked only by these is\n";
    echo "                         reported as unavailable rather than failed.\n\n";
    echo "An account holding NONE of a function's capabilities is treated as not\n";
    echo "provisioned for it, not as a failure. Holding some but not all is a failure.\n";
    echo "Exit status is 1 if anything failed.\n";
    exit(0);
}

$syscontext = context_system::instance();
$optionalcaps = array_values(array_filter(array_map('trim', explode(',', (string) $options['optional']))));

foreach ($optionalcaps as $cap) {
    if (!get_capability_info($cap)) {
        cli_error("--optional names {$cap}, which is not a capability on this site.");
    }
}

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
 * What db/services.php declares in the SOURCE TREE.
 *
 * Read as text rather than by including the file, because the whole point of the
 * comparison is to catch the case where the file on disk has moved on and the
 * DATABASE has not — the failure mode of editing services.php without bumping
 * version.php, where the site keeps serving the previously registered definition
 * and nothing anywhere says so.
 *
 * @return array ['functions' => name => capability list, 'services' => shortname => function names]
 */
function local_completionhistory_source_definition(): array {
    $src = file_get_contents(__DIR__ . '/../db/services.php');

    $functions = [];
    preg_match_all("/'(local_completionhistory_\w+)'\s*=>\s*\[(.*?)\n    \],/s", $src, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        preg_match("/'capabilities'\s*=>\s*'([^']*)'/", $m[2], $capm);
        $functions[$m[1]] = array_values(array_filter(array_map('trim', explode(',', $capm[1] ?? ''))));
    }

    $services = [];
    preg_match_all(
        "/'[^']+'\s*=>\s*\[\s*'functions'\s*=>\s*\[(.*?)\](.*?)\n    \],/s",
        $src,
        $servicematches,
        PREG_SET_ORDER
    );
    foreach ($servicematches as $m) {
        if (!preg_match("/'shortname'\s*=>\s*'([^']+)'/", $m[2], $sn)) {
            continue;
        }
        preg_match_all("/'(local_completionhistory_\w+)'/", $m[1], $fnames);
        $services[$sn[1]] = $fnames[1];
    }

    return ['functions' => $functions, 'services' => $services];
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

$source = local_completionhistory_source_definition();
$failures = [];
$warnings = [];
$protocols = array_values(array_filter(array_map('trim', explode(',', (string) ($CFG->webserviceprotocols ?? '')))));

foreach ($services as $service) {
    echo "\nService: {$service->name} ({$service->shortname})\n";
    echo '  enabled: ' . ($service->enabled ? 'yes' : 'NO')
        . '   restricted to authorised users: ' . ($service->restrictedusers ? 'yes' : 'no') . "\n";

    if (!$service->enabled) {
        $warnings[] = "{$service->shortname}: the service is disabled, so no token can call it.";
    }

    // What the site believes each function needs. This is the copy the upgrade
    // installed, and therefore the copy the site actually enforces.
    $sql = "SELECT f.name, f.capabilities
              FROM {external_services_functions} sf
              JOIN {external_functions} f ON f.name = sf.functionname
             WHERE sf.externalserviceid = :sid
          ORDER BY f.name";
    $functions = $DB->get_records_sql($sql, ['sid' => $service->id]);

    $registered = [];
    foreach ($functions as $fn) {
        $registered[$fn->name] = array_values(array_filter(array_map('trim', explode(',', (string) $fn->capabilities))));
    }

    // ------------------------------------------------------------------
    // Registered definition vs the file on disk.
    //
    // Both directions, because a function present in only ONE of the two is the
    // purest form of this failure: a new endpoint added to services.php without
    // a version bump is simply absent from the database, so a comparison that
    // only walked the registered functions would never look at it and would
    // report PASS on the very deploy it exists to catch.
    // ------------------------------------------------------------------
    $sourcefunctions = $source['services'][$service->shortname] ?? null;
    if ($sourcefunctions === null) {
        $warnings[] = "{$service->shortname}: db/services.php does not declare this service, so the "
            . 'registered definition cannot be compared against the source.';
    } else {
        foreach (array_diff($sourcefunctions, array_keys($registered)) as $missing) {
            $failures[] = "{$service->shortname}: db/services.php declares {$missing} but the site has not "
                . 'registered it. Bump version.php and run the upgrade - a file change alone re-registers nothing.';
        }
        foreach (array_diff(array_keys($registered), $sourcefunctions) as $extra) {
            $failures[] = "{$service->shortname}: the site still serves {$extra}, which db/services.php no "
                . 'longer declares. Bump version.php and run the upgrade so the removal takes effect.';
        }
    }

    foreach ($registered as $name => $installed) {
        if (!isset($source['functions'][$name])) {
            continue;
        }
        $insorted = $installed;
        $srcsorted = $source['functions'][$name];
        sort($insorted);
        sort($srcsorted);
        if ($insorted !== $srcsorted) {
            $failures[] = "{$name}: the registered capabilities (" . (implode(', ', $installed) ?: 'none')
                . ') do not match db/services.php (' . (implode(', ', $source['functions'][$name]) ?: 'none')
                . '). Bump version.php and run the upgrade - a file change alone re-registers nothing.';
        }
    }

    printf("  %d function(s) registered\n", count($registered));

    // ------------------------------------------------------------------
    // Who may call it: every account holding a token, plus every account on the
    // authorised list when the service is restricted. Both, because a token for
    // an account missing from the authorised list fails too, and an authorised
    // account with no token cannot call anything.
    // ------------------------------------------------------------------
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
        // An ENABLED service nobody can call is a total outage of that
        // integration. Reporting it as a warning would let a deploy gate go
        // green on a site where none of this works at all.
        $message = "{$service->shortname}: no account holds a token or authorisation for this service.";
        if ($service->enabled) {
            $failures[] = $message . ' The service is enabled, so nothing can call it.';
        } else {
            $warnings[] = $message;
        }
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

        // A token is not bound to a protocol, so the account needs at least ONE
        // enabled transport. Requiring every enabled one would fail a REST-only
        // account merely because SOAP is switched on for a different integration.
        $heldprotocols = [];
        foreach ($protocols as $protocol) {
            $protocolcap = "webservice/{$protocol}:use";
            if (get_capability_info($protocolcap) && has_capability($protocolcap, $syscontext, $user->id)) {
                $heldprotocols[] = $protocol;
            }
        }
        if ($protocols && !$heldprotocols) {
            $failures[] = "{$service->shortname}: {$user->username} holds no webservice/*:use capability for any "
                . 'enabled protocol (' . implode(', ', $protocols) . '), so no transport can authenticate it.';
        }

        // Coverage, one function at a time. See the header: none held is narrow,
        // some held is broken.
        $callable = 0;
        $unprovisioned = [];
        $withheld = [];
        foreach ($registered as $name => $caps) {
            $missing = [];
            foreach ($caps as $cap) {
                if (!has_capability($cap, $syscontext, $user->id)) {
                    $missing[] = $cap;
                }
            }
            if (!$missing) {
                $callable++;
                continue;
            }
            if (count($missing) === count($caps)) {
                $unprovisioned[] = $name;
                continue;
            }
            if (!array_diff($missing, $optionalcaps)) {
                $withheld[] = $name . ' (' . implode(', ', $missing) . ')';
                continue;
            }
            $failures[] = "{$service->shortname}: {$user->username} can reach {$name} but lacks "
                . implode(', ', $missing) . ' - it holds ' . implode(', ', array_diff($caps, $missing))
                . ', so the call gets as far as the capability check and is refused.';
        }

        printf("    %-40s %d of %d\n", 'functions it can call', $callable, count($registered));
        if ($unprovisioned) {
            printf("    %-40s %d (%s)\n", 'not provisioned for', count($unprovisioned),
                implode(', ', array_slice($unprovisioned, 0, 3)) . (count($unprovisioned) > 3 ? ', ...' : ''));
        }
        if ($withheld) {
            printf("    %-40s %d (%s)\n", 'deliberately withheld', count($withheld),
                implode('; ', array_slice($withheld, 0, 3)) . (count($withheld) > 3 ? '; ...' : ''));
        }
        if ($heldprotocols) {
            printf("    %-40s %s\n", 'transports available', implode(', ', $heldprotocols));
        }
    }
}

echo "\n";

foreach ($warnings as $w) {
    echo "  WARN  {$w}\n";
}

if (!$failures) {
    echo "\nPASS: every authorised account can call every function it is provisioned for.\n";
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
echo "\nIf a gap is deliberate, name the capability in --optional so it is recorded rather than repaired.\n";
echo "The source-tree counterpart is tests/static/check_service_capability_declarations.php.\n";
exit(1);
