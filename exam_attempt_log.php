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
 * Exam Attempt Log — per-attempt operational view.
 *
 * One row per quiz attempt across all configured exam tracks.
 * Separate from the Achievement Ledger (course-level completion records).
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$dir = dirname(dirname(dirname($_SERVER['SCRIPT_FILENAME'] ?? __DIR__)));
require($dir . '/config.php');
require_login();

use local_completionhistory\table\exam_attempts_table;
use local_completionhistory\local\course_config_service;

$systemcontext = context_system::instance();
require_capability('local/completionhistory:viewall', $systemcontext);

if (!get_config('local_completionhistory', 'enabled')) {
    throw new moodle_exception('plugindisabled', 'local_completionhistory');
}

// ---------------------------------------------------------------------------
// Filter parameters.
// ---------------------------------------------------------------------------
$filteruser       = optional_param('filteruser',     '',  PARAM_TEXT);   // Name or idnumber search.
$filtercoursename = optional_param('filtercoursename','', PARAM_TEXT);
$filtertrack      = optional_param('filtertrack',    '',  PARAM_ALPHA);   // program_final|direct_credit|certificate
$filterresult     = optional_param('filterresult',   '',  PARAM_ALPHA);   // passed|failed
$filterdatefrom   = optional_param('filterdatefrom', '',  PARAM_TEXT);
$filterdateto     = optional_param('filterdateto',   '',  PARAM_TEXT);
$filterexhausted  = optional_param('filterexhausted', 0,  PARAM_INT);    // 1 = show only exhausted-track rows
$filtercompletion = optional_param('filtercompletion', 0, PARAM_INT);    // 1 = completing attempt only

// ---------------------------------------------------------------------------
// Column state: single comma-separated visiblecols carrying set + order.
// Resolution: URL param → saved user preference → built-in defaults.
// ---------------------------------------------------------------------------
const LCH_ATTEMPTS_LAYOUT_PREF   = 'local_completionhistory_attempts_cols';
const LCH_ATTEMPTS_LAYOUT_CONFIG = 'attempts_default_cols';

$cansetdefault = has_capability('local/completionhistory:manage', $systemcontext);

$visiblecolsraw  = optional_param('visiblecols', null, PARAM_TEXT);
$savedpref       = get_user_preferences(LCH_ATTEMPTS_LAYOUT_PREF, '');
$siteconfigcols  = (string) get_config('local_completionhistory', LCH_ATTEMPTS_LAYOUT_CONFIG);
$fromsavedpref   = false;
$fromsitedefault = false;
if ($visiblecolsraw === null) {
    if ($savedpref !== '') {
        $visiblecolsraw = $savedpref;
        $fromsavedpref  = true;
    } elseif ($siteconfigcols !== '') {
        $visiblecolsraw  = $siteconfigcols;
        $fromsitedefault = true;
    }
}
$usingdefaultcols = ($visiblecolsraw === null || $visiblecolsraw === '');
$visiblecols      = $usingdefaultcols
    ? exam_attempts_table::default_visible_cols()
    : array_values(array_filter(array_map('trim', explode(',', $visiblecolsraw))));

// ---------------------------------------------------------------------------
// Page setup.
// ---------------------------------------------------------------------------
$PAGE->set_context($systemcontext);
$PAGE->set_url(new moodle_url('/local/completionhistory/exam_attempt_log.php'));
$PAGE->set_title(get_string('examattemptlog', 'local_completionhistory'));
$PAGE->set_heading(get_string('examattemptlog', 'local_completionhistory'));
$PAGE->set_pagelayout('admin');

// ---------------------------------------------------------------------------
// Handle Save / Reset layout actions BEFORE any output so we can redirect.
// ---------------------------------------------------------------------------
// Presence of these param names means the matching submit button was clicked.
// PARAM_BOOL (not PARAM_INT) is used because the submitted value is the button
// label text (e.g. "Save layout"), which would coerce to 0 under PARAM_INT.
$savelayout   = optional_param('savelayout',   0, PARAM_BOOL);
$resetlayout  = optional_param('resetlayout',  0, PARAM_BOOL);
$savedefault  = optional_param('savedefault',  0, PARAM_BOOL);
$resetdefault = optional_param('resetdefault', 0, PARAM_BOOL);

