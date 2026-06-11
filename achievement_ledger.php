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
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$dir = dirname(dirname(dirname($_SERVER['SCRIPT_FILENAME'] ?? __DIR__)));
require($dir . '/config.php');
require_login();

use local_completionhistory\table\achievements_table;

$systemcontext = context_system::instance();
require_capability('local/completionhistory:viewall', $systemcontext);

if (!get_config('local_completionhistory', 'enabled')) {
    throw new moodle_exception('plugindisabled', 'local_completionhistory');
}

// ---------------------------------------------------------------------------
// Filter parameters.
// ---------------------------------------------------------------------------
$filteruserid      = optional_param('filteruserid', 0, PARAM_INT);
$filtercoursename  = optional_param('filtercoursename', '', PARAM_TEXT);
$filtersource      = optional_param('filtersource', '', PARAM_TEXT);
$filterpassed      = optional_param('filterpassed', '', PARAM_ALPHA);
$filterhasprograms = optional_param('filterhasprograms', 0, PARAM_INT);
$filterprogramids  = optional_param('filterprogramids', '', PARAM_TEXT);
$filterdatefrom    = optional_param('filterdatefrom', '', PARAM_TEXT);
$filterdateto      = optional_param('filterdateto', '', PARAM_TEXT);

// Unified column state: single comma-separated list carrying both the
// visible set AND the order.
// Resolution order:
//   1. visiblecols URL param (per-request override)
//   2. saved user preference (per-user persistent layout)
//   3. built-in staff defaults
const LCH_LAYOUT_PREF   = 'local_completionhistory_ledger_cols';
const LCH_LAYOUT_CONFIG = 'ledger_default_cols';

$cansetdefault = has_capability('local/completionhistory:manage', $systemcontext);

$visiblecolsraw  = optional_param('visiblecols', null, PARAM_TEXT);
$savedpref       = get_user_preferences(LCH_LAYOUT_PREF, '');
$siteconfigcols  = (string) get_config('local_completionhistory', LCH_LAYOUT_CONFIG);
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
    ? achievements_table::default_visible_cols(true)
    : array_values(array_filter(array_map('trim', explode(',', $visiblecolsraw))));

// Parse comma-separated program IDs.
$programids = array_values(array_filter(array_map('intval', explode(',', $filterprogramids))));

// ---------------------------------------------------------------------------
// Page setup.
// ---------------------------------------------------------------------------
$PAGE->set_context($systemcontext);
$PAGE->set_url(new moodle_url('/local/completionhistory/achievement_ledger.php'));
$PAGE->set_title(get_string('achievementledger', 'local_completionhistory'));
$PAGE->set_heading(get_string('achievementledger', 'local_completionhistory'));
$PAGE->set_pagelayout('admin');

// ---------------------------------------------------------------------------
// Handle Save / Reset layout actions BEFORE any output so we can redirect.
// ---------------------------------------------------------------------------
// Presence of these param names means the matching submit button was clicked.
// PARAM_BOOL (not PARAM_INT) is used because the submitted value is the button
// label text (e.g. "Save layout"), which would coerce to 0 under PARAM_INT.
$savelayout    = optional_param('savelayout',    0, PARAM_BOOL);
$resetlayout   = optional_param('resetlayout',   0, PARAM_BOOL);
$savedefault   = optional_param('savedefault',   0, PARAM_BOOL);
$resetdefault  = optional_param('resetdefault',  0, PARAM_BOOL);

