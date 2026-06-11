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
 * Transactional outbox service for external SIS sync.
 *
 * Achievements are enqueued into {local_completionhistory_outbox} inside the
 * same DB transaction that writes the ledger row, guaranteeing exactly-once
 * capture with no lost or phantom events. The external Saylor SIS drains the
 * queue via the get_unsynced_outbox / mark_outbox_sent web services.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class outbox_service {

    /** @var string Entity type for achievement rows. */
    public const ENTITY_ACHIEVEMENT = 'achievement';

    /** @var string Pending (not yet delivered). */
    public const STATUS_PENDING = 'pending';

    /** @var string Delivered and acknowledged by the SIS. */
    public const STATUS_SENT = 'sent';

    /** @var string Delivery attempted and failed. */
    public const STATUS_FAILED = 'failed';

    /** @var string Withdrawn; will not be delivered. */
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Whether outbox enqueue is enabled.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return (bool) get_config('local_completionhistory', 'enableoutbox');
    }

    /**
     * Enqueue an entity payload for SIS sync.
     *
     * @param string $entitytype One of the ENTITY_* constants.
     * @param int    $entityid   Source entity id (e.g. achievement id).
     * @param array  $payload    Canonical payload to deliver.
     * @return int New outbox row id.
     */
    public static function enqueue(string $entitytype, int $entityid, array $payload): int {
        global $DB;

        $now = time();
        $row = new stdClass();
        $row->entitytype  = $entitytype;
        $row->entityid    = $entityid;
        $row->payloadjson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $row->status      = self::STATUS_PENDING;
        $row->retrycount  = 0;
        $row->lasterror   = null;
        $row->timecreated = $now;
        $row->timemodified = $now;

        return (int) $DB->insert_record('local_completionhistory_outbox', $row);
    }

    /**
     * Enqueue an achievement for SIS sync. No-op (returns 0) unless enabled.
     *
     * Intended to be called inside the ledger write transaction so the outbox
     * row commits atomically with the achievement row.
     *
     * @param stdClass $achievement A persisted achievement record (must have ->id).
     * @return int Outbox row id, or 0 if the outbox is disabled.
     */
    public static function enqueue_achievement(stdClass $achievement): int {
        if (!self::is_enabled()) {
            return 0;
        }
        $payload = self::build_achievement_payload($achievement);
        return self::enqueue(self::ENTITY_ACHIEVEMENT, (int) $achievement->id, $payload);
    }

    /**
     * Build the canonical SIS sync payload for an achievement row.
     *
     * This is the single source of truth for the achievement contract and is
     * shared by the outbox payload and the get_recent_achievements web service.
     * Snapshot string fields coalesce to '' (never null); optional numerics
     * remain null when unknown.
     *
     * @param stdClass $a An achievement record (or partial record with ->id).
     * @return array
     */
    public static function build_achievement_payload(stdClass $a): array {
        global $DB;

        $artifactstorage = (string) ($a->artifactstorage ?? '');

        $programs = [];
        if (!empty($a->id)) {
            $rows = $DB->get_records('local_completionhistory_ach_program', ['achievementid' => $a->id]);
            foreach ($rows as $p) {
                $programs[] = [
                    'programid'       => (int) ($p->programid ?? 0),
                    'programname'     => (string) ($p->programname_snapshot ?? ''),
                    'programidnumber' => (string) ($p->programidnumber_snapshot ?? ''),
                ];
            }
        }

        return [
            'id'              => (int) ($a->id ?? 0),
            'ledgeruuid'      => (string) ($a->ledgeruuid ?? ''),
            'userid'          => (int) ($a->userid ?? 0),
            'useridnumber'    => (string) ($a->useridnumber_snapshot ?? ''),
            'firstname'       => (string) ($a->firstname_snapshot ?? ''),
            'lastname'        => (string) ($a->lastname_snapshot ?? ''),
            'email'           => (string) ($a->email_snapshot ?? ''),
            'courseid'        => (int) ($a->courseid ?? 0),
            'courseidnumber'  => (string) ($a->courseidnumber_snapshot ?? ''),
            'courseshortname' => (string) ($a->courseshortname_snapshot ?? ''),
            'coursename'      => (string) ($a->coursename_snapshot ?? ''),
            'completiontime'  => (int) ($a->completiontime ?? 0),
            'enrolledtime'    => (int) ($a->enrolledtime_snapshot ?? 0),
            'grade'           => (isset($a->grade_decimal) && $a->grade_decimal !== null) ? (float) $a->grade_decimal : null,
            'gradepassed'     => (isset($a->grade_passed) && $a->grade_passed !== null) ? (int) $a->grade_passed : null,
            'gradesource'     => (string) ($a->grade_source ?? ''),
            'examtrack'       => (string) ($a->exam_track ?? ''),
            'attemptsused'    => (isset($a->attempts_used) && $a->attempts_used !== null) ? (int) $a->attempts_used : null,
            'attemptsallowed' => (isset($a->attempts_allowed) && $a->attempts_allowed !== null) ? (int) $a->attempts_allowed : null,
            'artifacturl'     => (string) ($a->artifacturl ?? ''),
            'artifactstorage' => $artifactstorage,
            'artifactcode'    => artifact_service::certificate_code_from_storage($artifactstorage),
            'sourcecomponent' => (string) ($a->source_component ?? ''),
            'sourceevent'     => (string) ($a->source_event ?? ''),
            'timecreated'     => (int) ($a->timecreated ?? 0),
            'programs'        => $programs,
        ];
    }

    /**
     * Fetch unsynced outbox rows in FIFO (id) order.
     *
     * @param int    $limit  Maximum rows to return.
     * @param string $status Status to fetch (default 'pending').
     * @return array Array of outbox records keyed by id.
     */
    public static function get_unsynced(int $limit = 500, string $status = self::STATUS_PENDING): array {
        global $DB;
        return $DB->get_records('local_completionhistory_outbox', ['status' => $status], 'id ASC', '*', 0, $limit);
    }

    /**
     * Acknowledge outbox rows after delivery.
     *
     * For STATUS_FAILED the retry counter is incremented and the error stored;
     * for any other status the error is cleared.
     *
     * @param int[]       $ids    Outbox row ids.
     * @param string      $status New status (sent|failed|cancelled).
     * @param string|null $error  Error message (stored only for failed).
     * @return int Number of rows updated.
     */
    public static function mark_sent(array $ids, string $status = self::STATUS_SENT, ?string $error = null): int {
        global $DB;

        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            fn($id) => $id > 0
        )));
        if (empty($ids)) {
            return 0;
        }
        if (!in_array($status, [self::STATUS_SENT, self::STATUS_FAILED, self::STATUS_CANCELLED], true)) {
            $status = self::STATUS_SENT;
        }

        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $count = $DB->count_records_select('local_completionhistory_outbox', "id {$insql}", $params);
        if (!$count) {
            return 0;
        }

        $params['st']  = $status;
        $params['now'] = time();

        if ($status === self::STATUS_FAILED) {
            $params['err'] = $error;
            $DB->execute(
                "UPDATE {local_completionhistory_outbox}
                    SET status = :st, retrycount = retrycount + 1, lasterror = :err, timemodified = :now
                  WHERE id {$insql}",
                $params
            );
        } else {
            $DB->execute(
                "UPDATE {local_completionhistory_outbox}
                    SET status = :st, lasterror = NULL, timemodified = :now
                  WHERE id {$insql}",
                $params
            );
        }

        return $count;
    }
}