if ($savelayout || $resetlayout || $savedefault || $resetdefault) {
    require_sesskey();

    if ($savedefault || $resetdefault) {
        require_capability('local/completionhistory:manage', $systemcontext);
    }

    if ($savelayout) {
        $tosave = trim(optional_param('visiblecols', '', PARAM_TEXT));
        if ($tosave !== '') {
            set_user_preference(LCH_ATTEMPTS_LAYOUT_PREF, $tosave);
        }
        $notice = get_string('layoutsaved', 'local_completionhistory');
    } else if ($resetlayout) {
        unset_user_preference(LCH_ATTEMPTS_LAYOUT_PREF);
        $notice = get_string('layoutreset', 'local_completionhistory');
    } else if ($savedefault) {
        $tosave = trim(optional_param('visiblecols', '', PARAM_TEXT));
        if ($tosave !== '') {
            set_config(LCH_ATTEMPTS_LAYOUT_CONFIG, $tosave, 'local_completionhistory');
        }
        $notice = get_string('layoutdefaultsaved', 'local_completionhistory');
    } else {
        unset_config(LCH_ATTEMPTS_LAYOUT_CONFIG, 'local_completionhistory');
        $notice = get_string('layoutdefaultreset', 'local_completionhistory');
    }

    $redirparams = array_filter([
        'filteruser'       => $filteruser       ?: null,
        'filtercoursename' => $filtercoursename ?: null,
        'filtertrack'      => $filtertrack      ?: null,
        'filterresult'     => $filterresult     ?: null,
        'filterdatefrom'   => $filterdatefrom   ?: null,
        'filterdateto'    => $filterdateto      ?: null,
        'filterexhausted'  => $filterexhausted  ?: null,
        'filtercompletion' => $filtercompletion ?: null,
    ], fn($v) => $v !== null && $v !== '');

    redirect(
        new moodle_url('/local/completionhistory/exam_attempt_log.php', $redirparams),
        $notice,
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();

// ---------------------------------------------------------------------------
// Stats bar — quick counts across the full (unfiltered) dataset.
// ---------------------------------------------------------------------------
$stat_total    = $DB->count_records('local_completionhistory_exam_attempt');
$stat_passed   = $DB->count_records('local_completionhistory_exam_attempt', ['grade_passed' => 1]);
$stat_failed   = $DB->count_records('local_completionhistory_exam_attempt', ['grade_passed' => 0]);
$stat_completing = $DB->count_records('local_completionhistory_exam_attempt', ['resulted_in_completion' => 1]);
$stat_exhausted  = $DB->count_records_sql(
    "SELECT COUNT(*) FROM {local_completionhistory_exam_attempt}
      WHERE attempts_allowed > 0
        AND attempt_number >= attempts_allowed
        AND grade_passed = 0"
);

// Per-track counts.
$track_counts = $DB->get_records_sql(
    "SELECT exam_track, COUNT(*) AS cnt
       FROM {local_completionhistory_exam_attempt}
      GROUP BY exam_track"
);
$by_track = [];
foreach ($track_counts as $r) { $by_track[$r->exam_track] = (int) $r->cnt; }

echo html_writer::start_div('row mb-4');

$stats = [
    ['total',       $stat_total,      'Total Attempts',    'secondary', ''],
    ['passed',      $stat_passed,     'Passed',            'success',   ''],
    ['failed',      $stat_failed,     'Failed',            'danger',    ''],
    ['exhausted',   $stat_exhausted,  'Track Exhausted',   'warning',   ''],
    ['completing',  $stat_completing, 'Completing Attempt','info',      ''],
];

foreach ($stats as [$key, $val, $label, $color, $extra]) {
    echo html_writer::start_div('col-md-2 col-sm-4 mb-2');
    echo html_writer::start_div("card border-{$color}");
    echo html_writer::start_div("card-body text-center p-2");
    echo html_writer::tag('div', number_format($val),
        ['class' => "h3 mb-0 text-{$color} font-weight-bold"]);
    echo html_writer::tag('div', $label, ['class' => 'small text-muted']);
    echo html_writer::end_div(); // card-body
    echo html_writer::end_div(); // card
    echo html_writer::end_div(); // col
}

// Per-track mini cards.
$track_display = [
    course_config_service::TRACK_PROGRAM_FINAL => ['Program Final', 'primary'],
    course_config_service::TRACK_DIRECT_CREDIT => ['Direct Credit', 'info'],
    course_config_service::TRACK_CERTIFICATE   => ['Certificate',   'success'],
];
foreach ($track_display as $track => [$tlabel, $tcolor]) {
    $cnt = $by_track[$track] ?? 0;
    echo html_writer::start_div('col-md-2 col-sm-4 mb-2');
    echo html_writer::start_div("card border-{$tcolor}");
    echo html_writer::start_div("card-body text-center p-2");
    echo html_writer::tag('div', number_format($cnt),
        ['class' => "h3 mb-0 text-{$tcolor} font-weight-bold"]);
    echo html_writer::tag('div', $tlabel, ['class' => 'small text-muted']);
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();
}

echo html_writer::end_div(); // row

// ---------------------------------------------------------------------------
// Filter form.
// ---------------------------------------------------------------------------
$reseturl = new moodle_url('/local/completionhistory/exam_attempt_log.php');
$ledgerurl = new moodle_url('/local/completionhistory/achievement_ledger.php');

// Back to ledger link.
echo html_writer::tag('a',
    '&#8592; ' . get_string('achievementledger', 'local_completionhistory'),
    ['href' => $ledgerurl->out(false), 'class' => 'btn btn-outline-secondary btn-sm mb-3']
);

echo html_writer::start_div('card mb-4');
echo html_writer::div(get_string('filter_heading', 'local_completionhistory'), 'card-header font-weight-bold');
echo html_writer::start_div('card-body');

echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => $PAGE->url->out_omit_querystring(),
    'id'     => 'attempt-filter-form',
]);

