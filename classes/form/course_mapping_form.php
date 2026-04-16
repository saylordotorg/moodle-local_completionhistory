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
 * Form for adding/editing a course replacement mapping.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_mapping_form extends \moodleform {

    /**
     * Form definition.
     */
    protected function definition(): void {
        $mform = $this->_form;

        // Hidden fields.
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'action');
        $mform->setType('action', PARAM_ALPHA);

        // Old course selector.
        $options = ['ajax' => 'core_course/form_course_selector', 'multiple' => false];
        $mform->addElement('autocomplete', 'oldcourseid',
            get_string('col_oldcourse', 'local_completionhistory'),
            [], $options);
        $mform->addRule('oldcourseid', get_string('required'), 'required', null, 'client');
        $mform->setType('oldcourseid', PARAM_INT);

        // New course selector.
        $mform->addElement('autocomplete', 'newcourseid',
            get_string('col_newcourse', 'local_completionhistory'),
            [], $options);
        $mform->addRule('newcourseid', get_string('required'), 'required', null, 'client');
        $mform->setType('newcourseid', PARAM_INT);

        // Migration rule.
        $mform->addElement('select', 'migrationrule',
            get_string('col_migrationrule', 'local_completionhistory'),
            [
                'redirect_incomplete' => get_string('migrationrule_redirect_incomplete', 'local_completionhistory'),
                'recommend' => get_string('migrationrule_recommend', 'local_completionhistory'),
            ]);
        $mform->setDefault('migrationrule', 'redirect_incomplete');

        // Active toggle.
        $mform->addElement('advcheckbox', 'active', get_string('col_active', 'local_completionhistory'));
        $mform->setDefault('active', 1);

        // Effective time.
        $mform->addElement('date_selector', 'effectivetime',
            get_string('col_effectivetime', 'local_completionhistory'),
            ['optional' => true]);

        // Note.
        $mform->addElement('textarea', 'note', get_string('col_note', 'local_completionhistory'),
            ['rows' => 3, 'cols' => 60]);
        $mform->setType('note', PARAM_TEXT);

        $this->add_action_buttons();
    }

    /**
     * Validation.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        if (!empty($data['oldcourseid']) && !empty($data['newcourseid'])
            && $data['oldcourseid'] == $data['newcourseid']) {
            $errors['newcourseid'] = 'Old and new course must be different.';
        }

        return $errors;
    }
}
