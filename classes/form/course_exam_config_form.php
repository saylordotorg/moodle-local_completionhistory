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

use html_writer;
use local_completionhistory\local\course_config_service;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

/**
 * Form for adding or editing a course exam configuration.
 *
 * Custom data:
 *   - course      stdClass|null  The course being edited (id, fullname, shortname), or null when adding.
 *   - quizoptions array          Quiz select options keyed by quiz id ('' => not mapped) for that course.
 *
 * The per-track sections are shown and hidden with hideIf rules keyed on the
 * course type, so no page-level JavaScript is needed.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_exam_config_form extends \moodleform {
    /** @var int Smallest number of attempts for the limited (program and direct credit) tracks. */
    const LIMITED_ATTEMPTS_MIN = 1;
    /** @var int Largest number of attempts for the limited (program and direct credit) tracks. */
    const LIMITED_ATTEMPTS_MAX = 10;
    /** @var int Smallest number of certificate attempts (0 means unlimited). */
    const CERT_ATTEMPTS_MIN = 0;
    /** @var int Largest number of certificate attempts. */
    const CERT_ATTEMPTS_MAX = 99;

    /**
     * Form definition.
     *
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;
        $course = $this->_customdata['course'] ?? null;
        $quizoptions = $this->_customdata['quizoptions'] ?? [
            '' => get_string('examconfig_noquiz', 'local_completionhistory'),
        ];

        // Course: locked once known, searchable when adding.
        if ($course) {
            $mform->addElement('hidden', 'courseid', (int) $course->id);
            $mform->setType('courseid', PARAM_INT);
            $mform->addElement(
                'static',
                'coursename',
                get_string('course'),
                format_string($course->fullname) . ' (' . s($course->shortname) . ')'
            );
        } else {
            $mform->addElement(
                'autocomplete',
                'courseid',
                get_string('course'),
                [],
                ['ajax' => 'core_course/form_course_selector', 'multiple' => false]
            );
            $mform->addRule('courseid', get_string('required'), 'required', null, 'client');
            $mform->setType('courseid', PARAM_INT);
        }

        // Course type drives which track sections are visible.
        $mform->addElement(
            'select',
            'course_type',
            get_string('examconfig_type', 'local_completionhistory'),
            course_config_service::type_labels()
        );
        $mform->setType('course_type', PARAM_ALPHA);
        $mform->setDefault('course_type', course_config_service::TYPE_STANDARD);

        // Program Final track.
        $this->add_track_section(
            'program',
            get_string('track_program_final', 'local_completionhistory'),
            'program_final_quizid',
            'program_attempts_allowed',
            get_string('examconfig_attempts', 'local_completionhistory'),
            $quizoptions
        );

        // Direct Credit track.
        $this->add_track_section(
            'dc',
            get_string('track_direct_credit', 'local_completionhistory'),
            'dc_quizid',
            'dc_attempts_allowed',
            get_string('examconfig_attempts', 'local_completionhistory'),
            $quizoptions
        );

        // Certificate track.
        $this->add_track_section(
            'cert',
            get_string('track_certificate', 'local_completionhistory'),
            'cert_quizid',
            'cert_attempts_allowed',
            get_string('examconfig_cert_attempts', 'local_completionhistory'),
            $quizoptions
        );
        $mform->addElement(
            'static',
            'cert_attempts_help',
            '',
            get_string('examconfig_cert_attempts_help', 'local_completionhistory')
        );

        // Show each section only for the course types that use its track.
        $nocert = [course_config_service::TYPE_STANDARD, course_config_service::TYPE_PROGRAM];
        foreach (['program_heading', 'program_final_quizid', 'program_attempts_allowed'] as $name) {
            $mform->hideIf($name, 'course_type', 'neq', course_config_service::TYPE_PROGRAM);
        }
        foreach (['dc_heading', 'dc_quizid', 'dc_attempts_allowed'] as $name) {
            $mform->hideIf($name, 'course_type', 'neq', course_config_service::TYPE_OPEN_DUAL);
        }
        foreach (['cert_heading', 'cert_quizid', 'cert_attempts_allowed', 'cert_attempts_help'] as $name) {
            $mform->hideIf($name, 'course_type', 'in', $nocert);
        }

        // Notes.
        $mform->addElement(
            'textarea',
            'notes',
            get_string('examconfig_notes', 'local_completionhistory'),
            ['rows' => 3, 'cols' => 60]
        );
        $mform->setType('notes', PARAM_TEXT);

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    /**
     * Add the heading, quiz select and attempts input for one exam track.
     *
     * @param string $prefix        Section prefix used for the heading element name.
     * @param string $heading       Track label shown above the section.
     * @param string $quizfield     Name of the quiz id field.
     * @param string $attemptsfield Name of the attempts allowed field.
     * @param string $attemptslabel Label for the attempts field.
     * @param array  $quizoptions   Quiz select options.
     * @return void
     */
    private function add_track_section(
        string $prefix,
        string $heading,
        string $quizfield,
        string $attemptsfield,
        string $attemptslabel,
        array $quizoptions
    ): void {
        $mform = $this->_form;

        $mform->addElement(
            'static',
            $prefix . '_heading',
            '',
            html_writer::tag('span', $heading, ['class' => 'lch-track-heading'])
        );

        $mform->addElement(
            'select',
            $quizfield,
            get_string('examconfig_quiz', 'local_completionhistory'),
            $quizoptions
        );
        $mform->setType($quizfield, PARAM_INT);

        $mform->addElement('text', $attemptsfield, $attemptslabel, ['size' => 4]);
        $mform->setType($attemptsfield, PARAM_INT);
        $mform->addRule($attemptsfield, get_string('err_numeric', 'form'), 'numeric', null, 'client');
    }

    /**
     * Server-side validation.
     *
     * @param array $data  Submitted data.
     * @param array $files Submitted files.
     * @return array Errors keyed by element name.
     */
    public function validation($data, $files): array {
        global $DB;

        $errors = parent::validation($data, $files);

        $courseid = (int) ($data['courseid'] ?? 0);
        if ($courseid <= 0 || !$DB->record_exists('course', ['id' => $courseid])) {
            $errors['courseid'] = get_string('error_nocourse', 'local_completionhistory');
        }

        if (!array_key_exists($data['course_type'] ?? '', course_config_service::type_labels())) {
            $errors['course_type'] = get_string('required');
        }

        $ranges = [
            'program_attempts_allowed' => [self::LIMITED_ATTEMPTS_MIN, self::LIMITED_ATTEMPTS_MAX],
            'dc_attempts_allowed'      => [self::LIMITED_ATTEMPTS_MIN, self::LIMITED_ATTEMPTS_MAX],
            'cert_attempts_allowed'    => [self::CERT_ATTEMPTS_MIN, self::CERT_ATTEMPTS_MAX],
        ];
        foreach ($ranges as $field => [$min, $max]) {
            $value = (int) ($data[$field] ?? 0);
            if ($value < $min || $value > $max) {
                $errors[$field] = get_string(
                    'examconfig_attempts_range',
                    'local_completionhistory',
                    (object) ['min' => $min, 'max' => $max]
                );
            }
        }

        return $errors;
    }
}