if ($savelayout || $resetlayout || $savedefault || $resetdefault) {
    require_sesskey();

    if ($savedefault || $resetdefault) {
        require_capability('local/completionhistory:manage', $systemcontext);
    }

    if ($savelayout) {
        $tosave = trim(optional_param('visiblecols', '', PARAM_TEXT));
        if ($tosave !== '') {
            set_user_preference(LCH_LAYOUT_PREF, $tosave);
        }
        $notice = get_string('layoutsaved', 'local_completionhistory');
    } else if ($resetlayout) {
        unset_user_preference(LCH_LAYOUT_PREF);
        $notice = get_string('layoutreset', 'local_completionhistory');
    } else if ($savedefault) {
        $tosave = trim(optional_param('visiblecols', '', PARAM_TEXT));
        if ($tosave !== '') {
            set_config(LCH_LAYOUT_CONFIG, $tosave, 'local_completionhistory');
        }
        $notice = get_string('layoutdefaultsaved', 'local_completionhistory');
    } else {
        unset_config(LCH_LAYOUT_CONFIG, 'local_completionhistory');
        $notice = get_string('layoutdefaultreset', 'local_completionhistory');
    }

    // Rebuild redirect URL preserving the user's current filter state
    // (but stripping the action flags and layout param so the saved
    // preference becomes the effective default).
    $redirparams = array_filter([
        'filteruserid'      => $filteruserid ?: null,
        'filtercoursename'  => $filtercoursename ?: null,
        'filtersource'      => $filtersource ?: null,
        'filterpassed'      => $filterpassed ?: null,
        'filterhasprograms' => $filterhasprograms ?: null,
        'filterprogramids'  => $filterprogramids ?: null,
        'filterdatefrom'    => $filterdatefrom ?: null,
        'filterdateto'      => $filterdateto ?: null,
    ], fn($v) => $v !== null && $v !== '');

    redirect(
        new moodle_url('/local/completionhistory/achievement_ledger.php', $redirparams),
        $notice,
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();

// ---------------------------------------------------------------------------
// Fetch programs for the multi-select dropdown.
// ---------------------------------------------------------------------------
$availableprograms = $DB->get_records_sql(
    'SELECT programid, MIN(programname_snapshot) AS programname_snapshot
       FROM {local_completionhistory_ach_program}
      WHERE programid IS NOT NULL
      GROUP BY programid
      ORDER BY MIN(programname_snapshot)'
);

// ---------------------------------------------------------------------------
// Build column UI state:
//   $allcollabels   — every known column name → translated label
//   $orderedvisible — ordered map of currently-visible columns (ordering from $visiblecols)
//   $hiddencols     — every known column NOT in the visible set
// ---------------------------------------------------------------------------
$allcollabels = achievements_table::all_col_labels(true);

$orderedvisible = [];
foreach ($visiblecols as $col) {
    if (isset($allcollabels[$col])) {
        $orderedvisible[$col] = $allcollabels[$col];
    }
}
$hiddencols = array_diff(array_keys($allcollabels), array_keys($orderedvisible));

// ---------------------------------------------------------------------------
// Filter form.
// ---------------------------------------------------------------------------
$reseturl = new moodle_url('/local/completionhistory/achievement_ledger.php');

echo html_writer::start_div('card mb-4');
echo html_writer::div(get_string('filter_heading', 'local_completionhistory'), 'card-header font-weight-bold');
echo html_writer::start_div('card-body');

echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => $PAGE->url->out_omit_querystring(),
    'id'     => 'ledger-filter-form',
]);

echo html_writer::empty_tag('input', [
    'type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey(),
]);

// ── Row 1: basic filters ────────────────────────────────────────────────────
echo html_writer::start_div('form-row align-items-end mb-3');

echo html_writer::start_div('form-group col-md-2');
echo html_writer::label(get_string('col_user', 'local_completionhistory'), 'filteruserid', true, ['class' => 'small font-weight-bold']);
echo html_writer::empty_tag('input', [
    'type' => 'number', 'name' => 'filteruserid', 'id' => 'filteruserid',
    'value' => $filteruserid ?: '', 'class' => 'form-control form-control-sm', 'placeholder' => 'User ID',
]);
echo html_writer::end_div();

echo html_writer::start_div('form-group col-md-3');
echo html_writer::label(get_string('col_coursename', 'local_completionhistory'), 'filtercoursename', true, ['class' => 'small font-weight-bold']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'filtercoursename', 'id' => 'filtercoursename',
    'value' => $filtercoursename, 'class' => 'form-control form-control-sm', 'placeholder' => 'Course name',
]);
echo html_writer::end_div();