echo html_writer::empty_tag('input', [
    'type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey(),
]);

// Build ordered visible labels + the hidden set for the Columns UI.
$allcollabels   = exam_attempts_table::all_col_labels();
$orderedvisible = [];
foreach ($visiblecols as $col) {
    if (isset($allcollabels[$col])) {
        $orderedvisible[$col] = $allcollabels[$col];
    }
}

// ── Row 1: identity + course ─────────────────────────────────────────────────
echo html_writer::start_div('form-row align-items-end mb-3');

echo html_writer::start_div('form-group col-md-3');
echo html_writer::label(get_string('filter_user_search', 'local_completionhistory'), 'filteruser', true,
    ['class' => 'small font-weight-bold']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'filteruser', 'id' => 'filteruser',
    'value' => $filteruser, 'class' => 'form-control form-control-sm',
    'placeholder' => 'Name or User ID#',
]);
echo html_writer::end_div();

echo html_writer::start_div('form-group col-md-3');
echo html_writer::label(get_string('col_coursename', 'local_completionhistory'), 'filtercoursename', true,
    ['class' => 'small font-weight-bold']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'filtercoursename', 'id' => 'filtercoursename',
    'value' => $filtercoursename, 'class' => 'form-control form-control-sm',
    'placeholder' => 'Course name',
]);
echo html_writer::end_div();

echo html_writer::start_div('form-group col-md-2');
echo html_writer::label(get_string('col_exam_track', 'local_completionhistory'), 'filtertrack', true,
    ['class' => 'small font-weight-bold']);
$track_options = [
    '' => get_string('filter_examtrack_any', 'local_completionhistory'),
    course_config_service::TRACK_PROGRAM_FINAL => get_string('track_program_final', 'local_completionhistory'),
    course_config_service::TRACK_DIRECT_CREDIT => get_string('track_direct_credit', 'local_completionhistory'),
    course_config_service::TRACK_CERTIFICATE   => get_string('track_certificate',   'local_completionhistory'),
];
echo html_writer::select($track_options, 'filtertrack', $filtertrack, false,
    ['id' => 'filtertrack', 'class' => 'form-control form-control-sm']);
echo html_writer::end_div();

echo html_writer::start_div('form-group col-md-2');
echo html_writer::label(get_string('col_attempt_result', 'local_completionhistory'), 'filterresult', true,
    ['class' => 'small font-weight-bold']);
