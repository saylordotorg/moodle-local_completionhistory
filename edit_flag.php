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
 * The form, its validation and the type-specific show/hide rules live in
 * {@see \local_completionhistory\form\flag_form}; this page only loads the
 * definition, saves it and redirects.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_login();

use local_completionhistory\form\flag_form;
use local_completionhistory\local\flag_service;

$systemcontext = context_system::instance();
require_capability('local/completionhistory:manage', $systemcontext);

if (!get_config('local_completionhistory', 'enabled')) {
    throw new moodle_exception('plugindisabled', 'local_completionhistory');
}

$id      = optional_param('id', 0, PARAM_INT);
$listurl = new moodle_url('/local/completionhistory/manage_flags.php');
$pageurl = new moodle_url('/local/completionhistory/edit_flag.php', ['id' => $id]);

$PAGE->set_context($systemcontext);
$PAGE->set_url($pageurl);
$PAGE->set_title(get_string($id ? 'editflag' : 'addflag', 'local_completionhistory'));
$PAGE->set_heading(get_string($id ? 'editflag' : 'addflag', 'local_completionhistory'));
$PAGE->set_pagelayout('admin');

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

$form = new flag_form($pageurl);

if ($form->is_cancelled()) {
    redirect($listurl);
}

if ($data = $form->get_data()) {
    $flag->code        = trim($data->code);
    $flag->name        = trim($data->name);
    $flag->description = trim((string) ($data->description ?? ''));
    $flag->flag_type   = $data->flag_type;
    $flag->severity    = $data->severity;
    $flag->enabled     = !empty($data->enabled) ? 1 : 0;
    $flag->configjson  = json_encode(flag_form::build_config($data));

    flag_service::save($flag);
    redirect(
        $listurl,
        get_string('flagsaved', 'local_completionhistory'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Defaults: the stored definition plus its decoded configuration values.
$defaults = clone $flag;
foreach ($config as $key => $value) {
    $defaults->$key = is_bool($value) ? (int) $value : $value;
}
$form->set_data($defaults);

echo $OUTPUT->header();
$form->display();
echo $OUTPUT->footer();
