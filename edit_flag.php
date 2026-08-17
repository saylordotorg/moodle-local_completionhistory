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
 * Add / edit a single system flag.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_login();

use local_completionhistory\local\flag_service;

$systemcontext = context_system::instance();
require_capability('local/completionhistory:manage', $systemcontext);

if (!get_config('local_completionhistory', 'enabled')) {
    throw new moodle_exception('plugindisabled', 'local_completionhistory');
}

$id      = optional_param('id', 0, PARAM_INT);
$listurl = new moodle_url('/local/completionhistory/manage_flags.php');

$PAGE->set_context($systemcontext);
$PAGE->set_url(new moodle_url('/local/completionhistory/edit_flag.php', ['id' => $id]));
$PAGE->set_title(get_string($id ? 'editflag' : 'addflag', 'local_completionhistory'));
$PAGE->set_heading(get_string($id ? 'editflag' : 'addflag', 'local_completionhistory'));
$PAGE->set_pagelayout('admin');

global $DB;

// Load existing or build blank.
if ($id) {
    $flag = $DB->get_record('local_completionhistory_flag_def', ['id' => $id], '*', MUST_EXIST);
    $config = json_decode($flag->configjson ?? '', true) ?: [];
} else {
    $flag = new stdClass();
    $flag->id          = 0;
    $flag->code        = '';
    $flag->name        = '';
    $flag->description = '';
    $flag->flag_type   = flag_service::TYPE_FAST_COMPLETION;
    $flag->severity    = flag_service::SEVERITY_WARNING;
    $flag->enabled     = 1;
    $config            = [];
}