$result_options = [
    ''       => get_string('filter_passed_any', 'local_completionhistory'),
    'passed' => get_string('filter_passed_yes', 'local_completionhistory'),
    'failed' => get_string('filter_passed_no',  'local_completionhistory'),
];
echo html_writer::select($result_options, 'filterresult', $filterresult, false,
    ['id' => 'filterresult', 'class' => 'form-control form-control-sm']);
echo html_writer::end_div();

echo html_writer::end_div(); // row 1

// ── Row 2: date range + boolean flags ────────────────────────────────────────
echo html_writer::start_div('form-row align-items-end mb-3');

echo html_writer::start_div('form-group col-md-2');
echo html_writer::label(get_string('filter_datefrom', 'local_completionhistory'), 'filterdatefrom', true,
    ['class' => 'small font-weight-bold']);
echo html_writer::empty_tag('input', [
    'type' => 'date', 'name' => 'filterdatefrom', 'id' => 'filterdatefrom',
    'value' => $filterdatefrom, 'class' => 'form-control form-control-sm',
]);
echo html_writer::end_div();

echo html_writer::start_div('form-group col-md-2');
echo html_writer::label(get_string('filter_dateto', 'local_completionhistory'), 'filterdateto', true,
    ['class' => 'small font-weight-bold']);
echo html_writer::empty_tag('input', [
    'type' => 'date', 'name' => 'filterdateto', 'id' => 'filterdateto',
    'value' => $filterdateto, 'class' => 'form-control form-control-sm',
]);
echo html_writer::end_div();

echo html_writer::start_div('form-group col-md-3 d-flex align-items-end');
echo html_writer::start_div('d-flex flex-column');

echo html_writer::start_div('form-check mb-1');
$exhattrs = ['type' => 'checkbox', 'name' => 'filterexhausted', 'id' => 'filterexhausted',
    'value' => '1', 'class' => 'form-check-input'];
if ($filterexhausted) $exhattrs['checked'] = 'checked';
echo html_writer::empty_tag('input', $exhattrs);
echo html_writer::label(
    get_string('filter_exhausted_only', 'local_completionhistory'),
    'filterexhausted', true, ['class' => 'form-check-label small']);
echo html_writer::end_div();

echo html_writer::start_div('form-check');
$compattrs = ['type' => 'checkbox', 'name' => 'filtercompletion', 'id' => 'filtercompletion',
    'value' => '1', 'class' => 'form-check-input'];
if ($filtercompletion) $compattrs['checked'] = 'checked';
echo html_writer::empty_tag('input', $compattrs);
echo html_writer::label(
    get_string('filter_completing_only', 'local_completionhistory'),
    'filtercompletion', true, ['class' => 'form-check-label small']);
echo html_writer::end_div();

echo html_writer::end_div(); // flex-column
echo html_writer::end_div(); // col

echo html_writer::end_div(); // row 2

// ── Row 3: unified columns manager (visibility + order) ─────────────────────
echo html_writer::start_div('form-row mb-3');
echo html_writer::start_div('col-12');

echo html_writer::tag('p', get_string('filter_columns', 'local_completionhistory'),
    ['class' => 'small font-weight-bold mb-1']);
echo html_writer::tag('p', get_string('filter_columns_help', 'local_completionhistory'),
    ['class' => 'small text-muted mb-2']);

if ($fromsavedpref) {
    echo html_writer::tag('p',
        '&#128190; ' . get_string('layout_using_saved', 'local_completionhistory'),
        ['class' => 'small text-success mb-2']);
} else if ($fromsitedefault) {
    echo html_writer::tag('p',
        '&#127960; ' . get_string('layout_using_default', 'local_completionhistory'),
        ['class' => 'small text-info mb-2']);
}

$colcats = exam_attempts_table::col_categories();
$catlabs = exam_attempts_table::category_labels();

// Category filter pills.
echo html_writer::start_div('d-flex flex-wrap mb-2', ['id' => 'col-category-pills']);
echo html_writer::tag('button', get_string('filter_columns_all', 'local_completionhistory'), [
    'type'       => 'button',
    'class'      => 'btn btn-sm btn-secondary mr-1 mb-1 lch-cat-pill active',
    'data-cat'   => '',
]);
foreach ($catlabs as $catkey => $catlabel) {
    echo html_writer::tag('button', s($catlabel), [
        'type'     => 'button',
        'class'    => 'btn btn-sm btn-outline-secondary mr-1 mb-1 lch-cat-pill',
        'data-cat' => $catkey,
    ]);
}
echo html_writer::end_div();

