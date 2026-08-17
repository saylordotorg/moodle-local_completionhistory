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

namespace local_completionhistory;

use local_completionhistory\local\ledger_service;
use local_completionhistory\local\exam_attempt_service;
use local_completionhistory\local\course_config_service;
use local_completionhistory\local\artifact_service;
use local_completionhistory\local\grade_snapshot_service;
use local_completionhistory\local\outbox_service;
use local_completionhistory\hook\course_completions_purged;

/**
 * Event observer and hook callback methods for local_completionhistory.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class callbacks {

    /**
     * Decimal places `local_completionhistory_achievement.grade_decimal` actually stores.
     *
     * Mirrors `DECIMALS="5"` on that field in db/install.xml. Comparing a stored grade to a fresh
     * gradebook read needs a tolerance, and the only defensible one is the column's own precision:
     * anything finer is comparing noise the database cannot hold.
     */
    private const GRADE_DECIMALS = 5;

    /**
     * Observer for \core\event\course_completed.
     *
     * Captures an immutable achievement record when a user completes a course.
     *
     * @param \core\event\course_completed $event
     */
    public static function course_completed(\core\event\course_completed $event): void {
        if (!get_config('local_completionhistory', 'enabled')) {
            return;
        }
        if (!get_config('local_completionhistory', 'autocapture')) {
            return;
        }

        global $DB;

        $userid = (int) $event->relateduserid;
        $courseid = (int) $event->courseid;

        // Load the canonical completion record.
        $completion = $DB->get_record('course_completions', [
            'userid' => $userid,
            'course' => $courseid,
        ]);

        if (!$completion || empty($completion->timecompleted)) {
            // Fallback: use event time if completion record is unavailable.
            $completion = new \stdClass();
            $completion->userid = $userid;
            $completion->course = $courseid;
            $completion->timecompleted = $event->timecreated;
        }

        try {
            ledger_service::capture_achievement(
                $completion,
                'core_completion',
                '\\core\\event\\course_completed'
            );
        } catch (\Exception $e) {
            debugging(
                'local_completionhistory: Failed to capture achievement for user '
                . $userid . ', course ' . $courseid . ': ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
        }
    }

    /**
     * Observer for \core\event\course_deleted.
     *
     * Does NOT delete achievement rows. Logs an audit entry noting that
     * the source course was deleted but achievement records are preserved.
     *
     * @param \core\event\course_deleted $event
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        if (!get_config('local_completionhistory', 'enabled')) {
            return;
        }
        if (!get_config('local_completionhistory', 'enablepurgeaudit')) {
            return;
        }

        global $DB;

        $courseid = (int) $event->objectid;

        // Count how many achievement records reference this course.
        $count = $DB->count_records('local_completionhistory_achievement', ['courseid' => $courseid]);

        if ($count > 0) {
            // Log audit for visibility — we intentionally preserve the rows.
            ledger_service::record_purge_audit(
                0, // No specific user.
                null,
                'course_deleted',
                json_encode([
                    'courseid' => $courseid,
                    'achievement_rows_preserved' => $count,
                    'event_class' => '\\core\\event\\course_deleted',
                ])
            );
        }
    }

    /**
     * Observer for \core\event\course_updated.
     *
     * Does NOT mutate existing achievement rows. Existing snapshot data
     * is intentionally immutable. This observer is a no-op placeholder
     * for potential future admin cache refresh.
     *
     * @param \core\event\course_updated $event
     */
    public static function course_updated(\core\event\course_updated $event): void {
        // Intentional no-op. Achievement rows are immutable snapshots.
        // Future: could trigger admin notification if course name changed
        // and there are existing achievement records referencing it.
    }

    /**
     * Observer for \core\event\user_deleted.
     *
     * If GDPR anonymize setting is enabled, anonymizes achievement records
     * by setting userid to 0 and clearing PII fields. Achievement records
     * are institutional academic records and are NOT deleted.
     *
     * @param \core\event\user_deleted $event
     */
    public static function user_deleted(\core\event\user_deleted $event): void {
        if (!get_config('local_completionhistory', 'enabled')) {
            return;
        }

        global $DB;

        $userid = (int) $event->objectid;

        $anonymize = (bool) get_config('local_completionhistory', 'gdpranonymize');

        // Always log the deletion as audit. Do not reintroduce a direct user id
        // into the audit table when this event is also anonymizing the records.
        if (get_config('local_completionhistory', 'enablepurgeaudit')) {
            $count = $DB->count_records('local_completionhistory_achievement', ['userid' => $userid]);
            ledger_service::record_purge_audit(
                $anonymize ? 0 : $userid,
                null,
                'user_deleted',
                json_encode([
                    'achievement_rows_affected' => $count,
                    'anonymized' => $anonymize,
                ])
            );
        }

        // Anonymize if configured.
        if ($anonymize) {
            ledger_service::anonymize_users([$userid]);
        }
    }

    /**
     * Observer for \mod_quiz\event\attempt_submitted.
     *
     * If the submitted quiz is configured as a tracked exam on the course,
     * records a per-attempt row in local_completionhistory_exam_attempt.
     *
     * @param \mod_quiz\event\attempt_submitted $event
     */
    public static function quiz_attempt_submitted(\mod_quiz\event\attempt_submitted $event): void {
        if (!get_config('local_completionhistory', 'enabled')) {
            return;
        }

        global $DB;

        $attempt = $DB->get_record('quiz_attempts',
            ['id' => (int) $event->objectid],
            'id, quiz, userid, sumgrades, timestart, timefinish, state'
        );
        if (!$attempt || $attempt->state !== 'finished') {
            return;
        }

        $quizid   = (int) $attempt->quiz;
        $userid   = (int) $attempt->userid;
        $courseid = (int) $event->courseid;

        // Only record if this quiz is a tracked exam for its course.
        $trackinfo = course_config_service::get_track_for_quiz($quizid);
        if (!$trackinfo) {
            return;
        }

        $quiz = $DB->get_record('quiz', ['id' => $quizid], 'id, sumgrades, grade');
        if (!$quiz) {
            return;
        }

        // Normalise this attempt's grade to 0–100.
        $grade = null;
        if ($attempt->sumgrades !== null && $quiz->sumgrades > 0) {
            $grade = ((float) $attempt->sumgrades / (float) $quiz->sumgrades) * 100.0;
        }

        // Pass threshold from the grade item, converted to the same 0–100 scale.
        $passed = null;
        if ($grade !== null) {
            $gitem = $DB->get_record('grade_items', [
                'itemtype'     => 'mod',
                'itemmodule'   => 'quiz',
                'iteminstance' => $quizid,
                'courseid'     => $courseid,
            ]);
            if ($gitem && (float) $gitem->gradepass > 0 && (float) $quiz->grade > 0) {
                $passthreshpct = ((float) $gitem->gradepass / (float) $quiz->grade) * 100.0;
                $passed = ($grade >= $passthreshpct);
            }
        }

        $timefinish = (int) ($attempt->timefinish ?: $event->timecreated);
        $timestart  = (int) ($attempt->timestart ?: 0);
        $duration   = ($timestart > 0 && $timefinish > $timestart)
            ? ($timefinish - $timestart)
            : null;

        try {
            exam_attempt_service::record_attempt(
                $userid,
                $courseid,
                $quizid,
                $trackinfo->track,
                (int) $trackinfo->attempts_allowed,
                $grade,
                $passed,
                $timefinish,
                $duration
            );
        } catch (\Exception $e) {
            debugging(
                'local_completionhistory: Failed to record exam attempt for user '
                . $userid . ', quiz ' . $quizid . ': ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
        }
    }

    /**
     * Observer for \tool_certificate\event\certificate_issued.
     *
     * Attaches Moodle Workplace course certificates to the matching immutable
     * achievement row, then republishes that row through the SIS outbox.
     *
     * @param \core\event\base $event
     */
    public static function certificate_issued(\core\event\base $event): void {
        if (!get_config('local_completionhistory', 'enabled')) {
            return;
        }

        try {
            artifact_service::attach_certificate_issue((int) $event->objectid);
        } catch (\Exception $e) {
            debugging(
                'local_completionhistory: Failed to attach certificate issue '
                . (int) $event->objectid . ': ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
        }
    }

    /**
     * Observer for \tool_certificate\event\certificate_revoked.
     *
     * Clears the certificate link from the matching achievement row and
     * republishes that row so the SIS removes stale credential links.
     *
     * @param \core\event\base $event
     */
    public static function certificate_revoked(\core\event\base $event): void {
        if (!get_config('local_completionhistory', 'enabled')) {
            return;
        }

        try {
            $issue = $event->get_record_snapshot('tool_certificate_issues', (int) $event->objectid);
            $userid = (int) ($issue->userid ?? $event->relateduserid);
            $courseid = (int) ($issue->courseid ?? 0);
            if ($courseid <= 0 && $event->contextlevel === CONTEXT_COURSE) {
                $courseid = (int) $event->contextinstanceid;
            }
            $code = (string) ($issue->code ?? ($event->other['code'] ?? ''));

            artifact_service::clear_certificate_issue(
                $userid,
                $courseid,
                $code
            );
        } catch (\Exception $e) {
            debugging(
                'local_completionhistory: Failed to clear certificate issue '
                . (int) $event->objectid . ': ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
        }
    }

    /**
     * Hook callback for course_completions_purged.
     *
     * Writes a purge audit row. Does NOT alter or delete achievement records.
     * The ledger is intentionally durable even when active Moodle completion
     * records are reset by enrol_programs or other components.
     *
     * @param course_completions_purged $hook
     */
    public static function completions_purged(course_completions_purged $hook): void {
        if (!get_config('local_completionhistory', 'enabled')) {
            return;
        }
        if (!get_config('local_completionhistory', 'enablepurgeaudit')) {
            return;
        }

        ledger_service::record_purge_audit(
            $hook->userid,
            $hook->programid,
            $hook->reason,
            json_encode([
                'courseid' => $hook->courseid,
                'purged_completion_ids' => $hook->purgedids,
            ])
        );
    }

    /**
     * A course grade changed after the completion was already recorded.
     *
     * WHAT THIS CLOSES. `course_completed` snapshots the gradebook total once, at the moment
     * completion fires. Nothing observed a later change, so a teacher regrading an exam left the SIS
     * — and the student's record page, and any transcript printed from it — showing the original
     * figure indefinitely. `reconcile_ledger` did not help: `backfill_service` is insert-only and
     * counts an existing row as skipped, so it fills gaps and never revises one.
     *
     * THE COURSE TOTAL ONLY. `user_graded` fires for every grade item — each quiz, each assignment —
     * and the SIS holds one grade per completed course, taken from the course total. Acting on the
     * others would enqueue a sync per marked question and could overwrite the course figure with an
     * activity's.
     *
     * ONLY FOR AN ALREADY-LEDGERED COMPLETION. With no ledger row there is nothing to correct: the
     * course is not complete, and `course_completed` will capture the grade when it becomes so. That
     * also keeps this observer silent for the ordinary case of marking coursework in progress.
     *
     * REVISES THE LEDGER ROW RATHER THAN ADDING ONE. A second row for one completion would make the
     * ledger contradict itself, and the SIS keys on `ledgeruuid`, so a correction has to travel under
     * the same identity. `source_event_hash` is deliberately untouched: the row's identity is still
     * the completion it came from, and rewriting it would let the backfill later insert a duplicate
     * for that same completion.
     *
     * @param \core\event\user_graded $event
     */
    public static function user_graded(\core\event\user_graded $event): void {
        if (!get_config('local_completionhistory', 'enabled')) {
            return;
        }
        // The same switch that governs whether a grade was ever snapshotted. With grade capture off
        // the ledger holds no grade to correct.
        if (!get_config('local_completionhistory', 'capturegrades')) {
            return;
        }

        global $DB;

        $userid = (int) $event->relateduserid;
        $courseid = (int) $event->courseid;
        if ($userid <= 0 || $courseid <= 0) {
            return;
        }

        // The course total, or nothing at all.
        $itemid = (int) ($event->other['itemid'] ?? 0);
        if ($itemid <= 0) {
            return;
        }
        $item = $DB->get_record('grade_items', ['id' => $itemid], 'id, courseid, itemtype');
        if (!$item || $item->itemtype !== 'course' || (int) $item->courseid !== $courseid) {
            return;
        }

        // Only an existing ledger row can be corrected. Newest first, so a course completed more than
        // once has its most recent record corrected rather than an older one.
        //
        // THIS READ ONLY DECIDES WHETHER THERE IS WORK TO DO — it is never the row that gets written,
        // and it is not what the outbox payload is built from. Both of those happen inside the
        // transaction, from a row read there; see apply_grade_correction. So the four columns the
        // decision needs are all it selects.
        $rows = $DB->get_records_select(
            'local_completionhistory_achievement',
            'userid = :userid AND courseid = :courseid',
            ['userid' => $userid, 'courseid' => $courseid],
            'completiontime DESC, id DESC',
            'id, completiontime, grade_decimal, grade_passed',
            0,
            1
        );
        $achievement = $rows ? reset($rows) : null;
        if (!$achievement) {
            return;
        }

        $snapshot = grade_snapshot_service::get_course_total($userid, $courseid);
        if ($snapshot === null) {
            // The total was cleared rather than changed. Left alone deliberately: erasing a grade a
            // student has already been told they earned is not a correction to make unattended.
            return;
        }

        // NOTHING TO DO WHEN NOTHING CHANGED. Moodle recalculates and re-fires this event freely —
        // saving one gradebook page can emit it for every enrolled user — so an unconditional write
        // would enqueue a sync per event and re-send an identical grade on every recalculation.
        if (!self::grade_differs($achievement, $snapshot)) {
            return;
        }

        // EVERYTHING BELOW RE-READS AND RE-DECIDES INSIDE THE TRANSACTION. The row above was loaded
        // to decide whether there is anything to do; it must not be the row that gets written. See
        // apply_grade_correction.
        $transaction = $DB->start_delegated_transaction();
        try {
            $enqueued = self::apply_grade_correction((int) $achievement->id, $snapshot);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            throw $e;
        }

        // AFTER the commit, so a rolled-back attempt cannot complain about transport.
        //
        // `enqueue_achievement` returns 0 when `enableoutbox` is off, which is the SHIPPED DEFAULT,
        // and the outbox is the only channel a correction can travel on: `get_recent_achievements`
        // pages on `timecreated > :since`, and a correction deliberately preserves `timecreated` so
        // the ledger row keeps the identity of the completion it came from. So with the outbox
        // disabled the ledger is corrected and the SIS is never told. That is a real gap in the pull
        // contract rather than something to paper over here — tracked as SIS-125 — but it must at
        // least be audible instead of silent. Raised in developer mode only: on a site that has
        // deliberately left the outbox off, this would otherwise be noise on every regrade.
        if ($enqueued === 0) {
            debugging(
                'local_completionhistory: corrected the stored grade for achievement '
                . (int) $achievement->id . ' but could not queue it for the SIS because the '
                . '"enableoutbox" setting is off. The timestamp pull endpoint cannot see corrections '
                . '(it filters on timecreated, which a correction preserves), so this change will not '
                . 'reach the SIS until the outbox is enabled.',
                DEBUG_DEVELOPER
            );
        }
    }

    /**
     * Has the course total actually moved?
     *
     * COMPARED AS NUMBERS AT THE COLUMN'S OWN PRECISION, not as strings. `grade_decimal` is
     * `number(10,5)`, and Moodle's database layer hands a numeric column back as a padded decimal
     * STRING — '88.50000' — while `grade_snapshot_service::get_course_total` casts the gradebook
     * value to a float, whose string form is '88.5'. The first version of this compared
     * `(string) $a->grade_decimal === (string) $snapshot->finalgrade`, so two numerically identical
     * grades never matched and the guard above inverted into its opposite: every subsequent course
     * total event rewrote the row and appended another outbox message, which is precisely the
     * flood the guard exists to prevent. It passed the behavioural harness only because the fixture
     * held PHP floats rather than the strings a real read returns. Found in review on PR #8.
     *
     * `grade_passed` is compared as a nullable int so that NULL (never captured) stays distinct
     * from 0 (captured as a fail) — under the old string cast both became ''.
     *
     * @param \stdClass $a        The stored achievement row.
     * @param \stdClass $snapshot The current gradebook total.
     * @return bool
     */
    private static function grade_differs(\stdClass $a, \stdClass $snapshot): bool {
        // Half a unit in the last place the column can actually store.
        $epsilon = 0.5 * (10 ** -self::GRADE_DECIMALS);

        $stored = $a->grade_decimal === null ? null : (float) $a->grade_decimal;
        $fresh = (float) $snapshot->finalgrade;
        if ($stored === null || abs($stored - $fresh) >= $epsilon) {
            return true;
        }

        $storedpassed = $a->grade_passed === null ? null : (int) $a->grade_passed;
        $freshpassed = $snapshot->passed === null ? null : (int) $snapshot->passed;
        return $storedpassed !== $freshpassed;
    }

    /**
     * Write the correction and queue it, deciding again from a row read inside the transaction.
     *
     * WHY THE ROW IS RE-READ AND ONLY FOUR COLUMNS ARE WRITTEN. `update_record` writes every
     * property present on the object it is given, and the row used for the decision above was read
     * BEFORE the transaction opened. If anything else mutated that row in between, passing the
     * stale copy back would silently revert it — and the mutation that matters is
     * `ledger_service::anonymize_users()`, which sets `userid = 0` and NULLs
     * `useridnumber_snapshot`, `firstname_snapshot`, `lastname_snapshot`, `email_snapshot`,
     * `artifacturl` and `artifactstorage` when a user is deleted. A regrade landing in that window
     * would have restored a deleted student's identity AND published it to the SIS through the
     * outbox payload. Found in review on PR #8.
     *
     * So: re-read, refuse if the row is gone or has been anonymized, write only the grade columns,
     * and build the outbox payload from a row read back after the write — never from the copy this
     * method was reasoning about.
     *
     * @param int       $id       Achievement id.
     * @param \stdClass $snapshot The gradebook total to store.
     * @return int Outbox row id, or 0 when nothing was queued.
     */
    private static function apply_grade_correction(int $id, \stdClass $snapshot): int {
        global $DB;

        $current = $DB->get_record('local_completionhistory_achievement', ['id' => $id]);
        if (!$current) {
            // Deleted between the read and here — a purge, most likely. Nothing to correct.
            return 0;
        }
        if ((int) $current->userid === 0) {
            // Anonymized while this observer was deciding. The student has been deleted; correcting
            // and re-publishing their grade is not something to do on the way past.
            return 0;
        }
        if (!self::grade_differs($current, $snapshot)) {
            // Another event already applied it. Not an error, and not worth a second outbox row.
            return 0;
        }

        // Only the grade. Every other column stays exactly as the row currently holds it.
        $DB->update_record('local_completionhistory_achievement', (object) [
            'id' => $id,
            'grade_decimal' => $snapshot->finalgrade,
            'grade_passed' => $snapshot->passed,
            'grade_source' => 'gradebook',
        ]);

        // Read back rather than reusing $current: the payload must describe what is now stored,
        // and build_achievement_payload reads the snapshot fields straight off whatever it is given.
        $stored = $DB->get_record('local_completionhistory_achievement', ['id' => $id]);
        if (!$stored) {
            return 0;
        }
        return outbox_service::enqueue_achievement($stored);
    }
}
