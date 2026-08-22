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
 * Upgrade steps for local_completionhistory.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_local_completionhistory_upgrade($oldversion) {
    global $CFG, $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026041701) {
        $table = new xmldb_table('local_completionhistory_achievement');

        // firstname_snapshot — add after useridnumber_snapshot.
        $field = new xmldb_field('firstname_snapshot', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'useridnumber_snapshot');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // lastname_snapshot — add after firstname_snapshot.
        $field = new xmldb_field('lastname_snapshot', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'firstname_snapshot');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // email_snapshot — add after lastname_snapshot.
        $field = new xmldb_field('email_snapshot', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'lastname_snapshot');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // enrolledtime_snapshot — add after completiontime.
        $field = new xmldb_field('enrolledtime_snapshot', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'completiontime');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026041701, 'local', 'completionhistory');
    }

    if ($oldversion < 2026041702) {

        // ── 1. Add exam_track, attempts_used, attempts_allowed to achievement ──

        $table = new xmldb_table('local_completionhistory_achievement');

        $field = new xmldb_field('exam_track', XMLDB_TYPE_CHAR, '30', null, null, null, null, 'grade_source');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('attempts_used', XMLDB_TYPE_INTEGER, '2', null, null, null, null, 'exam_track');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('attempts_allowed', XMLDB_TYPE_INTEGER, '2', null, null, null, null, 'attempts_used');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add index on exam_track for filtering.
        $index = new xmldb_index('exam_track_ix', XMLDB_INDEX_NOTUNIQUE, ['exam_track']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // ── 2. Create local_completionhistory_exam_attempt ────────────────────

        $table = new xmldb_table('local_completionhistory_exam_attempt');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('quizid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('exam_track', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, null);
            $table->add_field('attempt_number', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, null);
            $table->add_field('attempts_allowed', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '3');
            $table->add_field('grade_decimal', XMLDB_TYPE_NUMBER, '10, 5', null, null, null, null);
            $table->add_field('grade_passed', XMLDB_TYPE_INTEGER, '1', null, null, null, null);
            $table->add_field('resulted_in_completion', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('achievementid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('timetaken', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

            $table->add_index('userid_courseid_ix', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid']);
            $table->add_index('userid_courseid_track_ix', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid', 'exam_track']);
            $table->add_index('achievementid_ix', XMLDB_INDEX_NOTUNIQUE, ['achievementid']);
            $table->add_index('timetaken_ix', XMLDB_INDEX_NOTUNIQUE, ['timetaken']);

            $dbman->create_table($table);
        }

        // ── 3. Create local_completionhistory_course_exam_config ──────────────

        $table = new xmldb_table('local_completionhistory_course_exam_config');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('course_type', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'standard');
            $table->add_field('program_final_quizid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('dc_quizid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('cert_quizid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('program_attempts_allowed', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '3');
            $table->add_field('dc_attempts_allowed', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '3');
            $table->add_field('cert_attempts_allowed', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('notes', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

            $table->add_index('courseid_uq', XMLDB_INDEX_UNIQUE, ['courseid']);
            $table->add_index('course_type_ix', XMLDB_INDEX_NOTUNIQUE, ['course_type']);
            $table->add_index('program_final_quizid_ix', XMLDB_INDEX_NOTUNIQUE, ['program_final_quizid']);
            $table->add_index('dc_quizid_ix', XMLDB_INDEX_NOTUNIQUE, ['dc_quizid']);
            $table->add_index('cert_quizid_ix', XMLDB_INDEX_NOTUNIQUE, ['cert_quizid']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026041702, 'local', 'completionhistory');
    }

    if ($oldversion < 2026041705) {
        // Add duration field to exam_attempt (seconds, nullable).
        $table = new xmldb_table('local_completionhistory_exam_attempt');
        $field = new xmldb_field('duration', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'timetaken');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026041705, 'local', 'completionhistory');
    }

    if ($oldversion < 2026041706) {
        // Create flag_def table.
        $table = new xmldb_table('local_completionhistory_flag_def');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('code', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('name', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
            $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('flag_type', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('configjson', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('severity', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'warning');
            $table->add_field('enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('code_uq', XMLDB_INDEX_UNIQUE, ['code']);
            $table->add_index('enabled_ix', XMLDB_INDEX_NOTUNIQUE, ['enabled']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026041706, 'local', 'completionhistory');
    }

    if ($oldversion < 2026041707) {
        // Widen artifactstorage so Moodle Workplace certificate markers can
        // safely carry the certificate code.
        $table = new xmldb_table('local_completionhistory_achievement');
        $field = new xmldb_field(
            'artifactstorage',
            XMLDB_TYPE_CHAR,
            '64',
            null,
            null,
            null,
            null,
            'artifacturl'
        );
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_precision($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026041707, 'local', 'completionhistory');
    }

    if ($oldversion < 2026081700) {
        // Replace enumerable hashes with site-keyed hashes. Retaining a stable
        // deduplication key prevents later backfills from recreating anonymized rows.
        $hashsecret = (string) get_config('local_completionhistory', 'hashsecret');
        if (strlen($hashsecret) < 32) {
            $hashsecret = bin2hex(random_bytes(32));
            set_config('hashsecret', $hashsecret, 'local_completionhistory');
        }
        $lastid = 0;
        do {
            $records = $DB->get_records_select(
                'local_completionhistory_achievement',
                'id > :lastid',
                ['lastid' => $lastid],
                'id ASC',
                'id, userid, courseid, completiontime, source_component, source_event_hash',
                0,
                1000
            );
            foreach ($records as $record) {
                if ((int) $record->userid > 0) {
                    $source = $record->userid . '|' . $record->courseid . '|' .
                        $record->completiontime . '|' . $record->source_component;
                } else {
                    // The original userid is no longer available. Key the prior
                    // unique digest so anonymous rows cannot collapse onto one
                    // another when course and completion timestamps coincide.
                    $source = 'anonymized|' . $record->source_event_hash;
                }
                $DB->set_field(
                    'local_completionhistory_achievement',
                    'source_event_hash',
                    hash_hmac('sha256', $source, $hashsecret),
                    ['id' => $record->id]
                );
                $lastid = (int) $record->id;
            }
        } while (count($records) === 1000);

        // Older anonymization code cleared the ledger row but left its
        // denormalized outbox copy intact. Rewrite those legacy payloads now.
        $lastid = 0;
        do {
            $anonymous = $DB->get_records_select(
                'local_completionhistory_achievement',
                'userid = 0 AND id > :lastid',
                ['lastid' => $lastid],
                'id ASC',
                'id',
                0,
                1000
            );
            if ($anonymous) {
                $anonymousids = array_keys($anonymous);
                [$anonymousinsql, $anonymousparams] = $DB->get_in_or_equal(
                    $anonymousids,
                    SQL_PARAMS_NAMED,
                    'anonymousachievement'
                );
                $DB->execute(
                    "UPDATE {local_completionhistory_ach_program}
                        SET allocationid = NULL
                      WHERE achievementid {$anonymousinsql}",
                    $anonymousparams
                );
                \local_completionhistory\local\outbox_service::anonymize_achievement_payloads($anonymousids);
                $lastid = (int) array_key_last($anonymous);
            }
        } while (count($anonymous) === 1000);

        // Close the historical exam-attempt gap for sites which had already
        // opted into automatic anonymization before this release.
        if (get_config('local_completionhistory', 'gdpranonymize')) {
            \local_completionhistory\local\ledger_service::reconcile_deleted_users();
        }

        // Apply the sourcesite default on upgrades as well as fresh installs.
        if (get_config('local_completionhistory', 'sourcesite') === false) {
            set_config('sourcesite', $CFG->wwwroot, 'local_completionhistory');
        }

        upgrade_plugin_savepoint(true, 2026081700, 'local', 'completionhistory');
    }

    if ($oldversion < 2026082200) {
        // SIS-165: the SIS retires enrol_programs. From 0.7.0 provisioning never allocates, so
        // this step retires the allocations EARLIER versions created — without it, upgraded
        // installs keep the whole-programme course access that silently outranks the SIS
        // learning-window pacer, with the deadline endpoint that could bound it also gone
        // (PR #10 review). Two moves, strictly in this order:
        //
        //   1. BACKFILL: every active course enrolment granted by the 'programs' method gets a
        //      manual counterpart (same user, same course, same start, student role, active),
        //      so retiring the allocation cannot cost a learner access to a course they are in.
        //   2. ARCHIVE: every active allocation is archived, and enrol_programs is asked to
        //      recalculate the user's enrolments so it withdraws its own grants.
        //
        // Guarded like list_programs: an install that never had enrol_programs has nothing to
        // retire, and one where it was already uninstalled must not fatal on a missing table.
        if ($dbman->table_exists(new xmldb_table('enrol_programs_allocations'))) {
            require_once($CFG->dirroot . '/lib/enrollib.php');
            $manual = enrol_get_plugin('manual');
            $studentroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student']);
            $gaps = $DB->get_records_sql("
                SELECT ue.id, ue.userid, ue.timestart, e.courseid
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE e.enrol = 'programs' AND ue.status = :active
                   AND NOT EXISTS (
                       SELECT 1
                         FROM {user_enrolments} ue2
                         JOIN {enrol} e2 ON e2.id = ue2.enrolid
                        WHERE e2.enrol = 'manual' AND e2.courseid = e.courseid
                          AND ue2.userid = ue.userid AND ue2.status = :active2)",
                ['active' => ENROL_USER_ACTIVE, 'active2' => ENROL_USER_ACTIVE]);
            if ($gaps && (!$manual || $studentroleid <= 0)) {
                // Archiving without the backfill would take course access away from live
                // learners, so refuse the whole step loudly rather than half-run it.
                throw new \moodle_exception('generalexceptionmessage', 'error', '',
                    'local_completionhistory 0.7.0 upgrade: manual enrolment plugin or student role '
                    . 'unavailable, and ' . count($gaps) . ' programs-method enrolment(s) need a manual '
                    . 'counterpart before allocations can be archived.');
            }
            foreach ($gaps as $gap) {
                $course = $DB->get_record('course', ['id' => $gap->courseid], '*', MUST_EXIST);
                $instance = $DB->get_record('enrol',
                    ['courseid' => $gap->courseid, 'enrol' => 'manual', 'status' => ENROL_INSTANCE_ENABLED]);
                if (!$instance) {
                    $disabled = $DB->get_record('enrol', ['courseid' => $gap->courseid, 'enrol' => 'manual']);
                    if ($disabled) {
                        $manual->update_status($disabled, ENROL_INSTANCE_ENABLED);
                        $instance = $DB->get_record('enrol', ['id' => $disabled->id], '*', MUST_EXIST);
                    } else {
                        $instanceid = $manual->add_instance($course, $manual->get_instance_defaults());
                        $instance = $DB->get_record('enrol', ['id' => $instanceid], '*', MUST_EXIST);
                    }
                }
                $manual->enrol_user($instance, $gap->userid, $studentroleid,
                    (int) $gap->timestart, 0, ENROL_USER_ACTIVE);
            }
            $allocations = $DB->get_records('enrol_programs_allocations', ['archived' => 0]);
            foreach ($allocations as $allocation) {
                $allocation->archived = 1;
                $allocation->timemodified = time();
                $DB->update_record('enrol_programs_allocations', $allocation);
                // Same guarded call set_program_deadline used while it existed: enrol_programs
                // recalculates and withdraws its own course grants for this user, if it is
                // still installed to do so. Leftovers are swept when the plugin is disabled.
                if (class_exists('\\enrol_programs\\local\\allocation')
                        && method_exists('\\enrol_programs\\local\\allocation', 'fix_user_enrolments')) {
                    \enrol_programs\local\allocation::fix_user_enrolments($allocation->programid, $allocation->userid);
                }
            }
            if ($gaps || $allocations) {
                mtrace('local_completionhistory 0.7.0: backfilled ' . count($gaps)
                    . ' manual enrolment(s), archived ' . count($allocations) . ' program allocation(s).');
            }
        }
        upgrade_plugin_savepoint(true, 2026082200, 'local', 'completionhistory');
    }

    return true;
}
