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
 * Web service definitions for local_completionhistory.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_completionhistory_get_user_achievements' => [
        'classname'    => 'local_completionhistory\external\get_user_achievements',
        'description'  => 'Retrieve achievement records for a user.',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/completionhistory:viewown',
    ],
    'local_completionhistory_get_course_replacement' => [
        'classname'    => 'local_completionhistory\external\get_course_replacement',
        'description'  => 'Get the replacement mapping for a retired course.',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/completionhistory:viewown',
    ],
    'local_completionhistory_get_recent_achievements' => [
        'classname'    => 'local_completionhistory\external\get_recent_achievements',
        'description'  => 'Retrieve recent achievement records for SIS sync.',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/completionhistory:viewall',
    ],
    'local_completionhistory_get_purge_audit' => [
        'classname'    => 'local_completionhistory\external\get_purge_audit',
        'description'  => 'Retrieve purge audit records.',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/completionhistory:manage',
    ],
    'local_completionhistory_get_unsynced_outbox' => [
        'classname'    => 'local_completionhistory\external\get_unsynced_outbox',
        'description'  => 'Retrieve unsynced outbox rows (durable SIS sync queue).',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/completionhistory:viewall',
    ],
    'local_completionhistory_mark_outbox_sent' => [
        'classname'    => 'local_completionhistory\external\mark_outbox_sent',
        'description'  => 'Acknowledge outbox rows after the SIS has consumed them.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'local/completionhistory:manage',
    ],
    'local_completionhistory_provision_applicant' => [
        'classname'    => 'local_completionhistory\external\provision_applicant',
        'description'  => 'Create/find a user by email and allocate to a program (SIS admissions).',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'local/completionhistory:manage',
    ],
    'local_completionhistory_set_password' => [
        'classname'    => 'local_completionhistory\external\set_password',
        'description'  => 'Set a manual-auth user password by email and clear force-change (SIS welcome flow).',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'local/completionhistory:manage',
    ],
    // Proctoring flags for the SIS exam-review queue. The plugin evaluates these
    // rules at render time only, so without this the matches exist nowhere the SIS
    // can see them (SIS-69).
    'local_completionhistory_get_flagged_attempts' => [
        'classname'    => 'local_completionhistory\external\get_flagged_attempts',
        'description'  => 'Exam attempts matching the configured system flags (SIS exam-review case queue).',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/completionhistory:viewall',
    ],
    // Course catalog, wrapping core reads the SIS token is deliberately not
    // authorised for. Without this the SIS course sync had never worked (SIS-44).
    'local_completionhistory_list_courses' => [
        'classname'    => 'local_completionhistory\\external\\list_courses',
        'description'  => 'Course catalog and category tree (SIS course mapping / catalog sync).',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/completionhistory:viewall',
    ],
    // Login and last-access timestamps, which are a different question from
    // academic activity and could not be answered at all before (SIS-43).
    'local_completionhistory_get_user_activity' => [
        'classname'    => 'local_completionhistory\\external\\get_user_activity',
        'description'  => 'Login and last-access timestamps for learners (SIS engagement / last-login reporting).',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/completionhistory:viewall',
    ],
    'local_completionhistory_list_programs' => [
        'classname'    => 'local_completionhistory\external\list_programs',
        'description'  => 'List enrol_programs programs and their member courses (SIS program registry sync).',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/completionhistory:viewall',
    ],
    'local_completionhistory_get_user_inprogress_courses' => [
        'classname'    => 'local_completionhistory\external\get_user_inprogress_courses',
        'description'  => 'Courses a user has started but not completed (SIS requirements/teach-out engine).',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/completionhistory:viewall',
    ],
    'local_completionhistory_enrol_user_in_course' => [
        'classname'    => 'local_completionhistory\external\enrol_user_in_course',
        'description'  => 'Manually enrol a user into a course by email + idnumber (SIS course-window pacer / alumni enrolment).',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'local/completionhistory:manage',
    ],
    'local_completionhistory_set_program_deadline' => [
        'classname'    => 'local_completionhistory\external\set_program_deadline',
        'description'  => 'Set the timeend of a user program allocation (SIS time-to-completion clocks).',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'local/completionhistory:manage',
    ],
    // The one function here that can produce a LOGGED-IN BROWSER SESSION rather than
    // just data. 'write' rather than 'read' for that reason, even though it writes only
    // a key: the type is what an administrator reads when deciding what a token can do,
    // and calling this a read would understate it (SIS-29).
    'local_completionhistory_create_login_key' => [
        'classname'    => 'local_completionhistory\external\create_login_key',
        'description'  => 'Mint a single-use, IP-bound, 60-second key that logs one student into Moodle in a browser (SIS "Open in Moodle" deep links).',
        'type'         => 'write',
        // Not callable from page JavaScript. An AJAX-exposed login-key minter would be
        // reachable with any logged-in user's session cookie, which is a different and
        // much larger attack surface than a server-to-server token.
        'ajax'         => false,
        'capabilities' => 'local/completionhistory:manage',
    ],
];

$services = [
    'Completion History SIS' => [
        'functions'       => [
            'local_completionhistory_get_user_achievements',
            'local_completionhistory_get_course_replacement',
            'local_completionhistory_get_recent_achievements',
            'local_completionhistory_get_purge_audit',
            'local_completionhistory_get_unsynced_outbox',
            'local_completionhistory_mark_outbox_sent',
            'local_completionhistory_provision_applicant',
            'local_completionhistory_set_password',
            'local_completionhistory_get_flagged_attempts',
            'local_completionhistory_list_courses',
            'local_completionhistory_get_user_activity',
            'local_completionhistory_list_programs',
            'local_completionhistory_get_user_inprogress_courses',
            'local_completionhistory_enrol_user_in_course',
            'local_completionhistory_set_program_deadline',
            'local_completionhistory_create_login_key',
        ],
        'restrictedusers' => 1,
        'enabled'         => 0,
        'shortname'       => 'completionhistory_sis',
    ],
];
