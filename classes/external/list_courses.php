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
 * External function returning the course catalog and its category tree.
 *
 * WHY THIS EXISTS. The SIS course catalog had never synchronised — `course` and
 * `course_category` were both empty — because `syncCourses()` in the SIS calls
 * `core_course_get_courses` and `core_course_get_categories`, and the SIS token is
 * scoped to the `completionhistory_sis` external service, which authorises only
 * this plugin's own functions. Every call returned
 * `webservice_access_exception`, silently, since nothing was watching. Meanwhile
 * the program registry synced fine, because programs go through this plugin's
 * `list_programs` wrapper.
 *
 * So this is the missing wrapper, following exactly the precedent `list_programs`
 * set. The alternative — adding `core_course_get_*` to the shared service — is
 * fewer lines but widens the SIS token's reach into core, and a token that can read
 * core can usually read more than the job needs.
 *
 * SHAPE. Field names deliberately mirror `core_course_get_courses` and
 * `core_course_get_categories` (`id`, `categoryid`, `idnumber`, `shortname`,
 * `fullname`, `visible`, `sortorder`, and `name`/`parent`/`coursecount` for
 * categories) so the SIS can consume this by changing the function name and
 * nothing else. Returning a different shape would have meant changing the
 * ingestion at the same time as the transport, which is two variables at once.
 *
 * The site front page (course id 1) is returned rather than filtered here,
 * because the caller already filters it and a wrapper that silently drops rows is
 * harder to reason about than one that does not.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class list_courses extends external_api {

    /** Maximum rows returned by this snapshot-style catalog endpoint. */
    private const MAX_CATALOG_ROWS = 5000;

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'includehidden' => new external_value(PARAM_BOOL,
                'Include courses with visible=0 (default true — the SIS records visibility rather than filtering on it)',
                VALUE_DEFAULT, true),
        ]);
    }

    /**
     * @param bool $includehidden Include invisible courses.
     * @return array
     */
    public static function execute(bool $includehidden = true): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'includehidden' => $includehidden,
        ]);

        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);
        \local_completionhistory\local\security::require_enabled();
        // Same capability as the sibling read functions. The catalog is not
        // per-student data, but it is still an authenticated read.
        require_capability('local/completionhistory:integrate', $systemcontext);

        // Read the tables directly rather than through get_courses(), which loads
        // full course objects including summaries and format options the SIS does
        // not use. On a catalog of any size that is a large amount of wasted work.
        $where = $params['includehidden'] ? '' : 'WHERE c.visible = 1';
        $courses = $DB->get_records_sql(
            "SELECT c.id, c.category AS categoryid, c.idnumber, c.shortname, c.fullname,
                    c.visible, c.sortorder, c.timemodified
               FROM {course} c
               {$where}
           ORDER BY c.sortorder ASC, c.id ASC",
            [],
            0,
            self::MAX_CATALOG_ROWS + 1
        );

        $categories = $DB->get_records_sql(
            "SELECT cc.id, cc.name, cc.parent, cc.sortorder, cc.coursecount, cc.visible, cc.idnumber
               FROM {course_categories} cc
           ORDER BY cc.sortorder ASC, cc.id ASC",
            [],
            0,
            self::MAX_CATALOG_ROWS + 1
        );
        if (count($courses) > self::MAX_CATALOG_ROWS || count($categories) > self::MAX_CATALOG_ROWS) {
            throw new \moodle_exception('catalogtoolarge', 'local_completionhistory');
        }

        $outcourses = [];
        foreach ($courses as $c) {
            $outcourses[] = [
                'id'           => (int) $c->id,
                'categoryid'   => (int) $c->categoryid,
                'idnumber'     => (string) ($c->idnumber ?? ''),
                'shortname'    => (string) ($c->shortname ?? ''),
                'fullname'     => (string) ($c->fullname ?? ''),
                'visible'      => (int) $c->visible,
                'sortorder'    => (int) $c->sortorder,
                'timemodified' => (int) ($c->timemodified ?? 0),
            ];
        }

        $outcategories = [];
        foreach ($categories as $cc) {
            $outcategories[] = [
                'id'          => (int) $cc->id,
                'name'        => (string) ($cc->name ?? ''),
                'parent'      => (int) ($cc->parent ?? 0),
                'sortorder'   => (int) ($cc->sortorder ?? 0),
                'coursecount' => (int) ($cc->coursecount ?? 0),
                'visible'     => (int) ($cc->visible ?? 1),
                'idnumber'    => (string) ($cc->idnumber ?? ''),
            ];
        }

        return [
            'courses'    => $outcourses,
            'categories' => $outcategories,
            // Counts returned explicitly so a caller can assert it received what
            // the server thinks it sent, rather than inferring from array length
            // after a truncated transfer.
            'coursecount'   => count($outcourses),
            'categorycount' => count($outcategories),
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courses' => new external_multiple_structure(
                new external_single_structure([
                    'id'           => new external_value(PARAM_INT,  'Moodle internal course id — VOLATILE across delete/restore'),
                    'categoryid'   => new external_value(PARAM_INT,  'Category id'),
                    'idnumber'     => new external_value(PARAM_TEXT, 'Stable human-readable course key used throughout the SIS'),
                    'shortname'    => new external_value(PARAM_TEXT, 'Course shortname'),
                    'fullname'     => new external_value(PARAM_TEXT, 'Course fullname'),
                    'visible'      => new external_value(PARAM_INT,  '1 visible, 0 hidden'),
                    'sortorder'    => new external_value(PARAM_INT,  'Sort order'),
                    'timemodified' => new external_value(PARAM_INT,  'Last modification timestamp'),
                ]),
                'Courses, including the site front page (id 1) — the caller filters it'
            ),
            'categories' => new external_multiple_structure(
                new external_single_structure([
                    'id'          => new external_value(PARAM_INT,  'Category id'),
                    'name'        => new external_value(PARAM_TEXT, 'Category name'),
                    'parent'      => new external_value(PARAM_INT,  'Parent category id, 0 at top level'),
                    'sortorder'   => new external_value(PARAM_INT,  'Sort order'),
                    'coursecount' => new external_value(PARAM_INT,  'Courses directly in this category'),
                    'visible'     => new external_value(PARAM_INT,  '1 visible, 0 hidden'),
                    'idnumber'    => new external_value(PARAM_TEXT, 'Category idnumber'),
                ]),
                'Category tree'
            ),
            'coursecount'   => new external_value(PARAM_INT, 'Courses returned'),
            'categorycount' => new external_value(PARAM_INT, 'Categories returned'),
        ]);
    }
}
