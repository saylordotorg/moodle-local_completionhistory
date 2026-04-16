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
 * Course Replacement Mappings — admin CRUD page.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Use SCRIPT_FILENAME + dirname to handle symlinked plugin directories.
$dir = dirname(dirname(dirname($_SERVER['SCRIPT_FILENAME'] ?? __DIR__)));
require($dir . '/config.php');
require_login();

use local_completionhistory\form\course_mapping_form;
use local_completionhistory\table\course_mappings_table;
use local_completionhistory\local\replacement_service;

$systemcontext = context_system::instance();
require_capability('local/completionhistory:managecoursemap', $systemcontext);

if (!get_config('local_completionhistory', 'enabled')) {
    throw new moodle_exception('plugindisabled', 'local_completionhistory');
}

$action = optional_param('action', 'list', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);

$PAGE->set_context($systemcontext);
$PAGE->set_url(new moodle_url('/local/completionhistory/course_mappings.php'));
$PAGE->set_title(get_string('coursemappings', 'local_completionhistory'));
$PAGE->set_heading(get_string('coursemappings', 'local_completionhistory'));
$PAGE->set_pagelayout('admin');

// Handle delete action.
if ($action === 'delete' && $id > 0) {
    require_sesskey();
    $DB->delete_records('local_completionhistory_course_map', ['id' => $id]);
    \core\notification::success(get_string('mappingdeleted', 'local_completionhistory'));
    redirect(new moodle_url('/local/completionhistory/course_mappings.php'));
}

// Handle add/edit actions.
if ($action === 'add' || $action === 'edit') {
    $form = new course_mapping_form(null, null, 'post', '', null, true, ['action' => $action, 'id' => $id]);

    if ($action === 'edit' && $id > 0) {
        $existing = $DB->get_record('local_completionhistory_course_map', ['id' => $id], '*', MUST_EXIST);
        $form->set_data($existing);
    }

    if ($form->is_cancelled()) {
        redirect(new moodle_url('/local/completionhistory/course_mappings.php'));
    }

    if ($data = $form->get_data()) {
        if ($action === 'edit' && $id > 0) {
            // Update existing mapping.
            $update = new stdClass();
            $update->id = $id;
            $update->oldcourseid = $data->oldcourseid;
            $update->newcourseid = $data->newcourseid;
            $update->migrationrule = $data->migrationrule;
            $update->active = $data->active;
            $update->effectivetime = !empty($data->effectivetime) ? $data->effectivetime : null;
            $update->note = $data->note ?? null;

            // Re-snapshot course names.
            $oldcourse = $DB->get_record('course', ['id' => $data->oldcourseid]);
            $newcourse = $DB->get_record('course', ['id' => $data->newcourseid]);
            $update->oldcourseidnumber_snapshot = $oldcourse ? $oldcourse->idnumber : null;
            $update->oldcoursename_snapshot = $oldcourse ? $oldcourse->fullname : '[deleted]';
            $update->newcourseidnumber_snapshot = $newcourse ? $newcourse->idnumber : null;
            $update->newcoursename_snapshot = $newcourse ? $newcourse->fullname : '[deleted]';

            replacement_service::update_mapping($id, $update);
        } else {
            // Add new mapping.
            replacement_service::add_mapping(
                (int) $data->oldcourseid,
                (int) $data->newcourseid,
                $data->migrationrule,
                $data->note ?? null
            );
        }

        \core\notification::success(get_string('mappingsaved', 'local_completionhistory'));
        redirect(new moodle_url('/local/completionhistory/course_mappings.php'));
    }

    echo $OUTPUT->header();
    echo $OUTPUT->heading($action === 'edit'
        ? get_string('editmapping', 'local_completionhistory')
        : get_string('addmapping', 'local_completionhistory'));
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

// Default: list view.
echo $OUTPUT->header();

// Add mapping button.
$addurl = new moodle_url('/local/completionhistory/course_mappings.php', ['action' => 'add']);
echo html_writer::link($addurl, get_string('addmapping', 'local_completionhistory'), [
    'class' => 'btn btn-primary mb-3',
]);

$table = new course_mappings_table('local_completionhistory_coursemappings');
$table->set_sql('*', '{local_completionhistory_course_map}', '1 = 1', []);
$table->define_baseurl($PAGE->url);
$table->out(50, true);

echo $OUTPUT->footer();
