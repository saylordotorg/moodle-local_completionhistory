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
    /** @var string Flag type: attempt finished within a threshold number of minutes. */
    const TYPE_FAST_COMPLETION   = 'fast_completion';
    /** @var string Flag type: attempt duration equals a target, within a tolerance. */
    const TYPE_DURATION_EXACT    = 'duration_exact';
    /** @var string Flag type: attempt grade falls inside an inclusive percentage range. */
    const TYPE_SCORE_RANGE       = 'score_range';
    /** @var string Flag type: another account shares the learner's first and last name. */
    const TYPE_DUPLICATE_ACCOUNT = 'duplicate_account';
    /** @var string Flag type: the account was created shortly before the exam. */
    const TYPE_NEW_ACCOUNT       = 'new_account';

    /** @var string Severity: informational. */
    const SEVERITY_INFO     = 'info';
    /** @var string Severity: warning. */
    const SEVERITY_WARNING  = 'warning';
    /** @var string Severity: critical. */
    const SEVERITY_CRITICAL = 'critical';

    /** @var stdClass[]|null Cached list of enabled flag defs for the current request. */
    private static ?array $cacheddefs = null;
    /** @var bool[] Per-(userid, firstname, lastname, domain option) duplicate lookup cache. */
    private static array $dupcache = [];

    /**
     * Human-readable labels for flag types.
     *
     * @return string[] Labels keyed by flag type constant.
     */
    public static function type_labels(): array {
        return [
            self::TYPE_SCORE_RANGE       => get_string('flagtype_score_range', 'local_completionhistory'),
            self::TYPE_FAST_COMPLETION   => get_string('flagtype_fast_completion', 'local_completionhistory'),
            self::TYPE_DURATION_EXACT    => get_string('flagtype_duration_exact', 'local_completionhistory'),
            self::TYPE_DUPLICATE_ACCOUNT => get_string('flagtype_duplicate_account', 'local_completionhistory'),
            self::TYPE_NEW_ACCOUNT       => get_string('flagtype_new_account', 'local_completionhistory'),
        ];
    }

    /**
     * Human-readable labels for severities.
     *
     * @return string[] Labels keyed by severity constant.
     */
    public static function severity_labels(): array {
        return [
            self::SEVERITY_INFO     => get_string('flagseverity_info', 'local_completionhistory'),
            self::SEVERITY_WARNING  => get_string('flagseverity_warning', 'local_completionhistory'),
            self::SEVERITY_CRITICAL => get_string('flagseverity_critical', 'local_completionhistory'),
        ];
    }

    /**
     * Map severity to Bootstrap badge class.
     *
     * @param string $severity One of the SEVERITY_* constants.
     * @return string Badge CSS class.
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
        if (self::$cacheddefs === null) {
            self::$cacheddefs = $DB->get_records(
                'local_completionhistory_flag_def',
                ['enabled' => 1],
                'severity DESC, name ASC'
            );
        }
        return self::$cacheddefs;
    }

    /**
     * Clear caches. Call after write operations.
     *
     * @return void
     */
    public static function reset_cache(): void {
        self::$cacheddefs = null;
        self::$dupcache   = [];
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
     *
     * @param stdClass $def Flag definition record.
     * @param stdClass $row Exam attempt row.
     * @return bool True when the flag applies to the attempt.
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
                $durmins   = (int) ($config['duration_minutes'] ?? 0);
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
     *
     * @param stdClass $row    Exam attempt row carrying the learner name and email.
     * @param array    $config Decoded flag configuration.
     * @return bool True when another matching account exists.
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
     *
     * Names and descriptions are language strings (flagpreset_<code>_name / _desc)
     * resolved at load time; once inserted they become ordinary admin-editable rows.
     *
     * @return stdClass[] Preset definitions keyed by code.
     */
    public static function get_presets(): array {
        $presets = [
            'score_zero' => [
                'flag_type'  => self::TYPE_SCORE_RANGE,
                'configjson' => json_encode(['score_min' => 0, 'score_max' => 0]),
                'severity'   => self::SEVERITY_CRITICAL,
            ],
            'score_low' => [
                'flag_type'  => self::TYPE_SCORE_RANGE,
                'configjson' => json_encode(['score_min' => 1, 'score_max' => 20]),
                'severity'   => self::SEVERITY_WARNING,
            ],
            'score_high' => [
                'flag_type'  => self::TYPE_SCORE_RANGE,
                'configjson' => json_encode(['score_min' => 90, 'score_max' => 100]),
                'severity'   => self::SEVERITY_WARNING,
            ],
            'dur_at_most_20m' => [
                'flag_type'  => self::TYPE_FAST_COMPLETION,
                'configjson' => json_encode(['threshold_minutes' => 20]),
                'severity'   => self::SEVERITY_WARNING,
            ],
            'dur_exact_2h' => [
                'flag_type'  => self::TYPE_DURATION_EXACT,
                'configjson' => json_encode(['duration_minutes' => 120, 'tolerance_seconds' => 10]),
                'severity'   => self::SEVERITY_INFO,
            ],
            'potential_dupe' => [
                'flag_type'  => self::TYPE_DUPLICATE_ACCOUNT,
                'configjson' => json_encode([]),
                'severity'   => self::SEVERITY_CRITICAL,
            ],
            'new_account' => [
                'flag_type'  => self::TYPE_NEW_ACCOUNT,
                'configjson' => json_encode(['max_days_before' => 2]),
                'severity'   => self::SEVERITY_CRITICAL,
            ],
        ];

        $result = [];
        foreach ($presets as $code => $fields) {
            $preset              = (object) $fields;
            $preset->code        = $code;
            $preset->name        = get_string('flagpreset_' . $code . '_name', 'local_completionhistory');
            $preset->description = get_string('flagpreset_' . $code . '_desc', 'local_completionhistory');
            $result[$code]       = $preset;
        }
        return $result;
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
     * @param stdClass $flag Flag definition record (id empty or 0 to insert).
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
     *
     * @param int $id Flag def id.
     * @return void
     */
    public static function delete(int $id): void {
        global $DB;
        $DB->delete_records('local_completionhistory_flag_def', ['id' => $id]);
        self::reset_cache();
    }
}
