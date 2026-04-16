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

namespace local_completionhistory\local;

/**
 * Resolves program associations for a course completion.
 *
 * Queries enrol_programs tables to find all active programs that contain
 * a given course for a given user. Gracefully returns empty if
 * enrol_programs is not installed.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class program_context_resolver {

    /** @var bool|null Cached result of table existence check. */
    private static ?bool $tablesexist = null;

    /**
     * Check whether enrol_programs tables are available.
     *
     * @return bool
     */
    private static function programs_available(): bool {
        global $DB;

        if (self::$tablesexist === null) {
            $dbman = $DB->get_manager();
            self::$tablesexist = $dbman->table_exists('enrol_programs_items')
                && $dbman->table_exists('enrol_programs_programs')
                && $dbman->table_exists('enrol_programs_allocations');
        }
        return self::$tablesexist;
    }

    /**
     * Reset the cached table existence check (useful in tests).
     */
    public static function reset_cache(): void {
        self::$tablesexist = null;
    }

    /**
     * Resolve all program associations for a user's course completion.
     *
     * Returns an array of objects with: programid, fullname, idnumber, allocationid.
     *
     * @param int $userid
     * @param int $courseid
     * @return array Array of stdClass objects representing matched programs.
     */
    public static function resolve(int $userid, int $courseid): array {
        global $DB;

        if (!self::programs_available()) {
            return [];
        }

        $sql = "SELECT p.id AS programid, p.fullname, p.idnumber, a.id AS allocationid
                  FROM {enrol_programs_items} i
                  JOIN {enrol_programs_programs} p ON p.id = i.programid
                  JOIN {enrol_programs_allocations} a ON a.programid = p.id AND a.userid = :userid
                 WHERE i.courseid = :courseid
                   AND a.archived = 0";

        return array_values($DB->get_records_sql($sql, [
            'userid' => $userid,
            'courseid' => $courseid,
        ]));
    }
}
