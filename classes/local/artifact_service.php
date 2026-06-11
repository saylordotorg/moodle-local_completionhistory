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
 * Note: A "permanent" URL is not truly permanent if the underlying file lives in
 * a course context that may be deleted. Moodle Workplace certificate issues are
 * tracked by code so the SIS can update or clear credential links after capture.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class artifact_service {

    /** @var string Storage marker prefix carrying the issued certificate code. */
    private const STORAGE_TOOL_CERTIFICATE_PREFIX = 'tool_certificate:';

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

    /**
     * Resolve the latest Moodle Workplace certificate for a user/course pair.
     *
     * @param int $userid Moodle user id.
     * @param int $courseid Moodle course id.
     * @return array|null {url, storage, code} or null when no issue exists.
     */
    public static function certificate_artifact_for_user_course(int $userid, int $courseid): ?array {
        global $DB;

        if ($userid <= 0 || $courseid <= 0 || !class_exists(\tool_certificate\template::class)) {
            return null;
        }

        $issues = $DB->get_records_sql(
            "SELECT *
               FROM {tool_certificate_issues}
              WHERE userid = :userid
                AND courseid = :courseid
                AND archived = 0
           ORDER BY timecreated DESC, id DESC",
            ['userid' => $userid, 'courseid' => $courseid],
            0,
            1
        );
        if (!$issues) {
            return null;
        }

        return self::certificate_artifact_from_issue(reset($issues));
    }

    /**
     * Attach an issued Moodle certificate to the matching achievement row.
     *
     * This is called from the tool_certificate issued event. It updates the
     * latest achievement for the same user/course and enqueues the achievement
     * again so external SIS mirrors receive the certificate link.
     *
     * @param int $issueid tool_certificate_issues.id.
     * @return int|null Updated achievement id, or null when no course achievement matches.
     */
    public static function attach_certificate_issue(int $issueid): ?int {
        global $DB;

        if ($issueid <= 0 || !class_exists(\tool_certificate\template::class)) {
            return null;
        }

        $issue = $DB->get_record('tool_certificate_issues', ['id' => $issueid]);
        if (!$issue || empty($issue->courseid) || empty($issue->userid) || empty($issue->code) || !empty($issue->archived)) {
            return null;
        }

        $achievement = self::latest_achievement_for_user_course((int) $issue->userid, (int) $issue->courseid);
        if (!$achievement) {
            return null;
        }

        $artifact = self::certificate_artifact_from_issue($issue);
        if (!$artifact) {
            return null;
        }

        self::persist_artifact($achievement, $artifact['url'], $artifact['storage']);
        return (int) $achievement->id;
    }

    /**
     * Clear a Moodle certificate artifact from the matching achievement row.
     *
     * @param int $userid Moodle user id.
     * @param int $courseid Moodle course id.
     * @param string $code Optional certificate code guard.
     * @return int Number of achievements updated.
     */
    public static function clear_certificate_issue(int $userid, int $courseid, string $code = ''): int {
        if ($userid <= 0 || $courseid <= 0) {
            return 0;
        }

        $achievement = self::latest_achievement_for_user_course($userid, $courseid);
        if (!$achievement || empty($achievement->artifactstorage)) {
            return 0;
        }

        if ($code !== '' && self::certificate_code_from_storage((string) $achievement->artifactstorage) !== $code) {
            return 0;
        }

        self::persist_artifact($achievement, null, null);
        return 1;
    }

    /**
     * Extract the certificate code from a storage marker.
     *
     * @param string $storage
     * @return string
     */
    public static function certificate_code_from_storage(string $storage): string {
        if (strpos($storage, self::STORAGE_TOOL_CERTIFICATE_PREFIX) !== 0) {
            return '';
        }
        return substr($storage, strlen(self::STORAGE_TOOL_CERTIFICATE_PREFIX));
    }

    /**
     * Build canonical artifact metadata from a certificate issue record.
     *
     * @param \stdClass $issue
     * @return array|null
     */
    private static function certificate_artifact_from_issue(\stdClass $issue): ?array {
        if (empty($issue->code) || !class_exists(\tool_certificate\template::class)) {
            return null;
        }

        $url = \tool_certificate\template::view_url($issue->code)->out(false);
        return [
            'url' => $url,
            'storage' => self::STORAGE_TOOL_CERTIFICATE_PREFIX . $issue->code,
            'code' => (string) $issue->code,
        ];
    }

    /**
     * Find the newest achievement row for a user/course pair.
     *
     * @param int $userid
     * @param int $courseid
     * @return \stdClass|null
     */
    private static function latest_achievement_for_user_course(int $userid, int $courseid): ?\stdClass {
        global $DB;

        $rows = $DB->get_records_sql(
            "SELECT *
               FROM {local_completionhistory_achievement}
              WHERE userid = :userid
                AND courseid = :courseid
           ORDER BY completiontime DESC, id DESC",
            ['userid' => $userid, 'courseid' => $courseid],
            0,
            1
        );

        return $rows ? reset($rows) : null;
    }

    /**
     * Persist artifact fields and enqueue the updated achievement for SIS sync.
     *
     * @param \stdClass $achievement
     * @param string|null $url
     * @param string|null $storage
     */
    private static function persist_artifact(\stdClass $achievement, ?string $url, ?string $storage): void {
        global $DB;

        $updated = clone $achievement;
        $updated->artifacturl = $url;
        $updated->artifactstorage = $storage;

        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->update_record('local_completionhistory_achievement', $updated);
            outbox_service::enqueue_achievement($updated);
            $transaction->allow_commit();
        } catch (\Exception $e) {
            $transaction->rollback($e);
            throw $e;
        }
    }
}
