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

namespace local_completionhistory\hook;

/**
 * Hook dispatched when course completions are purged.
 *
 * This hook class is defined by local_completionhistory so that enrol_programs
 * (or other components) can dispatch it before purging completion records.
 * This allows us to capture achievements before they are lost.
 *
 * Until enrol_programs integrates this hook, it will not fire automatically.
 * The reconciliation task provides a safety net for missed captures.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class course_completions_purged {

    /** @var int The user whose completions are being purged. */
    public readonly int $userid;

    /** @var int|null The specific course ID, or null for program-wide purge. */
    public readonly ?int $courseid;

    /** @var int|null The program ID triggering the purge, if applicable. */
    public readonly ?int $programid;

    /** @var string Reason for the purge (e.g. 'program_reset', 'reallocation'). */
    public readonly string $reason;

    /** @var array IDs of course_completions rows being purged. */
    public readonly array $purgedids;

    /**
     * Constructor.
     *
     * @param int $userid
     * @param string $reason
     * @param array $purgedids
     * @param int|null $courseid
     * @param int|null $programid
     */
    public function __construct(
        int $userid,
        string $reason,
        array $purgedids = [],
        ?int $courseid = null,
        ?int $programid = null,
    ) {
        $this->userid = $userid;
        $this->reason = $reason;
        $this->purgedids = $purgedids;
        $this->courseid = $courseid;
        $this->programid = $programid;
    }
}
