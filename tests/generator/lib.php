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
 * Test data generator for local_completionhistory.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_completionhistory_generator extends testing_module_generator {

    /** @var int Counter for unique records. */
    protected int $achievementcount = 0;

    /**
     * Create an achievement record directly (bypasses services, useful for test setup).
     *
     * @param array $data Override fields.
     * @return stdClass The created record.
     */
    public function create_achievement(array $data = []): stdClass {
        global $DB;

        $this->achievementcount++;
        $now = time();

        $record = (object) array_merge([
            'ledgeruuid' => \local_completionhistory\local\ledger_service::generate_uuid(),
            'userid' => 0,
            'useridnumber_snapshot' => null,
            'courseid' => null,
            'courseidnumber_snapshot' => null,
            'courseshortname_snapshot' => 'TESTCOURSE' . $this->achievementcount,
            'coursename_snapshot' => 'Test Course ' . $this->achievementcount,
            'completiontime' => $now - 3600,
            'grade_decimal' => null,
            'grade_passed' => null,
            'grade_source' => null,
            'artifacturl' => null,
            'artifactstorage' => null,
            'source_component' => 'phpunit',
            'source_event' => 'test_generator',
            'source_event_hash' => hash('sha256', 'test_' . $this->achievementcount . '_' . $now . '_' . random_int(0, 999999)),
            'timecreated' => $now,
        ], $data);

        $record->id = $DB->insert_record('local_completionhistory_achievement', $record);
        return $record;
    }

    /**
     * Create a course mapping record.
     *
     * @param array $data Override fields.
     * @return stdClass
     */
    public function create_course_mapping(array $data = []): stdClass {
        global $DB;

        $now = time();
        $record = (object) array_merge([
            'oldcourseid' => null,
            'oldcourseidnumber_snapshot' => null,
            'oldcoursename_snapshot' => 'Old Course',
            'newcourseid' => null,
            'newcourseidnumber_snapshot' => null,
            'newcoursename_snapshot' => 'New Course',
            'migrationrule' => 'redirect_incomplete',
            'active' => 1,
            'effectivetime' => null,
            'note' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ], $data);

        $record->id = $DB->insert_record('local_completionhistory_course_map', $record);
        return $record;
    }

    /**
     * Create a purge audit record.
     *
     * @param array $data Override fields.
     * @return stdClass
     */
    public function create_purge_audit(array $data = []): stdClass {
        global $DB;

        $record = (object) array_merge([
            'userid' => 0,
            'programid' => null,
            'reason' => 'test',
            'detailsjson' => null,
            'timecreated' => time(),
        ], $data);

        $record->id = $DB->insert_record('local_completionhistory_purge_audit', $record);
        return $record;
    }
}
