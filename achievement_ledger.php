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
 * Achievement Ledger — staff view with filters.
 *
 * Filtering is a GET request (a search is a bookmarkable URL); the column-layout
 * buttons on the same form post. The filter form is ledger_filter_form, the SQL
 * lives in achievements_table::apply_filters(), the page is the ledger_page
 * renderable and the interactive behaviour is in the column_manager and
 * attempt_details AMD modules.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_login();

use local_completionhistory\form\ledger_filter_form;
use local_completionhistory\output\column_manager;
use local_completionhistory\output\ledger_page;
use local_completionhistory\table\achievements_table;

$systemcontext = context_system::instance();
require_capability('local/completionhistory:viewall', $systemcontext);

if (!get_config('local_completionhistory', 'enabled')) {
    throw new moodle_exception('plugindisabled', 'local_completionhistory');
}

// Filter parameters. The names are part of the page's URL contract.
$filteruserid      = optional_param('filteruserid', 0, PARAM_INT);
$filtercoursename  = optional_param('filtercoursename', '', PARAM_TEXT);
$filtersource      = optional_param('filtersource', '', PARAM_TEXT);
$filterpassed      = optional_param('filterpassed', '', PARAM_ALPHA);
$filterhasprograms = optional_param('filterhasprograms', 0, PARAM_INT);
$filterprogramids  = optional_param('filterprogramids', '', PARAM_TEXT);
$filterdatefrom    = optional_param('filterdatefrom', '', PARAM_TEXT);
$filterdateto      = optional_param('filterdateto', '', PARAM_TEXT);

// Without JavaScript the program multi-select submits its own values; fold them
// into the comma-separated parameter the page has always used. With nothing
// selected the form sends only Moodle's forced-submission marker (a string, not
// an array), which is not a selection.
if ($filterprogramids === '' && isset($_GET['filterprogramselector']) && is_array($_GET['filterprogramselector'])) {
    $selectedprograms = optional_param_array('filterprogramselector', [], PARAM_INT);
    $filterprogramids = implode(',', array_filter(array_map('intval', $selectedprograms)));
}
$programids = array_values(array_filter(array_map('intval', explode(',', $filterprogramids))));

// Unified column state: single comma-separated list carrying both the visible
// set AND the order. Resolution order: visiblecols URL param (per-request
// override), saved user preference, site-wide default, built-in staff defaults.
$layoutpref   = 'local_completionhistory_ledger_cols';
$layoutconfig = 'ledger_default_cols';

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
    ? achievements_table::default_visible_cols(true)
    : array_values(array_filter(array_map('trim', explode(',', $visiblecolsraw))));

// Page setup.
$pageurl = new moodle_url('/local/completionhistory/achievement_ledger.php');
$PAGE->set_context($systemcontext);
$PAGE->set_url($pageurl);
$PAGE->set_title(get_string('achievementledger', 'local_completionhistory'));
$PAGE->set_heading(get_string('achievementledger', 'local_completionhistory'));
$PAGE->set_pagelayout('admin');

// The filter state every redirect and the table's base URL carry.
$filterparams = array_filter([
    'filteruserid'      => $filteruserid ?: null,
    'filtercoursename'  => $filtercoursename ?: null,
    'filtersource'      => $filtersource ?: null,
    'filterpassed'      => $filterpassed ?: null,
    'filterhasprograms' => $filterhasprograms ?: null,
    'filterprogramids'  => $filterprogramids ?: null,
    'filterdatefrom'    => $filterdatefrom ?: null,
    'filterdateto'      => $filterdateto ?: null,
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

    // Redirect preserving the filter state but stripping the action flags and
    // the layout param, so the saved preference becomes the effective default.
    redirect(new moodle_url($pageurl, $filterparams), $notice, null, \core\output\notification::NOTIFY_SUCCESS);
}

// Programs for the multi-select.
$availableprograms = $DB->get_records_sql(
    'SELECT programid, MIN(programname_snapshot) AS programname_snapshot
       FROM {local_completionhistory_ach_program}
      WHERE programid IS NOT NULL
      GROUP BY programid
      ORDER BY MIN(programname_snapshot)'
);
$programoptions = [];
foreach ($availableprograms as $program) {
    $programoptions[(int) $program->programid] = format_string($program->programname_snapshot);
}

// Column manager widget (visibility + order), rendered into the filter form.
$columnmanager = new column_manager(
    achievements_table::all_col_labels(true),
    $visiblecols,
    [],
    [],
    $fromsavedpref,
    $fromsitedefault
);
$orderedvisible = $columnmanager->get_visible_columns();

// Filter form.
$formid = 'lch-ledger-filter-form';
$form   = new ledger_filter_form($pageurl, [
    'programs'          => $programoptions,
    'columnmanagerhtml' => $OUTPUT->render($columnmanager),
    'cansetdefault'     => $cansetdefault,
    'hassavedpref'      => $savedpref !== '',
    'hassitedefault'    => $siteconfigcols !== '',
], 'get', '', ['id' => $formid]);
$form->set_data([
    'filteruserid'          => $filteruserid ?: '',
    'filtercoursename'      => $filtercoursename,
    'filtersource'          => $filtersource,
    'filterpassed'          => $filterpassed,
    'filterhasprograms'     => $filterhasprograms,
    'filterprogramselector' => $programids,
    'filterprogramids'      => $filterprogramids,
    'filterdatefrom'        => $filterdatefrom,
    'filterdateto'          => $filterdateto,
]);
if ($form->is_cancelled()) {
    redirect($pageurl);
}

// Results table. Its base URL carries all filter and column-order state through
// pagination and sorting.
$tableparams = $filterparams;
if (!$usingdefaultcols) {
    $tableparams['visiblecols'] = implode(',', $orderedvisible);
}
$table = new achievements_table('local_completionhistory_ledger', true, $orderedvisible);
$table->apply_filters([
    'userid'      => $filteruserid,
    'coursename'  => $filtercoursename,
    'source'      => $filtersource,
    'passed'      => $filterpassed,
    'programids'  => $programids,
    'hasprograms' => $filterhasprograms,
    'datefrom'    => $filterdatefrom,
    'dateto'      => $filterdateto,
]);
$table->define_baseurl(new moodle_url($pageurl, $tableparams));

$PAGE->requires->js_call_amd('local_completionhistory/column_manager', 'init', [$formid]);
$PAGE->requires->js_call_amd('local_completionhistory/attempt_details', 'init');

echo $OUTPUT->header();
echo $OUTPUT->render(new ledger_page($form, $table));
echo $OUTPUT->footer();
