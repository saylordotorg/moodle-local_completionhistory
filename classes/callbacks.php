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

        // Always log the deletion as audit.
        if (get_config('local_completionhistory', 'enablepurgeaudit')) {
            $count = $DB->count_records('local_completionhistory_achievement', ['userid' => $userid]);
            ledger_service::record_purge_audit(
                $userid,
                null,
                'user_deleted',
                json_encode([
                    'achievement_rows_affected' => $count,
                    'anonymized' => (bool) get_config('local_completionhistory', 'gdpranonymize'),
                ])
            );
        }

        // Anonymize if configured.
        if (get_config('local_completionhistory', 'gdpranonymize')) {
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
}
