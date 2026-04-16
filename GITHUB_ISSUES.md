# GitHub Issues & Milestone Build Sheet

## Milestones

| # | Milestone | Target | Description |
|---|---|---|---|
| M1 | Foundation | Sprint 1 | Plugin skeleton, schema, and core service classes |
| M2 | Live Capture | Sprint 2 | Event observers and hooks — plugin reacts to live events |
| M3 | Backfill & Maintenance | Sprint 2 | CLI tools and scheduled reconciliation task |
| M4 | User Interface | Sprint 3 | All user-facing pages and admin CRUD |
| M5 | Integration & Quality | Sprint 4 | Web services, tests, and documentation |

---

## Issues

### Milestone 1: Foundation

#### Issue #1 — Plugin skeleton and schema
**Labels:** `phase-1`, `schema`, `priority-high`
**Milestone:** M1 — Foundation

**Description:**
Create the complete plugin skeleton with all required Moodle boilerplate files and the database schema for all 5 tables.

**Sub-tasks:**
- [x] `version.php` — component metadata, Moodle 4.5 requirement
- [x] `db/install.xml` — 5 tables: achievement, ach_program, course_map, purge_audit, outbox
- [x] `db/access.php` — 5 capabilities: viewown, viewall, manage, managecoursemap, runbackfill
- [x] `settings.php` — 9 admin settings (enabled, autocapture, grades, batch size, purge audit, achievements page, artifact mode, replacement notification, GDPR anonymize)
- [x] `lang/en/local_completionhistory.php` — all language strings
- [x] `lib.php` — minimal navigation hook
- [x] `classes/privacy/provider.php` — GDPR metadata, export, anonymization
- [x] `db/events.php`, `db/hooks.php`, `db/tasks.php`, `db/services.php` — stub files

**Acceptance criteria:**
- Plugin installs cleanly via Site Administration > Notifications
- All 5 tables are created
- Capabilities are registered
- Settings page renders
- Privacy provider passes `tool_dataprivacy` checks

---

#### Issue #2 — Core service classes
**Labels:** `phase-2`, `services`, `priority-high`
**Milestone:** M1 — Foundation

**Description:**
Implement all business logic service classes that power achievement capture, program resolution, grade snapshots, course replacement, and backfill.

**Sub-tasks:**
- [x] `classes/local/ledger_service.php` — capture_achievement (idempotent via SHA-256 hash), record_purge_audit, UUID generation
- [x] `classes/local/program_context_resolver.php` — cross-plugin query to enrol_programs with table-existence gate
- [x] `classes/local/grade_snapshot_service.php` — course total grade from gradebook
- [x] `classes/local/replacement_service.php` — CRUD for course mappings, chain following, user recommendations
- [x] `classes/local/artifact_service.php` — nullable artifact URL resolution (stub for future)
- [x] `classes/local/backfill_service.php` — scan course_completions, insert missing ledger rows
- [x] `classes/hook/course_completions_purged.php` — custom hook class for purge notification

**Acceptance criteria:**
- `ledger_service::capture_achievement()` creates a row with correct snapshots
- Calling twice with same completion returns null (dedup)
- Program resolver returns empty array when enrol_programs not installed
- Backfill processes course_completions and skips existing

---

### Milestone 2: Live Capture

#### Issue #3 — Event observers and hook subscriber
**Labels:** `phase-3`, `events`, `priority-high`
**Milestone:** M2 — Live Capture

**Description:**
Wire Moodle events and the custom purge hook to the service classes so the plugin captures achievements in real-time.

**Sub-tasks:**
- [x] `classes/callbacks.php` — all observer and hook handler methods
- [x] `db/events.php` — observe course_completed, course_deleted, course_updated, user_deleted
- [x] `db/hooks.php` — subscribe to course_completions_purged hook

**Observer behaviors:**
- `course_completed` → captures achievement via ledger_service
- `course_deleted` → logs audit, preserves achievements
- `course_updated` → no-op (immutable snapshots)
- `user_deleted` → anonymizes if GDPR setting enabled, writes audit
- `completions_purged` (hook) → writes audit, does NOT alter achievements

**Acceptance criteria:**
- Complete a course in Moodle → verify achievement row in DB
- Delete a course → verify achievement rows are preserved
- Delete a user with GDPR anonymize on → verify userid=0 and PII cleared

---

### Milestone 3: Backfill & Maintenance

#### Issue #4 — CLI tools and scheduled task
**Labels:** `phase-4`, `cli`, `priority-medium`
**Milestone:** M3 — Backfill & Maintenance

**Description:**
Create CLI scripts for backfilling historical completions and auditing the ledger, plus a scheduled reconciliation task.

**Sub-tasks:**
- [x] `cli/backfill_achievements.php` — dry-run, batch-size, userid/courseid filters, verbose mode
- [x] `cli/audit_achievements.php` — orphan detection, missing detection, hash integrity
- [x] `classes/task/reconcile_ledger.php` — daily catch-up for missed completions
- [x] `classes/task/process_outbox.php` — stub for future SIS sync
- [x] `db/tasks.php` — task registration (reconcile daily 02:15, outbox disabled)

**Acceptance criteria:**
- `backfill_achievements.php --dry-run` reports correct counts
- `backfill_achievements.php` inserts missing records
- Running backfill twice results in 0 new inserts (idempotent)
- Audit script reports orphaned/missing records accurately