echo html_writer::start_div('form-group col-md-2');
echo html_writer::label(get_string('col_source', 'local_completionhistory'), 'filtersource', true, ['class' => 'small font-weight-bold']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'filtersource', 'id' => 'filtersource',
    'value' => $filtersource, 'class' => 'form-control form-control-sm', 'placeholder' => 'Source component',
]);
echo html_writer::end_div();

echo html_writer::start_div('form-group col-md-2');
echo html_writer::label(get_string('filter_passed', 'local_completionhistory'), 'filterpassed', true, ['class' => 'small font-weight-bold']);
$passedoptions = [
    ''     => get_string('filter_passed_any', 'local_completionhistory'),
    '1'    => get_string('filter_passed_yes', 'local_completionhistory'),
    '0'    => get_string('filter_passed_no', 'local_completionhistory'),
    'null' => get_string('filter_passed_unknown', 'local_completionhistory'),
];
echo html_writer::select($passedoptions, 'filterpassed', $filterpassed, false,
    ['id' => 'filterpassed', 'class' => 'form-control form-control-sm']);
echo html_writer::end_div();

echo html_writer::end_div(); // row 1

// ── Row 2: date range ───────────────────────────────────────────────────────
echo html_writer::start_div('form-row align-items-end mb-3');

echo html_writer::start_div('form-group col-md-3');
echo html_writer::label(get_string('filter_datefrom', 'local_completionhistory'), 'filterdatefrom', true, ['class' => 'small font-weight-bold']);
echo html_writer::empty_tag('input', [
    'type' => 'date', 'name' => 'filterdatefrom', 'id' => 'filterdatefrom',
    'value' => $filterdatefrom, 'class' => 'form-control form-control-sm',
]);
echo html_writer::end_div();

echo html_writer::start_div('form-group col-md-3');
echo html_writer::label(get_string('filter_dateto', 'local_completionhistory'), 'filterdateto', true, ['class' => 'small font-weight-bold']);
echo html_writer::empty_tag('input', [
    'type' => 'date', 'name' => 'filterdateto', 'id' => 'filterdateto',
    'value' => $filterdateto, 'class' => 'form-control form-control-sm',
]);
echo html_writer::end_div();

echo html_writer::end_div(); // row 2

// ── Row 3: program filters ──────────────────────────────────────────────────
echo html_writer::start_div('form-row align-items-start mb-3');

echo html_writer::start_div('form-group col-md-3');
echo html_writer::tag('p', get_string('filter_programs_heading', 'local_completionhistory'), ['class' => 'small font-weight-bold mb-1']);
echo html_writer::start_div('form-check');
$hasprogattrs = [
    'type' => 'checkbox', 'name' => 'filterhasprograms', 'id' => 'filterhasprograms',
    'value' => '1', 'class' => 'form-check-input',
];
if ($filterhasprograms) $hasprogattrs['checked'] = 'checked';
echo html_writer::empty_tag('input', $hasprogattrs);
echo html_writer::label(get_string('filter_hasprograms', 'local_completionhistory'), 'filterhasprograms', true, ['class' => 'form-check-label small']);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('form-group col-md-5');
echo html_writer::label(get_string('filter_programs', 'local_completionhistory'), 'filterprogramselector', true, ['class' => 'small font-weight-bold']);
echo html_writer::tag('small', get_string('filter_programs_help', 'local_completionhistory'), ['class' => 'd-block text-muted mb-1']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'filterprogramids', 'id' => 'filterprogramids', 'value' => $filterprogramids]);
$selopts = ['id' => 'filterprogramselector', 'multiple' => 'multiple', 'class' => 'form-control form-control-sm', 'size' => '4'];
if (empty($availableprograms)) $selopts['disabled'] = 'disabled';
echo html_writer::start_tag('select', $selopts);
foreach ($availableprograms as $prog) {
    $optattrs = ['value' => $prog->programid];
    if (in_array((int)$prog->programid, $programids)) $optattrs['selected'] = 'selected';
    echo html_writer::tag('option', format_string($prog->programname_snapshot), $optattrs);
}
echo html_writer::end_tag('select');
echo html_writer::end_div();

