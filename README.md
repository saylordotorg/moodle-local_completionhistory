# Completion History (`local_completionhistory`)

[![Moodle Plugin CI for 4.5](https://github.com/saylordotorg/moodle-local_completionhistory/actions/workflows/moodle-plugin-ci.yml/badge.svg)](https://github.com/saylordotorg/moodle-local_completionhistory/actions/workflows/moodle-plugin-ci.yml)

A Moodle local plugin providing a durable academic-history ledger, exam-attempt audit data, course-replacement mappings, and a deliberately narrow integration surface for the Saylor SIS.

## Requirements

- Moodle 4.5 through 5.2
- PHP 8.1+
- Optional for the core ledger: `enrol_programs` and Moodle Workplace certificate tooling
- No longer required: `enrol_programs`. Since 0.7.0 provisioning creates the account only, and programme membership is a fact the SIS owns

## Installation and upgrade

1. Install this directory as `local/completionhistory`.
2. Visit **Site administration > Notifications** or run the standard Moodle CLI upgrade.
3. Review the settings under **Plugins > Local plugins > Completion History**.
4. If the SIS service is used, update its dedicated role for the capabilities described below. New integration capabilities intentionally have no archetype grants.
5. Verify the grant took, from the Moodle root:

   ```bash
   php local/completionhistory/cli/check_service_capabilities.php
   ```

   This is not optional politeness. Step 4 has been missed twice, and because the capabilities are `'archetypes' => []` the site reports nothing: the upgrade succeeds, the service is registered, the token is valid, and every call returns `nopermissions`. On 2026-08-24 only the *write* endpoints were ungranted, so the reads kept working and the outage surfaced two days later as a student who could not open a course she had just enrolled in.

Database and cached service/event changes are applied through `db/install.xml`, `db/install.php`, and `db/upgrade.php`. Do not deploy updated files without completing the Moodle upgrade.

## Configuration

| Setting | Default | Description |
|---|---:|---|
| Enable plugin | On | Master switch, enforced by browser, AJAX, task, observer, and external entry points |
| Auto-capture completions | On | Capture `course_completed` events |
| Capture grade snapshots | On | Store the course-total grade with an achievement |
| Backfill batch size | 1000 | Completion rows scanned per backfill batch |
| Enable purge audit | On | Record relevant completion purge events |
| Enable user achievements page | On | Show the learner-facing achievement page |
| Artifact storage mode | None | Controls certificate/artifact references |
| Replacement notification | Badge | Learner-facing replacement-course behavior |
| Anonymize on user deletion | Off | Automatically anonymize academic records on the core deletion event |
| Enable SIS outbox | Off | Create denormalized achievement messages for SIS synchronization |
| Source site | Site URL | Stable source identifier included in SIS achievement payloads |

## Security model

The bundled **Completion History SIS** external service is disabled and restricted to explicitly authorized users by default. Use a dedicated, non-human service account and a dedicated system role. Do not use an administrator account or grant broad core capabilities to the service account.

`local/completionhistory:integrate` permits the curated integration reads and outbox acknowledgements. Grant the following additional capabilities only when that operation is required:

| Capability | Purpose | Default grant |
|---|---|---|
| `local/completionhistory:viewcertificates` | Read a learner's issued certificates | None |
| `local/completionhistory:provisionusers` | Create learner accounts | None |
| `local/completionhistory:resetpasswords` | Complete the one-time initial password flow | None |
| `local/completionhistory:createloginkeys` | Mint short-lived learner SSO keys | None |
| `local/completionhistory:updateprofiles` | Change the six whitelisted learner contact fields | None |
| `local/completionhistory:enrolusers` | Create manual learner enrolments, and suspend the ones it created | None |

The initial-password endpoint is not a general reset API: it accepts only local manual-auth learner accounts carrying Moodle's force-change marker, enforces the site password policy, and consumes the marker after one successful call. Email identity lookups reject duplicate/ambiguous addresses.

SSO keys are IP-bound, single-use, scoped to this plugin, and valid for 60 seconds. Both minting and consumption refuse administrators, managers, teachers, and other staff-role accounts. The consumer will not replace an already authenticated session belonging to another user.

Browser mutations require POST plus a valid session key. SIS-only functions are not AJAX-callable. Bulk external requests and snapshot responses have defensive ceilings. Use HTTPS, restrict and rotate web-service tokens, and apply network restrictions supported by the hosting platform.

## Privacy

The Privacy API provider declares and exports achievement snapshots, program associations, exam attempts, outbox copies, purge-audit data, and saved table preferences. It also declares the Saylor SIS as an external data location.

On an approved erasure request, direct identifiers are removed from achievements and exam attempts, queued/sent outbox copies are rewritten, delivery errors are cleared, and user-specific purge-audit rows are deleted. Course, date, grade, and assessment data remain as anonymized institutional academic records. The automatic core `user_deleted` behavior is controlled separately by the **Anonymize on user deletion** setting and should be chosen according to institutional retention policy.

Deduplication uses a plugin-specific 256-bit secret and HMAC-SHA-256 so a retained hash cannot be reversed by enumerating Moodle user IDs. The secret is generated on installation/upgrade and is not exposed as an admin setting.

Data already delivered to an external SIS is outside Moodle's erasure boundary and must be handled under that system's retention and data-subject procedures.

## Backfill and audit

Run commands from the Moodle root:

```bash
php local/completionhistory/cli/backfill_achievements.php --dry-run
php local/completionhistory/cli/backfill_achievements.php --verbose
php local/completionhistory/cli/backfill_achievements.php --userid=42
php local/completionhistory/cli/audit_achievements.php
php local/completionhistory/cli/reconcile_anonymization.php --dryrun
```

The completion-ledger reconciliation task runs daily. Outbox processing and deleted-user reconciliation tasks ship disabled and must be enabled deliberately in Scheduled tasks.

## Checking the integration after a deploy

Two checks keep the capability story honest, because it is told in three places that drift apart: the `require_capability` calls in `classes/external/`, the `capabilities` metadata in `db/services.php`, and the grant table above.

```bash
# On the site, after the upgrade. Does the service account still hold what it needs?
php local/completionhistory/cli/check_service_capabilities.php

# On the source tree, no database needed. Do the three sources agree?
php local/completionhistory/tests/static/check_service_capability_declarations.php
```

The live check reads the capabilities the upgrade **registered**, not the ones on disk, and reports any disagreement between the two in either direction — a function declared but not registered, one still served after being removed, or a changed capability list. Editing `db/services.php` without bumping `version.php` re-registers nothing and leaves the site enforcing a definition no file describes any more. It also flags a disabled service, an enabled service no account can call, a suspended service account, a token whose account is missing from a restricted service's authorised list, and an account with no usable `webservice/*:use` transport.

**A narrow service account is not a broken one.** The security model above grants each capability only when the operation is required, so coverage is judged one function at a time:

| the account holds | verdict |
|---|---|
| none of a function's capabilities | not provisioned for it — reported, not failed |
| all of them | fine |
| some but not all | **failure** — it reaches the endpoint and is refused at the last check |

That middle case is the shape of both outages. A site that grants only `viewcertificates` passes cleanly; an account that holds `:integrate` but not `:enrolusers` does not. Where a gap in an otherwise fully-provisioned account is deliberate, name it once:

```bash
php local/completionhistory/cli/check_service_capabilities.php     --optional=local/completionhistory:resetpasswords
```

and it is reported as withheld instead of failed.

Neither check grants anything. An account can hold a capability through any of several roles, so the repair has no single right answer a script could pick; the live check prints the role each account actually holds and the statement to run.

## Stored data

| Table | Purpose |
|---|---|
| `local_completionhistory_achievement` | Durable achievement and identity/course snapshots |
| `local_completionhistory_ach_program` | Program snapshots associated with an achievement |
| `local_completionhistory_exam_attempt` | Per-attempt academic/proctoring history |
| `local_completionhistory_course_exam_config` | Admin exam-track configuration |
| `local_completionhistory_course_map` | Retired-to-replacement course mappings |
| `local_completionhistory_flag_def` | Admin-defined attempt review rules |
| `local_completionhistory_purge_audit` | Operational purge history |
| `local_completionhistory_outbox` | Denormalized SIS synchronization messages |

Achievement capture is transactional with program snapshots and the optional outbox row. A deterministic keyed event digest makes observer and backfill processing idempotent. Academic snapshots intentionally do not use foreign keys to live user, course, or program records because they must survive source-record retirement.

## Other capabilities

| Capability | Default |
|---|---|
| `local/completionhistory:viewown` | Authenticated user, student, teacher, manager |
| `local/completionhistory:viewall` | Manager |
| `local/completionhistory:manage` | Manager |
| `local/completionhistory:managecoursemap` | Manager |
| `local/completionhistory:runbackfill` | None |
| `local/completionhistory:integrate` | None |

## Known limitations

- The optional purge hook must be dispatched by the component performing the purge; scheduled reconciliation remains the safety net.
- Stable artifact retention depends on the originating certificate/file subsystem.
- Snapshot catalog/program APIs deliberately fail rather than return an unbounded or silently truncated response on unusually large sites; such deployments need a paginated integration contract.
- This plugin retains anonymized academic records by design. Institutions must validate that policy against their legal and records-management obligations.

## License

GNU GPL v3 or later — <https://www.gnu.org/copyleft/gpl.html>
