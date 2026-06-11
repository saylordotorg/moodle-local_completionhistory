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
 * Service for admin-defined system flags applied to exam attempts.
 *
 * Flags are evaluated at render time. Two built-in types:
 *   - fast_completion: attempt duration < config.threshold_minutes.
 *   - duplicate_account: another non-deleted user exists with matching
 *     firstname + lastname (case-insensitive). Optional config:
 *       same_email_domain: bool — also require same email @domain.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class flag_service {

    const TYPE_FAST_COMPLETION   = 'fast_completion';
    const TYPE_DURATION_EXACT    = 'duration_exact';
    const TYPE_SCORE_RANGE       = 'score_range';
    const TYPE_DUPLICATE_ACCOUNT = 'duplicate_account';
    const TYPE_NEW_ACCOUNT       = 'new_account';

    const SEVERITY_INFO     = 'info';
    const SEVERITY_WARNING  = 'warning';
    const SEVERITY_CRITICAL = 'critical';

    /** Cached list of enabled flag defs for the current request. */
    private static ?array $cached_defs = null;
    /** Per-(firstname,lastname) duplicate lookup cache. */
    private static array $dupcache = [];

    /**
     * Human-readable labels for flag types.
     */
    public static function type_labels(): array {
        return [
            self::TYPE_SCORE_RANGE       => get_string('flagtype_score_range',       'local_completionhistory'),
            self::TYPE_FAST_COMPLETION   => get_string('flagtype_fast_completion',   'local_completionhistory'),
            self::TYPE_DURATION_EXACT    => get_string('flagtype_duration_exact',    'local_completionhistory'),
            self::TYPE_DUPLICATE_ACCOUNT => get_string('flagtype_duplicate_account', 'local_completionhistory'),
            self::TYPE_NEW_ACCOUNT       => get_string('flagtype_new_account',       'local_completionhistory'),
        ];
    }

    /**
     * Human-readable labels for severities.
     */
    public static function severity_labels(): array {
        return [
            self::SEVERITY_INFO     => get_string('flagseverity_info',     'local_completionhistory'),
            self::SEVERITY_WARNING  => get_string('flagseverity_warning',  'local_completionhistory'),
            self::SEVERITY_CRITICAL => get_string('flagseverity_critical', 'local_completionhistory'),
        ];
    }

    /**
     * Map severity to Bootstrap badge class.
     */
    public static function severity_badge_class(string $severity): string {
        return match ($severity) {
            self::SEVERITY_CRITICAL => 'badge-danger',
            self::SEVERITY_INFO     => 'badge-info',
            default                 => 'badge-warning',
        };
    }

    /**
     * Get all enabled flag defs (cached for the request).
     *
     * @return stdClass[]
     */
    public static function get_enabled_defs(): array {
        global $DB;
        if (self::$cached_defs === null) {
            self::$cached_defs = $DB->get_records('local_completionhistory_flag_def',
                ['enabled' => 1], 'severity DESC, name ASC');
        }
        return self::$cached_defs;
    }

    /**
     * Clear caches. Call after write operations.
     */
    public static function reset_cache(): void {
        self::$cached_defs = null;
        self::$dupcache    = [];
    }

    /**
     * Evaluate all enabled flags against a single exam attempt row.
     *
     * @param stdClass $row Row with at least: userid, duration,
     *                      user_firstname, user_lastname, user_email.
     * @return stdClass[] Matching flag defs.
     */
    public static function evaluate(stdClass $row): array {
        $matches = [];
        foreach (self::get_enabled_defs() as $def) {
            if (self::matches($def, $row)) {
                $matches[] = $def;
            }
        }
        return $matches;
    }

    /**
     * Evaluate a single flag def against a row.
     */
    public static function matches(stdClass $def, stdClass $row): bool {
        $config = json_decode($def->configjson ?? '', true) ?: [];

        switch ($def->flag_type) {
            case self::TYPE_FAST_COMPLETION:
                $thresholdmins = (int) ($config['threshold_minutes'] ?? 0);
                if ($thresholdmins <= 0) {
                    return false;
                }
                $duration = (int) ($row->duration ?? 0);
                // Inclusive "<=": a 20-minute attempt should trigger the 20m flag.
                return $duration > 0 && $duration <= ($thresholdmins * 60);

            case self::TYPE_DURATION_EXACT:
                $durmins   = (int) ($config['duration_minutes']  ?? 0);
                $tolerance = (int) ($config['tolerance_seconds'] ?? 10);
                if ($durmins <= 0) {
                    return false;
                }
                $duration = (int) ($row->duration ?? 0);
                if ($duration <= 0) {
                    return false;
                }
                return abs($duration - ($durmins * 60)) <= $tolerance;

            case self::TYPE_SCORE_RANGE:
                if (!isset($row->grade_decimal) || $row->grade_decimal === null) {
                    return false;
                }
                $grade = (float) $row->grade_decimal;
                $min   = (float) ($config['score_min'] ?? 0);
                $max   = (float) ($config['score_max'] ?? 100);
                return $grade >= $min && $grade <= $max;

            case self::TYPE_DUPLICATE_ACCOUNT:
                return self::check_duplicate_account($row, $config);

            case self::TYPE_NEW_ACCOUNT:
                $maxdays = (int) ($config['max_days_before'] ?? 0);
                if ($maxdays <= 0) {
                    return false;
                }
                $usercreated = (int) ($row->user_timecreated ?? 0);
                $examtime    = (int) ($row->timetaken ?? 0);
                if ($usercreated <= 0 || $examtime <= 0 || $examtime < $usercreated) {
                    return false;
                }
                return ($examtime - $usercreated) < ($maxdays * 86400);
        }

        return false;
    }

    /**
     * Duplicate-account detector: look for another non-deleted user with
     * matching firstname + lastname. Optional config.same_email_domain
     * also requires matching @domain portion of the email.
     */
    private static function check_duplicate_account(stdClass $row, array $config): bool {
        global $DB;

        $userid = (int) ($row->userid ?? 0);
        $fn     = trim((string) ($row->user_firstname ?? ''));
        $ln     = trim((string) ($row->user_lastname ?? ''));
        if ($userid === 0 || $fn === '' || $ln === '') {
            return false;
        }

        $samedomain = !empty($config['same_email_domain']);
        $key = $userid . '|' . strtolower($fn . ' ' . $ln) . '|' . ($samedomain ? '1' : '0');
        if (isset(self::$dupcache[$key])) {
            return self::$dupcache[$key];
        }

        if ($samedomain) {
            $email = (string) ($row->user_email ?? '');
            $atpos = strpos($email, '@');
            if ($atpos === false) {
                return self::$dupcache[$key] = false;
            }
            $domain = substr($email, $atpos);
            $sql = 'SELECT 1 FROM {user}
                     WHERE LOWER(firstname) = LOWER(:fn)
                       AND LOWER(lastname)  = LOWER(:ln)
                       AND ' . $DB->sql_like('LOWER(email)', ':emailpat', false) . '
                       AND id <> :uid
                       AND deleted = 0';
            $exists = $DB->record_exists_sql($sql, [
                'fn'       => $fn,
                'ln'       => $ln,
                'emailpat' => '%' . $DB->sql_like_escape(strtolower($domain)),
                'uid'      => $userid,
            ]);
        } else {
            $sql = 'SELECT 1 FROM {user}
                     WHERE LOWER(firstname) = LOWER(:fn)
                       AND LOWER(lastname)  = LOWER(:ln)
                       AND id <> :uid
                       AND deleted = 0';
            $exists = $DB->record_exists_sql($sql, [
                'fn'  => $fn,
                'ln'  => $ln,
                'uid' => $userid,
            ]);
        }

        return self::$dupcache[$key] = (bool) $exists;
    }

    /**
     * Canonical preset flag set, matching the operational rubric in the admin
     * handbook. Keyed by `code`, which is the unique natural key in DB so
     * callers can load missing presets without clobbering admin edits.
     */
    public static function get_presets(): array {
        return [
            'score_zero' => (object) [
                'code' => 'score_zero', 'name' => 'Score = 0',
                'flag_type' => self::TYPE_SCORE_RANGE,
                'configjson' => json_encode(['score_min' => 0, 'score_max' => 0]),
                'severity' => self::SEVERITY_CRITICAL,
                'description' => 'Exam score is 0. Possible technical issues or dropped session.',
            ],
            'score_low' => (object) [
                'code' => 'score_low', 'name' => 'Score <= 20',
                'flag_type' => self::TYPE_SCORE_RANGE,
                'configjson' => json_encode(['score_min' => 1, 'score_max' => 20]),
                'severity' => self::SEVERITY_WARNING,
                'description' => 'Exam score is 1–20%. Possible technical issues or dropped session.',
            ],
            'score_high' => (object) [
                'code' => 'score_high', 'name' => 'Score >= 90',
                'flag_type' => self::TYPE_SCORE_RANGE,
                'configjson' => json_encode(['score_min' => 90, 'score_max' => 100]),
                'severity' => self::SEVERITY_WARNING,
                'description' => 'Exam score is 90% or higher. Possible use of external resources.',
            ],
            'dur_at_most_20m' => (object) [
                'code' => 'dur_at_most_20m', 'name' => 'Dur <= 20 min',
                'flag_type' => self::TYPE_FAST_COMPLETION,
                'configjson' => json_encode(['threshold_minutes' => 20]),
                'severity' => self::SEVERITY_WARNING,
                'description' => 'Exam duration <= 20 minutes. Possible technical issues or dropped session.',
            ],
            'dur_exact_2h' => (object) [
                'code' => 'dur_exact_2h', 'name' => 'Dur = 2 hr',
                'flag_type' => self::TYPE_DURATION_EXACT,
                'configjson' => json_encode(['duration_minutes' => 120, 'tolerance_seconds' => 10]),
                'severity' => self::SEVERITY_INFO,
                'description' => 'Exam duration is exactly 2 hours. Auto-submission at end of time limit; possible dropped session.',
            ],
            'potential_dupe' => (object) [
                'code' => 'potential_dupe', 'name' => 'Potential dupe',
                'flag_type' => self::TYPE_DUPLICATE_ACCOUNT,
                'configjson' => json_encode([]),
                'severity' => self::SEVERITY_CRITICAL,
                'description' => 'Account flagged as possible duplicate. Possible attempt to bypass waiting period.',
            ],
            'new_account' => (object) [
                'code' => 'new_account', 'name' => 'New account',
                'flag_type' => self::TYPE_NEW_ACCOUNT,
                'configjson' => json_encode(['max_days_before' => 2]),
                'severity' => self::SEVERITY_CRITICAL,
                'description' => 'Account created less than 2 days before exam. Possible attempt to bypass waiting period with an alternate account.',
            ],
        ];
    }

    /**
     * Insert any preset flags whose `code` is not already present in the DB.
     * Leaves existing rows (even if modified) untouched.
     *
     * @return int Number of flags inserted.
     */
    public static function load_presets(): int {
        global $DB;
        $existingcodes = $DB->get_fieldset_select('local_completionhistory_flag_def', 'code', '1=1');
        $existing = array_flip($existingcodes);
        $now = time();
        $inserted = 0;
        foreach (self::get_presets() as $code => $preset) {
            if (isset($existing[$code])) {
                continue;
            }
            $row              = clone $preset;
            $row->enabled     = 1;
            $row->timecreated = $now;
            $row->timemodified = $now;
            $DB->insert_record('local_completionhistory_flag_def', $row);
            $inserted++;
        }
        if ($inserted > 0) {
            self::reset_cache();
        }
        return $inserted;
    }

    /**
     * Save (insert or update) a flag def.
     *
     * @param stdClass $flag
     * @return int Flag def id.
     */
    public static function save(stdClass $flag): int {
        global $DB;

        $now = time();
        $flag->timemodified = $now;

        if (!empty($flag->id)) {
            $DB->update_record('local_completionhistory_flag_def', $flag);
            self::reset_cache();
            return (int) $flag->id;
        }

        $flag->timecreated = $now;
        $id = $DB->insert_record('local_completionhistory_flag_def', $flag);
        self::reset_cache();
        return $id;
    }

    /**
     * Delete a flag def.
     */
    public static function delete(int $id): void {
        global $DB;
        $DB->delete_records('local_completionhistory_flag_def', ['id' => $id]);
        self::reset_cache();
    }
}
