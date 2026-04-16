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
 * My Achievements page — user view of their completion history.
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

$userid = optional_param('userid', $USER->id, PARAM_INT);
$systemcontext = context_system::instance();

// Access control.
if ($userid == $USER->id) {
    require_capability('local/completionhistory:viewown', $systemcontext);
} else {
    require_capability('local/completionhistory:viewall', $systemcontext);
}

if (!get_config('local_completionhistory', 'enabled')) {
    throw new moodle_exception('plugindisabled', 'local_completionhistory');
}

$PAGE->set_context($systemcontext);
$PAGE->set_url(new moodle_url('/local/completionhistory/my_achievements.php', ['userid' => $userid]));
$PAGE->set_title(get_string('myachievements', 'local_completionhistory'));
$PAGE->set_heading(get_string('myachievements', 'local_completionhistory'));
$PAGE->set_pagelayout('standard');

echo $OUTPUT->header();

$table = new achievements_table('local_completionhistory_myachievements', false);
$table->set_sql(
    'a.*',
    '{local_completionhistory_achievement} a',
    'a.userid = :userid',
    ['userid' => $userid]
);
$table->define_baseurl($PAGE->url);
$table->out(50, true);

echo $OUTPUT->footer();
