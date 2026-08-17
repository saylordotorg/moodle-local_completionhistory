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

namespace local_completionhistory\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_completionhistory\local\ledger_service;

/**
 * Privacy provider for local_completionhistory.
 *
 * Achievement records are institutional academic records. On user deletion,
 * they are anonymized (userid set to 0) rather than deleted, preserving
 * the academic history while removing PII. This is a policy decision
 * documented in the plugin README.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\user_preference_provider
{
    /**
     * Describe the types of data stored by this plugin.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_completionhistory_achievement', [
            'ledgeruuid'            => 'privacy:metadata:achievement:ledgeruuid',
            'userid'              => 'privacy:metadata:achievement:userid',
            'useridnumber_snapshot' => 'privacy:metadata:achievement:useridnumber_snapshot',
            'firstname_snapshot'  => 'privacy:metadata:achievement:firstname_snapshot',
            'lastname_snapshot'   => 'privacy:metadata:achievement:lastname_snapshot',
            'email_snapshot'      => 'privacy:metadata:achievement:email_snapshot',
            'courseid'            => 'privacy:metadata:achievement:courseid',
            'courseidnumber_snapshot' => 'privacy:metadata:achievement:courseidnumber_snapshot',
            'courseshortname_snapshot' => 'privacy:metadata:achievement:courseshortname_snapshot',
            'coursename_snapshot' => 'privacy:metadata:achievement:coursename_snapshot',
            'completiontime'      => 'privacy:metadata:achievement:completiontime',
            'enrolledtime_snapshot' => 'privacy:metadata:achievement:enrolledtime_snapshot',
            'grade_decimal'       => 'privacy:metadata:achievement:grade_decimal',
            'grade_passed'        => 'privacy:metadata:achievement:grade_passed',
            'grade_source'        => 'privacy:metadata:achievement:grade_source',
            'exam_track'          => 'privacy:metadata:achievement:exam_track',
            'attempts_used'       => 'privacy:metadata:achievement:attempts_used',
            'attempts_allowed'    => 'privacy:metadata:achievement:attempts_allowed',
            'artifacturl'         => 'privacy:metadata:achievement:artifacturl',
            'artifactstorage'     => 'privacy:metadata:achievement:artifactstorage',
            'source_component'    => 'privacy:metadata:achievement:source_component',
            'source_event'        => 'privacy:metadata:achievement:source_event',
            'source_event_hash'   => 'privacy:metadata:achievement:source_event_hash',
            'timecreated'         => 'privacy:metadata:achievement:timecreated',
        ], 'privacy:metadata:achievement');
        $collection->add_database_table('local_completionhistory_ach_program', [
            'achievementid' => 'privacy:metadata:ach_program:achievementid',
            'allocationid' => 'privacy:metadata:ach_program:allocationid',
            'programid' => 'privacy:metadata:ach_program:programid',
            'programidnumber_snapshot' => 'privacy:metadata:ach_program:programidnumber_snapshot',
            'programname_snapshot' => 'privacy:metadata:ach_program:programname_snapshot',
            'timecreated' => 'privacy:metadata:ach_program:timecreated',
        ], 'privacy:metadata:ach_program');

        $collection->add_database_table('local_completionhistory_purge_audit', [
            'userid' => 'privacy:metadata:purge_audit:userid',
            'programid' => 'privacy:metadata:purge_audit:programid',
            'reason' => 'privacy:metadata:purge_audit:reason',
            'detailsjson' => 'privacy:metadata:purge_audit:detailsjson',
            'timecreated' => 'privacy:metadata:purge_audit:timecreated',
        ], 'privacy:metadata:purge_audit');

        $collection->add_database_table('local_completionhistory_exam_attempt', [
            'userid' => 'privacy:metadata:exam_attempt:userid',
            'courseid' => 'privacy:metadata:exam_attempt:courseid',
            'quizid' => 'privacy:metadata:exam_attempt:quizid',
            'exam_track' => 'privacy:metadata:exam_attempt:exam_track',
            'attempt_number' => 'privacy:metadata:exam_attempt:attempt_number',
            'attempts_allowed' => 'privacy:metadata:exam_attempt:attempts_allowed',
            'grade_decimal' => 'privacy:metadata:exam_attempt:grade_decimal',
            'grade_passed' => 'privacy:metadata:exam_attempt:grade_passed',
            'resulted_in_completion' => 'privacy:metadata:exam_attempt:resulted_in_completion',
            'achievementid' => 'privacy:metadata:exam_attempt:achievementid',
            'timetaken' => 'privacy:metadata:exam_attempt:timetaken',
            'duration' => 'privacy:metadata:exam_attempt:duration',
            'timecreated' => 'privacy:metadata:exam_attempt:timecreated',
        ], 'privacy:metadata:exam_attempt');

        $collection->add_database_table('local_completionhistory_outbox', [
            'entitytype' => 'privacy:metadata:outbox:entitytype',
            'entityid' => 'privacy:metadata:outbox:entityid',
            'payloadjson' => 'privacy:metadata:outbox:payloadjson',
            'status' => 'privacy:metadata:outbox:status',
            'retrycount' => 'privacy:metadata:outbox:retrycount',
            'lasterror' => 'privacy:metadata:outbox:lasterror',
            'timecreated' => 'privacy:metadata:outbox:timecreated',
            'timemodified' => 'privacy:metadata:outbox:timemodified',
        ], 'privacy:metadata:outbox');

        $collection->add_external_location_link('saylor_sis', [
            'userid' => 'privacy:metadata:saylor_sis:userid',
            'useridnumber' => 'privacy:metadata:saylor_sis:useridnumber',
            'name' => 'privacy:metadata:saylor_sis:name',
            'email' => 'privacy:metadata:saylor_sis:email',
            'enrolment' => 'privacy:metadata:saylor_sis:enrolment',
            'grades' => 'privacy:metadata:saylor_sis:grades',
            'activity' => 'privacy:metadata:saylor_sis:activity',
        ], 'privacy:metadata:saylor_sis');

        $collection->add_user_preference(
            'local_completionhistory_ledger_cols',
            'privacy:metadata:preference:ledger_cols'
        );
        $collection->add_user_preference(
            'local_completionhistory_attempts_cols',
            'privacy:metadata:preference:attempts_cols'
        );
        return $collection;
    }

    /**
     * Get the list of contexts that contain user data.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        // All plugin user data is stored at system context.
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                 WHERE ctx.contextlevel = :contextlevel
                   AND ctx.instanceid = 0
                   AND (
                       EXISTS (
                           SELECT 1 FROM {local_completionhistory_achievement} a
                            WHERE a.userid = :achievementuserid
                       )
                       OR EXISTS (
                           SELECT 1 FROM {local_completionhistory_exam_attempt} ea
                            WHERE ea.userid = :attemptuserid
                       )
                       OR EXISTS (
                           SELECT 1 FROM {local_completionhistory_purge_audit} pa
                            WHERE pa.userid = :audituserid
                       )
                   )";
        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_SYSTEM,
            'achievementuserid' => $userid,
            'attemptuserid' => $userid,
            'audituserid' => $userid,
        ]);

        return $contextlist;
    }

    /**
     * Get the list of users within a specific context.
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_system) {
            return;
        }

        $sql = "SELECT userid FROM {local_completionhistory_achievement} WHERE userid > 0
                UNION
                SELECT userid FROM {local_completionhistory_exam_attempt} WHERE userid > 0
                UNION
                SELECT userid FROM {local_completionhistory_purge_audit} WHERE userid > 0";
        $userlist->add_from_sql('userid', $sql, []);
    }

    /**
     * Export all user data for the specified approved contexts.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        $systemcontext = \context_system::instance();
        if (!in_array($systemcontext->id, $contextlist->get_contextids())) {
            return;
        }
        // Export achievements.
        $achievements = $DB->get_records('local_completionhistory_achievement', ['userid' => $userid], 'completiontime DESC');
        foreach ($achievements as $achievement) {
            // Get associated programs.
            $programs = $DB->get_records('local_completionhistory_ach_program', ['achievementid' => $achievement->id]);
            $achievement->programs = array_values($programs);

            writer::with_context($systemcontext)->export_data(
                [get_string('pluginname', 'local_completionhistory'), $achievement->ledgeruuid],
                $achievement
            );
        }

        // Export exam-attempt history, including attempts which never produced an achievement.
        $attempts = $DB->get_records(
            'local_completionhistory_exam_attempt',
            ['userid' => $userid],
            'timetaken DESC'
        );
        foreach ($attempts as $attempt) {
            writer::with_context($systemcontext)->export_data(
                [
                    get_string('pluginname', 'local_completionhistory'),
                    get_string('privacy:export:exam_attempts', 'local_completionhistory'),
                    $attempt->id,
                ],
                $attempt
            );
        }

        // The outbox is a denormalized copy of achievement data and must be visible
        // in an export too, regardless of whether delivery has completed.
        if ($achievements) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($achievements), SQL_PARAMS_NAMED, 'achievement');
            $params['entitytype'] = \local_completionhistory\local\outbox_service::ENTITY_ACHIEVEMENT;
            $outboxrows = $DB->get_records_select(
                'local_completionhistory_outbox',
                "entitytype = :entitytype AND entityid {$insql}",
                $params,
                'timecreated DESC'
            );
            foreach ($outboxrows as $outboxrow) {
                writer::with_context($systemcontext)->export_data(
                    [
                        get_string('pluginname', 'local_completionhistory'),
                        get_string('privacy:export:outbox', 'local_completionhistory'),
                        $outboxrow->id,
                    ],
                    $outboxrow
                );
            }
        }
        // Export purge audit records.
        $audits = $DB->get_records('local_completionhistory_purge_audit', ['userid' => $userid], 'timecreated DESC');
        foreach ($audits as $audit) {
            writer::with_context($systemcontext)->export_data(
                [get_string('pluginname', 'local_completionhistory'), get_string('purgeaudit', 'local_completionhistory'), $audit->id],
                $audit
            );
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * Achievement records are anonymized, not deleted, because they are
     * institutional academic records.
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if (!$context instanceof \context_system) {
            return;
        }

        // Anonymize all achievement records.
        $achievementusers = $DB->get_fieldset_sql(
            "SELECT DISTINCT userid
               FROM {local_completionhistory_achievement}
              WHERE userid <> 0"
        );
        $attemptusers = $DB->get_fieldset_sql(
            "SELECT DISTINCT userid
               FROM {local_completionhistory_exam_attempt}
              WHERE userid <> 0"
        );
        ledger_service::anonymize_users(array_merge($achievementusers, $attemptusers));
        // Delete purge audit records (these are operational, not academic).
        $DB->delete_records('local_completionhistory_purge_audit');
    }

    /**
     * Delete all data for the specified user in the specified context.
     *
     * Achievement records are anonymized, not deleted.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        $systemcontext = \context_system::instance();
        if (!in_array($systemcontext->id, $contextlist->get_contextids())) {
            return;
        }
        // Anonymize achievement records.
        ledger_service::anonymize_users([$userid]);

        // Delete purge audit records.
        $DB->delete_records('local_completionhistory_purge_audit', ['userid' => $userid]);
    }

    /**
     * Delete data for multiple users within a single context.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if (!$context instanceof \context_system) {
            return;
        }

        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        // Anonymize achievement records.
        ledger_service::anonymize_users($userids);

        // Delete purge audit records.
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $DB->execute(
            "DELETE FROM {local_completionhistory_purge_audit} WHERE userid {$insql}",
            $params
        );
    }

    /**
     * Export saved table-layout preferences.
     *
     * @param int $userid User id.
     */
    public static function export_user_preferences(int $userid): void {
        foreach ([
            'local_completionhistory_ledger_cols' => 'privacy:metadata:preference:ledger_cols',
            'local_completionhistory_attempts_cols' => 'privacy:metadata:preference:attempts_cols',
        ] as $name => $description) {
            $value = get_user_preferences($name, null, $userid);
            if ($value !== null) {
                writer::export_user_preference(
                    'local_completionhistory',
                    $name,
                    $value,
                    get_string($description, 'local_completionhistory')
                );
            }
        }
    }
}
