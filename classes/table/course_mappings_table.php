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
use stdClass;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/tablelib.php');

/**
 * Table for displaying course replacement mappings.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_mappings_table extends table_sql {
    /**
     * Constructor.
     *
     * @param string $uniqueid Unique id for this table instance.
     */
    public function __construct(string $uniqueid) {
        parent::__construct($uniqueid);

        $this->define_columns([
            'oldcoursename_snapshot',
            'newcoursename_snapshot',
            'migrationrule',
            'active',
            'note',
            'timecreated',
            'actions',
        ]);
        $this->define_headers([
            get_string('col_oldcourse', 'local_completionhistory'),
            get_string('col_newcourse', 'local_completionhistory'),
            get_string('col_migrationrule', 'local_completionhistory'),
            get_string('col_active', 'local_completionhistory'),
            get_string('col_note', 'local_completionhistory'),
            get_string('col_captured', 'local_completionhistory'),
            get_string('actions'),
        ]);
        $this->no_sorting('actions');
        $this->sortable(true, 'timecreated', SORT_DESC);
    }

    /**
     * Format the old course name column.
     *
     * @param stdClass $row Mapping record.
     * @return string HTML.
     */
    public function col_oldcoursename_snapshot(stdClass $row): string {
        return $this->format_course_snapshot($row->oldcoursename_snapshot, $row->oldcourseidnumber_snapshot);
    }

    /**
     * Format the new course name column.
     *
     * @param stdClass $row Mapping record.
     * @return string HTML.
     */
    public function col_newcoursename_snapshot(stdClass $row): string {
        return $this->format_course_snapshot($row->newcoursename_snapshot, $row->newcourseidnumber_snapshot);
    }

    /**
     * Course name with its ID number, as captured when the mapping was saved.
     *
     * @param string|null $name     Course full name snapshot.
     * @param string|null $idnumber Course ID number snapshot.
     * @return string HTML.
     */
    private function format_course_snapshot(?string $name, ?string $idnumber): string {
        $html = format_string((string) $name);
        if (!empty($idnumber)) {
            $html .= ' ' . html_writer::tag('small', '(' . s($idnumber) . ')', ['class' => 'text-muted']);
        }
        return $html;
    }

    /**
     * Format the migration rule column.
     *
     * @param stdClass $row Mapping record.
     * @return string Localised rule name.
     */
    public function col_migrationrule(stdClass $row): string {
        $key = 'migrationrule_' . $row->migrationrule;
        if (get_string_manager()->string_exists($key, 'local_completionhistory')) {
            return get_string($key, 'local_completionhistory');
        }
        return s($row->migrationrule);
    }

    /**
     * Format the active column.
     *
     * @param stdClass $row Mapping record.
     * @return string Yes or no.
     */
    public function col_active(stdClass $row): string {
        return $row->active ? get_string('yes') : get_string('no');
    }

    /**
     * Format the note column.
     *
     * @param stdClass $row Mapping record.
     * @return string HTML.
     */
    public function col_note(stdClass $row): string {
        if (empty($row->note)) {
            return '-';
        }
        return format_text($row->note, FORMAT_PLAIN);
    }

    /**
     * Format the created time column.
     *
     * @param stdClass $row Mapping record.
     * @return string Formatted date.
     */
    public function col_timecreated(stdClass $row): string {
        return userdate($row->timecreated, get_string('strftimedatetimeshort', 'langconfig'));
    }

    /**
     * Format the actions column: an edit link and a sesskey-protected delete form.
     *
     * The delete button uses core/utility's declarative confirmation
     * (data-confirmation="modal"), which core initialises on every page.
     *
     * @param stdClass $row Mapping record.
     * @return string HTML.
     */
    public function col_actions(stdClass $row): string {
        $editurl = new moodle_url('/local/completionhistory/course_mappings.php', [
            'action' => 'edit',
            'id' => $row->id,
        ]);
        $deleteurl = new moodle_url('/local/completionhistory/course_mappings.php');

        $actions = html_writer::link($editurl, get_string('edit'), ['class' => 'btn btn-sm btn-secondary']);
        $actions .= html_writer::start_tag('form', [
            'method' => 'post',
            'action' => $deleteurl->out(false),
            'class' => 'd-inline',
        ]);
        $actions .= html_writer::empty_tag('input', [
            'type' => 'hidden', 'name' => 'action', 'value' => 'delete',
        ]);
        $actions .= html_writer::empty_tag('input', [
            'type' => 'hidden', 'name' => 'id', 'value' => (int) $row->id,
        ]);
        $actions .= html_writer::empty_tag('input', [
            'type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey(),
        ]);
        $actions .= html_writer::tag('button', get_string('delete'), [
            'type' => 'submit',
            'class' => 'btn btn-sm btn-danger',
            'data-confirmation' => 'modal',
            'data-confirmation-type' => 'delete',
            'data-confirmation-title-str' => json_encode(['deletemapping', 'local_completionhistory']),
            'data-confirmation-content-str' => json_encode(['confirmdeletemapping', 'local_completionhistory']),
            'data-confirmation-yes-button-str' => json_encode(['delete', 'core']),
        ]);
        $actions .= html_writer::end_tag('form');
        return html_writer::div($actions, 'lch-actions');
    }
}
