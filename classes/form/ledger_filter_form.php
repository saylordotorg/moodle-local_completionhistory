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

namespace local_completionhistory\form;

/**
 * Filter form for the Achievement Ledger staff page.
 *
 * Element names are the query parameter names the page has always accepted, so
 * bookmarked URLs keep working. Besides the keys read by the base class, the
 * custom data carries:
 *   - programs (string[]) program id => program name, for the multi-select
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ledger_filter_form extends ledger_filter_base {
    /**
     * The ledger filters: identity, course, source, pass status, dates and programs.
     */
    protected function define_filters(): void {
        $mform = $this->_form;

        $this->add_text_filter(
            'filteruserid',
            get_string('col_user', 'local_completionhistory'),
            get_string('placeholder_userid', 'local_completionhistory'),
            ['size' => 12]
        );
        $this->add_text_filter(
            'filtercoursename',
            get_string('col_coursename', 'local_completionhistory'),
            get_string('placeholder_coursename', 'local_completionhistory')
        );
        $this->add_text_filter(
            'filtersource',
            get_string('col_source', 'local_completionhistory'),
            get_string('placeholder_source', 'local_completionhistory')
        );

        $mform->addElement('select', 'filterpassed', get_string('filter_passed', 'local_completionhistory'), [
            ''     => get_string('filter_passed_any', 'local_completionhistory'),
            '1'    => get_string('filter_passed_yes', 'local_completionhistory'),
            '0'    => get_string('filter_passed_no', 'local_completionhistory'),
            'null' => get_string('filter_passed_unknown', 'local_completionhistory'),
        ]);

        $this->add_date_filter('filterdatefrom', get_string('filter_datefrom', 'local_completionhistory'));
        $this->add_date_filter('filterdateto', get_string('filter_dateto', 'local_completionhistory'));

        $mform->addElement(
            'checkbox',
            'filterhasprograms',
            get_string('filter_programs_heading', 'local_completionhistory'),
            get_string('filter_hasprograms', 'local_completionhistory')
        );

        // The multi-select is the visible control; the hidden comma-separated
        // filterprogramids is the query parameter the page has always used. The
        // AMD module copies the selection into the hidden input on submit, and the
        // page also accepts the raw multi-select values when JavaScript is off.
        $programs = $this->_customdata['programs'] ?? [];
        $selector = $mform->addElement(
            'select',
            'filterprogramselector',
            get_string('filter_programs', 'local_completionhistory'),
            $programs,
            ['data-region' => 'program-selector', 'size' => 4]
        );
        $selector->setMultiple(true);
        if (empty($programs)) {
            $selector->updateAttributes(['disabled' => 'disabled']);
        }
        $mform->addElement(
            'static',
            'filterprogramshelp',
            '',
            get_string('filter_programs_help', 'local_completionhistory')
        );
        $mform->addElement('hidden', 'filterprogramids', '', ['data-region' => 'program-ids']);
        $mform->setType('filterprogramids', PARAM_TEXT);
    }
}
