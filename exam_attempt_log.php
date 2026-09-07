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
 * One row per quiz attempt across all configured exam tracks. Separate from the
 * Achievement Ledger (course-level completion records).
 *
 * Filtering is a GET request (a search is a bookmarkable URL); the column-layout
 * buttons on the same form post. The filter form is exam_attempt_filter_form, the
 * SQL lives in exam_attempts_table::apply_filters(), the page is the
 * exam_attempt_log_page renderable and the interactive behaviour is in the
 * column_manager AMD module.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_login();

use local_completionhistory\form\exam_attempt_filter_form;
use local_completionhistory\output\column_manager;
use local_completionhistory\output\exam_attempt_log_page;
use local_completionhistory\table\exam_attempts_table;

$systemcontext = context_system::instance();
require_capability('local/completionhistory:viewall', $systemcontext);

if (!get_config('local_completionhistory', 'enabled')) {
    throw new moodle_exception('plugindisabled', 'local_completionhistory');
}

// Filter parameters. The names are part of the page's URL contract.
$filteruser       = optional_param('filteruser', '', PARAM_TEXT);       // Name or idnumber search.
$filtercoursename = optional_param('filtercoursename', '', PARAM_TEXT);
$filtertrack      = optional_param('filtertrack', '', PARAM_ALPHAEXT);  // An exam track code (has an underscore).
$filterresult     = optional_param('filterresult', '', PARAM_ALPHA);    // Passed or failed.
$filterdatefrom   = optional_param('filterdatefrom', '', PARAM_TEXT);
$filterdateto     = optional_param('filterdateto', '', PARAM_TEXT);
$filterexhausted  = optional_param('filterexhausted', 0, PARAM_INT);    // 1 = show only exhausted-track rows.
$filtercompletion = optional_param('filtercompletion', 0, PARAM_INT);   // 1 = completing attempt only.

// Column state: single comma-separated visiblecols carrying set + order.
// Resolution: URL param, saved user preference, site-wide default, built-in defaults.
$layoutpref   = 'local_completionhistory_attempts_cols';
$layoutconfig = 'attempts_default_cols';

$cansetdefault = has_capability('local/completionhistory:manage', $systemcontext);

$visiblecolsraw  = optional_param('visiblecols', null, PARAM_TEXT);
$savedpref       = get_user_preferences($layoutpref, '');
$siteconfigcols  = (string) get_config('local_completionhistory', $layoutconfig);
$fromsavedpref   = false;
$fromsitedefault = false;
if ($visiblecolsraw === null) {
    if ($savedpref !== '') {
        $visiblecolsraw = $savedpref;
        $fromsavedpref  = true;
    } else if ($siteconfigcols !== '') {
        $visiblecolsraw  = $siteconfigcols;
        $fromsitedefault = true;
    }
}
$usingdefaultcols = ($visiblecolsraw === null || $visiblecolsraw === '');
$visiblecols      = $usingdefaultcols
    ? exam_attempts_table::default_visible_cols()
    : array_values(array_filter(array_map('trim', explode(',', $visiblecolsraw))));

// Page setup.
$pageurl = new moodle_url('/local/completionhistory/exam_attempt_log.php');
$PAGE->set_context($systemcontext);
$PAGE->set_url($pageurl);
$PAGE->set_title(get_string('examattemptlog', 'local_completionhistory'));
$PAGE->set_heading(get_string('examattemptlog', 'local_completionhistory'));
$PAGE->set_pagelayout('admin');

// The filter state every redirect and the table's base URL carry.
$filterparams = array_filter([
    'filteruser'       => $filteruser ?: null,
    'filtercoursename' => $filtercoursename ?: null,
    'filtertrack'      => $filtertrack ?: null,
    'filterresult'     => $filterresult ?: null,
    'filterdatefrom'   => $filterdatefrom ?: null,
    'filterdateto'     => $filterdateto ?: null,
    'filterexhausted'  => $filterexhausted ?: null,
    'filtercompletion' => $filtercompletion ?: null,
], fn($v) => $v !== null && $v !== '');

// Handle Save / Reset layout actions BEFORE any output so we can redirect.
// Presence of these param names means the matching submit button was clicked.
// PARAM_BOOL (not PARAM_INT) is used because the submitted value is the button
// label text (e.g. "Save layout"), which would coerce to 0 under PARAM_INT.
$savelayout   = optional_param('savelayout', 0, PARAM_BOOL);
$resetlayout  = optional_param('resetlayout', 0, PARAM_BOOL);
$savedefault  = optional_param('savedefault', 0, PARAM_BOOL);
$resetdefault = optional_param('resetdefault', 0, PARAM_BOOL);

