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
     * @param string $uniqueid
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
            '',
        ]);
        $this->no_sorting('actions');
        $this->sortable(true, 'timecreated', SORT_DESC);
    }

    /**
     * Format the old course name column.
     */
    public function col_oldcoursename_snapshot($row): string {
        $name = format_string($row->oldcoursename_snapshot);
        if (!empty($row->oldcourseidnumber_snapshot)) {
            $name .= ' ' . html_writer::tag('small', '(' . s($row->oldcourseidnumber_snapshot) . ')', ['class' => 'text-muted']);
        }
        return $name;
    }

    /**
     * Format the new course name column.
     */
    public function col_newcoursename_snapshot($row): string {
        $name = format_string($row->newcoursename_snapshot);
        if (!empty($row->newcourseidnumber_snapshot)) {
            $name .= ' ' . html_writer::tag('small', '(' . s($row->newcourseidnumber_snapshot) . ')', ['class' => 'text-muted']);
        }
        return $name;
    }

    /**
     * Format the migration rule column.
     */
    public function col_migrationrule($row): string {
        $key = 'migrationrule_' . $row->migrationrule;
        if (get_string_manager()->string_exists($key, 'local_completionhistory')) {
            return get_string($key, 'local_completionhistory');
        }
        return s($row->migrationrule);
    }

    /**
     * Format the active column.
     */
    public function col_active($row): string {
        return $row->active ? get_string('yes') : get_string('no');
    }

    /**
     * Format the note column.
     */
    public function col_note($row): string {
        if (empty($row->note)) {
            return '-';
        }
        return format_text($row->note, FORMAT_PLAIN);
    }

    /**
     * Format the created time column.
     */
    public function col_timecreated($row): string {
        return userdate($row->timecreated, get_string('strftimedatetimeshort', 'langconfig'));
    }

    /**
     * Format the actions column.
     */
    public function col_actions($row): string {
        $editurl = new moodle_url('/local/completionhistory/course_mappings.php', [
            'action' => 'edit',
            'id' => $row->id,
        ]);
        $deleteurl = new moodle_url('/local/completionhistory/course_mappings.php', [
            'action' => 'delete',
            'id' => $row->id,
            'sesskey' => sesskey(),
        ]);

        $actions = html_writer::link($editurl, get_string('edit'), ['class' => 'btn btn-sm btn-secondary mr-1']);
        $actions .= html_writer::link($deleteurl, get_string('delete'), [
            'class' => 'btn btn-sm btn-danger',
            'onclick' => "return confirm('" . get_string('confirmdeletemapping', 'local_completionhistory') . "');",
        ]);

        return $actions;
    }
}
