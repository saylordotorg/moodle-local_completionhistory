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
    \core_privacy\local\request\core_userlist_provider
{
    /**
     * Describe the types of data stored by this plugin.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_completionhistory_achievement', [
            'userid'              => 'privacy:metadata:achievement:userid',
            'firstname_snapshot'  => 'privacy:metadata:achievement:firstname_snapshot',
            'lastname_snapshot'   => 'privacy:metadata:achievement:lastname_snapshot',
            'email_snapshot'      => 'privacy:metadata:achievement:email_snapshot',
            'coursename_snapshot' => 'privacy:metadata:achievement:coursename_snapshot',
            'completiontime'      => 'privacy:metadata:achievement:completiontime',
            'enrolledtime_snapshot' => 'privacy:metadata:achievement:enrolledtime_snapshot',
            'grade_decimal'       => 'privacy:metadata:achievement:grade_decimal',
        ], 'privacy:metadata:achievement');

        $collection->add_database_table('local_completionhistory_ach_program', [
        ], 'privacy:metadata:ach_program');

        $collection->add_database_table('local_completionhistory_purge_audit', [
            'userid' => 'privacy:metadata:purge_audit:userid',
        ], 'privacy:metadata:purge_audit');

        return $collection;
    }

    /**
     * Get the list of contexts that contain user data.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        // Achievement data is stored at system context.
        $sql = "SELECT ctx.id
                  FROM {local_completionhistory_achievement} a
                  JOIN {context} ctx ON ctx.contextlevel = :contextlevel AND ctx.instanceid = 0
                 WHERE a.userid = :userid";

        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_SYSTEM,
            'userid' => $userid,
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

        $sql = "SELECT DISTINCT userid FROM {local_completionhistory_achievement}";
        $userlist->add_from_sql('userid', $sql, []);

        $sql = "SELECT DISTINCT userid FROM {local_completionhistory_purge_audit}";
        $userlist->add_from_sql('userid', $sql, []);
    }

    /**
     * Export all user data for the specified approved contexts.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        // Export achievements.
        $achievements = $DB->get_records('local_completionhistory_achievement', ['userid' => $userid], 'completiontime DESC');
        foreach ($achievements as $achievement) {
            // Get associated programs.
            $programs = $DB->get_records('local_completionhistory_ach_program', ['achievementid' => $achievement->id]);
            $achievement->programs = array_values($programs);

            $context = \context_system::instance();
            writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_completionhistory'), $achievement->ledgeruuid],
                $achievement
            );
        }

        // Export purge audit records.
        $audits = $DB->get_records('local_completionhistory_purge_audit', ['userid' => $userid], 'timecreated DESC');
        foreach ($audits as $audit) {
            $context = \context_system::instance();
            writer::with_context($context)->export_data(
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
        $allusers = $DB->get_fieldset_sql(
            "SELECT DISTINCT userid
               FROM {local_completionhistory_achievement}
              WHERE userid <> 0"
        );
        ledger_service::anonymize_users($allusers);

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
}
