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
use local_completionhistory\local\flag_service;
use local_completionhistory\output\attempt_badges;
use moodle_url;
use stdClass;
use table_sql;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/tablelib.php');

/**
 * Table for the Exam Attempt Log page.
 *
 * One row per exam attempt. Joined to mdl_user and mdl_course for display.
 * The base SQL (fields + from + where) is set from the page's filter values by
 * apply_filters() so that filter conditions are applied before the table renders.
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
    /** @var string[] Columns that are computed and therefore cannot be sorted in SQL. */
    private const NOSORT_COLS = ['achievement', 'duration', 'flags'];

    /** @var array Every column as [column name, label string key] pairs, in canonical order. */
    private const COLS = [
        ['user_firstname', 'col_firstname'],
        ['user_lastname', 'col_lastname'],
        ['user_email', 'col_email'],
        ['user_country', 'col_country'],
        ['useridnumber', 'col_useridnumber'],
        ['course_fullname', 'col_coursename'],
        ['course_shortname', 'col_courseshortname'],
        ['exam_track', 'col_exam_track'],
        ['attempt_number', 'col_attempt_number'],
        ['grade_decimal', 'col_grade'],
        ['grade_passed', 'col_attempt_result'],
        ['timetaken', 'col_attempt_date'],
        ['duration', 'col_duration'],
        ['flags', 'col_flags'],
        ['achievement', 'col_achievement_link'],
    ];

    /**
     * Constructor.
     *
     * @param string $uniqueid Unique table id.
     * @param string[] $visiblecols Visible column names in display order; empty for the defaults.
     */
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
     *
     * @return string[] Column name => label.
     */
    public static function all_col_labels(): array {
        $map = [];
        foreach (self::COLS as [$col, $key]) {
            $map[$col] = get_string($key, 'local_completionhistory');
        }
        return $map;
    }

    /**
     * Column => category map. Used by the Columns filter pills so admins
     * can narrow the checkbox grid to (say) just grade-related columns.
     *
     * @return string[] Column name => category key.
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
     * Category => translated label. Controls the pill order in the UI.
     *
     * @return string[] Category key => label.
     */
    public static function category_labels(): array {
        return [
            'user'   => get_string('colcat_user', 'local_completionhistory'),
            'course' => get_string('colcat_course', 'local_completionhistory'),
            'exam'   => get_string('colcat_exam', 'local_completionhistory'),
            'grade'  => get_string('colcat_grade', 'local_completionhistory'),
            'time'   => get_string('colcat_time', 'local_completionhistory'),
            'flags'  => get_string('colcat_flags', 'local_completionhistory'),
            'other'  => get_string('colcat_other', 'local_completionhistory'),
        ];
    }

    /**
     * Default visible columns in canonical order.
     *
     * @return string[] Column names.
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

    /**
     * Set the table SQL from the page's filter values.
     *
     * Filter keys (all optional): user (name or idnumber search), coursename,
     * track (exam track code), result ('passed', 'failed' or ''), datefrom and
     * dateto (YYYY-MM-DD strings), exhausted (bool) and completion (bool).
     *
     * @param array $filters The filter values.
     */
    public function apply_filters(array $filters): void {
        global $DB;

        $conditions = ['1 = 1'];
        $params     = [];

        if (!empty($filters['user'])) {
            $likeval      = '%' . $DB->sql_like_escape($filters['user']) . '%';
            $conditions[] = '(' . $DB->sql_like('u.firstname', ':fname', false)
                . ' OR ' . $DB->sql_like('u.lastname', ':lname', false)
                . ' OR ' . $DB->sql_like('u.idnumber', ':idnum', false) . ')';
            $params['fname'] = $likeval;
            $params['lname'] = $likeval;
            $params['idnum'] = $likeval;
        }

        if (!empty($filters['coursename'])) {
            $conditions[]               = $DB->sql_like('c.fullname', ':filtercoursename', false);
            $params['filtercoursename'] = '%' . $DB->sql_like_escape($filters['coursename']) . '%';
        }

        if (!empty($filters['track'])) {
            $conditions[]          = 'ea.exam_track = :filtertrack';
            $params['filtertrack'] = $filters['track'];
        }

        $result = (string) ($filters['result'] ?? '');
        if ($result === 'passed') {
            $conditions[] = 'ea.grade_passed = 1';
        } else if ($result === 'failed') {
            $conditions[] = 'ea.grade_passed = 0';
        }

        if (!empty($filters['datefrom'])) {
            $ts = strtotime($filters['datefrom']);
            if ($ts !== false) {
                $conditions[]             = 'ea.timetaken >= :filterdatefrom';
                $params['filterdatefrom'] = $ts;
            }
        }
        if (!empty($filters['dateto'])) {
            $ts = strtotime($filters['dateto'] . ' 23:59:59');
            if ($ts !== false) {
                $conditions[]           = 'ea.timetaken <= :filterdateto';
                $params['filterdateto'] = $ts;
            }
        }

        if (!empty($filters['exhausted'])) {
            $conditions[] = 'ea.attempts_allowed > 0 AND ea.attempt_number >= ea.attempts_allowed AND ea.grade_passed = 0';
        }

        if (!empty($filters['completion'])) {
            $conditions[] = 'ea.resulted_in_completion = 1';
        }

        $this->set_sql(
            'ea.*,
             u.firstname   AS user_firstname,
             u.lastname    AS user_lastname,
             u.email       AS user_email,
             u.country     AS user_country,
             u.idnumber    AS useridnumber,
             u.timecreated AS user_timecreated,
             c.fullname    AS course_fullname,
             c.shortname   AS course_shortname',
            '{local_completionhistory_exam_attempt} ea
             LEFT JOIN {user}   u ON u.id  = ea.userid
             LEFT JOIN {course} c ON c.id  = ea.courseid',
            implode(' AND ', $conditions),
            $params
        );
    }

    /**
     * A small badge.
     *
     * @param string $text Already-escaped badge content.
     * @param string $class Badge modifier classes.
     * @param array $attributes Extra HTML attributes.
     * @return string HTML.
     */
    protected function badge(string $text, string $class, array $attributes = []): string {
        $attributes['class'] = 'badge ' . $class;
        return html_writer::tag('span', $text, $attributes);
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
     * Country column, as the translated country name.
     *
     * @param stdClass $row The table row.
     * @return string HTML.
     */
    public function col_user_country(stdClass $row): string {
        if ((int) $row->userid === 0) {
            return '';
        }
        $code = trim((string) ($row->user_country ?? ''));
        if ($code === '') {
            return $this->nodata();
        }
        $countries = get_string_manager()->get_list_of_countries(true);
        return isset($countries[$code]) ? s($countries[$code]) : s($code);
    }

    /**
     * User idnumber column.
     *
     * @param stdClass $row The table row.
     * @return string HTML.
     */
    public function col_useridnumber(stdClass $row): string {
        return s($row->useridnumber ?? '');
    }

    /**
     * Course full name column.
     *
     * @param stdClass $row The table row.
     * @return string HTML.
     */
    public function col_course_fullname(stdClass $row): string {
        return format_string($row->course_fullname ?? '');
    }

    /**
     * Course short name column.
     *
     * @param stdClass $row The table row.
     * @return string HTML.
     */
    public function col_course_shortname(stdClass $row): string {
        return s($row->course_shortname ?? '');
    }

    /**
     * Exam track column.
     *
     * @param stdClass $row The table row.
     * @return string HTML.
     */
    public function col_exam_track(stdClass $row): string {
        return $this->badge(
            s(attempt_badges::track_label($row->exam_track)),
            attempt_badges::track_badge_class($row->exam_track) . ' lch-badge-sm'
        );
    }

    /**
     * Attempt number column: "N of M" plus a Final badge on the last allowed attempt.
     *
     * @param stdClass $row The table row.
     * @return string HTML.
     */
    public function col_attempt_number(stdClass $row): string {
        $number  = (int) $row->attempt_number;
        $allowed = (int) $row->attempts_allowed;

        $a = (object) [
            'number'  => $number,
            'allowed' => attempt_badges::allowed_label($allowed),
        ];
        $html = html_writer::tag(
            'span',
            s(get_string('attempt_of', 'local_completionhistory', $a)),
            ['class' => 'font-weight-bold']
        );

        // Final-attempt warning badge.
        if ($allowed > 0 && $number === $allowed) {
            $html .= ' ' . $this->badge(get_string('attempt_final', 'local_completionhistory'), 'badge-warning lch-badge-xs');
        }
        return $html;
    }

    /**
     * Grade column, as a percentage.
     *
     * @param stdClass $row The table row.
     * @return string HTML.
     */
    public function col_grade_decimal(stdClass $row): string {
        if ($row->grade_decimal === null) {
            return $this->nodata();
        }
        return format_float((float) $row->grade_decimal, 1) . '%';
    }

    /**
     * Result column: passed, failed (possibly exhausting the track) or unknown.
     *
     * @param stdClass $row The table row.
     * @return string HTML.
     */
    public function col_grade_passed(stdClass $row): string {
        $number  = (int) $row->attempt_number;
        $allowed = (int) $row->attempts_allowed;
        $passed  = $row->grade_passed;

        if ($passed === null || $passed === '') {
            return $this->badge(
                '&#8212; ' . get_string('gradeunknown', 'local_completionhistory'),
                'badge-secondary lch-badge-md'
            );
        }

        if ((int) $passed === 1) {
            $label = '&#10003; ' . get_string('result_passed', 'local_completionhistory');
            if ((int) $row->resulted_in_completion) {
                $label .= ' ' . html_writer::tag(
                    'span',
                    '&#127775;',
                    ['title' => get_string('attempt_completing', 'local_completionhistory')]
                );
            }
            return $this->badge($label, 'badge-success lch-badge-md');
        }

        $label = attempt_badges::is_exhausted($number, $allowed)
            ? get_string('result_failed_exhausted', 'local_completionhistory')
            : get_string('result_failed', 'local_completionhistory');
        return $this->badge('&#10007; ' . $label, 'badge-danger lch-badge-md');
    }

    /**
     * Attempt date column, with a relative "N days ago" hint.
     *
     * @param stdClass $row The table row.
     * @return string HTML.
     */
    public function col_timetaken(stdClass $row): string {
        if (empty($row->timetaken)) {
            return $this->nodata();
        }
        $ts   = (int) $row->timetaken;
        $days = (int) floor((time() - $ts) / 86400);

        $date = userdate($ts, '%m/%d/%Y');
        if ($days === 0) {
            $ago = get_string('ago_today', 'local_completionhistory');
        } else if ($days === 1) {
            $ago = get_string('days_ago_one', 'local_completionhistory');
        } else {
            $ago = get_string('days_ago', 'local_completionhistory', number_format($days));
        }

        return $date . html_writer::tag('span', ' (' . $ago . ')', ['class' => 'text-muted small']);
    }

    /**
     * Duration column, as hours, minutes and seconds.
     *
     * @param stdClass $row The table row.
     * @return string HTML.
     */
    public function col_duration(stdClass $row): string {
        $secs = isset($row->duration) ? (int) $row->duration : 0;
        if ($secs <= 0) {
            return $this->nodata();
        }

        $a = (object) [
            'h' => intdiv($secs, 3600),
            'm' => sprintf('%02d', intdiv($secs % 3600, 60)),
            's' => sprintf('%02d', $secs % 60),
        ];

        if ($a->h > 0) {
            return get_string('duration_hms', 'local_completionhistory', $a);
        }
        if ((int) $a->m > 0) {
            $a->m = (int) $a->m;
            return get_string('duration_ms', 'local_completionhistory', $a);
        }
        $a->s = (int) $a->s;
        return get_string('duration_s', 'local_completionhistory', $a);
    }

    /**
     * System flags column: one badge per matching flag definition.
     *
     * @param stdClass $row The table row.
     * @return string HTML.
     */
    public function col_flags(stdClass $row): string {
        $matches = flag_service::evaluate($row);
        if (empty($matches)) {
            return html_writer::tag('span', get_string('emptyvalue', 'local_completionhistory'), ['class' => 'text-muted']);
        }
        $badges = [];
        foreach ($matches as $def) {
            $cls   = flag_service::severity_badge_class($def->severity);
            $title = $def->description ? s($def->description) : s($def->name);
            $badges[] = $this->badge(s($def->name), "{$cls} mr-1 lch-badge-flag", ['title' => $title]);
        }
        return implode('', $badges);
    }

    /**
     * Achievement link column: opens the ledger filtered to this user and course.
     *
     * @param stdClass $row The table row.
     * @return string HTML.
     */
    public function col_achievement(stdClass $row): string {
        if ((int) $row->userid === 0) {
            return $this->nodata();
        }
        if (empty($row->achievementid)) {
            return $this->nodata();
        }

        $url = new moodle_url('/local/completionhistory/achievement_ledger.php', [
            'filteruserid'     => (int) $row->userid,
            'filtercoursename' => $row->course_fullname ?? '',
        ]);
        return html_writer::link(
            $url->out(false),
            '&#8594; ' . get_string('ledger_link', 'local_completionhistory'),
            ['class' => 'btn btn-outline-secondary btn-sm lch-btn-link-sm']
        );
    }
}
