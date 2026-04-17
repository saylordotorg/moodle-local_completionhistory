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
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ledger_service {

    /**
     * Capture an achievement from a course completion record.
     *
     * @param stdClass $completion A course_completions record (must have userid, course, timecompleted).
     * @param string $sourcecomponent Component that triggered capture (e.g. 'core_completion', 'cli_backfill').
     * @param string $sourceevent Event class name or CLI command identifier.
     * @return int|null The new achievement ID, or null if already captured (idempotent skip).
     */
    public static function capture_achievement(stdClass $completion, string $sourcecomponent, string $sourceevent): ?int {
        global $DB;

        $userid = (int) $completion->userid;
        $courseid = (int) $completion->course;
        $timecompleted = (int) $completion->timecompleted;

        // Compute deterministic dedup hash.
        $hash = self::compute_event_hash($userid, $courseid, $timecompleted, $sourcecomponent);

        // Idempotency check.
        if ($DB->record_exists('local_completionhistory_achievement', ['source_event_hash' => $hash])) {
            return null;
        }

        // Snapshot course metadata.
        $course = $DB->get_record('course', ['id' => $courseid]);
        $coursename = $course ? $course->fullname : '[deleted]';
        $courseshortname = $course ? $course->shortname : null;
        $courseidnumber = $course ? $course->idnumber : null;

        // Snapshot user fields.
        $user = $DB->get_record('user', ['id' => $userid], 'id, idnumber, firstname, lastname, email');
        $useridnumber = $user ? $user->idnumber   : null;
        $firstname    = $user ? $user->firstname  : null;
        $lastname     = $user ? $user->lastname   : null;
        $email        = $user ? $user->email      : null;

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
            // Prefer timestart when set (> 0), otherwise fall back to timecreated.
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

        // Build the achievement record.
        $record = new stdClass();
        $record->ledgeruuid = self::generate_uuid();
        $record->userid = $userid;
        $record->useridnumber_snapshot = $useridnumber ?: null;
        $record->firstname_snapshot    = $firstname    ?: null;
        $record->lastname_snapshot     = $lastname     ?: null;
        $record->email_snapshot        = $email        ?: null;
        $record->courseid = $courseid;
        $record->courseidnumber_snapshot = $courseidnumber ?: null;
        $record->courseshortname_snapshot = $courseshortname;
        $record->coursename_snapshot = $coursename;
        $record->completiontime        = $timecompleted;
        $record->enrolledtime_snapshot = $enrolledtime;
        $record->grade_decimal = $gradedata ? $gradedata->finalgrade : null;
        $record->grade_passed = $gradedata ? $gradedata->passed : null;
        $record->grade_source = $gradedata ? 'gradebook' : null;
        $record->artifacturl = null;
        $record->artifactstorage = null;
        $record->source_component = $sourcecomponent;
        $record->source_event = $sourceevent;
        $record->source_event_hash = $hash;
        $record->timecreated = time();

        // Wrap in transaction: achievement + program rows.
        $transaction = $DB->start_delegated_transaction();
        try {
            $achievementid = $DB->insert_record('local_completionhistory_achievement', $record);

            // Insert program association rows.
            foreach ($programs as $program) {
                $progrecord = new stdClass();
                $progrecord->achievementid = $achievementid;
                $progrecord->allocationid = $program->allocationid ?? null;
                $progrecord->programid = $program->programid ?? null;
                $progrecord->programidnumber_snapshot = $program->idnumber ?? null;
                $progrecord->programname_snapshot = $program->fullname;
                $progrecord->timecreated = time();
                $DB->insert_record('local_completionhistory_ach_program', $progrecord);
            }

            $transaction->allow_commit();
        } catch (\Exception $e) {
            $transaction->rollback($e);
            throw $e;
        }

        return $achievementid;
    }

    /**
     * Record a purge audit entry.
     *
     * @param int $userid
     * @param int|null $programid
     * @param string $reason
     * @param string|null $detailsjson
     * @return int The new purge_audit ID.
     */
    public static function record_purge_audit(int $userid, ?int $programid, string $reason, ?string $detailsjson = null): int {
        global $DB;

        $record = new stdClass();
        $record->userid = $userid;
        $record->programid = $programid;
        $record->reason = $reason;
        $record->detailsjson = $detailsjson;
        $record->timecreated = time();

        return $DB->insert_record('local_completionhistory_purge_audit', $record);
    }

    /**
     * Compute deterministic SHA-256 hash for deduplication.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $timecompleted
     * @param string $sourcecomponent
     * @return string 64-character hex hash.
     */
    public static function compute_event_hash(int $userid, int $courseid, int $timecompleted, string $sourcecomponent): string {
        return hash('sha256', $userid . '|' . $courseid . '|' . $timecompleted . '|' . $sourcecomponent);
    }

    /**
     * Generate a UUID v4.
     *
     * @return string 36-character UUID string.
     */
    public static function generate_uuid(): string {
        $data = random_bytes(16);
        // Set version to 0100 (UUID v4).
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        // Set variant to 10xx.
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
