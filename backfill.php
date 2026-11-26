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
 * Backfills admin page: the CLI backfills and the grade refresh, run through cron.
 *
 * For a site with no command line. Preview queues a dry run straight away; Run asks for
 * confirmation first. The work itself is backfill_runner's, done by the run_backfill ad hoc task.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_login();

use local_completionhistory\local\backfill_runner;

$systemcontext = context_system::instance();
require_capability('local/completionhistory:runbackfill', $systemcontext);

if (!get_config('local_completionhistory', 'enabled')) {
    throw new moodle_exception('plugindisabled', 'local_completionhistory');
}

$runid   = optional_param('id', 0, PARAM_INT);
$action  = optional_param('action', '', PARAM_ALPHA);
$pageurl = new moodle_url('/local/completionhistory/backfill.php');
$plugin  = 'local_completionhistory';

$PAGE->set_context($systemcontext);
$PAGE->set_url($runid ? new moodle_url($pageurl, ['id' => $runid]) : $pageurl);
$PAGE->set_title(get_string('backfills', $plugin));
$PAGE->set_heading(get_string('backfills', $plugin));
$PAGE->set_pagelayout('admin');

// Queue a run: a sesskey-protected POST. A real run is confirmed first; a preview is not.
if ($action === 'queue') {
    if (!data_submitted()) {
        throw new moodle_exception('invalidrequest');
    }
    require_sesskey();
    $kind    = required_param('kind', PARAM_ALPHA);
    $course  = optional_param('course', '', PARAM_RAW_TRIMMED);
    $commit  = optional_param('mode', 'preview', PARAM_ALPHA) === 'run';
    $confirm = optional_param('confirm', 0, PARAM_BOOL);
    if (!in_array($kind, backfill_runner::KINDS, true)) {
        throw new moodle_exception('backfillunknownkind', $plugin, '', s($kind));
    }
    try {
        $courseid = backfill_runner::resolve_course($course);
    } catch (moodle_exception $e) {
        redirect($pageurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }

    if ($commit && !$confirm) {
        $label = get_string('backfillkind_' . $kind, $plugin) . ($course !== '' ? ' — ' . $course : '');
        $continue = new single_button(
            new moodle_url($pageurl, [
                'action' => 'queue',
                'kind' => $kind,
                'course' => $course,
                'mode' => 'run',
                'confirm' => 1,
                'sesskey' => sesskey(),
            ]),
            get_string('backfillrun', $plugin),
            'post',
            single_button::BUTTON_PRIMARY
        );
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(get_string('backfillconfirm', $plugin, $label), $continue, $pageurl);
        echo $OUTPUT->footer();
        exit;
    }

    [$run, $existing] = backfill_runner::request($kind, !$commit, $courseid, 'web', (int) $USER->id);
    redirect(
        new moodle_url($pageurl, ['id' => $run->id]),
        $existing
            ? get_string('backfillalready', $plugin, get_string('backfillkind_' . $kind, $plugin))
            : get_string('backfillqueued', $plugin),
        null,
        $existing ? \core\output\notification::NOTIFY_WARNING : \core\output\notification::NOTIFY_SUCCESS
    );
}

/**
 * A run's counts as "name: n" pairs.
 *
 * @param stdClass $run The run row.
 * @return string
 */
function local_completionhistory_backfill_summary(stdClass $run): string {
    if (empty($run->summaryjson)) {
        return '';
    }
    $parts = [];
    foreach ((array) json_decode($run->summaryjson, true) as $name => $value) {
        if (is_bool($value)) {
            continue;
        }
        $parts[] = s($name) . ': ' . s((string) $value);
    }
    return implode(', ', $parts);
}

/**
 * Who asked for a run.
 *
 * @param stdClass $run The run row.
 * @return string
 */
function local_completionhistory_backfill_requester(stdClass $run): string {
    global $DB;
    $user = $run->requestedby ? $DB->get_record('user', ['id' => $run->requestedby]) : null;
    $name = $user ? fullname($user) : '-';
    if ($run->source === 'sis') {
        $name .= ' (' . get_string('backfillsource_sis', 'local_completionhistory') . ')';
    }
    return s($name);
}

/**
 * A run's course, by ID number or short name.
 *
 * @param stdClass $run The run row.
 * @return string
 */
function local_completionhistory_backfill_course(stdClass $run): string {
    global $DB;
    if (!$run->courseid) {
        return get_string('all');
    }
    $course = $DB->get_record('course', ['id' => $run->courseid], 'idnumber, shortname');
    if (!$course) {
        return (string) (int) $run->courseid;
    }
    return s($course->idnumber !== '' ? $course->idnumber : $course->shortname);
}

$statusbadge = static function (string $status) use ($plugin): string {
    $classes = ['queued' => 'secondary', 'running' => 'info', 'done' => 'success', 'failed' => 'danger'];
    return html_writer::span(
        get_string('backfillstatus_' . $status, $plugin),
        'badge bg-' . ($classes[$status] ?? 'secondary')
    );
};
$datetime = static function ($time): string {
    return $time ? userdate((int) $time, get_string('strftimedatetimeshort', 'langconfig')) : '-';
};

echo $OUTPUT->header();

// One run in detail.
if ($runid) {
    $run = $DB->get_record(backfill_runner::TABLE, ['id' => $runid], '*', MUST_EXIST);
    // Settle a run whose task has gone, so it does not read as queued for ever.
    backfill_runner::active_run($run->kind);
    $run = $DB->get_record(backfill_runner::TABLE, ['id' => $runid], '*', MUST_EXIST);

    echo $OUTPUT->heading(get_string('backfillkind_' . $run->kind, $plugin) . ' #' . (int) $run->id, 3);
    $table = new html_table();
    $table->attributes['class'] = 'generaltable';
    $table->data = [
        [get_string('backfillmode', $plugin), get_string('backfillmode_' . ($run->dryrun ? 'preview' : 'run'), $plugin)],
        [get_string('backfillcourse', $plugin), local_completionhistory_backfill_course($run)],
        [get_string('backfillstatus', $plugin), $statusbadge($run->status)],
        [get_string('backfillrequestedby', $plugin), local_completionhistory_backfill_requester($run)],
        [get_string('backfillrequested', $plugin), $datetime($run->timecreated)],
        [get_string('backfillfinished', $plugin), $datetime($run->timefinished)],
        [get_string('backfillresult', $plugin), local_completionhistory_backfill_summary($run)],
    ];
    echo html_writer::table($table);

    $summary = $run->summaryjson ? (array) json_decode($run->summaryjson, true) : [];
    if (!empty($summary['sisrescanneeded'])) {
        echo $OUTPUT->notification(get_string('backfillsisrescan', $plugin), \core\output\notification::NOTIFY_INFO);
    }
    if ((string) $run->log !== '') {
        echo html_writer::tag('pre', s($run->log), ['class' => 'border p-2 bg-light', 'style' => 'max-height: 30em']);
    }
    echo html_writer::div(
        $OUTPUT->single_button(new moodle_url($pageurl, ['id' => $run->id]), get_string('refresh'), 'get')
        . ' ' . $OUTPUT->single_button($pageurl, get_string('back'), 'get'),
        'mt-3'
    );
    echo $OUTPUT->footer();
    exit;
}

// The request form.
echo html_writer::tag('p', get_string('backfillintro', $plugin));

$form = html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(false)]);
$form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
$form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'queue']);
$form .= html_writer::tag('legend', get_string('backfilljob', $plugin), ['class' => 'h5']);
foreach (backfill_runner::KINDS as $i => $kind) {
    $id = 'backfillkind_' . $kind;
    $radio = html_writer::empty_tag('input', [
        'type' => 'radio',
        'name' => 'kind',
        'value' => $kind,
        'id' => $id,
        'class' => 'form-check-input',
    ] + ($i === 0 ? ['checked' => 'checked'] : []));
    $label = html_writer::label(
        html_writer::tag('strong', get_string('backfillkind_' . $kind, $plugin))
        . html_writer::div(get_string('backfillkind_' . $kind . '_desc', $plugin), 'text-muted small'),
        $id,
        false,
        ['class' => 'form-check-label']
    );
    $form .= html_writer::div($radio . $label, 'form-check mb-2');
}
$form .= html_writer::div(
    html_writer::label(get_string('backfillcourse', $plugin), 'backfillcourse', false, ['class' => 'form-label'])
    . html_writer::empty_tag('input', [
        'type' => 'text',
        'name' => 'course',
        'id' => 'backfillcourse',
        'class' => 'form-control',
        'style' => 'max-width: 20em',
    ])
    . html_writer::div(get_string('backfillcourse_desc', $plugin), 'form-text'),
    'mb-3 mt-3'
);
$form .= html_writer::tag('button', get_string('backfillpreview', $plugin), [
    'type' => 'submit',
    'name' => 'mode',
    'value' => 'preview',
    'class' => 'btn btn-secondary me-2',
]);
$form .= html_writer::tag('button', get_string('backfillrun', $plugin), [
    'type' => 'submit',
    'name' => 'mode',
    'value' => 'run',
    'class' => 'btn btn-primary',
]);
$form .= html_writer::end_tag('form');
echo html_writer::div($form, 'mb-4');

