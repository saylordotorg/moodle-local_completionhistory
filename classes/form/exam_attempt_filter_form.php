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

use local_completionhistory\local\course_config_service;

/**
 * Filter form for the Exam Attempt Log staff page.
 *
 * Element names are the query parameter names the page has always accepted, so
 * bookmarked URLs keep working.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class exam_attempt_filter_form extends ledger_filter_base {
    /**
     * The attempt log filters: user, course, track, result, dates and the two flags.
     */
    protected function define_filters(): void {
        $mform = $this->_form;

        $this->add_text_filter(
            'filteruser',
            get_string('filter_user_search', 'local_completionhistory'),
            get_string('placeholder_usersearch', 'local_completionhistory')
        );
        $this->add_text_filter(
            'filtercoursename',
            get_string('col_coursename', 'local_completionhistory'),
            get_string('placeholder_coursename', 'local_completionhistory')
        );

        $trackoptions = ['' => get_string('filter_examtrack_any', 'local_completionhistory')]
            + course_config_service::track_labels();
        $mform->addElement('select', 'filtertrack', get_string('col_exam_track', 'local_completionhistory'), $trackoptions);

        $mform->addElement('select', 'filterresult', get_string('col_attempt_result', 'local_completionhistory'), [
            ''       => get_string('filter_passed_any', 'local_completionhistory'),
            'passed' => get_string('filter_passed_yes', 'local_completionhistory'),
            'failed' => get_string('filter_passed_no', 'local_completionhistory'),
        ]);

        $this->add_date_filter('filterdatefrom', get_string('filter_datefrom', 'local_completionhistory'));
        $this->add_date_filter('filterdateto', get_string('filter_dateto', 'local_completionhistory'));

        $mform->addElement(
            'checkbox',
            'filterexhausted',
            '',
            get_string('filter_exhausted_only', 'local_completionhistory')
        );
        $mform->addElement(
            'checkbox',
            'filtercompletion',
            '',
            get_string('filter_completing_only', 'local_completionhistory')
        );
    }
}
