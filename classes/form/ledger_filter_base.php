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

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

/**
 * Shared shape of the staff ledger filter forms.
 *
 * Both staff pages filter with GET (so a search is a bookmarkable URL) and change
 * the saved column layout with POST buttons on the same form. This base adds the
 * pieces both forms share: date filters, the column manager widget, and the
 * Search / Reset / layout button row.
 *
 * Custom data keys read here:
 *   - columnmanagerhtml (string) the rendered column_manager widget
 *   - cansetdefault     (bool)   whether the site-default layout buttons are offered
 *   - hassavedpref      (bool)   whether the user has a saved layout to reset
 *   - hassitedefault    (bool)   whether a site-default layout exists to reset
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class ledger_filter_base extends \moodleform {
    /**
     * Form definition: the page-specific filters, then the shared column manager and buttons.
     */
    protected function definition(): void {
        $mform = $this->_form;
        $mform->updateAttributes(['class' => $mform->getAttribute('class') . ' lch-filter-form']);
        $this->set_display_vertical();

        $this->define_filters();
        $this->add_column_manager();
        $this->add_buttons();
    }

    /**
     * Add the page-specific filter elements.
     */
    abstract protected function define_filters(): void;

    /**
     * Add a text filter.
     *
     * @param string $name Element name (also the query parameter name).
     * @param string $label Translated label.
     * @param string $placeholder Translated placeholder text.
     * @param array $attributes Extra HTML attributes.
     */
    protected function add_text_filter(string $name, string $label, string $placeholder = '', array $attributes = []): void {
        $mform = $this->_form;
        if ($placeholder !== '') {
            $attributes['placeholder'] = $placeholder;
        }
        $mform->addElement('text', $name, $label, $attributes);
        $mform->setType($name, PARAM_TEXT);
    }

    /**
     * Add a date filter.
     *
     * The value is a YYYY-MM-DD string so that existing URLs keep working; the
     * column_manager AMD module upgrades the input to a native date picker.
     *
     * @param string $name Element name (also the query parameter name).
     * @param string $label Translated label.
     */
    protected function add_date_filter(string $name, string $label): void {
        $this->add_text_filter($name, $label, get_string('placeholder_date', 'local_completionhistory'), [
            'data-lch-type' => 'date',
            'size'          => 12,
        ]);
    }

    /**
     * Add the pre-rendered column manager widget (checkbox grid, drag list, hidden visiblecols).
     */
    protected function add_column_manager(): void {
        $html = (string) ($this->_customdata['columnmanagerhtml'] ?? '');
        if ($html !== '') {
            $this->_form->addElement('html', $html);
        }
    }

    /**
     * Add the Search / Reset / layout button row.
     *
     * Search submits with GET. Every other button changes state and therefore
     * posts (formmethod="post"); the page checks the session key on those.
     */
    protected function add_buttons(): void {
        $mform         = $this->_form;
        $cansetdefault = !empty($this->_customdata['cansetdefault']);
        $hassavedpref  = !empty($this->_customdata['hassavedpref']);
        $hassitedef    = !empty($this->_customdata['hassitedefault']);

        $buttons   = [];
        $buttons[] = $mform->createElement(
            'submit',
            'submitbutton',
            get_string('search'),
            [],
            true,
            ['customclassoverride' => 'btn-primary mr-2 mb-1']
        );
        $buttons[] = $mform->createElement(
            'cancel',
            'cancel',
            get_string('filter_reset', 'local_completionhistory'),
            ['formmethod' => 'post', 'class' => 'mr-2 mb-1']
        );
        $buttons[] = $mform->createElement(
            'submit',
            'savelayout',
            get_string('savelayout', 'local_completionhistory'),
            [
                'formmethod' => 'post',
                'title'      => get_string('savelayout_help', 'local_completionhistory'),
            ],
            false,
            ['customclassoverride' => 'btn-outline-success mr-2 mb-1']
        );
        if ($hassavedpref) {
            $buttons[] = $mform->createElement(
                'submit',
                'resetlayout',
                get_string('resetlayout', 'local_completionhistory'),
                [
                    'formmethod'   => 'post',
                    'title'        => get_string('resetlayout_help', 'local_completionhistory'),
                    'data-confirm' => get_string('resetlayout_confirm', 'local_completionhistory'),
                ],
                false,
                ['customclassoverride' => 'btn-outline-danger mr-2 mb-1']
            );
        }
        if ($cansetdefault) {
            $buttons[] = $mform->createElement(
                'submit',
                'savedefault',
                get_string('savedefault', 'local_completionhistory'),
                [
                    'formmethod' => 'post',
                    'title'      => get_string('savedefault_help', 'local_completionhistory'),
                ],
                false,
                ['customclassoverride' => 'btn-outline-primary mr-2 mb-1']
            );
            if ($hassitedef) {
                $buttons[] = $mform->createElement(
                    'submit',
                    'resetdefault',
                    get_string('resetdefault', 'local_completionhistory'),
                    [
                        'formmethod'   => 'post',
                        'title'        => get_string('resetdefault_help', 'local_completionhistory'),
                        'data-confirm' => get_string('resetdefault_confirm', 'local_completionhistory'),
                    ],
                    false,
                    ['customclassoverride' => 'btn-outline-warning mr-2 mb-1']
                );
            }
        }

        $mform->addGroup($buttons, 'buttonar', '', ' ', false);
        $mform->closeHeaderBefore('buttonar');
    }
}
