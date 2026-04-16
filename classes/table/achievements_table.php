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
use moodle_url;
use html_writer;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/tablelib.php');

/**
 * Table for displaying achievement records.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class achievements_table extends table_sql {

    /** @var bool Whether to show the user column (staff view). */
    protected bool $showuser;

    /** @var array Optional extra column names to include. */
    protected array $optionalcols;

    /** @var array Cached program associations keyed by achievementid. */
    protected array $programcache = [];

    /**
     * Constructor.
     *
     * @param string $uniqueid
     * @param bool   $showuser       Whether to show the user column.
     * @param array  $optionalcols   Optional column names to append (e.g. 'useridnumber_snapshot').
     */
    public function __construct(string $uniqueid, bool $showuser = false, array $optionalcols = []) {
        parent::__construct($uniqueid);
        $this->showuser     = $showuser;
        $this->optionalcols = $optionalcols;

        $columns = [];
        $headers = [];

        if ($showuser) {
            $columns[] = 'userid';
            $headers[] = get_string('col_user', 'local_completionhistory');
        }

        $columns = array_merge($columns, [
            'coursename_snapshot',
            'completiontime',
            'grade_decimal',
            'grade_passed',
            'programs',
            'source_component',
            'timecreated',
        ]);
        $headers = array_merge($headers, [
            get_string('col_coursename', 'local_completionhistory'),
            get_string('col_completiondate', 'local_completionhistory'),
            get_string('col_grade', 'local_completionhistory'),
            get_string('col_passed', 'local_completionhistory'),
            get_string('col_programs', 'local_completionhistory'),
            get_string('col_source', 'local_completionhistory'),
            get_string('col_captured', 'local_completionhistory'),
        ]);

        // Optional extra columns appended after the core set.
        $optionalheadermap = [
            'useridnumber_snapshot'    => get_string('col_useridnumber', 'local_completionhistory'),
            'courseidnumber_snapshot'  => get_string('col_courseidnumber', 'local_completionhistory'),
            'courseshortname_snapshot' => get_string('col_courseshortname', 'local_completionhistory'),
            'source_event'             => get_string('col_source_event', 'local_completionhistory'),
            'artifacturl'              => get_string('col_artifact', 'local_completionhistory'),
        ];
        foreach ($optionalcols as $col) {
            if (isset($optionalheadermap[$col])) {
                $columns[] = $col;
                $headers[]  = $optionalheadermap[$col];
            }
        }

        $this->define_columns($columns);
        $this->define_headers($headers);
        $this->no_sorting('programs');
        $this->no_sorting('artifacturl');
        $this->sortable(true, 'completiontime', SORT_DESC);
    }

    // -----------------------------------------------------------------------
    // Core column formatters.
    // -----------------------------------------------------------------------

    public function col_userid($row): string {
        global $DB;
        if ((int) $row->userid === 0) {
            return get_string('privacy:metadata:achievement:userid', 'local_completionhistory') . ' [anonymized]';
        }
        $user = $DB->get_record('user', ['id' => $row->userid], 'id, firstname, lastname, email');
        if (!$user) {
            return "User #{$row->userid} [deleted]";
        }
        return fullname($user);
    }

    public function col_coursename_snapshot($row): string {
        $name = format_string($row->coursename_snapshot);
        if (!empty($row->courseidnumber_snapshot)) {
            $name .= ' ' . html_writer::tag('small', '(' . s($row->courseidnumber_snapshot) . ')', ['class' => 'text-muted']);
        }
        return $name;
    }

    public function col_completiontime($row): string {
        return userdate($row->completiontime, get_string('strftimedaydate', 'langconfig'));
    }

    public function col_grade_decimal($row): string {
        if ($row->grade_decimal === null) {
            return '-';
        }
        return format_float($row->grade_decimal, 2);
    }

    /**
     * Passed column rendered as a colour pill with a check or X icon.
     */
    public function col_grade_passed($row): string {
        if ($row->grade_passed === null) {
            return html_writer::tag(
                'span',
                '&#8212; ' . get_string('gradeunknown', 'local_completionhistory'),
                ['class' => 'badge badge-secondary', 'style' => 'font-size:0.85em']
            );
        }
        if ($row->grade_passed) {
            return html_writer::tag(
                'span',
                '&#10003; ' . get_string('gradepassed', 'local_completionhistory'),
                ['class' => 'badge badge-success', 'style' => 'font-size:0.85em']
            );
        }
        return html_writer::tag(
            'span',
            '&#10007; ' . get_string('gradefailed', 'local_completionhistory'),
            ['class' => 'badge badge-danger', 'style' => 'font-size:0.85em']
        );
    }

    public function col_programs($row): string {
        global $DB;

        if (!isset($this->programcache[$row->id])) {
            $this->programcache[$row->id] = $DB->get_records(
                'local_completionhistory_ach_program',
                ['achievementid' => $row->id]
            );
        }

        $programs = $this->programcache[$row->id];
        if (empty($programs)) {
            return '-';
        }

        $names = [];
        foreach ($programs as $p) {
            $names[] = html_writer::tag('span', format_string($p->programname_snapshot), ['class' => 'badge badge-info']);
        }
        return implode(' ', $names);
    }

    public function col_source_component($row): string {
        return s($row->source_component);
    }

    public function col_timecreated($row): string {
        return userdate($row->timecreated, get_string('strftimedatetimeshort', 'langconfig'));
    }

    // -----------------------------------------------------------------------
    // Optional column formatters.
    // -----------------------------------------------------------------------

    public function col_useridnumber_snapshot($row): string {
        return s($row->useridnumber_snapshot ?? '');
    }

    public function col_courseidnumber_snapshot($row): string {
        return s($row->courseidnumber_snapshot ?? '');
    }

    public function col_courseshortname_snapshot($row): string {
        return s($row->courseshortname_snapshot ?? '');
    }

    public function col_source_event($row): string {
        return s($row->source_event ?? '');
    }

    public function col_artifacturl($row): string {
        if (empty($row->artifacturl)) {
            return '-';
        }
        return html_writer::link($row->artifacturl, get_string('col_artifact', 'local_completionhistory'), [
            'target' => '_blank',
            'rel'    => 'noopener noreferrer',
        ]);
    }
}
