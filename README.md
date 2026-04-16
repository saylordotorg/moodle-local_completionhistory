# Completion History (`local_completionhistory`)

A Moodle local plugin that provides a durable, immutable academic-history ledger. It preserves course achievement records permanently, even when courses are renamed, archived, deleted, or when `enrol_programs` purges completion records.

## What This Plugin Is

- An **append-only academic ledger** that snapshots course completions at the moment they occur.
- A **read API surface** for a future external Saylor SIS.
- A **course replacement mapping** system for tracking retired/legacy courses.
- A **purge audit trail** that logs when other plugins (e.g., `enrol_programs`) purge Moodle's native completion records.

## What This Plugin Is NOT

- Not a full Student Information System (SIS).
- Not a replacement for Moodle's built-in course completion system.
- Does not modify Moodle core or fork `enrol_programs`.
- Does not handle admissions, enrollment agreements, transcript review, exam attempts, or billing.

## Requirements

- Moodle 4.5+ (2024100700)
- PHP 8.1+
- Optional: `enrol_programs` plugin for program association snapshots

## Installation

1. Copy or symlink this directory to `local/completionhistory/` in your Moodle installation.
2. Visit **Site Administration > Notifications** to trigger the database install.
3. Configure at **Site Administration > Plugins > Local plugins > Completion History**.

## Configuration

| Setting | Default | Description |
|---|---|---|
| Enable plugin | On | Master switch |
| Auto-capture completions | On | Capture achievement on `course_completed` event |
| Capture grade snapshots | On | Include course total grade in achievement records |
| Backfill batch size | 1000 | Records per backfill batch |
| Enable purge audit | On | Log when completions are purged |
| Enable user achievements page | On | Show "My Achievements" in user navigation |
| Artifact storage mode | None | How certificate URLs are stored (none/url/pluginfile) |
| Replacement notification | Badge | How to show course replacement recommendations |
| Anonymize on user deletion | Off | Anonymize (not delete) achievements on GDPR erasure |

## Backfill Existing Completions

After initial install, run the backfill to capture all historical completions:

```bash
# Dry run first
php local/completionhistory/cli/backfill_achievements.php --dry-run

# Actual backfill
php local/completionhistory/cli/backfill_achievements.php --verbose

# Backfill a specific user
php local/completionhistory/cli/backfill_achievements.php --userid=42

# Audit the ledger
php local/completionhistory/cli/audit_achievements.php
```

## Cron / Scheduled Tasks

- **Reconcile achievement ledger**: Runs daily at 02:15. Catches any completions that the observer missed.
- **Process SIS sync outbox**: Disabled by default. Stub for future external SIS integration.

## Upgrade Path

Database changes use Moodle's standard `db/install.xml` and `db/upgrade.php` mechanism. Always run the Moodle upgrade process after updating the plugin files.

## Architecture

### Tables

| Table | Purpose | Mutable? |
|---|---|---|
| `local_completionhistory_achievement` | Immutable achievement ledger | No (append-only) |
| `local_completionhistory_ach_program` | Program associations per achievement | No (append-only) |
| `local_completionhistory_course_map` | Course replacement mappings | Yes (admin CRUD) |
| `local_completionhistory_purge_audit` | Audit trail for purge events | No (append-only) |
| `local_completionhistory_outbox` | Future SIS sync queue | Yes (status updates) |

### Key Design Decisions

1. **No foreign keys to `course`, `user`, or `enrol_programs` tables.** Achievement rows must survive rename/delete/archive/reset of the referenced entities.
2. **Snapshot fields are immutable.** Course names, user ID numbers, and program names are captured at insert time and never updated.
3. **Idempotent inserts.** A deterministic SHA-256 hash (`userid|courseid|timecompleted|source_component`) ensures the observer and backfill job never create duplicate records.
4. **enrol_programs is optional.** If the plugin is not installed, program associations are simply empty. The `program_context_resolver` checks for table existence before querying.
5. **Privacy by anonymization.** On GDPR erasure, achievement records have `userid` set to 0 and PII cleared, but the academic record (course name, grade, date) is preserved.

### Event Flow

```
course_completed event
  → callbacks::course_completed()
    → ledger_service::capture_achievement()
      → grade_snapshot_service (optional)
      → program_context_resolver (optional, if enrol_programs installed)
      → INSERT achievement + ach_program rows (in transaction)
```

### Moodle Components Read From

- `{course_completions}` — completion records
- `{course}` — course metadata snapshots
- `{user}` — user idnumber snapshots
- `{grade_items}` — course total grade item
- `{grade_grades}` — user's grade for course total
- `{enrol_programs_items}` — course-to-program mapping (optional)
- `{enrol_programs_programs}` — program metadata (optional)
- `{enrol_programs_allocations}` — user-to-program allocation (optional)

## Web Services

A pre-configured external service "Completion History SIS" is available (disabled by default):

- `local_completionhistory_get_user_achievements` — Get achievements for a user
- `local_completionhistory_get_course_replacement` — Get replacement mapping for a course
- `local_completionhistory_get_recent_achievements` — Get recent achievements (for SIS sync)
- `local_completionhistory_get_purge_audit` — Get purge audit records

Enable the service at **Site Administration > Server > Web services > External services**.

## Capabilities

| Capability | Type | Default |
|---|---|---|
| `local/completionhistory:viewown` | Read | Student, Teacher, Manager |
| `local/completionhistory:viewall` | Read | Manager |
| `local/completionhistory:manage` | Write | Manager |
| `local/completionhistory:managecoursemap` | Write | Manager |
| `local/completionhistory:runbackfill` | Write | Admin only |

## Known Limitations

1. **Purge hook not yet dispatched.** The `\local_completionhistory\hook\course_completions_purged` hook class exists but `enrol_programs` does not dispatch it yet. The daily reconciliation task serves as a safety net.
2. **Artifact storage is stubbed.** Certificate URLs depend on a stable file storage mechanism that doesn't exist yet. The `artifacturl` field is nullable.
3. **GDPR anonymization is a policy question.** The default is OFF. Institutions must decide whether to anonymize or retain full PII in achievement records.
4. **No automatic course redirects.** V1 shows replacement recommendations only; it does not redirect users to replacement courses.

## Next Steps for External SIS Sync

1. Implement `process_outbox` task to push achievements to an external endpoint.
2. Add `local_completionhistory_get_unsynced_outbox` and `local_completionhistory_mark_outbox_sent` web service functions.
3. Define the external SIS API contract.
4. Enable the outbox scheduled task.

## License

GNU GPL v3 or later — https://www.gnu.org/copyleft/gpl.html
