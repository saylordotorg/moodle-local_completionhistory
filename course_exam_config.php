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
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$dir = dirname(dirname(dirname($_SERVER['SCRIPT_FILENAME'] ?? __DIR__)));
require($dir . '/config.php');
require_login();

use local_completionhistory\local\course_config_service;

$systemcontext = context_system::instance();
require_capability('local/completionhistory:manage', $systemcontext);

if (!get_config('local_completionhistory', 'enabled')) {
    throw new moodle_exception('plugindisabled', 'local_completionhistory');
}

$action   = optional_param('action', 'list', PARAM_ALPHA);
$courseid = optional_param('courseid', 0, PARAM_INT);
$page     = optional_param('page', 0, PARAM_INT);

$PAGE->set_context($systemcontext);
$PAGE->set_url(new moodle_url('/local/completionhistory/course_exam_config.php'));
$PAGE->set_title(get_string('courseexamconfig', 'local_completionhistory'));
$PAGE->set_heading(get_string('courseexamconfig', 'local_completionhistory'));
$PAGE->set_pagelayout('admin');

// ── Handle form save ─────────────────────────────────────────────────────────
if ($action === 'save' && confirm_sesskey()) {
    $cid = required_param('courseid', PARAM_INT);

    $config                           = new stdClass();
    $config->courseid                 = $cid;
    $config->course_type              = required_param('course_type', PARAM_ALPHA);
    $config->program_final_quizid     = optional_param('program_final_quizid', null, PARAM_INT) ?: null;
    $config->dc_quizid                = optional_param('dc_quizid', null, PARAM_INT) ?: null;
    $config->cert_quizid              = optional_param('cert_quizid', null, PARAM_INT) ?: null;
    $config->program_attempts_allowed = optional_param('program_attempts_allowed', 3, PARAM_INT);
    $config->dc_attempts_allowed      = optional_param('dc_attempts_allowed', 3, PARAM_INT);
    $config->cert_attempts_allowed    = optional_param('cert_attempts_allowed', 0, PARAM_INT);
    $config->notes                    = optional_param('notes', '', PARAM_TEXT);

    course_config_service::save_config($config);

    redirect(
        new moodle_url('/local/completionhistory/course_exam_config.php'),
        get_string('examconfigsaved', 'local_completionhistory'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// ── Handle delete ─────────────────────────────────────────────────────────────
if ($action === 'delete' && $courseid && confirm_sesskey()) {
    course_config_service::delete_config($courseid);
    redirect(
        new moodle_url('/local/completionhistory/course_exam_config.php'),
        get_string('examconfigdeleted', 'local_completionhistory'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();

// ── Edit / Add form ───────────────────────────────────────────────────────────
if ($action === 'edit') {
    $config  = course_config_service::get_config($courseid ?: 0);
    $course  = $courseid ? $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname') : null;

    echo html_writer::tag('h4', $courseid
        ? get_string('examconfig_edit', 'local_completionhistory') . ': ' . format_string($course->fullname ?? "Course {$courseid}")
        : get_string('examconfig_add', 'local_completionhistory')
    );

    // Fetch all quizzes for the selected course (for quiz dropdowns).
    $quizzes = [];
    if ($courseid) {
        $quizzes = $DB->get_records('quiz', ['course' => $courseid], 'name', 'id, name');
    }
    $quiz_options = ['' => get_string('examconfig_noquiz', 'local_completionhistory')];
    foreach ($quizzes as $q) {
        $quiz_options[$q->id] = format_string($q->name);
    }

    $type_options = course_config_service::type_labels();

    $formurl = new moodle_url('/local/completionhistory/course_exam_config.php', ['action' => 'save', 'sesskey' => sesskey()]);
    $listurl = new moodle_url('/local/completionhistory/course_exam_config.php');

    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $formurl->out(false), 'id' => 'exam-config-form']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

    echo html_writer::start_div('card mb-4');
    echo html_writer::start_div('card-body');

    // Course selector (if no courseid yet, show a text input; otherwise locked).
    echo html_writer::start_div('form-group row');
    echo html_writer::tag('label', get_string('course'), ['class' => 'col-sm-3 col-form-label font-weight-bold', 'for' => 'courseid_input']);
    echo html_writer::start_div('col-sm-9');
    if ($courseid) {
        echo html_writer::tag('p', format_string($course->fullname) . ' (' . $course->shortname . ')', ['class' => 'form-control-plaintext']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);
    } else {
        echo html_writer::empty_tag('input', [
            'type' => 'number', 'name' => 'courseid', 'id' => 'courseid_input',
            'class' => 'form-control', 'placeholder' => 'Enter course ID',
            'required' => 'required',
        ]);
        echo html_writer::tag('small', get_string('examconfig_courseid_help', 'local_completionhistory'), ['class' => 'form-text text-muted']);
    }
    echo html_writer::end_div();
    echo html_writer::end_div();

    // Course type.
    echo html_writer::start_div('form-group row');
    echo html_writer::tag('label', get_string('examconfig_type', 'local_completionhistory'), ['class' => 'col-sm-3 col-form-label font-weight-bold', 'for' => 'course_type']);
    echo html_writer::start_div('col-sm-9');
    echo html_writer::select($type_options, 'course_type', $config->course_type, false,
        ['id' => 'course_type', 'class' => 'form-control']);
    echo html_writer::end_div();
    echo html_writer::end_div();

    // ── Program Final section ─────────────────────────────────────────────
    echo html_writer::start_div('lch-section', ['id' => 'section-program', 'style' => 'display:none']);
    echo html_writer::tag('hr', '');
    echo html_writer::tag('h6', get_string('track_program_final', 'local_completionhistory'), ['class' => 'font-weight-bold text-primary']);

    echo html_writer::start_div('form-group row');
    echo html_writer::tag('label', get_string('examconfig_quiz', 'local_completionhistory'), ['class' => 'col-sm-3 col-form-label', 'for' => 'program_final_quizid']);
    echo html_writer::start_div('col-sm-4');
    echo html_writer::select($quiz_options, 'program_final_quizid', $config->program_final_quizid ?? '', false,
        ['id' => 'program_final_quizid', 'class' => 'form-control']);
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::start_div('form-group row');
    echo html_writer::tag('label', get_string('examconfig_attempts', 'local_completionhistory'), ['class' => 'col-sm-3 col-form-label', 'for' => 'program_attempts_allowed']);
    echo html_writer::start_div('col-sm-2');
    echo html_writer::empty_tag('input', [
        'type' => 'number', 'name' => 'program_attempts_allowed', 'id' => 'program_attempts_allowed',
        'value' => (int) $config->program_attempts_allowed, 'min' => '1', 'max' => '10',
        'class' => 'form-control',
    ]);
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::end_div(); // section-program

    // ── Direct Credit section ─────────────────────────────────────────────
    echo html_writer::start_div('lch-section', ['id' => 'section-dc', 'style' => 'display:none']);
    echo html_writer::tag('hr', '');
    echo html_writer::tag('h6', get_string('track_direct_credit', 'local_completionhistory'), ['class' => 'font-weight-bold text-info']);

    echo html_writer::start_div('form-group row');
    echo html_writer::tag('label', get_string('examconfig_quiz', 'local_completionhistory'), ['class' => 'col-sm-3 col-form-label', 'for' => 'dc_quizid']);
    echo html_writer::start_div('col-sm-4');
    echo html_writer::select($quiz_options, 'dc_quizid', $config->dc_quizid ?? '', false,
        ['id' => 'dc_quizid', 'class' => 'form-control']);
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::start_div('form-group row');
    echo html_writer::tag('label', get_string('examconfig_attempts', 'local_completionhistory'), ['class' => 'col-sm-3 col-form-label', 'for' => 'dc_attempts_allowed']);
    echo html_writer::start_div('col-sm-2');
    echo html_writer::empty_tag('input', [
        'type' => 'number', 'name' => 'dc_attempts_allowed', 'id' => 'dc_attempts_allowed',
        'value' => (int) $config->dc_attempts_allowed, 'min' => '1', 'max' => '10',
        'class' => 'form-control',
    ]);
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::end_div(); // section-dc

    // ── Certificate section ───────────────────────────────────────────────
    echo html_writer::start_div('lch-section', ['id' => 'section-cert', 'style' => 'display:none']);
    echo html_writer::tag('hr', '');
    echo html_writer::tag('h6', get_string('track_certificate', 'local_completionhistory'), ['class' => 'font-weight-bold text-success']);

    echo html_writer::start_div('form-group row');
    echo html_writer::tag('label', get_string('examconfig_quiz', 'local_completionhistory'), ['class' => 'col-sm-3 col-form-label', 'for' => 'cert_quizid']);
    echo html_writer::start_div('col-sm-4');
    echo html_writer::select($quiz_options, 'cert_quizid', $config->cert_quizid ?? '', false,
        ['id' => 'cert_quizid', 'class' => 'form-control']);
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::start_div('form-group row');
    echo html_writer::tag('label', get_string('examconfig_cert_attempts', 'local_completionhistory'), ['class' => 'col-sm-3 col-form-label', 'for' => 'cert_attempts_allowed']);
    echo html_writer::start_div('col-sm-2');
    echo html_writer::empty_tag('input', [
        'type' => 'number', 'name' => 'cert_attempts_allowed', 'id' => 'cert_attempts_allowed',
        'value' => (int) $config->cert_attempts_allowed, 'min' => '0', 'max' => '99',
        'class' => 'form-control',
    ]);
    echo html_writer::tag('small', get_string('examconfig_cert_attempts_help', 'local_completionhistory'), ['class' => 'form-text text-muted']);
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::end_div(); // section-cert

    // ── Notes ─────────────────────────────────────────────────────────────
    echo html_writer::tag('hr', '');
    echo html_writer::start_div('form-group row');
    echo html_writer::tag('label', get_string('examconfig_notes', 'local_completionhistory'), ['class' => 'col-sm-3 col-form-label', 'for' => 'notes']);
    echo html_writer::start_div('col-sm-9');
    echo html_writer::tag('textarea', s($config->notes ?? ''), [
        'name' => 'notes', 'id' => 'notes', 'class' => 'form-control', 'rows' => '3',
    ]);
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::end_div(); // card-body
    echo html_writer::end_div(); // card

    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('savechanges'), 'class' => 'btn btn-primary mr-2']);
    echo html_writer::tag('a', get_string('cancel'), ['href' => $listurl->out(false), 'class' => 'btn btn-secondary']);
    echo html_writer::end_tag('form');

    // JS to show/hide sections based on course type.
    $type_sections = [
        course_config_service::TYPE_STANDARD  => [],
        course_config_service::TYPE_PROGRAM   => ['section-program'],
        course_config_service::TYPE_OPEN_DUAL => ['section-dc', 'section-cert'],
        course_config_service::TYPE_OPEN_CERT => ['section-cert'],
    ];
    echo html_writer::script('
(function() {
    var sectionMap = ' . json_encode($type_sections) . ';
    var sel = document.getElementById("course_type");
    function applyType() {
        document.querySelectorAll(".lch-section").forEach(function(el) { el.style.display = "none"; });
        var show = sectionMap[sel.value] || [];
        show.forEach(function(id) {
            var el = document.getElementById(id);
            if (el) el.style.display = "";
        });
    }
    if (sel) { sel.addEventListener("change", applyType); applyType(); }
})();
');

    echo $OUTPUT->footer();
    exit;
}

// ── List view ─────────────────────────────────────────────────────────────────
$perpage = 25;
['configs' => $configs, 'total' => $total] = course_config_service::get_all_configs($page, $perpage);

$addurl  = new moodle_url('/local/completionhistory/course_exam_config.php', ['action' => 'edit', 'courseid' => 0]);
echo html_writer::tag('a', '+ ' . get_string('examconfig_add', 'local_completionhistory'),
    ['href' => $addurl->out(false), 'class' => 'btn btn-primary mb-3']);

if (empty($configs)) {
    echo $OUTPUT->notification(get_string('examconfig_none', 'local_completionhistory'), 'info');
} else {
    $type_labels = course_config_service::type_labels();

    echo html_writer::start_tag('table', ['class' => 'table table-hover table-sm generaltable']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    foreach (['Course', 'Short Name', 'Type', 'Program Final Quiz', 'DC Quiz', 'Cert Quiz', 'Attempts (P/DC/Cert)', 'Actions'] as $h) {
        echo html_writer::tag('th', $h);
    }
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    foreach ($configs as $cfg) {
        $course = $DB->get_record('course', ['id' => $cfg->courseid], 'id, fullname, shortname');

        $editurl   = new moodle_url('/local/completionhistory/course_exam_config.php',
            ['action' => 'edit',   'courseid' => $cfg->courseid]);
        $deleteurl = new moodle_url('/local/completionhistory/course_exam_config.php',
            ['action' => 'delete', 'courseid' => $cfg->courseid, 'sesskey' => sesskey()]);

        $quiz_name = function(?int $qid) use ($DB): string {
            if (!$qid) return '—';
            $q = $DB->get_record('quiz', ['id' => $qid], 'id, name');
            return $q ? format_string($q->name) : "Quiz #{$qid}";
        };

        $cert_att = (int) $cfg->cert_attempts_allowed === 0 ? '∞' : $cfg->cert_attempts_allowed;
        $attempts_summary = "{$cfg->program_attempts_allowed} / {$cfg->dc_attempts_allowed} / {$cert_att}";

        $type_badge_map = [
            course_config_service::TYPE_STANDARD  => 'secondary',
            course_config_service::TYPE_PROGRAM    => 'primary',
            course_config_service::TYPE_OPEN_DUAL  => 'info',
            course_config_service::TYPE_OPEN_CERT  => 'success',
        ];
        $badge_cls = $type_badge_map[$cfg->course_type] ?? 'secondary';
        $type_html = html_writer::tag('span',
            $type_labels[$cfg->course_type] ?? $cfg->course_type,
            ['class' => "badge badge-{$badge_cls}"]
        );

        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', $course ? format_string($course->fullname) : "Course #{$cfg->courseid}");
        echo html_writer::tag('td', $course ? s($course->shortname) : '—');
        echo html_writer::tag('td', $type_html);
        echo html_writer::tag('td', $quiz_name($cfg->program_final_quizid));
        echo html_writer::tag('td', $quiz_name($cfg->dc_quizid));
        echo html_writer::tag('td', $quiz_name($cfg->cert_quizid));
        echo html_writer::tag('td', $attempts_summary);
        echo html_writer::tag('td',
            html_writer::link($editurl->out(false), get_string('edit'), ['class' => 'btn btn-sm btn-outline-primary mr-1']) .
            html_writer::link($deleteurl->out(false), get_string('delete'),
                ['class' => 'btn btn-sm btn-outline-danger',
                 'onclick' => "return confirm('" . get_string('examconfig_confirmdelete', 'local_completionhistory') . "')"])
        );
        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');

    echo $OUTPUT->paging_bar($total, $page, $perpage,
        new moodle_url('/local/completionhistory/course_exam_config.php'));
}

echo $OUTPUT->footer();