echo html_writer::empty_tag('input', [
    'type'        => 'search',
    'id'          => 'col-search',
    'class'       => 'form-control form-control-sm mb-2',
    'style'       => 'max-width:280px;',
    'placeholder' => get_string('filter_columns_search', 'local_completionhistory'),
    'autocomplete'=> 'off',
]);

echo html_writer::start_div('d-flex flex-wrap mb-2', ['id' => 'col-checkbox-list']);
foreach ($allcollabels as $colname => $label) {
    $cbattrs = [
        'type'       => 'checkbox',
        'id'         => 'colcb_' . $colname,
        'value'      => '1',
        'class'      => 'form-check-input lch-col-toggle',
        'data-col'   => $colname,
        'data-label' => $label,
    ];
    if (isset($orderedvisible[$colname])) {
        $cbattrs['checked'] = 'checked';
    }
    echo html_writer::start_div('form-check mr-4 mb-1', [
        'data-category' => $colcats[$colname] ?? 'other',
    ]);
    echo html_writer::empty_tag('input', $cbattrs);
    echo html_writer::label($label, 'colcb_' . $colname, true, ['class' => 'form-check-label small']);
    echo html_writer::end_div();
}
echo html_writer::end_div();

echo html_writer::start_tag('div', [
    'id'    => 'col-order-list',
    'class' => 'd-flex flex-wrap',
    'style' => 'gap:6px; min-height:36px; padding:6px; border:1px dashed #ccc; border-radius:4px; background:#fafafa;',
]);
foreach ($orderedvisible as $col => $label) {
    echo html_writer::tag('span', '&#8597; ' . s($label), [
        'class'     => 'badge badge-light border',
        'draggable' => 'true',
        'data-col'  => $col,
        'style'     => 'font-size:0.85em; padding:5px 10px; cursor:grab; user-select:none;',
    ]);
}
echo html_writer::end_tag('div');

echo html_writer::empty_tag('input', [
    'type'  => 'hidden',
    'name'  => 'visiblecols',
    'id'    => 'visiblecols-input',
    'value' => implode(',', array_keys($orderedvisible)),
]);

echo html_writer::end_div();
echo html_writer::end_div(); // row 3

// Submit / reset / save / reset layout.
echo html_writer::start_div('form-row');
echo html_writer::start_div('col-12');
echo html_writer::empty_tag('input', [
    'type' => 'submit', 'value' => get_string('search'),
    'class' => 'btn btn-primary btn-sm mr-2',
]);
echo html_writer::tag('a', get_string('reset'), [
    'href' => $reseturl->out(false),
    'class' => 'btn btn-secondary btn-sm mr-2',
]);
echo html_writer::empty_tag('input', [
    'type'  => 'submit',
    'name'  => 'savelayout',
    'value' => get_string('savelayout', 'local_completionhistory'),
    'class' => 'btn btn-outline-success btn-sm mr-2',
    'title' => get_string('savelayout_help', 'local_completionhistory'),
]);
if ($savedpref !== '') {
    echo html_writer::empty_tag('input', [
        'type'    => 'submit',
        'name'    => 'resetlayout',
        'value'   => get_string('resetlayout', 'local_completionhistory'),
        'class'   => 'btn btn-outline-danger btn-sm mr-2',
        'title'   => get_string('resetlayout_help', 'local_completionhistory'),
        'onclick' => 'return confirm(' . json_encode(get_string('resetlayout_confirm', 'local_completionhistory')) . ');',
    ]);
}
if ($cansetdefault) {
    echo html_writer::empty_tag('input', [
        'type'  => 'submit',
        'name'  => 'savedefault',
        'value' => get_string('savedefault', 'local_completionhistory'),
        'class' => 'btn btn-outline-primary btn-sm mr-2',
        'title' => get_string('savedefault_help', 'local_completionhistory'),
    ]);
    if ($siteconfigcols !== '') {
        echo html_writer::empty_tag('input', [
            'type'    => 'submit',
            'name'    => 'resetdefault',
            'value'   => get_string('resetdefault', 'local_completionhistory'),
            'class'   => 'btn btn-outline-warning btn-sm',
            'title'   => get_string('resetdefault_help', 'local_completionhistory'),
            'onclick' => 'return confirm(' . json_encode(get_string('resetdefault_confirm', 'local_completionhistory')) . ');',
        ]);
    }
}
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_tag('form');
echo html_writer::end_div(); // card-body
echo html_writer::end_div(); // card