echo html_writer::end_div(); // row 3

// ── Row 4: unified columns manager (visibility + order) ─────────────────────
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

// 4a: checkbox grid — one checkbox per known column. Checked = visible.
echo html_writer::start_div('d-flex flex-wrap mb-2', ['id' => 'col-checkbox-list']);
foreach ($allcollabels as $colname => $label) {
    $cbattrs = [
        'type'        => 'checkbox',
        'id'          => 'colcb_' . $colname,
        'value'       => '1',
        'class'       => 'form-check-input lch-col-toggle',
        'data-col'    => $colname,
        'data-label'  => $label,
    ];
    if (isset($orderedvisible[$colname])) {
        $cbattrs['checked'] = 'checked';
    }
    echo html_writer::start_div('form-check mr-4 mb-1');
    echo html_writer::empty_tag('input', $cbattrs);
    echo html_writer::label($label, 'colcb_' . $colname, true, ['class' => 'form-check-label small']);
    echo html_writer::end_div();
}
echo html_writer::end_div();

// 4b: draggable badge list — shows visible columns in display order.
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

// Hidden input holds the authoritative ordered list of visible columns.
echo html_writer::empty_tag('input', [
    'type'  => 'hidden',
    'name'  => 'visiblecols',
    'id'    => 'visiblecols-input',
    'value' => implode(',', array_keys($orderedvisible)),
]);

echo html_writer::end_div();
echo html_writer::end_div(); // row 4

// ── Submit / reset ──────────────────────────────────────────────────────────
echo html_writer::start_div('form-row');
echo html_writer::start_div('col-12');
echo html_writer::empty_tag('input', [
    'type' => 'submit', 'value' => get_string('search'),
    'class' => 'btn btn-primary btn-sm mr-2',
]);
echo html_writer::tag('a', get_string('reset'),
    ['href' => $reseturl->out(false), 'class' => 'btn btn-secondary btn-sm mr-2']);

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
// JavaScript: drag-and-drop column reorder + program multi-select sync.
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

    // ── Checkbox toggle: add/remove badge in drag list ────────────────────
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

    // ── Drag-and-drop reorder ─────────────────────────────────────────────
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

    // Always resync on submit so the hidden input is authoritative.
    var form = document.getElementById("ledger-filter-form");
    if (form) form.addEventListener("submit", syncHidden);

    // ── Program multi-select → hidden input ──────────────────────────────
    var sel        = document.getElementById("filterprogramselector");
    var proghidden = document.getElementById("filterprogramids");
    if (sel && proghidden && form) {
        var saved = (proghidden.value || "").split(",").filter(Boolean);
        Array.from(sel.options).forEach(function (o) {
            if (saved.indexOf(o.value) !== -1) o.selected = true;
        });
        form.addEventListener("submit", function () {
            var ids = Array.from(sel.selectedOptions).map(function (o) { return o.value; });
            proghidden.value = ids.join(",");
        });
    }
})();
');

// ---------------------------------------------------------------------------
// Build SQL WHERE conditions.
// ---------------------------------------------------------------------------
$conditions = ['1 = 1'];
$params = [];

