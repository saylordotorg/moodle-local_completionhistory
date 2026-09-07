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

namespace local_completionhistory\local;

use stdClass;

/**
 * Central service for the achievement ledger: capture, revision and anonymization.
 *
 * THE LEDGER IS APPEND-ONLY IN ITS IDENTITY, NOT IN EVERY COLUMN. A row is captured once per
 * completion (idempotent via a deterministic keyed event hash), is never deleted when its source
 * data changes, and keeps its ledgeruuid, user, course and completion time for life. But Moodle can
 * learn something new about a completion after the fact — a teacher regrades the exam, a certificate
 * is issued or revoked, a backfill identifies the completing attempt — and a ledger that refused to
 * reflect that would be wrong forever. So the grade, exam-context and certificate columns MAY be
 * revised, and every revision is written to local_completionhistory_ach_revision with the previous
 * and new value, the reason and the trigger: see revise_achievement(). The figure originally captured
 * is always recoverable from that history.
 *
 * Anonymization on erasure is the one change deliberately kept OUT of the history, because a history
 * of the erased identity would defeat the erasure; see anonymize_users().
 *
 * When a course completion is triggered by a tracked exam attempt, callers
 * should pass the optional exam context parameters to record which track
 * and attempt count led to completion.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ledger_service {
    /**
     * Achievement columns a revision may change.
     *
     * Everything else — the identity of the completion (user, course, completion time, dedup hash,
     * uuid, capture time) and the identity snapshots — is written once and never revised.
     */
    public const REVISABLE_FIELDS = [
        'grade_decimal',
        'grade_passed',
        'grade_source',
        'exam_track',
        'attempts_used',
        'attempts_allowed',
        'artifacturl',
        'artifactstorage',
    ];

    /** @var string Revision reason: the course total changed after completion was recorded. */
    public const REVISION_GRADE_CORRECTED = 'grade_corrected';

    /** @var string Revision reason: the completing exam track and attempt count were identified later. */
    public const REVISION_EXAM_CONTEXT = 'exam_context_set';

    /** @var string Revision reason: a certificate was issued for the completion. */
    public const REVISION_CERTIFICATE_ATTACHED = 'certificate_attached';

    /** @var string Revision reason: the certificate for the completion was revoked. */
    public const REVISION_CERTIFICATE_CLEARED = 'certificate_cleared';

    /**
     * Capture an achievement from a course completion record.
     *
     * @param stdClass    $completion      A course_completions record (userid, course, timecompleted).
     * @param string      $sourcecomponent Component that triggered capture.
     * @param string      $sourceevent     Event class name or CLI identifier.
     * @param string|null $examtrack      Exam track that completed the course, or null.
     * @param int|null    $attemptsused   Attempts consumed on the completing track.
     * @param int|null    $attemptsallowed Max attempts allowed on that track (0 = unlimited).
     * @return int|null New achievement ID, or null if already captured (idempotent skip).
     */
    public static function capture_achievement(
        stdClass $completion,
        string $sourcecomponent,
        string $sourceevent,
        ?string $examtrack = null,
        ?int $attemptsused = null,
        ?int $attemptsallowed = null
    ): ?int {
        global $DB;

        $userid        = (int) $completion->userid;
        $courseid      = (int) $completion->course;
        $timecompleted = (int) $completion->timecompleted;

        // Compute deterministic dedup hash.
        $hash = self::compute_event_hash($userid, $courseid, $timecompleted, $sourcecomponent);

        // Idempotency check.
        if ($DB->record_exists('local_completionhistory_achievement', ['source_event_hash' => $hash])) {
            return null;
        }

        // Snapshot course metadata.
        $course          = $DB->get_record('course', ['id' => $courseid]);
        $coursename      = $course ? $course->fullname : '[deleted]';
        $courseshortname = $course ? $course->shortname : null;
        $courseidnumber  = $course ? $course->idnumber : null;

        // Snapshot user fields.
        $user = $DB->get_record('user', ['id' => $userid], 'id, deleted, idnumber, firstname, lastname, email');
        $anonymizeonwrite = (bool) get_config('local_completionhistory', 'gdpranonymize') &&
            (!$user || !empty($user->deleted));
        $useridnumber = ($user && !$anonymizeonwrite) ? $user->idnumber : null;
        $firstname    = ($user && !$anonymizeonwrite) ? $user->firstname : null;
        $lastname     = ($user && !$anonymizeonwrite) ? $user->lastname : null;
        $email        = ($user && !$anonymizeonwrite) ? $user->email : null;

        // Snapshot earliest enrolment date for this user+course.
        $enrolments = $DB->get_records_sql(
            "SELECT ue.timestart, ue.timecreated
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.courseid = :courseid AND ue.userid = :userid",
            ['courseid' => $courseid, 'userid' => $userid]
        );
        $enrolledtime = null;
        foreach ($enrolments as $ue) {
            $ts = ($ue->timestart > 0) ? (int) $ue->timestart : (int) $ue->timecreated;
            if ($enrolledtime === null || $ts < $enrolledtime) {
                $enrolledtime = $ts;
            }
        }

        // Snapshot grade if enabled.
        $gradedata = null;
        if (get_config('local_completionhistory', 'capturegrades')) {
            $gradedata = grade_snapshot_service::get_course_total($userid, $courseid);
        }

        // Resolve program context.
        $programs = program_context_resolver::resolve($userid, $courseid);
        $artifact = $anonymizeonwrite
            ? null
            : artifact_service::certificate_artifact_for_user_course($userid, $courseid);

        // If no exam_track was explicitly provided, try to auto-detect from course config.
        if ($examtrack === null) {
            $config = course_config_service::get_config($courseid);
            if ($config->course_type !== course_config_service::TYPE_STANDARD) {
                // Infer from the most recent completing attempt if available.
                $completing = $DB->get_record_select(
                    'local_completionhistory_exam_attempt',
                    'userid = :uid AND courseid = :cid AND resulted_in_completion = 1',
                    ['uid' => $userid, 'cid' => $courseid],
                    '*',
                    IGNORE_MULTIPLE
                );
                if ($completing) {
                    $examtrack       = $completing->exam_track;
                    $attemptsused    = (int) $completing->attempt_number;
                    $attemptsallowed = (int) $completing->attempts_allowed;
                }
            }
        }

        // Build the achievement record.
        $record                           = new stdClass();
        $record->ledgeruuid               = self::generate_uuid();
        $record->userid                   = $anonymizeonwrite ? 0 : $userid;
        $record->useridnumber_snapshot    = $useridnumber ?: null;
        $record->firstname_snapshot       = $firstname ?: null;
        $record->lastname_snapshot        = $lastname ?: null;
        $record->email_snapshot           = $email ?: null;
        $record->courseid                 = $courseid;
        $record->courseidnumber_snapshot  = $courseidnumber ?: null;
        $record->courseshortname_snapshot = $courseshortname;
        $record->coursename_snapshot      = $coursename;
        $record->completiontime           = $timecompleted;
        $record->enrolledtime_snapshot    = $enrolledtime;
        $record->grade_decimal            = $gradedata ? $gradedata->finalgrade : null;
        $record->grade_passed             = $gradedata ? $gradedata->passed : null;
        $record->grade_source             = $gradedata ? 'gradebook' : null;
        $record->exam_track               = $examtrack;
        $record->attempts_used            = $attemptsused;
        $record->attempts_allowed         = $attemptsallowed;
        $record->artifacturl              = $artifact['url'] ?? null;
        $record->artifactstorage          = $artifact['storage'] ?? null;
        $record->source_component         = $sourcecomponent;
        $record->source_event             = $sourceevent;
        $record->source_event_hash        = $hash;
        $record->timecreated              = time();

        // Wrap in transaction: achievement + program rows.
        $transaction = $DB->start_delegated_transaction();
        try {
            $achievementid = $DB->insert_record('local_completionhistory_achievement', $record);

            foreach ($programs as $program) {
                $progrecord                           = new stdClass();
                $progrecord->achievementid            = $achievementid;
                $progrecord->allocationid             = $anonymizeonwrite ? null : ($program->allocationid ?? null);
                $progrecord->programid                = $program->programid ?? null;
                $progrecord->programidnumber_snapshot = $program->idnumber ?? null;
                $progrecord->programname_snapshot     = $program->fullname;
                $progrecord->timecreated              = time();
                $DB->insert_record('local_completionhistory_ach_program', $progrecord);
            }

            // Enqueue this achievement for external SIS sync (transactional outbox).
            // No-op unless the 'enableoutbox' setting is on. Performed inside the
            // same transaction so the outbox row commits atomically with the ledger
            // row — guaranteeing exactly-once capture with no lost/phantom events.
            $record->id = $achievementid;
            outbox_service::enqueue_achievement($record);

            $transaction->allow_commit();
        } catch (\Exception $e) {
            $transaction->rollback($e);
            throw $e;
        }

        return $achievementid;
    }

    /**
     * Record the exam context on an existing achievement row, as a revision.
     *
     * Used when the completing attempt is identified after the achievement was already written
     * (backfill scenarios). Goes through revise_achievement() so the previous values, if any, stay
     * recoverable.
     *
     * @param int    $achievementid   The achievement to revise.
     * @param string $examtrack       Exam track that completed the course.
     * @param int    $attemptsused    Attempts consumed on the completing track.
     * @param int    $attemptsallowed Max attempts allowed on that track (0 = unlimited).
     * @param string $source          What identified the context: an event class or CLI identifier.
     * @return int Number of columns actually changed.
     */
    public static function set_exam_context(
        int $achievementid,
        string $examtrack,
        int $attemptsused,
        int $attemptsallowed,
        string $source = 'cli_backfill'
    ): int {
        return self::revise_achievement($achievementid, [
            'exam_track' => $examtrack,
            'attempts_used' => $attemptsused,
            'attempts_allowed' => $attemptsallowed,
        ], self::REVISION_EXAM_CONTEXT, $source);
    }

    /**
     * Revise an achievement row, recording every changed column in the correction history.
     *
     * The new values are written to the row itself, so every reader — the ledger pages, the SIS
     * outbox payload, the transcript — sees the corrected figure under the same ledgeruuid; and one
     * row per changed column goes to local_completionhistory_ach_revision holding the previous value,
     * the new value, why, and what triggered it. A row with no revisions is exactly what was captured;
     * a row with revisions can be read back to any point in its history.
     *
     * Only the columns in REVISABLE_FIELDS may be revised; asking for any other is a coding error, not
     * a data condition, and throws. Unchanged values are ignored, so a caller can pass the full intended
     * state and a no-op leaves no trace. Runs in a delegated transaction and so nests inside a caller's.
     *
     * @param int    $achievementid The row to revise.
     * @param array  $changes       Column name => new value.
     * @param string $reason        One of the REVISION_* constants.
     * @param string $source        What triggered it: an event class name, or a CLI/service identifier.
     * @return int Number of columns actually changed (0 = nothing was written).
     * @throws \coding_exception For a column that is not revisable.
     * @throws \dml_exception When the achievement does not exist.
     */
    public static function revise_achievement(int $achievementid, array $changes, string $reason, string $source): int {
        global $DB;

        foreach (array_keys($changes) as $field) {
            if (!in_array($field, self::REVISABLE_FIELDS, true)) {
                throw new \coding_exception('Achievement column is not revisable: ' . $field);
            }
        }

        $revisions = [];
        $transaction = $DB->start_delegated_transaction();
        try {
            $current = $DB->get_record('local_completionhistory_achievement', ['id' => $achievementid], '*', MUST_EXIST);
            $update = ['id' => $achievementid];
            $now = time();
            foreach ($changes as $field => $value) {
                if (self::same_value($current->$field, $value)) {
                    continue;
                }
                $update[$field] = $value;
                $revisions[] = (object) [
                    'achievementid' => $achievementid,
                    'fieldname' => $field,
                    'oldvalue' => $current->$field === null ? null : (string) $current->$field,
                    'newvalue' => $value === null ? null : (string) $value,
                    'reason' => $reason,
                    'source' => $source,
                    'timecreated' => $now,
                ];
            }
            if ($revisions) {
                $DB->update_record('local_completionhistory_achievement', (object) $update);
                $DB->insert_records('local_completionhistory_ach_revision', $revisions);
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            throw $e;
        }

        return count($revisions);
    }

    /**
     * The correction history of one achievement, oldest first.
     *
     * @param int $achievementid The achievement.
     * @return stdClass[] Revision rows.
     */
    public static function get_revisions(int $achievementid): array {
        global $DB;

        return array_values($DB->get_records(
            'local_completionhistory_ach_revision',
            ['achievementid' => $achievementid],
            'timecreated ASC, id ASC'
        ));
    }

    /**
     * Are a stored value and a proposed value the same fact, allowing for how the database hands numbers back?
     *
     * `grade_decimal` is number(10,5), which a read returns as a padded STRING ('84.00000') while the
     * caller holds a float (84.0); compared as strings every regrade would look like a change. Numeric
     * pairs are therefore compared as floats at the column's own precision. Everything else compares as
     * strings, and NULL equals only NULL — 0 and NULL are different facts for grade_passed.
     *
     * @param mixed $stored   The value currently in the row.
     * @param mixed $proposed The value a caller wants to write.
     * @return bool
     */
    private static function same_value($stored, $proposed): bool {
        if ($stored === null || $proposed === null) {
            return $stored === null && $proposed === null;
        }
        if (is_numeric($stored) && is_numeric($proposed)) {
            return abs((float) $stored - (float) $proposed) < 0.5 * (10 ** -5);
        }
        return (string) $stored === (string) $proposed;
    }

    /**
     * Scrub PII from achievement rows belonging to the given userids.
     * Clears the userid (sets to 0) and nulls every field that can carry
     * user-identifying data: useridnumber_snapshot, firstname_snapshot,
     * lastname_snapshot, email_snapshot, artifacturl, artifactstorage.
     *
     * Academic payload (course, completion time, grade, exam track) is
     * intentionally preserved — these rows remain institutional records.
     *
     * Deliberately NOT a revision: recording the erased identity in the
     * correction history would defeat the erasure. The certificate values in
     * existing revision rows are scrubbed for the same reason.
     *
     * @param int[] $userids Userids whose achievements should be anonymized.
     * @return int Number of achievement rows affected.
     */
    public static function anonymize_users(array $userids): int {
        global $DB;

        $userids = array_values(array_unique(array_filter(
            array_map('intval', $userids),
            fn($id) => $id > 0
        )));
        if (empty($userids)) {
            return 0;
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        $achievements = $DB->get_records_select(
            'local_completionhistory_achievement',
            "userid {$insql}",
            $params,
            '',
            'id'
        );
        $count = count($achievements);

        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->execute(
                "UPDATE {local_completionhistory_achievement}
                    SET userid                = 0,
                        useridnumber_snapshot = NULL,
                        firstname_snapshot    = NULL,
                        lastname_snapshot     = NULL,
                        email_snapshot        = NULL,
                        artifacturl           = NULL,
                        artifactstorage       = NULL
                  WHERE userid {$insql}",
                $params
            );

            // Attempt rows are academic records too, but the direct userid is not
            // required once the account is erased.
            $DB->execute(
                "UPDATE {local_completionhistory_exam_attempt}
                    SET userid = 0
                  WHERE userid {$insql}",
                $params
            );

            // Allocation ids point back to a user-specific enrol_programs row;
            // retain the academic program snapshot but remove that live linkage.
            if ($achievements) {
                [$achievementinsql, $achievementparams] = $DB->get_in_or_equal(
                    array_keys($achievements),
                    SQL_PARAMS_NAMED,
                    'achievement'
                );
                $DB->execute(
                    "UPDATE {local_completionhistory_ach_program}
                        SET allocationid = NULL
                      WHERE achievementid {$achievementinsql}",
                    $achievementparams
                );

                // The correction history keeps the fact that a certificate was attached or cleared,
                // but the values were a user-specific URL and code — they go the way the columns did.
                $DB->execute(
                    "UPDATE {local_completionhistory_ach_revision}
                        SET oldvalue = NULL,
                            newvalue = NULL
                      WHERE achievementid {$achievementinsql}
                        AND fieldname IN ('artifacturl', 'artifactstorage')",
                    $achievementparams
                );
            }

            // Outbox rows contain a denormalized JSON copy of all snapshot PII.
            outbox_service::anonymize_achievement_payloads(array_keys($achievements));
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        return $count;
    }

    /**
     * Find achievement rows that reference a deleted or fully-purged user
     * and anonymize them. Closes three gaps left by the user_deleted
     * observer:
     *   1. Rows captured after the user_deleted event fired (late events,
     *      CLI backfill scanning stale course_completions).
     *   2. Rows captured while the gdpranonymize setting was off and never
     *      retroactively scrubbed after the admin enabled it.
     *   3. Rows whose user record has since been fully purged from {user}.
     *
     * @return stdClass {candidates: int, anonymized: int}
     */
    public static function reconcile_deleted_users(): stdClass {
        global $DB;

        $stats = new stdClass();
        $stats->candidates = 0;
        $stats->anonymized = 0;

        // Include exam-only users as well as users with ledger rows.
        $achievementusers = $DB->get_fieldset_sql(
            "SELECT DISTINCT a.userid
               FROM {local_completionhistory_achievement} a
          LEFT JOIN {user} u ON u.id = a.userid
              WHERE a.userid > 0
                AND (u.id IS NULL OR u.deleted = 1)"
        );
        $attemptusers = $DB->get_fieldset_sql(
            "SELECT DISTINCT ea.userid
               FROM {local_completionhistory_exam_attempt} ea
          LEFT JOIN {user} u ON u.id = ea.userid
              WHERE ea.userid > 0
                AND (u.id IS NULL OR u.deleted = 1)"
        );
        $userids = array_values(array_unique(array_merge($achievementusers, $attemptusers)));

        $stats->candidates = count($userids);
        if (empty($userids)) {
            return $stats;
        }

        $stats->anonymized = self::anonymize_users($userids);
        return $stats;
    }

    /**
     * Record a purge audit entry.
     *
     * @param int         $userid
     * @param int|null    $programid
     * @param string      $reason
     * @param string|null $detailsjson
     * @return int The new purge_audit ID.
     */
    public static function record_purge_audit(int $userid, ?int $programid, string $reason, ?string $detailsjson = null): int {
        global $DB;

        $record              = new stdClass();
        $record->userid      = $userid;
        $record->programid   = $programid;
        $record->reason      = $reason;
        $record->detailsjson = $detailsjson;
        $record->timecreated = time();

        return $DB->insert_record('local_completionhistory_purge_audit', $record);
    }

    /**
     * Compute a deterministic, site-keyed SHA-256 hash for deduplication.
     *
     * A keyed hash prevents the original userid from being recovered by enumerating
     * the small set of candidate ids while retaining the key after anonymization so
     * that a later backfill cannot recreate the same achievement.
     *
     * @param int    $userid
     * @param int    $courseid
     * @param int    $timecompleted
     * @param string $sourcecomponent
     * @return string 64-character hex hash.
     */
    public static function compute_event_hash(int $userid, int $courseid, int $timecompleted, string $sourcecomponent): string {
        return hash_hmac(
            'sha256',
            $userid . '|' . $courseid . '|' . $timecompleted . '|' . $sourcecomponent,
            self::get_hash_secret()
        );
    }

    /**
     * Return the private key for event hashes.
     *
     * The install and upgrade hooks persist a plugin-specific random key. The
     * site-identifier fallback keeps restored or unusually bootstrapped sites
     * deterministic without creating a race between concurrent first events.
     *
     * @return string
     */
    private static function get_hash_secret(): string {
        $secret = (string) get_config('local_completionhistory', 'hashsecret');
        if (strlen($secret) >= 32) {
            return $secret;
        }

        return hash('sha256', 'local_completionhistory|' . get_site_identifier());
    }

    /**
     * Generate a UUID v4.
     *
     * @return string 36-character UUID string.
     */
    public static function generate_uuid(): string {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
