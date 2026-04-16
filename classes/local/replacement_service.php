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

use stdClass;

/**
 * Service for managing course replacement mappings.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class replacement_service {

    /**
     * Get the active replacement mapping for an old course.
     *
     * @param int $oldcourseid
     * @return stdClass|null The mapping record, or null if none active.
     */
    public static function get_replacement(int $oldcourseid): ?stdClass {
        global $DB;

        $record = $DB->get_record('local_completionhistory_course_map', [
            'oldcourseid' => $oldcourseid,
            'active' => 1,
        ]);

        return $record ?: null;
    }

    /**
     * Get a replacement recommendation for a user.
     *
     * Only returns a recommendation if the user has NOT already completed
     * the replacement course in the achievement ledger.
     *
     * @param int $userid
     * @param int $oldcourseid
     * @return stdClass|null The mapping record with recommendation, or null.
     */
    public static function get_recommendation_for_user(int $userid, int $oldcourseid): ?stdClass {
        global $DB;

        $mapping = self::get_replacement($oldcourseid);
        if (!$mapping || !$mapping->newcourseid) {
            return null;
        }

        // Check if user already completed the replacement course.
        $alreadycompleted = $DB->record_exists('local_completionhistory_achievement', [
            'userid' => $userid,
            'courseid' => $mapping->newcourseid,
        ]);

        if ($alreadycompleted) {
            return null;
        }

        return $mapping;
    }

    /**
     * Follow the replacement chain for a course (with cycle detection).
     *
     * @param int $courseid Starting course ID.
     * @param int $maxdepth Maximum chain depth to follow.
     * @return array Array of mapping records forming the chain.
     */
    public static function get_chain(int $courseid, int $maxdepth = 10): array {
        $chain = [];
        $seen = [];
        $currentid = $courseid;

        for ($i = 0; $i < $maxdepth; $i++) {
            if (isset($seen[$currentid])) {
                break; // Cycle detected.
            }
            $seen[$currentid] = true;

            $mapping = self::get_replacement($currentid);
            if (!$mapping || !$mapping->newcourseid) {
                break;
            }

            $chain[] = $mapping;
            $currentid = (int) $mapping->newcourseid;
        }

        return $chain;
    }

    /**
     * Add a new course replacement mapping.
     *
     * @param int $oldcourseid
     * @param int $newcourseid
     * @param string $migrationrule
     * @param string|null $note
     * @return int The new mapping ID.
     */
    public static function add_mapping(
        int $oldcourseid,
        int $newcourseid,
        string $migrationrule = 'redirect_incomplete',
        ?string $note = null
    ): int {
        global $DB;

        // Snapshot course metadata.
        $oldcourse = $DB->get_record('course', ['id' => $oldcourseid]);
        $newcourse = $DB->get_record('course', ['id' => $newcourseid]);

        $record = new stdClass();
        $record->oldcourseid = $oldcourseid;
        $record->oldcourseidnumber_snapshot = $oldcourse ? $oldcourse->idnumber : null;
        $record->oldcoursename_snapshot = $oldcourse ? $oldcourse->fullname : '[deleted]';
        $record->newcourseid = $newcourseid;
        $record->newcourseidnumber_snapshot = $newcourse ? $newcourse->idnumber : null;
        $record->newcoursename_snapshot = $newcourse ? $newcourse->fullname : '[deleted]';
        $record->migrationrule = $migrationrule;
        $record->active = 1;
        $record->effectivetime = null;
        $record->note = $note;
        $record->timecreated = time();
        $record->timemodified = time();

        return $DB->insert_record('local_completionhistory_course_map', $record);
    }

    /**
     * Update an existing course replacement mapping.
     *
     * @param int $mappingid
     * @param stdClass $data Fields to update.
     * @return bool
     */
    public static function update_mapping(int $mappingid, stdClass $data): bool {
        global $DB;

        $data->id = $mappingid;
        $data->timemodified = time();

        return $DB->update_record('local_completionhistory_course_map', $data);
    }

    /**
     * Deactivate a course replacement mapping.
     *
     * @param int $mappingid
     * @return bool
     */
    public static function deactivate_mapping(int $mappingid): bool {
        global $DB;

        $update = new stdClass();
        $update->id = $mappingid;
        $update->active = 0;
        $update->timemodified = time();

        return $DB->update_record('local_completionhistory_course_map', $update);
    }

    /**
     * Get all mappings (optionally filtered).
     *
     * @param bool|null $activeonly If true, only active mappings. If null, all.
     * @return array
     */
    public static function get_all_mappings(?bool $activeonly = null): array {
        global $DB;

        $conditions = [];
        if ($activeonly !== null) {
            $conditions['active'] = $activeonly ? 1 : 0;
        }

        return array_values($DB->get_records('local_completionhistory_course_map', $conditions, 'timecreated DESC'));
    }
}