if ($filteruserid > 0) {
    $conditions[] = 'a.userid = :filteruserid';
    $params['filteruserid'] = $filteruserid;
}
if (!empty($filtercoursename)) {
    $conditions[] = $DB->sql_like('a.coursename_snapshot', ':filtercoursename', false);
    $params['filtercoursename'] = '%' . $DB->sql_like_escape($filtercoursename) . '%';
}
if (!empty($filtersource)) {
    $conditions[] = $DB->sql_like('a.source_component', ':filtersource', false);
    $params['filtersource'] = '%' . $DB->sql_like_escape($filtersource) . '%';
}
if ($filterpassed === '1') {
    $conditions[] = 'a.grade_passed = 1';
} elseif ($filterpassed === '0') {
    $conditions[] = 'a.grade_passed = 0';
} elseif ($filterpassed === 'null') {
    $conditions[] = 'a.grade_passed IS NULL';
}
if (!empty($programids)) {
    [$insql, $inparams] = $DB->get_in_or_equal($programids, SQL_PARAMS_NAMED, 'progid');
    $conditions[] = "EXISTS (SELECT 1 FROM {local_completionhistory_ach_program} ap WHERE ap.achievementid = a.id AND ap.programid $insql)";
    $params = array_merge($params, $inparams);
} elseif ($filterhasprograms) {
    $conditions[] = "EXISTS (SELECT 1 FROM {local_completionhistory_ach_program} ap WHERE ap.achievementid = a.id)";
}
if (!empty($filterdatefrom)) {
    $ts = strtotime($filterdatefrom);
    if ($ts !== false) {
        $conditions[] = 'a.completiontime >= :filterdatefrom';
        $params['filterdatefrom'] = $ts;
    }
}
if (!empty($filterdateto)) {
    $ts = strtotime($filterdateto . ' 23:59:59');
    if ($ts !== false) {
        $conditions[] = 'a.completiontime <= :filterdateto';
        $params['filterdateto'] = $ts;
    }
}

// Collapse duplicates: keep one canonical achievement per (userid, courseid).
// Canonical row wins on (grade_passed DESC NULLS LAST, completiontime DESC, id DESC)
// so a passing attempt beats a failing one, and the most recent beats older ones.
$conditions[] = "NOT EXISTS (
    SELECT 1 FROM {local_completionhistory_achievement} a2
     WHERE a2.userid = a.userid
       AND a2.courseid = a.courseid
       AND a2.id <> a.id
       AND (
            COALESCE(a2.grade_passed, -1) > COALESCE(a.grade_passed, -1)
         OR (COALESCE(a2.grade_passed, -1) = COALESCE(a.grade_passed, -1)
             AND a2.completiontime > a.completiontime)
         OR (COALESCE(a2.grade_passed, -1) = COALESCE(a.grade_passed, -1)
             AND a2.completiontime = a.completiontime
             AND a2.id > a.id)
       )
)";

$where = implode(' AND ', $conditions);

// ---------------------------------------------------------------------------
// Build table base URL (carries all filter + column-order state).
// ---------------------------------------------------------------------------
$urlparams = array_filter([
    'filteruserid'      => $filteruserid ?: null,
    'filtercoursename'  => $filtercoursename ?: null,
    'filtersource'      => $filtersource ?: null,
    'filterpassed'      => $filterpassed ?: null,
    'filterhasprograms' => $filterhasprograms ?: null,
    'filterprogramids'  => $filterprogramids ?: null,
    'filterdatefrom'    => $filterdatefrom ?: null,
    'filterdateto'      => $filterdateto ?: null,
    'visiblecols'       => $usingdefaultcols ? null : implode(',', array_keys($orderedvisible)),
], fn($v) => $v !== null && $v !== '');
$tableurl = new moodle_url('/local/completionhistory/achievement_ledger.php', $urlparams);

// ---------------------------------------------------------------------------
// Render table.
// ---------------------------------------------------------------------------
$table = new achievements_table(
    'local_completionhistory_ledger',
    true,                    // showuser (staff view)
    array_keys($orderedvisible)
);

$table->set_sql(
    'a.*,
     cc.timeenrolled                         AS enroldate,
     u.firstname                             AS user_firstname,
     u.lastname                              AS user_lastname,
     u.email                                 AS user_email',
    '{local_completionhistory_achievement} a
     LEFT JOIN {course_completions} cc ON cc.userid = a.userid AND cc.course = a.courseid
     LEFT JOIN {user} u                ON u.id = a.userid',
    $where,
    $params
);

$table->define_baseurl($tableurl);
$table->out(50, true);

echo $OUTPUT->footer();
