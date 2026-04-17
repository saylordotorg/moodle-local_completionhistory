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
 * Language strings for local_completionhistory.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Plugin name.
$string['pluginname'] = 'Completion History';

// Capabilities.
$string['completionhistory:viewown'] = 'View own achievement history';
$string['completionhistory:viewall'] = 'View all achievement history';
$string['completionhistory:manage'] = 'Manage achievement history';
$string['completionhistory:managecoursemap'] = 'Manage course replacement mappings';
$string['completionhistory:runbackfill'] = 'Run achievement backfill';

// Settings.
$string['setting_enabled'] = 'Enable plugin';
$string['setting_enabled_desc'] = 'Enable or disable the Completion History plugin.';
$string['setting_autocapture'] = 'Auto-capture completions';
$string['setting_autocapture_desc'] = 'Automatically capture an achievement record when a course is completed.';
$string['setting_capturegrades'] = 'Capture grade snapshots';
$string['setting_capturegrades_desc'] = 'Include the course total grade when capturing achievement records.';
$string['setting_backfillbatchsize'] = 'Backfill batch size';
$string['setting_backfillbatchsize_desc'] = 'Maximum number of records to process per backfill batch.';
$string['setting_enablepurgeaudit'] = 'Enable purge audit';
$string['setting_enablepurgeaudit_desc'] = 'Log an audit record when course completions are purged by other plugins.';
$string['setting_enableuserachievements'] = 'Enable user achievements page';
$string['setting_enableuserachievements_desc'] = 'Show the "My Achievements" page in user navigation.';
$string['setting_artifactstoragemode'] = 'Artifact storage mode';
$string['setting_artifactstoragemode_desc'] = 'How certificate/transcript artifact URLs are stored.';
$string['setting_replacementnotification'] = 'Replacement notification mode';
$string['setting_replacementnotification_desc'] = 'How to notify users about course replacement recommendations.';
$string['setting_gdpranonymize'] = 'Anonymize on user deletion';
$string['setting_gdpranonymize_desc'] = 'When a user is deleted, anonymize their achievement records instead of keeping full PII. Achievement records are retained as institutional academic records.';

// Artifact modes.
$string['artifactmode_none'] = 'None';
$string['artifactmode_url'] = 'External URL';
$string['artifactmode_pluginfile'] = 'Plugin file storage';

// Replacement modes.
$string['replacementmode_none'] = 'None';
$string['replacementmode_badge'] = 'Badge in achievement view';
$string['replacementmode_notification'] = 'Moodle notification';

// Navigation and page titles.
$string['myachievements'] = 'My Achievements';
$string['achievementledger'] = 'Achievement Ledger';
$string['coursemappings'] = 'Course Replacement Mappings';
$string['purgeaudit'] = 'Purge Audit Log';

// Table column headers.
$string['col_coursename'] = 'Course';
$string['col_completiondate'] = 'Completed';
$string['col_grade'] = 'Grade';
$string['col_passed'] = 'Passed';
$string['col_programs'] = 'Programs';
$string['col_artifact'] = 'Certificate';
$string['col_source'] = 'Source';
$string['col_source_event'] = 'Source event';
$string['col_captured'] = 'Captured';
$string['col_user'] = 'User';
$string['col_firstname'] = 'First name';
$string['col_lastname'] = 'Last name';
$string['col_email'] = 'Email';
$string['col_useridnumber'] = 'User ID#';
$string['col_enrolleddate'] = 'Enrolled date';
$string['col_courseidnumber'] = 'Course ID#';
$string['col_courseshortname'] = 'Short name';
$string['col_oldcourse'] = 'Old course';
$string['col_newcourse'] = 'New course';
$string['col_migrationrule'] = 'Migration rule';
$string['col_active'] = 'Active';
$string['col_effectivetime'] = 'Effective date';
$string['col_note'] = 'Notes';
$string['col_reason'] = 'Reason';
$string['col_details'] = 'Details';

