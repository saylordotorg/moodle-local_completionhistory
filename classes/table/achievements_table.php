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
use local_completionhistory\local\course_config_service;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/tablelib.php');

/**
 * Table for displaying achievement records in the staff ledger.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class achievements_table extends table_sql {

    protected bool $showuser;
    protected array $programcache = [];

    private const NOSORT_COLS = ['programs', 'enroldays', 'completiondays', 'attempts', 'artifacturl'];

    private const STAFF_COLS = [
        ['user_firstname',           'col_firstname'],
        ['user_lastname',            'col_lastname'],
        ['user_email',               'col_email'],
        ['useridnumber_snapshot',    'col_useridnumber'],
        ['coursename_snapshot',      'col_coursename'],
        ['courseshortname_snapshot', 'col_courseshortname'],
        ['enroldate',                'col_enroldate'],
        ['enroldays',                'col_enroldays'],
        ['completiontime',           'col_completiondate'],
        ['completiondays',           'col_completiondays'],
        ['grade_decimal',            'col_grade'],
        ['grade_passed',             'col_passed'],
        ['exam_track',               'col_exam_track'],
        ['attempts',                 'col_attempts'],
        ['programs',                 'col_programs'],
        ['source_component',         'col_source'],
        ['timecreated',              'col_captured'],
    ];

    private const STUDENT_COLS = [
        ['coursename_snapshot',      'col_coursename'],
        ['courseshortname_snapshot', 'col_courseshortname'],
        ['enroldate',                'col_enroldate'],
        ['enroldays',                'col_enroldays'],
        ['completiontime',           'col_completiondate'],
        ['completiondays',           'col_completiondays'],
        ['grade_decimal',            'col_grade'],
        ['grade_passed',             'col_passed'],
        ['exam_track',               'col_exam_track'],
        ['attempts',                 'col_attempts'],
        ['programs',                 'col_programs'],
        ['source_component',         'col_source'],
        ['timecreated',              'col_captured'],
    ];

    private const OPTIONAL_COLS = [
        'courseidnumber_snapshot' => 'col_courseidnumber',
        'source_event'            => 'col_source_event',
        'artifacturl'             => 'col_artifact',
    ];

    public function __construct(
        string $uniqueid,
        bool $showuser = false,
        array $visiblecols = []
    ) {
        parent::__construct($uniqueid);
        $this->showuser = $showuser;

        $allmap = self::all_col_labels($showuser);

        if (empty($visiblecols)) {
            $visiblecols = self::default_visible_cols($showuser);
        }

        // Build ordered visible map, filtering unknown column names.
        $defaultmap = [];
        foreach ($visiblecols as $col) {
            if (isset($allmap[$col])) {
                $defaultmap[$col] = $allmap[$col];
            }
        }
        // Safety net: if all columns were deselected, fall back to defaults
        // so the table still renders.
        if (empty($defaultmap)) {
            foreach (self::default_visible_cols($showuser) as $col) {
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
        $this->sortable(true, 'completiontime', SORT_DESC);
    }

    /**
     * All known column names → translated label, for the view mode (staff/student).
     * Includes default columns and optional columns.
     */
    public static function all_col_labels(bool $showuser = true): array {
        $basedefs = $showuser ? self::STAFF_COLS : self::STUDENT_COLS;
        $map = [];
        foreach ($basedefs as [$col, $key]) {
            $map[$col] = get_string($key, 'local_completionhistory');
        }
        foreach (self::OPTIONAL_COLS as $col => $key) {
            $map[$col] = get_string($key, 'local_completionhistory');
        }
        return $map;
    }

    /**
     * Default visible columns (staff defaults or student defaults) in canonical order.
     */
    public static function default_visible_cols(bool $showuser = true): array {
        $basedefs = $showuser ? self::STAFF_COLS : self::STUDENT_COLS;
        return array_map(fn($def) => $def[0], $basedefs);
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

    public function col_useridnumber_snapshot($row): string {
        return s($row->useridnumber_snapshot ?? '');
    }

    // ── Course ───────────────────────────────────────────────────────────────

    public function col_coursename_snapshot($row): string {
        return format_string($row->coursename_snapshot);
    }

    public function col_courseshortname_snapshot($row): string {
        return s($row->courseshortname_snapshot ?? '');
    }

    // ── Enrollment ───────────────────────────────────────────────────────────

    public function col_enroldate($row): string {
        return empty($row->enroldate) ? '-' : userdate((int) $row->enroldate, '%m/%d/%Y');
    }

    public function col_enroldays($row): string {
        if (empty($row->enroldate)) return '-';
        $days = (int) floor((time() - (int) $row->enroldate) / 86400);
        if ($days < 0)  return '-';
        if ($days === 0) return html_writer::tag('span', 'Today', ['class' => 'badge badge-primary', 'style' => 'font-size:0.82em']);
        $label = $days === 1 ? '1 day ago' : number_format($days) . ' days ago';
        return html_writer::tag('span', $label, ['class' => 'text-muted small']);
    }

    // ── Completion + grade ───────────────────────────────────────────────────

    public function col_completiontime($row): string {
        return userdate($row->completiontime, '%m/%d/%Y');
    }

    public function col_completiondays($row): string {
        $enroll = (int) ($row->enroldate ?? 0);
        if ($enroll === 0 && !empty($row->enrolledtime_snapshot)) {
            $enroll = (int) $row->enrolledtime_snapshot;
        }
        $completed = (int) ($row->completiontime ?? 0);
        if ($enroll === 0 || $completed === 0 || $completed < $enroll) {
            return '-';
        }
        $days = (int) floor(($completed - $enroll) / 86400);
        if ($days === 0) {
            return html_writer::tag('span', 'Same day',
                ['class' => 'badge badge-primary', 'style' => 'font-size:0.82em']);
        }
        $label = $days === 1 ? '1 day' : number_format($days) . ' days';
        return html_writer::tag('span', $label, ['class' => 'text-muted small']);
    }

    public function col_grade_decimal($row): string {
        return $row->grade_decimal === null ? '-' : format_float($row->grade_decimal, 2);
    }

    public function col_grade_passed($row): string {
        if ($row->grade_passed === null) {
            return html_writer::tag('span', '&#8212; ' . get_string('gradeunknown', 'local_completionhistory'),
                ['class' => 'badge badge-secondary', 'style' => 'font-size:0.85em']);
        }
        if ($row->grade_passed) {
            return html_writer::tag('span', '&#10003; ' . get_string('gradepassed', 'local_completionhistory'),
                ['class' => 'badge badge-success', 'style' => 'font-size:0.85em']);
        }
        return html_writer::tag('span', '&#10007; ' . get_string('gradefailed', 'local_completionhistory'),
            ['class' => 'badge badge-danger', 'style' => 'font-size:0.85em']);
    }

    // ── Exam track ───────────────────────────────────────────────────────────

    public function col_exam_track($row): string {
        if (empty($row->exam_track)) {
            return html_writer::tag('span', '—', ['class' => 'text-muted']);
        }

        $labels = [
            course_config_service::TRACK_PROGRAM_FINAL => ['Program Final', 'badge-primary'],
            course_config_service::TRACK_DIRECT_CREDIT => ['Direct Credit', 'badge-info'],
            course_config_service::TRACK_CERTIFICATE   => ['Certificate',   'badge-success'],
        ];

        [$label, $cls] = $labels[$row->exam_track] ?? [$row->exam_track, 'badge-secondary'];
        return html_writer::tag('span', $label, ['class' => "badge {$cls}", 'style' => 'font-size:0.82em']);
    }

    /**
     * Attempts column: shows "2 / 3" summary + an expand toggle button.
     * Clicking the button fetches attempt detail via AJAX and injects a
     * sub-row below the current table row.
     */
    public function col_attempts($row): string {
        $used    = $row->attempts_used    ?? null;
        $allowed = $row->attempts_allowed ?? null;

        if ($used === null) {
            // No attempt data recorded yet.
            $summary = html_writer::tag('span', '—', ['class' => 'text-muted']);
        } else {
            $allowedlabel = ($allowed === null || (int) $allowed === 0) ? '∞' : (int) $allowed;
            $summary = html_writer::tag('span', "{$used} / {$allowedlabel}",
                ['class' => 'font-weight-bold mr-2']);
        }

        // Expand button — only shown when we have a userid to look up.
        $btn = '';
        if ((int) $row->userid !== 0 && !empty($row->courseid)) {
            $btn = html_writer::tag('button', '&#9654; Details', [
                'class'        => 'btn btn-outline-secondary btn-sm lch-expand-attempts',
                'style'        => 'font-size:0.75em; padding:1px 6px;',
                'data-userid'  => (int) $row->userid,
                'data-courseid'=> (int) $row->courseid,
                'data-rowid'   => (int) $row->id,
                'type'         => 'button',
            ]);
        }

        return $summary . $btn;
    }

    // ── Programs ─────────────────────────────────────────────────────────────

    public function col_programs($row): string {
        global $DB;
        if (!isset($this->programcache[$row->id])) {
            $this->programcache[$row->id] = $DB->get_records(
                'local_completionhistory_ach_program', ['achievementid' => $row->id]
            );
        }
        $programs = $this->programcache[$row->id];
        if (empty($programs)) return '-';
        $names = [];
        foreach ($programs as $p) {
            $names[] = html_writer::tag('span', format_string($p->programname_snapshot), ['class' => 'badge badge-info']);
        }
        return implode(' ', $names);
    }

    // ── Misc ─────────────────────────────────────────────────────────────────

    public function col_source_component($row): string { return s($row->source_component); }

    public function col_timecreated($row): string {
        return userdate($row->timecreated, '%m/%d/%Y');
    }

    // ── Optional columns ─────────────────────────────────────────────────────

    public function col_courseidnumber_snapshot($row): string { return s($row->courseidnumber_snapshot ?? ''); }
    public function col_source_event($row): string            { return s($row->source_event ?? ''); }

    public function col_artifacturl($row): string {
        if (empty($row->artifacturl)) return '-';
        return html_writer::link($row->artifacturl, get_string('col_artifact', 'local_completionhistory'),
            ['target' => '_blank', 'rel' => 'noopener noreferrer']);
    }
}