---

### Milestone 4: User Interface

#### Issue #5 — UI pages
**Labels:** `phase-5`, `ui`, `priority-medium`
**Milestone:** M4 — User Interface

**Description:**
Build all user-facing pages: My Achievements (student), Achievement Ledger (staff), and Course Replacement Mappings (admin).

**Sub-tasks:**
- [x] `my_achievements.php` — user's own achievement table, sorted by completion date
- [x] `achievement_ledger.php` — staff view with user/course/source/date filters
- [x] `course_mappings.php` — admin CRUD with add/edit/delete actions
- [x] `classes/table/achievements_table.php` — SQL table with formatted columns
- [x] `classes/table/course_mappings_table.php` — SQL table with action buttons
- [x] `classes/form/course_mapping_form.php` — Moodle form with autocomplete course selectors
- [x] Navigation integration via `lib.php` and `settings.php`

**Acceptance criteria:**
- Student can view their achievements at `/local/completionhistory/my_achievements.php`
- Manager can filter the ledger by user, course, and source
- Manager can add/edit/delete course replacement mappings
- Capability checks enforced on all pages

---

### Milestone 5: Integration & Quality

#### Issue #6 — Web services
**Labels:** `phase-6`, `api`, `priority-medium`
**Milestone:** M5 — Integration & Quality

**Description:**
Implement external API functions for future SIS integration.

**Sub-tasks:**
- [x] `classes/external/get_user_achievements.php` — paginated user achievements with program data
- [x] `classes/external/get_course_replacement.php` — replacement mapping lookup
- [x] `classes/external/get_recent_achievements.php` — since-timestamp query for sync
- [x] `classes/external/get_purge_audit.php` — audit records
- [x] `db/services.php` — function registration and "Completion History SIS" service definition

**Acceptance criteria:**
- Each function returns valid typed responses
- Capability checks enforced
- Service is disabled by default (requires manual enablement)

---

#### Issue #7 — Test suite
**Labels:** `phase-7`, `testing`, `priority-high`
**Milestone:** M5 — Integration & Quality

**Description:**
Comprehensive PHPUnit and Behat test coverage.

**PHPUnit tests:**
- [x] `tests/local/ledger_service_test.php` — capture, dedup, deleted course, UUID, hash, purge audit, snapshots
- [x] `tests/local/replacement_service_test.php` — add/get/deactivate, recommendation, chain
- [x] `tests/local/backfill_service_test.php` — insert, idempotent, dry-run, filter
- [x] `tests/local/program_context_resolver_test.php` — empty return, array type, cache reset
- [x] `tests/observer_test.php` — purge hook audit, achievement preservation, user anonymization, autocapture disable
- [x] `tests/privacy_provider_test.php` — contexts, export, anonymization
- [x] `tests/generator/lib.php` — test data generator

**Behat tests:**
- [x] `tests/behat/my_achievements.feature` — student achievements page
- [x] `tests/behat/achievement_ledger.feature` — manager ledger access
- [x] `tests/behat/course_mappings.feature` — manager mappings CRUD

**Acceptance criteria:**
- All PHPUnit tests pass
- All Behat scenarios pass
- Reasonable coverage (>80% on service classes)

---

#### Issue #8 — Documentation
**Labels:** `phase-8`, `docs`, `priority-medium`
**Milestone:** M5 — Integration & Quality

**Description:**
Write complete documentation for installation, configuration, and development.

**Sub-tasks:**
- [x] `README.md` — install, upgrade, cron, backfill, architecture, known limitations, next steps
- [x] `GITHUB_ISSUES.md` — issue tracker and milestone build sheet

**Acceptance criteria:**
- A new developer can install and configure the plugin from README alone
- Architecture decisions are documented
- Known limitations are explicit

---

## Implementation Summary

### Assumptions Made
1. `enrol_programs` is optional — the plugin works without it, program associations are simply empty.
2. Achievement records are institutional academic records and should survive user deletion (anonymized, not deleted).
3. The `course_completions_purged` hook will be dispatched by `enrol_programs` in a future version — until then, the reconciliation task catches missed completions.
4. Stable artifact (certificate) storage is a follow-up — the field is nullable.
5. Course replacement recommendations are display-only in V1; no automatic redirects.

### What Is Stubbed
- `classes/task/process_outbox.php` — logs "not yet implemented"
- `classes/local/artifact_service.php` — returns null for pattern-based URLs
- Outbox scheduled task is registered but disabled

### Moodle Components Read From
- `{course_completions}` — canonical completion records
- `{course}` — course metadata for snapshots
- `{user}` — user idnumber for snapshots
- `{grade_items}` + `{grade_grades}` — course total grade
- `{enrol_programs_items}` — course-to-program mapping (optional)
- `{enrol_programs_programs}` — program metadata (optional)
- `{enrol_programs_allocations}` — user-to-program allocation (optional)

### Next Recommended Steps for External SIS Sync
1. Define the external SIS API contract (endpoint, auth, payload format).
2. Implement `process_outbox` task to push pending achievements.
3. Add `mark_outbox_sent` web service function.
4. Enable the outbox task and "Completion History SIS" external service.
5. Add monitoring/alerting for outbox failures.
