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

namespace local_completionhistory\table;

use table_sql;
use html_writer;
use moodle_url;
use local_completionhistory\local\course_config_service;
use local_completionhistory\local\flag_service;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/tablelib.php');

/**
 * Table for the Exam Attempt Log page.
 *
 * One row per exam attempt. Joined to mdl_user and mdl_course for display.
 * The base SQL (fields + from + where) is set by the calling page so that
 * filter conditions can be applied before the table renders.
 *
 * Columns are controlled by a single ordered list passed to the constructor,
 * mirroring the achievements_table pattern (checkbox visibility + drag
 * reorder + saved layout).
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class exam_attempts_table extends table_sql {

    private const TRACK_BADGE = [
        course_config_service::TRACK_PROGRAM_FINAL => ['Program Final', 'badge-primary'],
        course_config_service::TRACK_DIRECT_CREDIT => ['Direct Credit', 'badge-info'],
        course_config_service::TRACK_CERTIFICATE   => ['Certificate',   'badge-success'],
    ];

    private const NOSORT_COLS = ['achievement', 'duration', 'flags'];

    private const COLS = [
        ['user_firstname',  'col_firstname'],
        ['user_lastname',   'col_lastname'],
        ['user_email',      'col_email'],
        ['user_country',    'col_country'],
        ['useridnumber',    'col_useridnumber'],
        ['course_fullname', 'col_coursename'],
        ['course_shortname','col_courseshortname'],
        ['exam_track',      'col_exam_track'],
        ['attempt_number',  'col_attempt_number'],
        ['grade_decimal',   'col_grade'],
        ['grade_passed',    'col_attempt_result'],
        ['timetaken',       'col_attempt_date'],
        ['duration',        'col_duration'],
        ['flags',           'col_flags'],
        ['achievement',     'col_achievement_link'],
    ];

    public function __construct(string $uniqueid, array $visiblecols = []) {
        parent::__construct($uniqueid);

        $allmap = self::all_col_labels();

        if (empty($visiblecols)) {
            $visiblecols = self::default_visible_cols();
        }

        $defaultmap = [];
        foreach ($visiblecols as $col) {
            if (isset($allmap[$col])) {
                $defaultmap[$col] = $allmap[$col];
            }
        }
        if (empty($defaultmap)) {
            foreach (self::default_visible_cols() as $col) {
                $defaultmap[$col] = $allmap[$col];
            }
        }

        $this->define_columns(array_keys($defaultmap));
        $this->define_headers(array_values($defaultmap));

        foreach (self::NOSORT_COLS as $col) {
            if (array_key_exists($col, $defaultmap)) {
                $this->no_sorting($col);
            }
        }
        $this->sortable(true, 'timetaken', SORT_DESC);
    }

    /**
     * All known column names mapped to translated labels.
     */
    public static function all_col_labels(): array {
        $map = [];
        foreach (self::COLS as [$col, $key]) {
            $map[$col] = get_string($key, 'local_completionhistory');
        }
        return $map;
    }

    /**
     * Column → category map. Used by the Columns filter pills so admins
     * can narrow the checkbox grid to (say) just grade-related columns.
     */
    public static function col_categories(): array {
        return [
            'user_firstname'   => 'user',
            'user_lastname'    => 'user',
            'user_email'       => 'user',
            'user_country'     => 'user',
            'useridnumber'     => 'user',
            'course_fullname'  => 'course',
            'course_shortname' => 'course',
            'exam_track'       => 'exam',
            'attempt_number'   => 'exam',
            'grade_decimal'    => 'grade',
            'grade_passed'     => 'grade',
            'timetaken'        => 'time',
            'duration'         => 'time',
            'flags'            => 'flags',
            'achievement'      => 'other',
        ];
    }

    /**
     * Category → translated label. Controls the pill order in the UI.
     */
    public static function category_labels(): array {
        return [
            'user'   => get_string('colcat_user',   'local_completionhistory'),
            'course' => get_string('colcat_course', 'local_completionhistory'),
            'exam'   => get_string('colcat_exam',   'local_completionhistory'),
            'grade'  => get_string('colcat_grade',  'local_completionhistory'),
            'time'   => get_string('colcat_time',   'local_completionhistory'),
            'flags'  => get_string('colcat_flags',  'local_completionhistory'),
            'other'  => get_string('colcat_other',  'local_completionhistory'),
        ];
    }

    /**
     * Default visible columns in canonical order.
     */
    public static function default_visible_cols(): array {
        return [
            'user_firstname',
            'user_lastname',
            'useridnumber',
            'course_fullname',
            'course_shortname',
            'exam_track',
            'attempt_number',
            'grade_decimal',
            'grade_passed',
            'timetaken',
            'achievement',
        ];
    }

    // ── User identity ────────────────────────────────────────────────────────

    public function col_user_firstname($row): string {
        if ((int) $row->userid === 0) {
            return html_writer::tag('em', 'anonymized', ['class' => 'text-muted']);
        }
        return s($row->user_firstname ?? '');
    }

    public function col_user_lastname($row): string {
        if ((int) $row->userid === 0) return '';
        return s($row->user_lastname ?? '');
    }

    public function col_user_email($row): string {
        if ((int) $row->userid === 0) return '';
        $email = $row->user_email ?? '';
        return $email ? html_writer::link('mailto:' . s($email), s($email)) : '-';
    }

    public function col_user_country($row): string {
        if ((int) $row->userid === 0) return '';
        $code = trim((string) ($row->user_country ?? ''));
        if ($code === '') return '-';
        $countries = get_string_manager()->get_list_of_countries(true);
        return isset($countries[$code]) ? s($countries[$code]) : s($code);
    }

    public function col_useridnumber($row): string {
        return s($row->useridnumber ?? '');
    }

    // ── Course ───────────────────────────────────────────────────────────────

    public function col_course_fullname($row): string {
        return format_string($row->course_fullname ?? '');
    }

    public function col_course_shortname($row): string {
        return s($row->course_shortname ?? '');
    }

    // ── Exam track ───────────────────────────────────────────────────────────

    public function col_exam_track($row): string {
        [$label, $cls] = self::TRACK_BADGE[$row->exam_track] ?? [$row->exam_track, 'badge-secondary'];
        return html_writer::tag('span', s($label), [
            'class' => "badge {$cls}",
            'style' => 'font-size:0.82em',
        ]);
    }

    // ── Attempt number ───────────────────────────────────────────────────────

    public function col_attempt_number($row): string {
        $n       = (int) $row->attempt_number;
        $allowed = (int) $row->attempts_allowed;
        $label   = $allowed === 0 ? '∞' : $allowed;

        $html = html_writer::tag('span', "{$n} of {$label}", ['class' => 'font-weight-bold']);

        // "Final attempt" warning badge.
        if ($allowed > 0 && $n === $allowed) {
            $html .= ' ' . html_writer::tag('span', 'Final', [
                'class' => 'badge badge-warning',
                'style' => 'font-size:0.75em',
            ]);
        }
        return $html;
    }

    // ── Grade ────────────────────────────────────────────────────────────────

    public function col_grade_decimal($row): string {
        if ($row->grade_decimal === null) return '-';
        return format_float((float) $row->grade_decimal, 1) . '%';
    }

    // ── Result ───────────────────────────────────────────────────────────────

    public function col_grade_passed($row): string {
        $n       = (int) $row->attempt_number;
        $allowed = (int) $row->attempts_allowed;
        $passed  = $row->grade_passed;

        if ($passed === null || $passed === '') {
            return html_writer::tag('span', '— N/A',
                ['class' => 'badge badge-secondary', 'style' => 'font-size:0.85em']);
        }

        if ((int) $passed === 1) {
            $icon = (int) $row->resulted_in_completion
                ? '&#10003; Passed &#127775;'
                : '&#10003; Passed';
            return html_writer::tag('span', $icon,
                ['class' => 'badge badge-success', 'style' => 'font-size:0.85em']);
        }

        $exhausted = ($allowed > 0 && $n >= $allowed);
        $label = $exhausted
            ? '&#10007; Failed — track exhausted'
            : '&#10007; Failed';
        return html_writer::tag('span', $label,
            ['class' => 'badge badge-danger', 'style' => 'font-size:0.85em']);
    }

    // ── Date ─────────────────────────────────────────────────────────────────

    public function col_timetaken($row): string {
        if (empty($row->timetaken)) return '-';
        $ts   = (int) $row->timetaken;
        $days = (int) floor((time() - $ts) / 86400);

        $date = userdate($ts, '%m/%d/%Y');
        $ago  = $days === 0 ? 'today'
              : ($days === 1 ? '1 day ago'
              : number_format($days) . ' days ago');

        return $date . html_writer::tag('span', ' (' . $ago . ')',
            ['class' => 'text-muted small']);
    }

    // ── Duration ─────────────────────────────────────────────────────────────

    public function col_duration($row): string {
        $secs = isset($row->duration) ? (int) $row->duration : 0;
        if ($secs <= 0) return '-';

        $h = intdiv($secs, 3600);
        $m = intdiv($secs % 3600, 60);
        $s = $secs % 60;

        if ($h > 0) {
            return sprintf('%dh %02dm %02ds', $h, $m, $s);
        }
        if ($m > 0) {
            return sprintf('%dm %02ds', $m, $s);
        }
        return "{$s}s";
    }

    // ── System flags ─────────────────────────────────────────────────────────

    public function col_flags($row): string {
        $matches = flag_service::evaluate($row);
        if (empty($matches)) {
            return html_writer::tag('span', '—', ['class' => 'text-muted']);
        }
        $badges = [];
        foreach ($matches as $def) {
            $cls   = flag_service::severity_badge_class($def->severity);
            $title = $def->description ? s($def->description) : s($def->name);
            $badges[] = html_writer::tag('span', s($def->name), [
                'class' => "badge {$cls} mr-1",
                'style' => 'font-size:0.78em;',
                'title' => $title,
            ]);
        }
        return implode('', $badges);
    }

    // ── Achievement link ─────────────────────────────────────────────────────

    public function col_achievement($row): string {
        if ((int) $row->userid === 0) return '-';
        if (empty($row->achievementid)) return '-';

        $url = new moodle_url('/local/completionhistory/achievement_ledger.php', [
            'filteruserid'     => (int) $row->userid,
            'filtercoursename' => $row->course_fullname ?? '',
        ]);
        return html_writer::link($url->out(false), '&#8594; Ledger',
            ['class' => 'btn btn-outline-secondary btn-sm',
             'style' => 'font-size:0.75em; padding:2px 8px;']);
    }
}