// Recent runs.
echo $OUTPUT->heading(get_string('backfillruns', $plugin), 3);
$runs = $DB->get_records(backfill_runner::TABLE, null, 'id DESC', '*', 0, 25);
if (!$runs) {
    echo html_writer::tag('p', get_string('backfillnoruns', $plugin));
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable';
    $table->head = [
        '#',
        get_string('backfilljob', $plugin),
        get_string('backfillmode', $plugin),
        get_string('backfillcourse', $plugin),
        get_string('backfillstatus', $plugin),
        get_string('backfillrequestedby', $plugin),
        get_string('backfillrequested', $plugin),
        get_string('backfillfinished', $plugin),
        get_string('backfillresult', $plugin),
    ];
    foreach ($runs as $run) {
        $table->data[] = [
            html_writer::link(new moodle_url($pageurl, ['id' => $run->id]), (int) $run->id),
            get_string('backfillkind_' . $run->kind, $plugin),
            get_string('backfillmode_' . ($run->dryrun ? 'preview' : 'run'), $plugin),
            local_completionhistory_backfill_course($run),
            $statusbadge($run->status),
            local_completionhistory_backfill_requester($run),
            $datetime($run->timecreated),
            $datetime($run->timefinished),
            local_completionhistory_backfill_summary($run),
        ];
    }
    echo html_writer::table($table);
    echo $OUTPUT->single_button($pageurl, get_string('refresh'), 'get');
}

echo $OUTPUT->footer();
