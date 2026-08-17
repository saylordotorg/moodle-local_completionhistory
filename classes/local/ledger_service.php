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
 * Central service for inserting immutable achievement ledger rows.
 *
 * All inserts are idempotent via a deterministic SHA-256 event hash.
 * Rows are never mutated or deleted after insertion.
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
     * Capture an achievement from a course completion record.
     *
     * @param stdClass    $completion      A course_completions record (userid, course, timecompleted).
     * @param string      $sourcecomponent Component that triggered capture.
     * @param string      $sourceevent     Event class name or CLI identifier.
     * @param string|null $exam_track      Exam track that completed the course, or null.
     * @param int|null    $attempts_used   Attempts consumed on the completing track.
     * @param int|null    $attempts_allowed Max attempts allowed on that track (0 = unlimited).
     * @return int|null New achievement ID, or null if already captured (idempotent skip).
     */
    public static function capture_achievement(
        stdClass $completion,
        string $sourcecomponent,
        string $sourceevent,
        ?string $exam_track = null,
        ?int $attempts_used = null,
        ?int $attempts_allowed = null
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
        $coursename      = $course ? $course->fullname  : '[deleted]';
        $courseshortname = $course ? $course->shortname : null;
        $courseidnumber  = $course ? $course->idnumber  : null;

        // Snapshot user fields.
        $user = $DB->get_record('user', ['id' => $userid], 'id, deleted, idnumber, firstname, lastname, email');
        $anonymizeonwrite = (bool) get_config('local_completionhistory', 'gdpranonymize') &&
            (!$user || !empty($user->deleted));
        $useridnumber = ($user && !$anonymizeonwrite) ? $user->idnumber  : null;
        $firstname    = ($user && !$anonymizeonwrite) ? $user->firstname : null;
        $lastname     = ($user && !$anonymizeonwrite) ? $user->lastname  : null;
        $email        = ($user && !$anonymizeonwrite) ? $user->email     : null;

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
        if ($exam_track === null) {
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
                    $exam_track       = $completing->exam_track;
                    $attempts_used    = (int) $completing->attempt_number;
                    $attempts_allowed = (int) $completing->attempts_allowed;
                }
            }
        }

        // Build the achievement record.
        $record                           = new stdClass();
        $record->ledgeruuid               = self::generate_uuid();
        $record->userid                   = $anonymizeonwrite ? 0 : $userid;
        $record->useridnumber_snapshot    = $useridnumber  ?: null;
        $record->firstname_snapshot       = $firstname     ?: null;
        $record->lastname_snapshot        = $lastname      ?: null;
        $record->email_snapshot           = $email         ?: null;
        $record->courseid                 = $courseid;
        $record->courseidnumber_snapshot  = $courseidnumber  ?: null;
        $record->courseshortname_snapshot = $courseshortname;
        $record->coursename_snapshot      = $coursename;
        $record->completiontime           = $timecompleted;
        $record->enrolledtime_snapshot    = $enrolledtime;
        $record->grade_decimal            = $gradedata ? $gradedata->finalgrade : null;
        $record->grade_passed             = $gradedata ? $gradedata->passed     : null;
        $record->grade_source             = $gradedata ? 'gradebook'            : null;
        $record->exam_track               = $exam_track;
        $record->attempts_used            = $attempts_used;
        $record->attempts_allowed         = $attempts_allowed;
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
                $progrecord->programid                = $program->programid    ?? null;
                $progrecord->programidnumber_snapshot = $program->idnumber     ?? null;
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
     * Update the exam context on an existing achievement row.
     * Used when the completing attempt is identified after the achievement
     * was already written (e.g. backfill scenarios).
     *
     * @param int    $achievementid
     * @param string $exam_track
     * @param int    $attempts_used
     * @param int    $attempts_allowed
     */
    public static function set_exam_context(
        int $achievementid,
        string $exam_track,
        int $attempts_used,
        int $attempts_allowed
    ): void {
        global $DB;

        $DB->set_field('local_completionhistory_achievement', 'exam_track',       $exam_track,       ['id' => $achievementid]);
        $DB->set_field('local_completionhistory_achievement', 'attempts_used',    $attempts_used,    ['id' => $achievementid]);
        $DB->set_field('local_completionhistory_achievement', 'attempts_allowed', $attempts_allowed, ['id' => $achievementid]);
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
