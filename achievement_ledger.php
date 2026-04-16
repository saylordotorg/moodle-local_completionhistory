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

// Use SCRIPT_FILENAME + dirname to handle symlinked plugin directories.
$dir = dirname(dirname(dirname($_SERVER['SCRIPT_FILENAME'] ?? __DIR__)));
require($dir . '/config.php');
require_login();

use local_completionhistory\table\achievements_table;

$systemcontext = context_system::instance();
require_capability('local/completionhistory:viewall', $systemcontext);

if (!get_config('local_completionhistory', 'enabled')) {
    throw new moodle_exception('plugindisabled', 'local_completionhistory');
}

// Filter parameters.
$filteruserid = optional_param('filteruserid', 0, PARAM_INT);
$filtercoursename = optional_param('filtercoursename', '', PARAM_TEXT);
$filtersource = optional_param('filtersource', '', PARAM_TEXT);
$filterdatefrom = optional_param('filterdatefrom', 0, PARAM_INT);
$filterdateto = optional_param('filterdateto', 0, PARAM_INT);

$PAGE->set_context($systemcontext);
$PAGE->set_url(new moodle_url('/local/completionhistory/achievement_ledger.php'));
$PAGE->set_title(get_string('achievementledger', 'local_completionhistory'));
$PAGE->set_heading(get_string('achievementledger', 'local_completionhistory'));
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();

// Filter form.
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $PAGE->url->out_omit_querystring(), 'class' => 'mb-3']);
echo html_writer::start_div('form-inline');

echo html_writer::label(get_string('col_user', 'local_completionhistory') . ': ', 'filteruserid', true, ['class' => 'mr-2']);
echo html_writer::empty_tag('input', [
    'type' => 'number', 'name' => 'filteruserid', 'id' => 'filteruserid',
    'value' => $filteruserid ?: '', 'class' => 'form-control mr-3', 'placeholder' => 'User ID',
    'style' => 'width: 120px',
]);

echo html_writer::label(get_string('col_coursename', 'local_completionhistory') . ': ', 'filtercoursename', true, ['class' => 'mr-2']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'filtercoursename', 'id' => 'filtercoursename',
    'value' => $filtercoursename, 'class' => 'form-control mr-3', 'placeholder' => 'Course name',
    'style' => 'width: 200px',
]);

echo html_writer::label(get_string('col_source', 'local_completionhistory') . ': ', 'filtersource', true, ['class' => 'mr-2']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'filtersource', 'id' => 'filtersource',
    'value' => $filtersource, 'class' => 'form-control mr-3', 'placeholder' => 'Source',
    'style' => 'width: 160px',
]);

echo html_writer::empty_tag('input', [
    'type' => 'submit', 'value' => get_string('search'),
    'class' => 'btn btn-primary mr-2',
]);

echo html_writer::end_div();
echo html_writer::end_tag('form');

// Build SQL conditions.
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
if ($filterdatefrom > 0) {
    $conditions[] = 'a.completiontime >= :filterdatefrom';
    $params['filterdatefrom'] = $filterdatefrom;
}
if ($filterdateto > 0) {
    $conditions[] = 'a.completiontime <= :filterdateto';
    $params['filterdateto'] = $filterdateto;
}

$where = implode(' AND ', $conditions);

$table = new achievements_table('local_completionhistory_ledger', true);
$table->set_sql('a.*', '{local_completionhistory_achievement} a', $where, $params);
$table->define_baseurl($PAGE->url);
$table->out(50, true);

echo $OUTPUT->footer();
