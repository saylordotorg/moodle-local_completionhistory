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
 * AJAX endpoint: returns attempt history HTML for a user + course.
 *
 * Called by the expand button in the achievement ledger table.
 * Returns a self-contained HTML fragment (not a full page).
 *
 * GET params:
 *   userid   (int) — target user
 *   courseid (int) — target course
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_login();

use local_completionhistory\local\exam_attempt_service;
use local_completionhistory\local\course_config_service;

$systemcontext = context_system::instance();
require_capability('local/completionhistory:viewall', $systemcontext);

if (!get_config('local_completionhistory', 'enabled')) {
    throw new moodle_exception('plugindisabled', 'local_completionhistory');
}

$userid   = required_param('userid',   PARAM_INT);
$courseid = required_param('courseid', PARAM_INT);

header('Content-Type: text/html; charset=utf-8');

$attempts = exam_attempt_service::get_attempts($userid, $courseid);

if (empty($attempts)) {
    echo '<p class="text-muted m-2"><em>No individual attempt records found for this course.</em></p>';
    exit;
}

$track_labels = [
    course_config_service::TRACK_PROGRAM_FINAL => 'Program Final',
    course_config_service::TRACK_DIRECT_CREDIT => 'Direct Credit',
    course_config_service::TRACK_CERTIFICATE   => 'Certificate',
];

$track_badge = [
    course_config_service::TRACK_PROGRAM_FINAL => 'badge-primary',
    course_config_service::TRACK_DIRECT_CREDIT => 'badge-info',
    course_config_service::TRACK_CERTIFICATE   => 'badge-success',
];

echo '<div class="p-2" style="background:#f8f9fa;">';
echo '<table class="table table-sm table-bordered mb-0" style="font-size:0.85em;">';
echo '<thead class="thead-light">';
echo '<tr>';
echo '<th>Track</th>';
echo '<th>Attempt</th>';
echo '<th>Grade</th>';
echo '<th>Result</th>';
echo '<th>Date</th>';
echo '</tr>';
echo '</thead>';
echo '<tbody>';

foreach ($attempts as $a) {
    $trackname  = $track_labels[$a->exam_track] ?? $a->exam_track;
    $trackclass = $track_badge[$a->exam_track]  ?? 'badge-secondary';

    $allowed_label = ((int) $a->attempts_allowed === 0) ? '∞' : (int) $a->attempts_allowed;
    $attempt_label = "{$a->attempt_number} / {$allowed_label}";

    if ($a->grade_decimal !== null) {
        $grade_label = number_format((float) $a->grade_decimal, 1) . '%';
    } else {
        $grade_label = '—';
    }

    if ($a->grade_passed === null || $a->grade_passed === '') {
        $result_html = '<span class="badge badge-secondary">N/A</span>';
    } elseif ((int) $a->grade_passed === 1) {
        $icon = $a->resulted_in_completion ? '&#10003; Passed &#127775;' : '&#10003; Passed';
        $result_html = '<span class="badge badge-success">' . $icon . '</span>';
    } else {
        $exhausted = ((int) $a->attempts_allowed > 0 && (int) $a->attempt_number >= (int) $a->attempts_allowed);
        $icon = $exhausted ? '&#10007; Failed (track exhausted)' : '&#10007; Failed';
        $result_html = '<span class="badge badge-danger">' . $icon . '</span>';
    }

    $date_label = userdate((int) $a->timetaken, '%m/%d/%Y');

    echo '<tr' . ($a->resulted_in_completion ? ' class="table-success"' : '') . '>';
    echo '<td><span class="badge ' . $trackclass . '" style="font-size:0.8em">' . htmlspecialchars($trackname) . '</span></td>';
    echo '<td>' . htmlspecialchars($attempt_label) . '</td>';
    echo '<td>' . htmlspecialchars($grade_label) . '</td>';
    echo '<td>' . $result_html . '</td>';
    echo '<td>' . htmlspecialchars($date_label) . '</td>';
    echo '</tr>';
}

echo '</tbody>';
echo '</table>';
echo '</div>';