// ---------------------------------------------------------------------------
// Build SQL WHERE conditions.
// ---------------------------------------------------------------------------
$conditions = ['1 = 1'];
$params     = [];

if (!empty($filteruser)) {
    $likeval = '%' . $DB->sql_like_escape($filteruser) . '%';
    $conditions[] = '(
        ' . $DB->sql_like('u.firstname', ':fname', false) . '
     OR ' . $DB->sql_like('u.lastname',  ':lname', false) . '
     OR ' . $DB->sql_like('u.idnumber',  ':idnum', false) . '
    )';
    $params['fname'] = $likeval;
    $params['lname'] = $likeval;
    $params['idnum'] = $likeval;
}

if (!empty($filtercoursename)) {
    $conditions[] = $DB->sql_like('c.fullname', ':filtercoursename', false);
    $params['filtercoursename'] = '%' . $DB->sql_like_escape($filtercoursename) . '%';
}

if (!empty($filtertrack)) {
    $conditions[] = 'ea.exam_track = :filtertrack';
    $params['filtertrack'] = $filtertrack;
}

if ($filterresult === 'passed') {
    $conditions[] = 'ea.grade_passed = 1';
} elseif ($filterresult === 'failed') {
    $conditions[] = 'ea.grade_passed = 0';
}

if (!empty($filterdatefrom)) {
    $ts = strtotime($filterdatefrom);
    if ($ts !== false) {
        $conditions[] = 'ea.timetaken >= :filterdatefrom';
        $params['filterdatefrom'] = $ts;
    }
}
if (!empty($filterdateto)) {
    $ts = strtotime($filterdateto . ' 23:59:59');
    if ($ts !== false) {
        $conditions[] = 'ea.timetaken <= :filterdateto';
        $params['filterdateto'] = $ts;
    }
}

if ($filterexhausted) {
    $conditions[] = 'ea.attempts_allowed > 0 AND ea.attempt_number >= ea.attempts_allowed AND ea.grade_passed = 0';
}

if ($filtercompletion) {
    $conditions[] = 'ea.resulted_in_completion = 1';
}

$where = implode(' AND ', $conditions);

// ---------------------------------------------------------------------------
// Build base URL (carries all filter state through pagination + sorting).
// ---------------------------------------------------------------------------
$urlparams = array_filter([
    'filteruser'       => $filteruser      ?: null,
    'filtercoursename' => $filtercoursename ?: null,
    'filtertrack'      => $filtertrack     ?: null,
    'filterresult'     => $filterresult    ?: null,
    'filterdatefrom'   => $filterdatefrom  ?: null,
    'filterdateto'     => $filterdateto    ?: null,
    'filterexhausted'  => $filterexhausted ?: null,
    'filtercompletion' => $filtercompletion ?: null,
    'visiblecols'      => $usingdefaultcols ? null : implode(',', array_keys($orderedvisible)),
], fn($v) => $v !== null && $v !== '');
$tableurl = new moodle_url('/local/completionhistory/exam_attempt_log.php', $urlparams);

// ---------------------------------------------------------------------------
// Render table.
// ---------------------------------------------------------------------------
$table = new exam_attempts_table('local_completionhistory_attempts', array_keys($orderedvisible));

$table->set_sql(
    'ea.*,
     u.firstname   AS user_firstname,
     u.lastname    AS user_lastname,
     u.email       AS user_email,
     u.country     AS user_country,
     u.idnumber    AS useridnumber,
     u.timecreated AS user_timecreated,
     c.fullname    AS course_fullname,
     c.shortname   AS course_shortname',
    '{local_completionhistory_exam_attempt} ea
     LEFT JOIN {user}   u ON u.id  = ea.userid
     LEFT JOIN {course} c ON c.id  = ea.courseid',
    $where,
    $params
);

$table->define_baseurl($tableurl);
$table->out(50, true);

