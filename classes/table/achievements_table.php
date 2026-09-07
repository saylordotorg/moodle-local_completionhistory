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

use html_writer;
use local_completionhistory\output\attempt_badges;
use stdClass;
use table_sql;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/tablelib.php');

/**
 * Table for displaying achievement records in the staff ledger and the student view.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class achievements_table extends table_sql {
    /** @var bool Whether user identity columns are offered (staff view). */
    protected bool $showuser;

    /** @var array Achievement id => program association records, per request. */
    protected array $programcache = [];

    /** @var string[] Columns that are computed and therefore cannot be sorted in SQL. */
    private const NOSORT_COLS = ['programs', 'enroldays', 'completiondays', 'attempts', 'artifacturl'];

    /** @var array Staff default columns as [column name, label string key] pairs, in canonical order. */
    private const STAFF_COLS = [
        ['user_firstname', 'col_firstname'],
        ['user_lastname', 'col_lastname'],
        ['user_email', 'col_email'],
        ['useridnumber_snapshot', 'col_useridnumber'],
        ['coursename_snapshot', 'col_coursename'],
        ['courseshortname_snapshot', 'col_courseshortname'],
        ['enroldate', 'col_enroldate'],
        ['enroldays', 'col_enroldays'],
        ['completiontime', 'col_completiondate'],
        ['completiondays', 'col_completiondays'],
        ['grade_decimal', 'col_grade'],
        ['grade_passed', 'col_passed'],
        ['exam_track', 'col_exam_track'],
        ['attempts', 'col_attempts'],
        ['programs', 'col_programs'],
        ['source_component', 'col_source'],
        ['timecreated', 'col_captured'],
    ];

    /** @var array Student default columns as [column name, label string key] pairs, in canonical order. */
    private const STUDENT_COLS = [
        ['coursename_snapshot', 'col_coursename'],
        ['courseshortname_snapshot', 'col_courseshortname'],
        ['enroldate', 'col_enroldate'],
        ['enroldays', 'col_enroldays'],
        ['completiontime', 'col_completiondate'],
        ['completiondays', 'col_completiondays'],
        ['grade_decimal', 'col_grade'],
        ['grade_passed', 'col_passed'],
        ['exam_track', 'col_exam_track'],
        ['attempts', 'col_attempts'],
        ['programs', 'col_programs'],
        ['source_component', 'col_source'],
        ['timecreated', 'col_captured'],
    ];

    /** @var string[] Columns that are hidden by default, column name => label string key. */
    private const OPTIONAL_COLS = [
        'courseidnumber_snapshot' => 'col_courseidnumber',
        'source_event'            => 'col_source_event',
        'artifacturl'             => 'col_artifact',
    ];

    /**
     * Constructor.
     *
     * @param string $uniqueid Unique table id.
     * @param bool $showuser Whether user identity columns are offered (staff view).
     * @param string[] $visiblecols Visible column names in display order; empty for the defaults.
     */
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
     * All known column names => translated label, for the view mode (staff/student).
     * Includes default columns and optional columns.
     *
     * @param bool $showuser Whether user identity columns are offered (staff view).
     * @return string[] Column name => label.
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
     *
     * @param bool $showuser Whether user identity columns are offered (staff view).
     * @return string[] Column names.
     */
    public static function default_visible_cols(bool $showuser = true): array {
        $basedefs = $showuser ? self::STAFF_COLS : self::STUDENT_COLS;
        return array_map(fn($def) => $def[0], $basedefs);
    }

    /**
     * Set the staff ledger SQL from the page's filter values.
     *
     * Filter keys (all optional): userid (int), coursename (string), source (string),
     * passed ('1', '0', 'null' or ''), programids (int[]), hasprograms (bool),
     * datefrom and dateto (YYYY-MM-DD strings).
     *
     * Duplicates are collapsed to one canonical achievement per (userid, courseid):
     * a passing record beats a failing one, then the most recent completion, then the
     * highest id.
     *
     * @param array $filters The filter values.
     */
    public function apply_filters(array $filters): void {
        global $DB;

        $conditions = ['1 = 1'];
        $params     = [];

        $userid = (int) ($filters['userid'] ?? 0);
        if ($userid > 0) {
            $conditions[]           = 'a.userid = :filteruserid';
            $params['filteruserid'] = $userid;
        }
        if (!empty($filters['coursename'])) {
            $conditions[]               = $DB->sql_like('a.coursename_snapshot', ':filtercoursename', false);
            $params['filtercoursename'] = '%' . $DB->sql_like_escape($filters['coursename']) . '%';
        }
        if (!empty($filters['source'])) {
            $conditions[]           = $DB->sql_like('a.source_component', ':filtersource', false);
            $params['filtersource'] = '%' . $DB->sql_like_escape($filters['source']) . '%';
        }
        $passed = (string) ($filters['passed'] ?? '');
        if ($passed === '1') {
            $conditions[] = 'a.grade_passed = 1';
        } else if ($passed === '0') {
            $conditions[] = 'a.grade_passed = 0';
        } else if ($passed === 'null') {
            $conditions[] = 'a.grade_passed IS NULL';
        }
        $programids = array_values(array_filter(array_map('intval', $filters['programids'] ?? [])));
        if (!empty($programids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($programids, SQL_PARAMS_NAMED, 'progid');
            $conditions[] = "EXISTS (SELECT 1 FROM {local_completionhistory_ach_program} ap
                                      WHERE ap.achievementid = a.id AND ap.programid $insql)";
            $params = array_merge($params, $inparams);
        } else if (!empty($filters['hasprograms'])) {
            $conditions[] = "EXISTS (SELECT 1 FROM {local_completionhistory_ach_program} ap
                                      WHERE ap.achievementid = a.id)";
        }
        if (!empty($filters['datefrom'])) {
            $ts = strtotime($filters['datefrom']);
            if ($ts !== false) {
                $conditions[]             = 'a.completiontime >= :filterdatefrom';
                $params['filterdatefrom'] = $ts;
            }
        }
        if (!empty($filters['dateto'])) {
            $ts = strtotime($filters['dateto'] . ' 23:59:59');
            if ($ts !== false) {
                $conditions[]           = 'a.completiontime <= :filterdateto';
                $params['filterdateto'] = $ts;
            }
        }

        // Collapse duplicates: keep one canonical achievement per (userid, courseid).
        // Canonical row wins on (grade_passed DESC NULLS LAST, completiontime DESC, id DESC)
        // so a passing attempt beats a failing one, and the most recent beats older ones.
        $conditions[] = "NOT EXISTS (
            SELECT 1 FROM {local_completionhistory_achievement} a2
             WHERE a2.userid = a.userid
               AND a2.courseid = a.courseid
               AND a2.id <> a.id
               AND (
                    COALESCE(a2.grade_passed, -1) > COALESCE(a.grade_passed, -1)
                 OR (COALESCE(a2.grade_passed, -1) = COALESCE(a.grade_passed, -1)
                     AND a2.completiontime > a.completiontime)
                 OR (COALESCE(a2.grade_passed, -1) = COALESCE(a.grade_passed, -1)
                     AND a2.completiontime = a.completiontime
                     AND a2.id > a.id)
               )
        )";

        $this->set_sql(
            'a.*,
             cc.timeenrolled                         AS enroldate,
             u.firstname                             AS user_firstname,
             u.lastname                              AS user_lastname,
             u.email                                 AS user_email',
            '{local_completionhistory_achievement} a
             LEFT JOIN {course_completions} cc ON cc.userid = a.userid AND cc.course = a.courseid
             LEFT JOIN {user} u                ON u.id = a.userid',
            implode(' AND ', $conditions),
            $params
        );
    }

    /**
     * A small badge.
     *
     * @param string $text Already-escaped badge content.
     * @param string $class Badge modifier classes.
     * @return string HTML.
     */
    protected function badge(string $text, string $class): string {
        return html_writer::tag('span', $text, ['class' => 'badge ' . $class]);
    }

    /**
     * The placeholder shown when a cell has no value.
     *
     * @return string The placeholder text.
     */
    protected function nodata(): string {
        return get_string('nodata', 'local_completionhistory');
    }

    /**
     * First name column; anonymized records have no user.
     *
     * @param stdClass $row The table row.
     * @return string HTML.
     */
    public function col_user_firstname(stdClass $row): string {
        if ((int) $row->userid === 0) {
            return html_writer::tag('em', get_string('anonymized', 'local_completionhistory'), ['class' => 'text-muted']);
        }
        return s($row->user_firstname ?? '');
    }

    /**
     * Last name column.
     *
     * @param stdClass $row The table row.
     * @return string HTML.
     */
    public function col_user_lastname(stdClass $row): string {
        if ((int) $row->userid === 0) {
            return '';
        }
        return s($row->user_lastname ?? '');
    }

    /**
     * Email column, as a mailto link.
     *
     * @param stdClass $row The table row.
     * @return string HTML.
     */
    public function col_user_email(stdClass $row): string {
        if ((int) $row->userid === 0) {
            return '';
        }
        $email = $row->user_email ?? '';
        return $email ? html_writer::link('mailto:' . s($email), s($email)) : $this->nodata();
    }

    /**
     * User idnumber snapshot column.
     *
     * @param stdClass $row The table row.
     * @return string HTML.
     */
    public function col_useridnumber_snapshot(stdClass $row): string {
        return s($row->useridnumber_snapshot ?? '');
    }

    /**
     * Course name snapshot column.
     *
     * @param stdClass $row The table row.
     * @return string HTML.
     */
    public function col_coursename_snapshot(stdClass $row): string {
        return format_string($row->coursename_snapshot);
    }

    /**
     * Course short name snapshot column.
     *
     * @param stdClass $row The table row.
     * @return string HTML.
     */
    public function col_courseshortname_snapshot(stdClass $row): string {
        return s($row->courseshortname_snapshot ?? '');
    }

    /**
     * Enrolment date column.
     *
     * @param stdClass $row The table row.
     * @return string HTML.
     */
    public function col_enroldate(stdClass $row): string {
        return empty($row->enroldate) ? $this->nodata() : userdate((int) $row->enroldate, '%m/%d/%Y');
    }

    /**
     * Days since enrolment column.
     *
     * @param stdClass $row The table row.
     * @return string HTML.
     */
    public function col_enroldays(stdClass $row): string {
        if (empty($row->enroldate)) {
            return $this->nodata();
        }
        $days = (int) floor((time() - (int) $row->enroldate) / 86400);
        if ($days < 0) {
            return $this->nodata();
        }
        if ($days === 0) {
            return $this->badge(get_string('days_today', 'local_completionhistory'), 'badge-primary lch-badge-sm');
        }
        $label = $days === 1
            ? get_string('days_ago_one', 'local_completionhistory')
            : get_string('days_ago', 'local_completionhistory', number_format($days));
        return html_writer::tag('span', $label, ['class' => 'text-muted small']);
    }

    /**
     * Completion date column.
     *
     * @param stdClass $row The table row.
     * @return string HTML.
     */
    public function col_completiontime(stdClass $row): string {
        return userdate($row->completiontime, '%m/%d/%Y');
    }

    /**
     * Days from enrolment to completion column.
     *
     * @param stdClass $row The table row.
     * @return string HTML.
     */
    public function col_completiondays(stdClass $row): string {
        $enroll = (int) ($row->enroldate ?? 0);
        if ($enroll === 0 && !empty($row->enrolledtime_snapshot)) {
            $enroll = (int) $row->enrolledtime_snapshot;
        }
        $completed = (int) ($row->completiontime ?? 0);
        if ($enroll === 0 || $completed === 0 || $completed < $enroll) {
            return $this->nodata();
        }
        $days = (int) floor(($completed - $enroll) / 86400);
        if ($days === 0) {
            return $this->badge(get_string('days_sameday', 'local_completionhistory'), 'badge-primary lch-badge-sm');
        }
        $label = $days === 1
            ? get_string('days_one', 'local_completionhistory')
            : get_string('days_count', 'local_completionhistory', number_format($days));
        return html_writer::tag('span', $label, ['class' => 'text-muted small']);
    }

    /**
     * Grade column.
     *
     * @param stdClass $row The table row.
     * @return string HTML.
     */
    public function col_grade_decimal(stdClass $row): string {
        return $row->grade_decimal === null ? $this->nodata() : format_float($row->grade_decimal, 2);
    }

    /**
     * Pass status column.
     *
     * @param stdClass $row The table row.
     * @return string HTML.
     */
    public function col_grade_passed(stdClass $row): string {
        if ($row->grade_passed === null) {
            return $this->badge(
                '&#8212; ' . get_string('gradeunknown', 'local_completionhistory'),
                'badge-secondary lch-badge-md'
            );
        }
        if ($row->grade_passed) {
            return $this->badge(
                '&#10003; ' . get_string('gradepassed', 'local_completionhistory'),
                'badge-success lch-badge-md'
            );
        }
        return $this->badge(
            '&#10007; ' . get_string('gradefailed', 'local_completionhistory'),
            'badge-danger lch-badge-md'
        );
    }

    /**
     * Exam track column.
     *
     * @param stdClass $row The table row.
     * @return string HTML.
     */
    public function col_exam_track(stdClass $row): string {
        if (empty($row->exam_track)) {
            return html_writer::tag('span', get_string('emptyvalue', 'local_completionhistory'), ['class' => 'text-muted']);
        }
        return $this->badge(
            s(attempt_badges::track_label($row->exam_track)),
            attempt_badges::track_badge_class($row->exam_track) . ' lch-badge-sm'
        );
    }

    /**
     * Attempts column: a "used / allowed" summary plus a Details button.
     *
     * The local_completionhistory/attempt_details AMD module fetches the attempt
     * history for the button's user and course and injects it under this row.
     *
     * @param stdClass $row The table row.
     * @return string HTML.
     */
    public function col_attempts(stdClass $row): string {
        $used    = $row->attempts_used ?? null;
        $allowed = $row->attempts_allowed ?? null;

        if ($used === null) {
            // No attempt data recorded yet.
            $summary = html_writer::tag('span', get_string('emptyvalue', 'local_completionhistory'), ['class' => 'text-muted']);
        } else {
            $summary = html_writer::tag(
                'span',
                s(attempt_badges::attempts_summary((int) $used, (int) $allowed)),
                ['class' => 'font-weight-bold mr-2']
            );
        }

        // Expand button: only shown when we have a userid to look up.
        $btn = '';
        if ((int) $row->userid !== 0 && !empty($row->courseid)) {
            $btn = html_writer::tag('button', '&#9654; ' . get_string('attempt_details', 'local_completionhistory'), [
                'class'         => 'btn btn-outline-secondary btn-sm lch-btn-xs lch-expand-attempts',
                'data-userid'   => (int) $row->userid,
                'data-courseid' => (int) $row->courseid,
                'data-rowid'    => (int) $row->id,
                'aria-expanded' => 'false',
                'type'          => 'button',
            ]);
        }

        return $summary . $btn;
    }

    /**
     * Programs column: one badge per associated program.
     *
     * @param stdClass $row The table row.
     * @return string HTML.
     */
    public function col_programs(stdClass $row): string {
        global $DB;
        if (!isset($this->programcache[$row->id])) {
            $this->programcache[$row->id] = $DB->get_records(
                'local_completionhistory_ach_program',
                ['achievementid' => $row->id]
            );
        }
        $programs = $this->programcache[$row->id];
        if (empty($programs)) {
            return $this->nodata();
        }
        $names = [];
        foreach ($programs as $program) {
            $names[] = $this->badge(format_string($program->programname_snapshot), 'badge-info');
        }
        return implode(' ', $names);
    }

    /**
     * Source component column.
     *
     * @param stdClass $row The table row.
     * @return string HTML.
     */
    public function col_source_component(stdClass $row): string {
        return s($row->source_component);
    }

    /**
     * Capture time column.
     *
     * @param stdClass $row The table row.
     * @return string HTML.
     */
    public function col_timecreated(stdClass $row): string {
        return userdate($row->timecreated, '%m/%d/%Y');
    }

    /**
     * Course idnumber snapshot column (optional).
     *
     * @param stdClass $row The table row.
     * @return string HTML.
     */
    public function col_courseidnumber_snapshot(stdClass $row): string {
        return s($row->courseidnumber_snapshot ?? '');
    }

    /**
     * Source event column (optional).
     *
     * @param stdClass $row The table row.
     * @return string HTML.
     */
    public function col_source_event(stdClass $row): string {
        return s($row->source_event ?? '');
    }

    /**
     * Certificate link column (optional).
     *
     * @param stdClass $row The table row.
     * @return string HTML.
     */
    public function col_artifacturl(stdClass $row): string {
        if (empty($row->artifacturl)) {
            return $this->nodata();
        }
        $url = clean_param((string) $row->artifacturl, PARAM_URL);
        if ($url === '') {
            return $this->nodata();
        }
        return html_writer::link(
            $url,
            get_string('col_artifact', 'local_completionhistory'),
            ['target' => '_blank', 'rel' => 'noopener noreferrer']
        );
    }
}
