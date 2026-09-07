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

/**
 * Course Exam Configuration admin page.
 *
 * Lets admins classify each course by exam type and map quiz IDs to tracks.
 * The page is the controller only: the add/edit form is
 * {@see \local_completionhistory\form\course_exam_config_form} and the list is
 * {@see \local_completionhistory\output\course_exam_config_list}.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_login();

use local_completionhistory\form\course_exam_config_form;
use local_completionhistory\local\course_config_service;
use local_completionhistory\output\course_exam_config_list;

$systemcontext = context_system::instance();
require_capability('local/completionhistory:manage', $systemcontext);

if (!get_config('local_completionhistory', 'enabled')) {
    throw new moodle_exception('plugindisabled', 'local_completionhistory');
}

$action   = optional_param('action', 'list', PARAM_ALPHA);
$courseid = optional_param('courseid', 0, PARAM_INT);
$page     = optional_param('page', 0, PARAM_INT);

$listurl = new moodle_url('/local/completionhistory/course_exam_config.php');

$PAGE->set_context($systemcontext);
$PAGE->set_url($listurl);
$PAGE->set_title(get_string('courseexamconfig', 'local_completionhistory'));
$PAGE->set_heading(get_string('courseexamconfig', 'local_completionhistory'));
$PAGE->set_pagelayout('admin');

// Delete: a sesskey-protected POST from the list.
if ($action === 'delete' && $courseid) {
    if (!data_submitted()) {
        throw new moodle_exception('invalidrequest');
    }
    require_sesskey();
    course_config_service::delete_config($courseid);
    redirect(
        $listurl,
        get_string('examconfigdeleted', 'local_completionhistory'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Add / edit. The form posts back to action=edit; action=save is kept as an alias.
if ($action === 'edit' || $action === 'save') {
    $course = $courseid ? $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname') : null;

    // Quiz options come from the course once it is known; when adding there is nothing to map yet.
    $quizoptions = ['' => get_string('examconfig_noquiz', 'local_completionhistory')];
    if ($course) {
        foreach ($DB->get_records('quiz', ['course' => $course->id], 'name', 'id, name') as $quiz) {
            $quizoptions[$quiz->id] = format_string($quiz->name);
        }
    }

    $formurl = new moodle_url('/local/completionhistory/course_exam_config.php', [
        'action'   => 'edit',
        'courseid' => $course ? (int) $course->id : 0,
    ]);
    $PAGE->set_url($formurl);
    $form = new course_exam_config_form($formurl, ['course' => $course, 'quizoptions' => $quizoptions]);

    if ($form->is_cancelled()) {
        redirect($listurl);
    }

    if ($data = $form->get_data()) {
        $config                           = new stdClass();
        $config->courseid                 = (int) $data->courseid;
        $config->course_type              = $data->course_type;
        $config->program_final_quizid     = $data->program_final_quizid ?: null;
        $config->dc_quizid                = $data->dc_quizid ?: null;
        $config->cert_quizid              = $data->cert_quizid ?: null;
        $config->program_attempts_allowed = (int) $data->program_attempts_allowed;
        $config->dc_attempts_allowed      = (int) $data->dc_attempts_allowed;
        $config->cert_attempts_allowed    = (int) $data->cert_attempts_allowed;
        $config->notes                    = $data->notes ?? '';

        course_config_service::save_config($config);

        redirect(
            $listurl,
            get_string('examconfigsaved', 'local_completionhistory'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    $defaults = course_config_service::get_config($course ? (int) $course->id : 0);
    if (!$course) {
        // Leave the course selector empty rather than pre-filling it with 0.
        unset($defaults->courseid);
    }
    $form->set_data($defaults);

    echo $OUTPUT->header();
    echo $OUTPUT->heading(
        $course
            ? get_string('examconfig_edit_heading', 'local_completionhistory', format_string($course->fullname))
            : get_string('examconfig_add', 'local_completionhistory'),
        4
    );
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

// List view.
$perpage = 25;
['configs' => $configs, 'total' => $total] = course_config_service::get_all_configs($page, $perpage);

echo $OUTPUT->header();
echo $OUTPUT->render(new course_exam_config_list($configs, $total, $page, $perpage));
echo $OUTPUT->footer();