// ---------------------------------------------------------------------------
// Inline JS: column visibility/order management + row hover.
// ---------------------------------------------------------------------------
echo html_writer::script('
(function () {
    var list   = document.getElementById("col-order-list");
    var hidden = document.getElementById("visiblecols-input");
    var checks = document.getElementById("col-checkbox-list");

    function syncHidden() {
        if (!list || !hidden) return;
        var cols = Array.from(list.querySelectorAll("[data-col]"))
                       .map(function (el) { return el.dataset.col; });
        hidden.value = cols.join(",");
    }

    function makeBadge(col, label) {
        var span = document.createElement("span");
        span.className = "badge badge-light border";
        span.setAttribute("draggable", "true");
        span.setAttribute("data-col", col);
        span.style.cssText = "font-size:0.85em; padding:5px 10px; cursor:grab; user-select:none;";
        span.textContent = "\u2195 " + label;
        return span;
    }

    if (checks && list) {
        checks.addEventListener("change", function (e) {
            var cb = e.target;
            if (!cb.classList || !cb.classList.contains("lch-col-toggle")) return;
            var col   = cb.dataset.col;
            var label = cb.dataset.label;
            var existing = list.querySelector("[data-col=\"" + col + "\"]");
            if (cb.checked && !existing) {
                list.appendChild(makeBadge(col, label));
            } else if (!cb.checked && existing) {
                existing.remove();
            }
            syncHidden();
        });
    }

    // ── Category pills + search: combined filter for the checkbox list ────
    var search = document.getElementById("col-search");
    var pills  = document.getElementById("col-category-pills");
    var activeCat = "";

    function applyColFilter() {
        if (!checks) return;
        var q = search ? search.value.trim().toLowerCase() : "";
        Array.from(checks.querySelectorAll(".form-check")).forEach(function (wrap) {
            var cb = wrap.querySelector(".lch-col-toggle");
            if (!cb) return;
            var label = (cb.dataset.label || "").toLowerCase();
            var col   = (cb.dataset.col   || "").toLowerCase();
            var cat   = wrap.getAttribute("data-category") || "";
            var textHit = q === "" || label.indexOf(q) !== -1 || col.indexOf(q) !== -1;
            var catHit  = activeCat === "" || cat === activeCat;
            wrap.style.display = (textHit && catHit) ? "" : "none";
        });
    }

    if (search) search.addEventListener("input", applyColFilter);

    if (pills) {
        pills.addEventListener("click", function (e) {
            var btn = e.target.closest(".lch-cat-pill");
            if (!btn) return;
            var cat = btn.getAttribute("data-cat") || "";
            // Toggle: clicking the active category clears it.
            activeCat = (activeCat === cat) ? "" : cat;
            // Fall back to the "All" pill when no category is active.
            Array.from(pills.querySelectorAll(".lch-cat-pill")).forEach(function (b) {
                var isActive = (b.getAttribute("data-cat") || "") === activeCat;
                b.classList.toggle("btn-secondary", isActive);
                b.classList.toggle("btn-outline-secondary", !isActive);
                b.classList.toggle("active", isActive);
            });
            applyColFilter();
        });
    }

    if (list && hidden) {
        var dragging = null;
        list.addEventListener("dragstart", function (e) {
            dragging = e.target.closest("[data-col]");
            if (dragging) {
                dragging.style.opacity = "0.4";
                e.dataTransfer.effectAllowed = "move";
            }
        });
        list.addEventListener("dragend", function () {
            if (dragging) dragging.style.opacity = "";
            dragging = null;
            syncHidden();
        });
        list.addEventListener("dragover", function (e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = "move";
            var target = e.target.closest("[data-col]");
            if (!target || target === dragging) return;
            var rect = target.getBoundingClientRect();
            var after = (e.clientX - rect.left) > rect.width / 2;
            list.insertBefore(dragging, after ? target.nextSibling : target);
        });
        list.addEventListener("drop", function (e) { e.preventDefault(); });
    }

    var form = document.getElementById("attempt-filter-form");
    if (form) form.addEventListener("submit", syncHidden);

    // Highlight entire student group on row hover.
    document.querySelectorAll(".generaltable tbody tr").forEach(function (row) {
        row.addEventListener("mouseenter", function () { this.style.background = "#fffbea"; });
        row.addEventListener("mouseleave", function () { this.style.background = ""; });
    });
})();
');

echo $OUTPUT->footer();
