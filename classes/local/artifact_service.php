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
 * Service for resolving artifact (certificate/transcript) URLs.
 *
 * Note: Stable artifact storage is a follow-up. A "permanent" URL is not truly
 * permanent if the underlying file lives in a course context that may be deleted.
 * For now, this supports nullable artifact URLs and a configurable URL pattern.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class artifact_service {

    /**
     * Resolve the artifact URL for an achievement.
     *
     * If an explicit URL is stored on the achievement record, returns that.
     * Otherwise, attempts to build a URL from the configured pattern.
     * Returns null if no artifact source is available.
     *
     * @param int $achievementid
     * @return string|null The artifact URL, or null.
     */
    public static function resolve_url(int $achievementid): ?string {
        global $DB;

        $achievement = $DB->get_record('local_completionhistory_achievement', ['id' => $achievementid]);
        if (!$achievement) {
            return null;
        }

        // If an explicit URL is stored, return it.
        if (!empty($achievement->artifacturl)) {
            return $achievement->artifacturl;
        }

        // Check storage mode.
        $mode = get_config('local_completionhistory', 'artifactstoragemode');
        if ($mode === 'none' || empty($mode)) {
            return null;
        }

        // Future: implement URL pattern substitution and pluginfile resolution.
        // For now, return null as stable artifact storage is not yet available.
        return null;
    }
}
