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

namespace local_completionhistory\output;

use local_completionhistory\local\course_config_service;

/**
 * Presentation helpers shared by every surface that shows an exam track or attempt result.
 *
 * The ledger table, the attempt log table, the attempt log stats cards and the
 * attempt history fragment all colour an exam track the same way; this is the one
 * place that mapping lives.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempt_badges {
    /** @var string[] Exam track => Bootstrap badge modifier class. */
    private const TRACK_BADGE = [
        course_config_service::TRACK_PROGRAM_FINAL => 'badge-primary',
        course_config_service::TRACK_DIRECT_CREDIT => 'badge-info',
        course_config_service::TRACK_CERTIFICATE   => 'badge-success',
    ];

    /** @var string[] Exam track => Bootstrap contextual colour name (for borders and text). */
    private const TRACK_COLOUR = [
        course_config_service::TRACK_PROGRAM_FINAL => 'primary',
        course_config_service::TRACK_DIRECT_CREDIT => 'info',
        course_config_service::TRACK_CERTIFICATE   => 'success',
    ];

    /**
     * Bootstrap badge class for an exam track.
     *
     * @param string|null $track The exam track code.
     * @return string A badge-* modifier class.
     */
    public static function track_badge_class(?string $track): string {
        return self::TRACK_BADGE[$track] ?? 'badge-secondary';
    }

    /**
     * Bootstrap contextual colour name for an exam track (primary, info, ...).
     *
     * @param string|null $track The exam track code.
     * @return string A contextual colour name.
     */
    public static function track_colour(?string $track): string {
        return self::TRACK_COLOUR[$track] ?? 'secondary';
    }

    /**
     * Translated label for an exam track, falling back to the raw code for unknown tracks.
     *
     * @param string|null $track The exam track code.
     * @return string The label.
     */
    public static function track_label(?string $track): string {
        $labels = course_config_service::track_labels();
        return $labels[$track] ?? (string) $track;
    }

    /**
     * The "N / M" attempts summary, where an unlimited allowance shows as the infinity string.
     *
     * @param int $used The attempt number or the number of attempts used.
     * @param int $allowed The attempt allowance; 0 means unlimited.
     * @return string The summary text.
     */
    public static function attempts_summary(int $used, int $allowed): string {
        $a = (object) [
            'used'    => $used,
            'allowed' => self::allowed_label($allowed),
        ];
        return get_string('attempts_summary', 'local_completionhistory', $a);
    }

    /**
     * The attempt allowance as text, where 0 means unlimited.
     *
     * @param int $allowed The attempt allowance.
     * @return string The allowance label.
     */
    public static function allowed_label(int $allowed): string {
        return $allowed === 0 ? get_string('attempts_unlimited', 'local_completionhistory') : (string) $allowed;
    }

    /**
     * Whether an attempt exhausted its track: a failing attempt at or past a finite allowance.
     *
     * @param int $attemptnumber The attempt number.
     * @param int $allowed The attempt allowance; 0 means unlimited.
     * @return bool True when no further attempts remain on the track.
     */
    public static function is_exhausted(int $attemptnumber, int $allowed): bool {
        return $allowed > 0 && $attemptnumber >= $allowed;
    }
}
