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
];

$services = [
    'Completion History SIS' => [
        'functions'       => [
            'local_completionhistory_get_user_achievements',
            'local_completionhistory_get_course_replacement',
            'local_completionhistory_get_recent_achievements',
            'local_completionhistory_get_purge_audit',
        ],
        'restrictedusers' => 1,
        'enabled'         => 0,
        'shortname'       => 'completionhistory_sis',
    ],
];
