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
 * AJAX endpoint: returns the attempt history fragment for a user + course.
 *
 * Called by the local_completionhistory/attempt_details AMD module from the
 * Details button in the achievement ledger table. Returns the rendered
 * attempt_history template (an HTML fragment, not a full page).
 *
 * GET params:
 *   userid   (int) — target user
 *   courseid (int) — target course
 *   sesskey  (string) — the session key
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_login();
require_sesskey();

use local_completionhistory\local\exam_attempt_service;
use local_completionhistory\output\attempt_history;

$systemcontext = context_system::instance();
require_capability('local/completionhistory:viewall', $systemcontext);

if (!get_config('local_completionhistory', 'enabled')) {
    throw new moodle_exception('plugindisabled', 'local_completionhistory');
}

$userid   = required_param('userid', PARAM_INT);
$courseid = required_param('courseid', PARAM_INT);

$PAGE->set_context($systemcontext);
$PAGE->set_url(new moodle_url('/local/completionhistory/ajax_get_attempts.php', [
    'userid'   => $userid,
    'courseid' => $courseid,
]));

header('Content-Type: text/html; charset=utf-8');

$attempts = exam_attempt_service::get_attempts($userid, $courseid);
echo $OUTPUT->render(new attempt_history($attempts));