// Ledger filter UI.
$string['filter_heading'] = 'Filters';
$string['filter_passed'] = 'Pass status';
$string['filter_passed_any'] = 'Any';
$string['filter_passed_yes'] = 'Passed';
$string['filter_passed_no'] = 'Not passed';
$string['filter_passed_unknown'] = 'Unknown / N/A';
$string['filter_datefrom'] = 'Completed from';
$string['filter_dateto'] = 'Completed to';
$string['filter_programs_heading'] = 'Program association';
$string['filter_hasprograms'] = 'Has at least one program';
$string['filter_programs'] = 'Specific programs';
$string['filter_programs_help'] = 'Hold Ctrl / Cmd to select multiple. Selecting a program implies "Has program".';
$string['filter_columns'] = 'Extra columns';

// Migration rules.
$string['migrationrule_redirect_incomplete'] = 'Redirect incomplete learners';
$string['migrationrule_recommend'] = 'Recommend only';

// Actions.
$string['addmapping'] = 'Add course mapping';
$string['editmapping'] = 'Edit course mapping';
$string['deletemapping'] = 'Delete course mapping';
$string['confirmdeletemapping'] = 'Are you sure you want to delete this course replacement mapping?';
$string['mappingsaved'] = 'Course replacement mapping saved.';
$string['mappingdeleted'] = 'Course replacement mapping deleted.';

// Replacement recommendations.
$string['replacedby'] = 'Replaced by: {$a}';
$string['replacementavailable'] = 'A replacement course is available';

// Status and info messages.
$string['noachievements'] = 'No achievement records found.';
$string['nomappings'] = 'No course replacement mappings found.';
$string['plugindisabled'] = 'The Completion History plugin is currently disabled.';
$string['gradepassed'] = 'Passed';
$string['gradefailed'] = 'Not passed';
$string['gradeunknown'] = 'N/A';

// CLI strings.
$string['cli_backfill_started'] = 'Starting achievement backfill...';
$string['cli_backfill_dryrun'] = 'DRY RUN - no records will be inserted.';
$string['cli_backfill_complete'] = 'Backfill complete. Scanned: {$a->scanned}, Inserted: {$a->inserted}, Skipped: {$a->skipped}, Errors: {$a->errors}';
$string['cli_audit_started'] = 'Starting achievement audit...';
$string['cli_audit_complete'] = 'Audit complete.';

// Privacy.
$string['privacy:metadata:achievement'] = 'Records of course completions captured as immutable achievement history.';
$string['privacy:metadata:achievement:userid'] = 'The user who completed the course.';
$string['privacy:metadata:achievement:firstname_snapshot'] = 'The user\'s first name at the time of completion.';
$string['privacy:metadata:achievement:lastname_snapshot'] = 'The user\'s last name at the time of completion.';
$string['privacy:metadata:achievement:email_snapshot'] = 'The user\'s email address at the time of completion.';
$string['privacy:metadata:achievement:coursename_snapshot'] = 'The course name at the time of completion.';
$string['privacy:metadata:achievement:completiontime'] = 'When the course was completed.';
$string['privacy:metadata:achievement:enrolledtime_snapshot'] = 'When the user first enrolled in the course.';
$string['privacy:metadata:achievement:grade_decimal'] = 'The final grade at the time of completion.';
$string['privacy:metadata:ach_program'] = 'Program associations for achievement records.';
$string['privacy:metadata:purge_audit'] = 'Audit trail of completion purge events.';
$string['privacy:metadata:purge_audit:userid'] = 'The user affected by the purge.';

// Task names.
$string['task_reconcile_ledger'] = 'Reconcile achievement ledger';
$string['task_process_outbox'] = 'Process SIS sync outbox';

// Errors.
$string['error_nocourse'] = 'Course not found.';
$string['error_nouser'] = 'User not found.';
$string['error_duplicatehash'] = 'Achievement already recorded (duplicate event hash).';
$string['error_plugindisabled'] = 'Completion History plugin is disabled.';