if ($savelayout || $resetlayout || $savedefault || $resetdefault) {
    if (!data_submitted()) {
        throw new moodle_exception('invalidrequest');
    }
    require_sesskey();

    if ($savedefault || $resetdefault) {
        require_capability('local/completionhistory:manage', $systemcontext);
    }

    if ($savelayout) {
        $tosave = trim(optional_param('visiblecols', '', PARAM_TEXT));
        if ($tosave !== '') {
            set_user_preference($layoutpref, $tosave);
        }
        $notice = get_string('layoutsaved', 'local_completionhistory');
    } else if ($resetlayout) {
        unset_user_preference($layoutpref);
        $notice = get_string('layoutreset', 'local_completionhistory');
    } else if ($savedefault) {
        $tosave = trim(optional_param('visiblecols', '', PARAM_TEXT));
        if ($tosave !== '') {
            set_config($layoutconfig, $tosave, 'local_completionhistory');
        }
        $notice = get_string('layoutdefaultsaved', 'local_completionhistory');
    } else {
        unset_config($layoutconfig, 'local_completionhistory');
        $notice = get_string('layoutdefaultreset', 'local_completionhistory');
    }

    redirect(new moodle_url($pageurl, $filterparams), $notice, null, \core\output\notification::NOTIFY_SUCCESS);
}

// Column manager widget (visibility + order + category pills), rendered into the filter form.
$columnmanager = new column_manager(
    exam_attempts_table::all_col_labels(),
    $visiblecols,
    exam_attempts_table::col_categories(),
    exam_attempts_table::category_labels(),
    $fromsavedpref,
    $fromsitedefault
);
$orderedvisible = $columnmanager->get_visible_columns();

// Filter form.
$formid = 'lch-attempt-filter-form';
$form   = new exam_attempt_filter_form($pageurl, [
    'columnmanagerhtml' => $OUTPUT->render($columnmanager),
    'cansetdefault'     => $cansetdefault,
    'hassavedpref'      => $savedpref !== '',
    'hassitedefault'    => $siteconfigcols !== '',
], 'get', '', ['id' => $formid]);
$form->set_data([
    'filteruser'       => $filteruser,
    'filtercoursename' => $filtercoursename,
    'filtertrack'      => $filtertrack,
    'filterresult'     => $filterresult,
    'filterdatefrom'   => $filterdatefrom,
    'filterdateto'     => $filterdateto,
    'filterexhausted'  => $filterexhausted,
    'filtercompletion' => $filtercompletion,
]);
if ($form->is_cancelled()) {
    redirect($pageurl);
}

// Stats bar: quick counts across the full (unfiltered) dataset.
$counts = [
    'total'      => $DB->count_records('local_completionhistory_exam_attempt'),
    'passed'     => $DB->count_records('local_completionhistory_exam_attempt', ['grade_passed' => 1]),
    'failed'     => $DB->count_records('local_completionhistory_exam_attempt', ['grade_passed' => 0]),
    'completing' => $DB->count_records('local_completionhistory_exam_attempt', ['resulted_in_completion' => 1]),
    'exhausted'  => $DB->count_records_sql(
        "SELECT COUNT(*) FROM {local_completionhistory_exam_attempt}
          WHERE attempts_allowed > 0
            AND attempt_number >= attempts_allowed
            AND grade_passed = 0"
    ),
];
$trackcounts = [];
$trackrecords = $DB->get_records_sql(
    "SELECT exam_track, COUNT(*) AS cnt
       FROM {local_completionhistory_exam_attempt}
      GROUP BY exam_track"
);
foreach ($trackrecords as $record) {
    $trackcounts[$record->exam_track] = (int) $record->cnt;
}

// Results table. Its base URL carries all filter and column-order state through
// pagination and sorting.
$tableparams = $filterparams;
if (!$usingdefaultcols) {
    $tableparams['visiblecols'] = implode(',', $orderedvisible);
}
$table = new exam_attempts_table('local_completionhistory_attempts', $orderedvisible);
$table->apply_filters([
    'user'       => $filteruser,
    'coursename' => $filtercoursename,
    'track'      => $filtertrack,
    'result'     => $filterresult,
    'datefrom'   => $filterdatefrom,
    'dateto'     => $filterdateto,
    'exhausted'  => $filterexhausted,
    'completion' => $filtercompletion,
]);
$table->define_baseurl(new moodle_url($pageurl, $tableparams));

$PAGE->requires->js_call_amd('local_completionhistory/column_manager', 'init', [$formid]);

echo $OUTPUT->header();
echo $OUTPUT->render(new exam_attempt_log_page($form, $table, $counts, $trackcounts));
echo $OUTPUT->footer();
