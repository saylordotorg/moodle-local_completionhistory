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
 * Upgrade steps for local_completionhistory.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_local_completionhistory_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026041701) {
        $table = new xmldb_table('local_completionhistory_achievement');

        // firstname_snapshot — add after useridnumber_snapshot.
        $field = new xmldb_field('firstname_snapshot', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'useridnumber_snapshot');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // lastname_snapshot — add after firstname_snapshot.
        $field = new xmldb_field('lastname_snapshot', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'firstname_snapshot');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // email_snapshot — add after lastname_snapshot.
        $field = new xmldb_field('email_snapshot', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'lastname_snapshot');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // enrolledtime_snapshot — add after completiontime.
        $field = new xmldb_field('enrolledtime_snapshot', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'completiontime');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026041701, 'local', 'completionhistory');
    }

    return true;
}
