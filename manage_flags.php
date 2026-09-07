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
 * The list itself is {@see \local_completionhistory\output\flags_list}; this
 * page handles the sesskey-protected POST actions and redirects.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_login();

use local_completionhistory\local\flag_service;
use local_completionhistory\output\flags_list;

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

// Actions: load presets, toggle enabled, delete. All are sesskey-protected POSTs.
$action = optional_param('action', '', PARAM_ALPHA);
$flagid = optional_param('id', 0, PARAM_INT);

if ($action === 'loadpresets') {
    if (!data_submitted()) {
        throw new moodle_exception('invalidrequest');
    }
    require_sesskey();
    $inserted = flag_service::load_presets();
    redirect(
        $PAGE->url,
        get_string('flagspresetsloaded', 'local_completionhistory', $inserted),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action && $flagid) {
    if (!data_submitted()) {
        throw new moodle_exception('invalidrequest');
    }
    require_sesskey();
    $flag = $DB->get_record('local_completionhistory_flag_def', ['id' => $flagid], '*', MUST_EXIST);

    if ($action === 'toggle') {
        $flag->enabled = $flag->enabled ? 0 : 1;
        flag_service::save($flag);
        redirect(
            $PAGE->url,
            get_string($flag->enabled ? 'flagenabled' : 'flagdisabled', 'local_completionhistory'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    if ($action === 'delete') {
        flag_service::delete((int) $flag->id);
        redirect(
            $PAGE->url,
            get_string('flagdeleted', 'local_completionhistory'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

$flags = $DB->get_records('local_completionhistory_flag_def', null, 'severity DESC, name ASC');

echo $OUTPUT->header();
echo $OUTPUT->render(new flags_list(array_values($flags)));
echo $OUTPUT->footer();
