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
 * Manage System Flags — list view with CRUD actions.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$dir = dirname(dirname(dirname($_SERVER['SCRIPT_FILENAME'] ?? __DIR__)));
require($dir . '/config.php');
require_login();

use local_completionhistory\local\flag_service;

$systemcontext = context_system::instance();
require_capability('local/completionhistory:manage', $systemcontext);

if (!get_config('local_completionhistory', 'enabled')) {
    throw new moodle_exception('plugindisabled', 'local_completionhistory');
}

$PAGE->set_context($systemcontext);
$PAGE->set_url(new moodle_url('/local/completionhistory/manage_flags.php'));
$PAGE->set_title(get_string('manageflags', 'local_completionhistory'));
$PAGE->set_heading(get_string('manageflags', 'local_completionhistory'));
$PAGE->set_pagelayout('admin');

// ── Handle toggle enable/disable and delete ─────────────────────────────────
$action = optional_param('action', '', PARAM_ALPHA);
$flagid = optional_param('id', 0, PARAM_INT);

if ($action === 'loadpresets') {
    require_sesskey();
    $inserted = flag_service::load_presets();
    redirect($PAGE->url,
        get_string('flagspresetsloaded', 'local_completionhistory', $inserted),
        null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($action && $flagid) {
    require_sesskey();
    global $DB;
    $flag = $DB->get_record('local_completionhistory_flag_def', ['id' => $flagid], '*', MUST_EXIST);

    if ($action === 'toggle') {
        $flag->enabled = $flag->enabled ? 0 : 1;
        flag_service::save($flag);
        redirect($PAGE->url,
            get_string($flag->enabled ? 'flagenabled' : 'flagdisabled', 'local_completionhistory'),
            null, \core\output\notification::NOTIFY_SUCCESS);
    }

    if ($action === 'delete') {
        flag_service::delete((int) $flag->id);
        redirect($PAGE->url,
            get_string('flagdeleted', 'local_completionhistory'),
            null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

echo $OUTPUT->header();

$editurl    = new moodle_url('/local/completionhistory/edit_flag.php');
$presetsurl = new moodle_url($PAGE->url, ['action' => 'loadpresets', 'sesskey' => sesskey()]);

echo html_writer::tag('a',
    '&#43; ' . get_string('addflag', 'local_completionhistory'),
    ['href' => $editurl->out(false), 'class' => 'btn btn-primary btn-sm mr-2 mb-3']);

echo html_writer::tag('a',
    '&#8681; ' . get_string('flagsloadpresets', 'local_completionhistory'),
    [
        'href'    => $presetsurl->out(false),
        'class'   => 'btn btn-outline-secondary btn-sm mb-3',
        'title'   => get_string('flagsloadpresets_help', 'local_completionhistory'),
        'onclick' => 'return confirm(' . json_encode(get_string('flagsloadpresets_confirm', 'local_completionhistory')) . ');',
    ]);

global $DB;
$flags = $DB->get_records('local_completionhistory_flag_def', null, 'severity DESC, name ASC');

if (empty($flags)) {
    echo html_writer::tag('p', get_string('flags_none', 'local_completionhistory'),
        ['class' => 'alert alert-info']);
    echo $OUTPUT->footer();
    return;
}

$typelabels = flag_service::type_labels();
$sevlabels  = flag_service::severity_labels();

$table = new html_table();
$table->head = [
    get_string('flag_name',        'local_completionhistory'),
    get_string('flag_code',        'local_completionhistory'),
    get_string('flag_type',        'local_completionhistory'),
    get_string('flag_severity',    'local_completionhistory'),
    get_string('flag_config',      'local_completionhistory'),
    get_string('flag_enabled',     'local_completionhistory'),
    get_string('actions'),
];
$table->attributes = ['class' => 'generaltable'];

foreach ($flags as $f) {
    $typelabel = $typelabels[$f->flag_type] ?? $f->flag_type;
    $sevlabel  = $sevlabels[$f->severity]    ?? $f->severity;
    $sevcls    = flag_service::severity_badge_class($f->severity);

    $configsummary = '';
    $config = json_decode($f->configjson ?? '', true) ?: [];
    if (!empty($config)) {
        $pairs = [];
        foreach ($config as $k => $v) {
            $pairs[] = s($k) . '=' . s(is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v);
        }
        $configsummary = implode(', ', $pairs);
    }

    $editlink = html_writer::link(
        new moodle_url('/local/completionhistory/edit_flag.php', ['id' => $f->id]),
        get_string('edit'),
        ['class' => 'btn btn-outline-secondary btn-sm mr-1']
    );
    $toggleurl = new moodle_url('/local/completionhistory/manage_flags.php', [
        'action' => 'toggle', 'id' => $f->id, 'sesskey' => sesskey(),
    ]);
    $togglelabel = $f->enabled
        ? get_string('flagdisable', 'local_completionhistory')
        : get_string('flagenable',  'local_completionhistory');
    $togglelink = html_writer::link($toggleurl->out(false), $togglelabel,
        ['class' => 'btn btn-outline-' . ($f->enabled ? 'warning' : 'success') . ' btn-sm mr-1']);

    $deleteurl = new moodle_url('/local/completionhistory/manage_flags.php', [
        'action' => 'delete', 'id' => $f->id, 'sesskey' => sesskey(),
    ]);
    $deletelink = html_writer::link($deleteurl->out(false), get_string('delete'), [
        'class'   => 'btn btn-outline-danger btn-sm',
        'onclick' => 'return confirm(' . json_encode(get_string('flagdelete_confirm', 'local_completionhistory')) . ');',
    ]);

    $enabledbadge = $f->enabled
        ? html_writer::tag('span', get_string('yes'), ['class' => 'badge badge-success'])
        : html_writer::tag('span', get_string('no'),  ['class' => 'badge badge-secondary']);

    $table->data[] = [
        s($f->name),
        html_writer::tag('code', s($f->code)),
        s($typelabel),
        html_writer::tag('span', s($sevlabel), ['class' => "badge {$sevcls}"]),
        $configsummary ? html_writer::tag('small', $configsummary, ['class' => 'text-muted']) : '-',
        $enabledbadge,
        $editlink . $togglelink . $deletelink,
    ];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
