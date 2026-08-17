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
 * Manages per-course exam assessment model configuration.
 *
 * Course types:
 *   standard    — No exam tracking. Default for unconfigured courses.
 *   program     — One program final exam, limited attempts (default 3).
 *   open_dual   — Direct credit exam + certificate final exam. DC has limited
 *                 attempts; cert is unlimited. Failing DC does not block cert.
 *   open_cert   — Certificate final exam only, unlimited attempts.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_config_service {

    // ── Course type constants ────────────────────────────────────────────────

    const TYPE_STANDARD  = 'standard';
    const TYPE_PROGRAM   = 'program';
    const TYPE_OPEN_DUAL = 'open_dual';
    const TYPE_OPEN_CERT = 'open_cert';

    // ── Exam track constants ─────────────────────────────────────────────────

    const TRACK_PROGRAM_FINAL = 'program_final';
    const TRACK_DIRECT_CREDIT = 'direct_credit';
    const TRACK_CERTIFICATE   = 'certificate';

    /**
     * Human-readable labels for course types.
     */
    public static function type_labels(): array {
        return [
            self::TYPE_STANDARD  => get_string('course_type_standard', 'local_completionhistory'),
            self::TYPE_PROGRAM   => get_string('course_type_program', 'local_completionhistory'),
            self::TYPE_OPEN_DUAL => get_string('course_type_open_dual', 'local_completionhistory'),
            self::TYPE_OPEN_CERT => get_string('course_type_open_cert', 'local_completionhistory'),
        ];
    }

    /**
     * Human-readable labels for exam tracks.
     */
    public static function track_labels(): array {
        return [
            self::TRACK_PROGRAM_FINAL => get_string('track_program_final', 'local_completionhistory'),
            self::TRACK_DIRECT_CREDIT => get_string('track_direct_credit', 'local_completionhistory'),
            self::TRACK_CERTIFICATE   => get_string('track_certificate', 'local_completionhistory'),
        ];
    }

    /**
     * Get the exam config for a course. Returns defaults if not configured.
     *
     * @param int $courseid
     * @return stdClass Config record with all fields populated.
     */
    public static function get_config(int $courseid): stdClass {
        global $DB;

        $config = $DB->get_record('local_completionhistory_course_exam_config', ['courseid' => $courseid]);
        if ($config) {
            return $config;
        }

        // Return a default (unsaved) config object.
        $default = new stdClass();
        $default->id                      = 0;
        $default->courseid                = $courseid;
        $default->course_type             = self::TYPE_STANDARD;
        $default->program_final_quizid    = null;
        $default->dc_quizid               = null;
        $default->cert_quizid             = null;
        $default->program_attempts_allowed = 3;
        $default->dc_attempts_allowed      = 3;
        $default->cert_attempts_allowed    = 0; // unlimited
        $default->notes                   = '';
        $default->timecreated             = 0;
        $default->timemodified            = 0;
        return $default;
    }

    /**
     * Save (insert or update) a course exam config.
     *
     * @param stdClass $config Must have courseid and course_type set.
     * @return int Record ID.
     */
    public static function save_config(stdClass $config): int {
        global $DB;

        $config = self::validate_config($config);
        $now = time();
        $existing = $DB->get_record('local_completionhistory_course_exam_config', ['courseid' => $config->courseid]);

        if ($existing) {
            $config->id           = $existing->id;
            $config->timecreated  = $existing->timecreated;
            $config->timemodified = $now;
            $DB->update_record('local_completionhistory_course_exam_config', $config);
            return $config->id;
        }

        $config->timecreated  = $now;
        $config->timemodified = $now;
        return $DB->insert_record('local_completionhistory_course_exam_config', $config);
    }

    /**
     * Delete the exam config for a course (resets to 'standard' defaults).
     *
     * @param int $courseid
     */
    public static function delete_config(int $courseid): void {
        global $DB;
        $DB->delete_records('local_completionhistory_course_exam_config', ['courseid' => $courseid]);
    }

    /**
     * Get all configured courses (paginated).
     *
     * @param int $page    0-based page number.
     * @param int $perpage Records per page.
     * @return array ['configs' => stdClass[], 'total' => int]
     */
    public static function get_all_configs(int $page = 0, int $perpage = 50): array {
        global $DB;

        $page = max(0, $page);
        $perpage = max(1, min(100, $perpage));
        $total   = $DB->count_records('local_completionhistory_course_exam_config');
        $configs = $DB->get_records(
            'local_completionhistory_course_exam_config',
            null,
            'timemodified DESC',
            '*',
            $page * $perpage,
            $perpage
        );

        return ['configs' => array_values($configs), 'total' => $total];
    }

    /**
     * Validate references and bounded values before persisting configuration.
     *
     * @param stdClass $config Submitted configuration.
     * @return stdClass Sanitized clone.
     */
    private static function validate_config(stdClass $config): stdClass {
        global $DB;

        $config = clone $config;
        $config->courseid = (int) ($config->courseid ?? 0);
        if ($config->courseid <= 0 || !$DB->record_exists('course', ['id' => $config->courseid])) {
            throw new \invalid_parameter_exception('A valid course is required.');
        }

        $types = array_keys(self::type_labels());
        if (!in_array($config->course_type ?? '', $types, true)) {
            throw new \invalid_parameter_exception('Unknown course exam type.');
        }

        $quizfields = ['program_final_quizid', 'dc_quizid', 'cert_quizid'];
        $quizids = [];
        foreach ($quizfields as $field) {
            $quizid = empty($config->$field) ? null : (int) $config->$field;
            if ($quizid !== null && !$DB->record_exists('quiz', [
                    'id' => $quizid,
                    'course' => $config->courseid,
                ])) {
                throw new \invalid_parameter_exception('Every selected quiz must belong to the configured course.');
            }
            if ($quizid !== null && in_array($quizid, $quizids, true)) {
                throw new \invalid_parameter_exception('A quiz cannot be assigned to more than one exam track.');
            }
            $config->$field = $quizid;
            if ($quizid !== null) {
                $quizids[] = $quizid;
            }
        }

        foreach (['program_attempts_allowed', 'dc_attempts_allowed', 'cert_attempts_allowed'] as $field) {
            $value = (int) ($config->$field ?? 0);
            if ($value < 0 || $value > 99) {
                throw new \invalid_parameter_exception('Attempts allowed must be between 0 and 99.');
            }
            $config->$field = $value;
        }

        $config->notes = clean_param((string) ($config->notes ?? ''), PARAM_TEXT);
        return $config;
    }

    /**
     * Determine which exam track a quiz belongs to for a given course.
     * Returns null if the quiz is not configured as a tracked exam.
     *
     * @param int $quizid
     * @return stdClass|null Object with ->courseid and ->track, or null.
     */
    public static function get_track_for_quiz(int $quizid): ?stdClass {
        global $DB;

        $sql = "SELECT id, courseid,
                       course_type,
                       program_final_quizid,
                       dc_quizid,
                       cert_quizid,
                       program_attempts_allowed,
                       dc_attempts_allowed,
                       cert_attempts_allowed
                  FROM {local_completionhistory_course_exam_config}
                 WHERE program_final_quizid = :q1
                    OR dc_quizid = :q2
                    OR cert_quizid = :q3";

        $row = $DB->get_record_sql($sql, ['q1' => $quizid, 'q2' => $quizid, 'q3' => $quizid]);
        if (!$row) {
            return null;
        }

        $result           = new stdClass();
        $result->courseid = $row->courseid;
        $result->config   = $row;

        if ((int) $row->program_final_quizid === $quizid) {
            $result->track            = self::TRACK_PROGRAM_FINAL;
            $result->attempts_allowed = (int) $row->program_attempts_allowed;
        } elseif ((int) $row->dc_quizid === $quizid) {
            $result->track            = self::TRACK_DIRECT_CREDIT;
            $result->attempts_allowed = (int) $row->dc_attempts_allowed;
        } else {
            $result->track            = self::TRACK_CERTIFICATE;
            $result->attempts_allowed = (int) $row->cert_attempts_allowed;
        }

        return $result;
    }

    /**
     * Returns the active exam tracks for a course type.
     * Used to validate attempt recording.
     *
     * @param string $course_type
     * @return string[]
     */
    public static function tracks_for_type(string $course_type): array {
        return match ($course_type) {
            self::TYPE_PROGRAM   => [self::TRACK_PROGRAM_FINAL],
            self::TYPE_OPEN_DUAL => [self::TRACK_DIRECT_CREDIT, self::TRACK_CERTIFICATE],
            self::TYPE_OPEN_CERT => [self::TRACK_CERTIFICATE],
            default              => [],
        };
    }
}
