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
 * Event observers for local_completionhistory.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\course_completed',
        'callback'  => '\local_completionhistory\callbacks::course_completed',
    ],
    /*
     * A GRADE CORRECTED AFTER COMPLETION USED TO GO NOWHERE.
     *
     * `course_completed` fires once and snapshots the gradebook total at that moment. Nothing
     * observed a later change, so a teacher regrading an exam left the SIS -- and therefore the
     * student's record page and any transcript printed from it -- showing the original figure for
     * ever. `reconcile_ledger` did not save it either: `backfill_service` is insert-only and counts
     * an existing row as `skipped`, so it fills gaps and never revises.
     *
     * Only the COURSE TOTAL is acted on; see the callback. Reacting to every activity grade would
     * enqueue a sync per quiz question edit.
     */
    [
        'eventname' => '\core\event\user_graded',
        'callback'  => '\local_completionhistory\callbacks::user_graded',
    ],
    [
        'eventname' => '\core\event\course_deleted',
        'callback'  => '\local_completionhistory\callbacks::course_deleted',
    ],
    [
        'eventname' => '\core\event\course_updated',
        'callback'  => '\local_completionhistory\callbacks::course_updated',
    ],
    [
        'eventname' => '\core\event\user_deleted',
        'callback'  => '\local_completionhistory\callbacks::user_deleted',
    ],
    [
        'eventname' => '\mod_quiz\event\attempt_submitted',
        'callback'  => '\local_completionhistory\callbacks::quiz_attempt_submitted',
    ],
    [
        'eventname' => '\tool_certificate\event\certificate_issued',
        'callback'  => '\local_completionhistory\callbacks::certificate_issued',
    ],
    [
        'eventname' => '\tool_certificate\event\certificate_revoked',
        'callback'  => '\local_completionhistory\callbacks::certificate_revoked',
    ],
];