// Handle save.
$errors = [];
if (data_submitted()) {
    require_sesskey();
    $flag->code        = trim(required_param('code', PARAM_ALPHANUMEXT));
    $flag->name        = trim(required_param('name', PARAM_TEXT));
    $flag->description = trim(optional_param('description', '', PARAM_TEXT));
    $flag->flag_type   = required_param('flag_type', PARAM_ALPHANUMEXT);
    $flag->severity    = required_param('severity', PARAM_ALPHA);
    $flag->enabled     = optional_param('enabled', 0, PARAM_INT) ? 1 : 0;

    if (!array_key_exists($flag->flag_type, flag_service::type_labels())) {
        $errors[] = get_string('flag_invalid_type', 'local_completionhistory');
    }
    if (!array_key_exists($flag->severity, flag_service::severity_labels())) {
        $errors[] = get_string('flag_invalid_severity', 'local_completionhistory');
    }
    // Uniqueness on code (excluding self).
    $existing = $DB->get_record('local_completionhistory_flag_def', ['code' => $flag->code]);
    if ($existing && (int) $existing->id !== (int) $flag->id) {
        $errors[] = get_string('flag_code_taken', 'local_completionhistory');
    }

    // Build type-specific config.
    $newconfig = [];
    if ($flag->flag_type === flag_service::TYPE_FAST_COMPLETION) {
        $mins = (int) optional_param('threshold_minutes', 0, PARAM_INT);
        if ($mins <= 0) {
            $errors[] = get_string('flag_threshold_required', 'local_completionhistory');
        }
        $newconfig['threshold_minutes'] = $mins;
    } else if ($flag->flag_type === flag_service::TYPE_DURATION_EXACT) {
        $mins = (int) optional_param('duration_minutes', 0, PARAM_INT);
        $tol  = (int) optional_param('tolerance_seconds', 10, PARAM_INT);
        if ($mins <= 0) {
            $errors[] = get_string('flag_duration_required', 'local_completionhistory');
        }
        if ($tol < 0) {
            $tol = 0;
        }
        $newconfig['duration_minutes']  = $mins;
        $newconfig['tolerance_seconds'] = $tol;
    } else if ($flag->flag_type === flag_service::TYPE_SCORE_RANGE) {
        $min = (int) optional_param('score_min', 0, PARAM_INT);
        $max = (int) optional_param('score_max', 100, PARAM_INT);
        if ($min < 0 || $max > 100 || $min > $max) {
            $errors[] = get_string('flag_score_range_invalid', 'local_completionhistory');
        }
        $newconfig['score_min'] = $min;
        $newconfig['score_max'] = $max;
    } else if ($flag->flag_type === flag_service::TYPE_DUPLICATE_ACCOUNT) {
        $newconfig['same_email_domain'] = optional_param('same_email_domain', 0, PARAM_INT) ? true : false;
    } else if ($flag->flag_type === flag_service::TYPE_NEW_ACCOUNT) {
        $days = (int) optional_param('max_days_before', 0, PARAM_INT);
        if ($days <= 0) {
            $errors[] = get_string('flag_maxdays_required', 'local_completionhistory');
        }
        $newconfig['max_days_before'] = $days;
    }
    $flag->configjson = json_encode($newconfig);
    $config           = $newconfig;

    if (empty($errors)) {
        $savedid = flag_service::save($flag);
        redirect($listurl,
            get_string('flagsaved', 'local_completionhistory'),
            null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

echo $OUTPUT->header();

if (!empty($errors)) {
    foreach ($errors as $e) {
        echo $OUTPUT->notification($e, \core\output\notification::NOTIFY_ERROR);
    }
}

$typelabels = flag_service::type_labels();
$sevlabels  = flag_service::severity_labels();

echo html_writer::start_tag('form', [
    'method' => 'post', 'action' => $PAGE->url->out(false), 'class' => 'mform',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

// Name.
echo html_writer::start_div('form-group');
echo html_writer::label(get_string('flag_name', 'local_completionhistory'), 'name',
    true, ['class' => 'font-weight-bold']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'name', 'id' => 'name',
    'value' => s($flag->name), 'class' => 'form-control', 'required' => 'required',
    'maxlength' => 100,
]);
echo html_writer::end_div();

// Code.
echo html_writer::start_div('form-group');
echo html_writer::label(get_string('flag_code', 'local_completionhistory'), 'code',
    true, ['class' => 'font-weight-bold']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'code', 'id' => 'code',
    'value' => s($flag->code), 'class' => 'form-control', 'required' => 'required',
    'maxlength' => 50, 'pattern' => '[A-Za-z0-9_\-]+',
]);
echo html_writer::tag('small', get_string('flag_code_help', 'local_completionhistory'),
    ['class' => 'form-text text-muted']);
echo html_writer::end_div();

// Description.
echo html_writer::start_div('form-group');
echo html_writer::label(get_string('flag_description', 'local_completionhistory'), 'description',
    true, ['class' => 'font-weight-bold']);
echo html_writer::tag('textarea', s($flag->description), [
    'name' => 'description', 'id' => 'description',
    'class' => 'form-control', 'rows' => '2',
]);
echo html_writer::end_div();

// Type.
echo html_writer::start_div('form-group');
echo html_writer::label(get_string('flag_type', 'local_completionhistory'), 'flag_type',
    true, ['class' => 'font-weight-bold']);
echo html_writer::select($typelabels, 'flag_type', $flag->flag_type, false, [
    'id' => 'flag_type', 'class' => 'form-control',
]);
echo html_writer::end_div();

// Severity.
echo html_writer::start_div('form-group');
echo html_writer::label(get_string('flag_severity', 'local_completionhistory'), 'severity',
    true, ['class' => 'font-weight-bold']);
echo html_writer::select($sevlabels, 'severity', $flag->severity, false, [
    'id' => 'severity', 'class' => 'form-control',
]);
echo html_writer::end_div();

// Enabled.
echo html_writer::start_div('form-check mb-3');
$enattrs = [
    'type' => 'checkbox', 'name' => 'enabled', 'id' => 'enabled',
    'value' => '1', 'class' => 'form-check-input',
];
if ($flag->enabled) $enattrs['checked'] = 'checked';
echo html_writer::empty_tag('input', $enattrs);
echo html_writer::label(get_string('flag_enabled', 'local_completionhistory'), 'enabled',
    true, ['class' => 'form-check-label']);
echo html_writer::end_div();

// Type-specific config blocks (show/hide via JS based on flag_type).
echo html_writer::tag('h4', get_string('flag_config', 'local_completionhistory'));

// fast_completion config.
echo html_writer::start_div('card mb-3', [
    'id' => 'config-fast_completion',
    'style' => $flag->flag_type === flag_service::TYPE_FAST_COMPLETION ? '' : 'display:none;',
]);
echo html_writer::start_div('card-body');
echo html_writer::start_div('form-group');
echo html_writer::label(
    get_string('flag_threshold_minutes', 'local_completionhistory'),
    'threshold_minutes', true, ['class' => 'font-weight-bold']);
echo html_writer::empty_tag('input', [
    'type' => 'number', 'name' => 'threshold_minutes', 'id' => 'threshold_minutes',
    'value' => (int) ($config['threshold_minutes'] ?? 20),
    'class' => 'form-control', 'min' => '1', 'max' => '600',
]);
echo html_writer::tag('small', get_string('flag_threshold_minutes_help', 'local_completionhistory'),
    ['class' => 'form-text text-muted']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

// duration_exact config.
echo html_writer::start_div('card mb-3', [
    'id' => 'config-duration_exact',
    'style' => $flag->flag_type === flag_service::TYPE_DURATION_EXACT ? '' : 'display:none;',
]);
echo html_writer::start_div('card-body');
echo html_writer::start_div('form-group');
echo html_writer::label(
    get_string('flag_duration_minutes', 'local_completionhistory'),
    'duration_minutes', true, ['class' => 'font-weight-bold']);
echo html_writer::empty_tag('input', [
    'type' => 'number', 'name' => 'duration_minutes', 'id' => 'duration_minutes',
    'value' => (int) ($config['duration_minutes'] ?? 120),
    'class' => 'form-control', 'min' => '1', 'max' => '1440',
]);
echo html_writer::end_div();
echo html_writer::start_div('form-group');
echo html_writer::label(
    get_string('flag_tolerance_seconds', 'local_completionhistory'),
    'tolerance_seconds', true, ['class' => 'font-weight-bold']);
echo html_writer::empty_tag('input', [
    'type' => 'number', 'name' => 'tolerance_seconds', 'id' => 'tolerance_seconds',
    'value' => (int) ($config['tolerance_seconds'] ?? 10),
    'class' => 'form-control', 'min' => '0', 'max' => '3600',
]);
echo html_writer::tag('small', get_string('flag_tolerance_seconds_help', 'local_completionhistory'),
    ['class' => 'form-text text-muted']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

// score_range config.
echo html_writer::start_div('card mb-3', [
    'id' => 'config-score_range',
    'style' => $flag->flag_type === flag_service::TYPE_SCORE_RANGE ? '' : 'display:none;',
]);
echo html_writer::start_div('card-body');
echo html_writer::start_div('form-row');
echo html_writer::start_div('form-group col-md-6');
echo html_writer::label(
    get_string('flag_score_min', 'local_completionhistory'),
    'score_min', true, ['class' => 'font-weight-bold']);
echo html_writer::empty_tag('input', [
    'type' => 'number', 'name' => 'score_min', 'id' => 'score_min',
    'value' => (int) ($config['score_min'] ?? 0),
    'class' => 'form-control', 'min' => '0', 'max' => '100',
]);
echo html_writer::end_div();
echo html_writer::start_div('form-group col-md-6');
echo html_writer::label(
    get_string('flag_score_max', 'local_completionhistory'),
    'score_max', true, ['class' => 'font-weight-bold']);
echo html_writer::empty_tag('input', [
    'type' => 'number', 'name' => 'score_max', 'id' => 'score_max',
    'value' => (int) ($config['score_max'] ?? 100),
    'class' => 'form-control', 'min' => '0', 'max' => '100',
]);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::tag('small', get_string('flag_score_range_help', 'local_completionhistory'),
    ['class' => 'form-text text-muted']);
echo html_writer::end_div();
echo html_writer::end_div();

// new_account config.
echo html_writer::start_div('card mb-3', [
    'id' => 'config-new_account',
    'style' => $flag->flag_type === flag_service::TYPE_NEW_ACCOUNT ? '' : 'display:none;',
]);
echo html_writer::start_div('card-body');
echo html_writer::start_div('form-group');
echo html_writer::label(
    get_string('flag_max_days_before', 'local_completionhistory'),
    'max_days_before', true, ['class' => 'font-weight-bold']);
echo html_writer::empty_tag('input', [
    'type' => 'number', 'name' => 'max_days_before', 'id' => 'max_days_before',
    'value' => (int) ($config['max_days_before'] ?? 2),
    'class' => 'form-control', 'min' => '1', 'max' => '3650',
]);
echo html_writer::tag('small', get_string('flag_max_days_before_help', 'local_completionhistory'),
    ['class' => 'form-text text-muted']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

// duplicate_account config.
echo html_writer::start_div('card mb-3', [
    'id' => 'config-duplicate_account',
    'style' => $flag->flag_type === flag_service::TYPE_DUPLICATE_ACCOUNT ? '' : 'display:none;',
]);
echo html_writer::start_div('card-body');
echo html_writer::start_div('form-check');
$domattrs = [
    'type' => 'checkbox', 'name' => 'same_email_domain', 'id' => 'same_email_domain',
    'value' => '1', 'class' => 'form-check-input',
];
if (!empty($config['same_email_domain'])) $domattrs['checked'] = 'checked';
echo html_writer::empty_tag('input', $domattrs);
echo html_writer::label(
    get_string('flag_same_email_domain', 'local_completionhistory'),
    'same_email_domain', true, ['class' => 'form-check-label']);
echo html_writer::end_div();
echo html_writer::tag('small', get_string('flag_duplicate_account_help', 'local_completionhistory'),
    ['class' => 'form-text text-muted']);
echo html_writer::end_div();
echo html_writer::end_div();

// Buttons.
echo html_writer::empty_tag('input', [
    'type' => 'submit', 'value' => get_string('save'),
    'class' => 'btn btn-primary mr-2',
]);
echo html_writer::tag('a', get_string('cancel'), [
    'href' => $listurl->out(false), 'class' => 'btn btn-secondary',
]);

echo html_writer::end_tag('form');

// Toggle config blocks when type changes.
echo html_writer::script('
(function () {
    var sel = document.getElementById("flag_type");
    if (!sel) return;
    var ids = [
        "fast_completion",
        "duration_exact",
        "score_range",
        "duplicate_account",
        "new_account"
    ];
    sel.addEventListener("change", function () {
        var v = sel.value;
        ids.forEach(function (id) {
            var el = document.getElementById("config-" + id);
            if (el) el.style.display = (v === id) ? "" : "none";
        });
    });
})();
');

echo $OUTPUT->footer();
