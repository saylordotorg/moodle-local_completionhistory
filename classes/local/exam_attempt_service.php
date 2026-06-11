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
 * Records and queries per-attempt exam history.
 *
 * This service is the write path for individual quiz attempt outcomes
 * on configured exam tracks. It does not itself trigger achievement
 * capture — that remains the responsibility of the completion observer
 * and ledger_service. The link between an attempt and the resulting
 * achievement is set via link_to_achievement() once the achievement row
 * is confirmed.
 *
 * Typical flow for a program_final attempt:
 *   1. Quiz attempt finishes → quiz_attempt_submitted observer fires.
 *   2. Observer calls exam_attempt_service::record_attempt().
 *   3. If the attempt passed AND the course completes → ledger_service
 *      captures the achievement with exam context.
 *   4. Observer calls exam_attempt_service::link_to_achievement().
 *
 * For open_dual courses:
 *   - DC attempts are recorded normally.
 *   - After 3 failed DC attempts the track is exhausted; cert attempts
 *     begin (or may have been running in parallel — policy decision).
 *   - The cert attempt that passes is linked to the achievement.
 *   - The failed DC attempts remain as unlinked audit rows.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class exam_attempt_service {

    /**
     * Record a single exam attempt.
     *
     * @param int        $userid
     * @param int        $courseid
     * @param int|null   $quizid           Quiz instance ID (null if recorded manually).
     * @param string     $exam_track       One of course_config_service::TRACK_* constants.
     * @param int        $attempts_allowed Max attempts on this track (0 = unlimited).
     * @param float|null $grade            Raw grade (0–100 scale or null).
     * @param bool|null  $passed           True/false/null if no pass threshold.
     * @param int        $timetaken        Unix timestamp when attempt was submitted.
     * @return int New attempt record ID.
     */
    public static function record_attempt(
        int $userid,
        int $courseid,
        ?int $quizid,
        string $exam_track,
        int $attempts_allowed,
        ?float $grade,
        ?bool $passed,
        int $timetaken,
        ?int $duration = null
    ): int {
        global $DB;

        // Compute next attempt number for this user/course/track.
        $attempt_number = self::count_attempts_on_track($userid, $courseid, $exam_track) + 1;

        $record = new stdClass();
        $record->userid                  = $userid;
        $record->courseid                = $courseid;
        $record->quizid                  = $quizid;
        $record->exam_track              = $exam_track;
        $record->attempt_number          = $attempt_number;
        $record->attempts_allowed        = $attempts_allowed;
        $record->grade_decimal           = $grade !== null ? round($grade, 5) : null;
        $record->grade_passed            = $passed !== null ? ($passed ? 1 : 0) : null;
        $record->resulted_in_completion  = 0; // Updated later if course completes.
        $record->achievementid           = null;
        $record->timetaken               = $timetaken;
        $record->duration                = ($duration !== null && $duration >= 0) ? $duration : null;
        $record->timecreated             = time();

        return $DB->insert_record('local_completionhistory_exam_attempt', $record);
    }

    /**
     * Mark an attempt as the one that resulted in course completion,
     * and link it to the achievement record.
     *
     * @param int $attemptid     The exam_attempt.id to update.
     * @param int $achievementid The achievement.id to link.
     */
    public static function link_to_achievement(int $attemptid, int $achievementid): void {
        global $DB;

        $DB->set_field('local_completionhistory_exam_attempt', 'resulted_in_completion', 1, ['id' => $attemptid]);
        $DB->set_field('local_completionhistory_exam_attempt', 'achievementid', $achievementid, ['id' => $attemptid]);
    }

    /**
     * Count attempts a user has made on a specific track for a course.
     *
     * @param int    $userid
     * @param int    $courseid
     * @param string $exam_track
     * @return int
     */
    public static function count_attempts_on_track(int $userid, int $courseid, string $exam_track): int {
        global $DB;

        return (int) $DB->count_records('local_completionhistory_exam_attempt', [
            'userid'     => $userid,
            'courseid'   => $courseid,
            'exam_track' => $exam_track,
        ]);
    }

    /**
     * Check whether a user has exhausted all allowed attempts on a track.
     *
     * @param int    $userid
     * @param int    $courseid
     * @param string $exam_track
     * @param int    $attempts_allowed 0 = unlimited (never exhausted).
     * @return bool
     */
    public static function has_exhausted_track(int $userid, int $courseid, string $exam_track, int $attempts_allowed): bool {
        if ($attempts_allowed === 0) {
            return false; // Unlimited.
        }
        return self::count_attempts_on_track($userid, $courseid, $exam_track) >= $attempts_allowed;
    }

    /**
     * Get all attempt records for a user/course, ordered by track then attempt number.
     *
     * @param int      $userid
     * @param int      $courseid
     * @param string[] $tracks Optional filter to specific tracks.
     * @return stdClass[]
     */
    public static function get_attempts(int $userid, int $courseid, array $tracks = []): array {
        global $DB;

        $params = ['userid' => $userid, 'courseid' => $courseid];

        if (!empty($tracks)) {
            [$insql, $inparams] = $DB->get_in_or_equal($tracks, SQL_PARAMS_NAMED, 'track');
            $sql = "SELECT * FROM {local_completionhistory_exam_attempt}
                     WHERE userid = :userid AND courseid = :courseid AND exam_track {$insql}
                     ORDER BY exam_track, attempt_number";
            $params = array_merge($params, $inparams);
        } else {
            $sql = "SELECT * FROM {local_completionhistory_exam_attempt}
                     WHERE userid = :userid AND courseid = :courseid
                     ORDER BY exam_track, attempt_number";
        }

        return array_values($DB->get_records_sql($sql, $params));
    }

    /**
     * Get attempt records for a specific achievement (the completing attempt
     * plus all prior attempts on the same user/course).
     *
     * @param int $achievementid
     * @return stdClass[]
     */
    public static function get_attempts_for_achievement(int $achievementid): array {
        global $DB;

        // Find the userid + courseid from the achievement.
        $ach = $DB->get_record('local_completionhistory_achievement', ['id' => $achievementid], 'userid, courseid');
        if (!$ach) {
            return [];
        }

        return self::get_attempts((int) $ach->userid, (int) $ach->courseid);
    }

    /**
     * Summarise attempts per track for a user/course.
     * Returns an array keyed by track name, each with:
     *   total, passed, failed, exhausted, attempts_allowed.
     *
     * @param int $userid
     * @param int $courseid
     * @return array
     */
    public static function summarise_by_track(int $userid, int $courseid): array {
        global $DB;

        $rows = self::get_attempts($userid, $courseid);
        $summary = [];

        foreach ($rows as $row) {
            $track = $row->exam_track;
            if (!isset($summary[$track])) {
                $summary[$track] = [
                    'track'            => $track,
                    'total'            => 0,
                    'passed'           => 0,
                    'failed'           => 0,
                    'attempts_allowed' => (int) $row->attempts_allowed,
                    'exhausted'        => false,
                ];
            }
            $summary[$track]['total']++;
            if ($row->grade_passed === '1' || $row->grade_passed === 1) {
                $summary[$track]['passed']++;
            } elseif ($row->grade_passed === '0' || $row->grade_passed === 0) {
                $summary[$track]['failed']++;
            }
        }

        // Determine exhaustion.
        foreach ($summary as $track => &$info) {
            $allowed = $info['attempts_allowed'];
            $info['exhausted'] = ($allowed > 0 && $info['total'] >= $allowed);
        }
        unset($info);

        return $summary;
    }
}
