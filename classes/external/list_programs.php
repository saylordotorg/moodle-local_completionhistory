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

namespace local_completionhistory\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * External function: list enrol_programs programs and their member courses, so
 * the SIS can sync the real program registry (SIS-65) instead of hardwiring it.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class list_programs extends external_api {

    /** Maximum programs returned in one registry snapshot. */
    private const MAX_PROGRAMS = 1000;

    /** Maximum total program-to-course links returned in one snapshot. */
    private const MAX_COURSE_LINKS = 10000;

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function execute(): array {
        global $DB;

        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);
        \local_completionhistory\local\security::require_enabled();
        require_capability('local/completionhistory:integrate', $systemcontext);

        $dbman = $DB->get_manager();
        if (!$dbman->table_exists(new \xmldb_table('enrol_programs_programs')) ||
                !$dbman->table_exists(new \xmldb_table('enrol_programs_items'))) {
            return ['programs' => []];
        }

        $out = [];
        $programs = $DB->get_records(
            'enrol_programs_programs',
            null,
            'idnumber ASC',
            '*',
            0,
            self::MAX_PROGRAMS + 1
        );
        if (count($programs) > self::MAX_PROGRAMS) {
            throw new \moodle_exception('programregistrytoolarge', 'local_completionhistory');
        }

        $linkcount = 0;
        foreach ($programs as $p) {
            $courses = [];
            // Items with a courseid are the program's member courses; structural
            // items (sets/folders) have a null courseid and are skipped.
            $items = $DB->get_records_select(
                'enrol_programs_items',
                'programid = :pid AND courseid IS NOT NULL',
                ['pid' => $p->id],
                'id ASC',
                '*',
                0,
                self::MAX_COURSE_LINKS - $linkcount + 1
            );
            $linkcount += count($items);
            if ($linkcount > self::MAX_COURSE_LINKS) {
                throw new \moodle_exception('programregistrytoolarge', 'local_completionhistory');
            }
            foreach ($items as $item) {
                $course = $DB->get_record('course', ['id' => $item->courseid], 'id, idnumber, shortname, fullname');
                if (!$course) {
                    continue;
                }
                $courses[] = [
                    'courseid'  => (int) $course->id,
                    'idnumber'  => (string) $course->idnumber,
                    'shortname' => (string) $course->shortname,
                    'fullname'  => (string) $course->fullname,
                ];
            }
            $out[] = [
                'programid'   => (int) $p->id,
                'idnumber'    => (string) $p->idnumber,
                'fullname'    => (string) $p->fullname,
                'archived'    => (int) ($p->archived ?? 0),
                'coursecount' => count($courses),
                'courses'     => $courses,
            ];
        }

        return ['programs' => $out];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'programs' => new external_multiple_structure(new external_single_structure([
                'programid'   => new external_value(PARAM_INT, 'Moodle enrol_programs program id'),
                'idnumber'    => new external_value(PARAM_RAW, 'Program idnumber (stable key)'),
                'fullname'    => new external_value(PARAM_RAW, 'Program full name'),
                'archived'    => new external_value(PARAM_INT, 'Whether the program is archived'),
                'coursecount' => new external_value(PARAM_INT, 'Number of member courses'),
                'courses'     => new external_multiple_structure(new external_single_structure([
                    'courseid'  => new external_value(PARAM_INT, 'Moodle course id'),
                    'idnumber'  => new external_value(PARAM_RAW, 'Course idnumber'),
                    'shortname' => new external_value(PARAM_RAW, 'Course short name'),
                    'fullname'  => new external_value(PARAM_RAW, 'Course full name'),
                ])),
            ])),
        ]);
    }
}
