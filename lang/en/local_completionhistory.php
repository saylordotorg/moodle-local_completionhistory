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
$string['completionhistory:viewown']         = 'View own achievement history';
$string['completionhistory:viewall']         = 'View all achievement history';
$string['completionhistory:manage']          = 'Manage achievement history';
$string['completionhistory:managecoursemap'] = 'Manage course replacement mappings';
$string['completionhistory:runbackfill']     = 'Run achievement backfill';

// Settings.
$string['setting_enabled']               = 'Enable plugin';
$string['setting_enabled_desc']          = 'Enable or disable the Completion History plugin.';
$string['setting_autocapture']           = 'Auto-capture completions';
$string['setting_autocapture_desc']      = 'Automatically capture an achievement record when a course is completed.';
$string['setting_capturegrades']         = 'Capture grade snapshots';
$string['setting_capturegrades_desc']    = 'Include the course total grade when capturing achievement records.';
$string['setting_backfillbatchsize']     = 'Backfill batch size';
$string['setting_backfillbatchsize_desc']= 'Maximum number of records to process per backfill batch.';
$string['setting_enablepurgeaudit']      = 'Enable purge audit';
$string['setting_enablepurgeaudit_desc'] = 'Log an audit record when course completions are purged by other plugins.';
$string['setting_enableuserachievements']     = 'Enable user achievements page';
$string['setting_enableuserachievements_desc']= 'Show the "My Achievements" page in user navigation.';
$string['setting_artifactstoragemode']        = 'Artifact storage mode';
$string['setting_artifactstoragemode_desc']   = 'How certificate/transcript artifact URLs are stored.';
$string['setting_replacementnotification']     = 'Replacement notification mode';
$string['setting_replacementnotification_desc']= 'How to notify users about course replacement recommendations.';
$string['setting_gdpranonymize']     = 'Anonymize on user deletion';
$string['setting_gdpranonymize_desc']= 'When a user is deleted, anonymize their achievement records instead of keeping full PII. Achievement records are retained as institutional academic records.';
$string['setting_enableoutbox']      = 'Enable SIS sync outbox';
$string['setting_enableoutbox_desc'] = 'Queue each captured achievement into a transactional outbox for the external Saylor SIS to consume (via the get_unsynced_outbox / mark_outbox_sent web services). Off by default.';

// Artifact & replacement modes.
$string['artifactmode_none']      = 'None';
$string['artifactmode_url']       = 'External URL';
$string['artifactmode_pluginfile']= 'Plugin file storage';
$string['replacementmode_none']         = 'None';
$string['replacementmode_badge']        = 'Badge in achievement view';
$string['replacementmode_notification'] = 'Moodle notification';

// Navigation / page titles.
$string['myachievements']   = 'My Achievements';
$string['achievementledger']= 'Achievement Ledger';
$string['coursemappings']   = 'Course Replacement Mappings';
$string['courseexamconfig'] = 'Course Exam Configuration';
$string['examattemptlog']   = 'Exam Attempt Log';
$string['purgeaudit']       = 'Purge Audit Log';
$string['manageflags']      = 'Manage System Flags';

// ── Table column headers ─────────────────────────────────────────────────────
$string['col_user']                  = 'User';
$string['col_firstname']             = 'First Name';
$string['col_lastname']              = 'Last Name';
$string['col_email']                 = 'Email';
$string['col_country']               = 'Country';
$string['col_duration']              = 'Duration';
$string['col_flags']                 = 'Flags';
$string['col_useridnumber']          = 'User ID#';
$string['col_coursename']            = 'Course';
$string['col_courseshortname']       = 'Short Name';
$string['col_courseidnumber']        = 'Course ID#';
$string['col_enroldate']             = 'Enrolled';
$string['col_enroldays']             = 'Days Enrolled';
$string['col_completiondate']        = 'Completed';
$string['col_completiondays']        = 'Days to complete';
$string['col_grade']                 = 'Grade';
$string['col_passed']                = 'Passed';
$string['col_exam_track']            = 'Exam Track';
$string['col_attempts']              = 'Attempts';
$string['col_programs']              = 'Programs';
$string['col_artifact']              = 'Certificate';
$string['col_source']                = 'Source';
$string['col_source_event']          = 'Source event';
$string['col_captured']              = 'Captured';
$string['col_oldcourse']             = 'Old course';
$string['col_newcourse']             = 'New course';
$string['col_migrationrule']         = 'Migration rule';
$string['col_active']                = 'Active';
$string['col_effectivetime']         = 'Effective date';
$string['col_note']                  = 'Notes';
$string['col_reason']                = 'Reason';
$string['col_details']               = 'Details';

// Exam attempt log column headers.
$string['col_attempt_number']       = 'Attempt';
$string['col_attempt_result']       = 'Result';
$string['col_attempt_date']         = 'Date';
$string['col_achievement_link']     = 'Achievement';

// ── Ledger filter UI ─────────────────────────────────────────────────────────
$string['filter_heading']          = 'Filters';
$string['filter_passed']           = 'Pass status';
$string['filter_passed_any']       = 'Any';
$string['filter_passed_yes']       = 'Passed';
$string['filter_passed_no']        = 'Not passed';
$string['filter_passed_unknown']   = 'Unknown / N/A';
$string['filter_datefrom']         = 'Completed from';
$string['filter_dateto']           = 'Completed to';
$string['filter_programs_heading'] = 'Program association';
$string['filter_hasprograms']      = 'Has at least one program';
$string['filter_programs']         = 'Specific programs';
$string['filter_programs_help']    = 'Hold Ctrl / Cmd to select multiple. Selecting a program implies "Has program".';
$string['filter_columns']          = 'Columns';
$string['filter_columns_help']     = 'Check columns to include them in the table. Drag the badges below to reorder. Uncheck to remove a column from the table.';
$string['filter_columns_search']   = 'Search columns…';
$string['filter_columns_all']      = 'All';
$string['colcat_user']             = 'User';
$string['colcat_course']           = 'Course';
$string['colcat_exam']             = 'Exam';
$string['colcat_grade']            = 'Grade';
$string['colcat_time']             = 'Time';
$string['colcat_flags']            = 'Flags';
$string['colcat_other']            = 'Other';
$string['filter_colorder']         = 'Column order';
$string['filter_colorder_help']    = 'Drag the badges left or right to reorder columns. Click Apply to save.';

$string['savelayout']          = 'Save layout';
$string['savelayout_help']     = 'Save the current visible columns and order as your default for this page.';
$string['resetlayout']         = 'Reset layout';
$string['resetlayout_help']    = 'Remove the saved column layout and return to the plugin defaults.';
$string['resetlayout_confirm'] = 'Remove your saved column layout and return to the default?';
$string['layoutsaved']         = 'Column layout saved.';
$string['layoutreset']         = 'Column layout reset to default.';
$string['layout_using_saved']  = 'Using your saved column layout.';

$string['savedefault']          = 'Save as site default';
$string['savedefault_help']     = 'Save the current columns and order as the site-wide default for this page (visible to all users who have not saved their own layout).';
$string['resetdefault']         = 'Reset site default';
$string['resetdefault_help']    = 'Remove the site-wide default column layout and fall back to the plugin built-ins.';
$string['resetdefault_confirm'] = 'Remove the site-wide default column layout?';
$string['layoutdefaultsaved']   = 'Site-wide default column layout saved.';
$string['layoutdefaultreset']   = 'Site-wide default column layout reset.';
$string['layout_using_default'] = 'Using the site-wide default column layout.';
$string['filter_examtrack']        = 'Exam track';
$string['filter_examtrack_any']    = 'Any track';
$string['filter_user_search']      = 'User search';
$string['filter_exhausted_only']   = 'Only show rows where the track is exhausted';
$string['filter_completing_only']  = 'Only show the attempt that completed the course';

// ── Course types ─────────────────────────────────────────────────────────────
$string['course_type_standard']  = 'Standard (no exam tracking)';
$string['course_type_program']   = 'Program course — one final exam';
$string['course_type_open_dual'] = 'Open course — Direct Credit + Certificate tracks';
$string['course_type_open_cert'] = 'Open course — Certificate track only';

// ── Exam tracks ──────────────────────────────────────────────────────────────
$string['track_program_final'] = 'Program Final';
$string['track_direct_credit'] = 'Direct Credit';
$string['track_certificate']   = 'Certificate';

// ── Course exam config admin UI ──────────────────────────────────────────────
$string['examconfig_add']              = 'Add Course Exam Config';
$string['examconfig_edit']             = 'Edit Course Exam Config';
$string['examconfig_none']             = 'No courses have been configured yet. Click "Add" to get started.';
$string['examconfig_type']             = 'Course type';
$string['examconfig_quiz']             = 'Exam quiz';
$string['examconfig_noquiz']           = '— Not mapped —';
$string['examconfig_attempts']         = 'Attempts allowed';
$string['examconfig_cert_attempts']    = 'Cert attempts (0=unlimited)';
$string['examconfig_cert_attempts_help']= 'Enter 0 for unlimited certificate exam attempts.';
$string['examconfig_notes']            = 'Notes';
$string['examconfig_courseid_help']    = 'Enter the Moodle course ID (visible in the course URL).';
$string['examconfig_confirmdelete']    = 'Delete this exam configuration? This does not affect existing attempt records.';
$string['examconfigsaved']             = 'Course exam configuration saved.';
$string['examconfigdeleted']           = 'Course exam configuration deleted.';

// ── Attempt history (expand panel) ──────────────────────────────────────────
$string['attempts_panel_title']     = 'Exam Attempt History';
$string['attempts_none']            = 'No attempt records found for this course.';
$string['attempt_track']            = 'Track';
$string['attempt_number']           = 'Attempt';
$string['attempt_grade']            = 'Grade';
$string['attempt_result']           = 'Result';
$string['attempt_date']             = 'Date';
$string['attempt_completing']       = 'Completing attempt';
$string['track_exhausted']          = 'Track exhausted';

// ── Migration rules ──────────────────────────────────────────────────────────
$string['migrationrule_redirect_incomplete'] = 'Redirect incomplete learners';
$string['migrationrule_recommend']           = 'Recommend only';

// ── Actions ──────────────────────────────────────────────────────────────────
$string['addmapping']           = 'Add course mapping';
$string['editmapping']          = 'Edit course mapping';
$string['deletemapping']        = 'Delete course mapping';
$string['confirmdeletemapping'] = 'Are you sure you want to delete this course replacement mapping?';
$string['mappingsaved']         = 'Course replacement mapping saved.';
$string['mappingdeleted']       = 'Course replacement mapping deleted.';

// ── Replacement recommendations ──────────────────────────────────────────────
$string['replacedby']             = 'Replaced by: {$a}';
$string['replacementavailable']   = 'A replacement course is available';

// ── Status messages ──────────────────────────────────────────────────────────
$string['noachievements']  = 'No achievement records found.';
$string['nomappings']      = 'No course replacement mappings found.';
$string['plugindisabled']  = 'The Completion History plugin is currently disabled.';
$string['gradepassed']     = 'Passed';
$string['gradefailed']     = 'Not passed';
$string['gradeunknown']    = 'N/A';

// ── CLI ──────────────────────────────────────────────────────────────────────
$string['cli_backfill_started']  = 'Starting achievement backfill...';
$string['cli_backfill_dryrun']   = 'DRY RUN - no records will be inserted.';
$string['cli_backfill_complete'] = 'Backfill complete. Scanned: {$a->scanned}, Inserted: {$a->inserted}, Skipped: {$a->skipped}, Errors: {$a->errors}';
$string['cli_audit_started']     = 'Starting achievement audit...';
$string['cli_audit_complete']    = 'Audit complete.';

// ── Privacy ──────────────────────────────────────────────────────────────────
$string['privacy:metadata:achievement']                          = 'Records of course completions captured as immutable achievement history.';
$string['privacy:metadata:achievement:userid']                   = 'The user who completed the course.';
$string['privacy:metadata:achievement:firstname_snapshot']       = 'The user\'s first name at the time of completion.';
$string['privacy:metadata:achievement:lastname_snapshot']        = 'The user\'s last name at the time of completion.';
$string['privacy:metadata:achievement:email_snapshot']           = 'The user\'s email address at the time of completion.';
$string['privacy:metadata:achievement:coursename_snapshot']      = 'The course name at the time of completion.';
$string['privacy:metadata:achievement:completiontime']           = 'When the course was completed.';
$string['privacy:metadata:achievement:enrolledtime_snapshot']    = 'When the user first enrolled in the course.';
$string['privacy:metadata:achievement:grade_decimal']            = 'The final grade at the time of completion.';
$string['privacy:metadata:ach_program']                          = 'Program associations for achievement records.';
$string['privacy:metadata:purge_audit']                          = 'Audit trail of completion purge events.';
$string['privacy:metadata:purge_audit:userid']                   = 'The user affected by the purge.';

// ── Tasks ────────────────────────────────────────────────────────────────────
$string['task_reconcile_ledger']        = 'Reconcile achievement ledger';
$string['task_process_outbox']          = 'Process SIS sync outbox';
$string['task_reconcile_anonymization'] = 'Reconcile anonymization for deleted users';

// ── System flags ─────────────────────────────────────────────────────────────
$string['addflag']                    = 'Add flag';
$string['editflag']                   = 'Edit flag';
$string['flags_none']                 = 'No system flags defined yet. Use the Add flag button to create one.';
$string['flag_name']                  = 'Name';
$string['flag_code']                  = 'Code';
$string['flag_code_help']             = 'A short machine-friendly identifier. Letters, numbers, underscore and hyphen only.';
$string['flag_description']           = 'Description';
$string['flag_type']                  = 'Type';
$string['flag_config']                = 'Configuration';
$string['flag_severity']              = 'Severity';
$string['flag_enabled']               = 'Enabled';
$string['flagtype_fast_completion']   = 'Fast completion (duration at most)';
$string['flagtype_duration_exact']    = 'Duration exact (with tolerance)';
$string['flagtype_score_range']       = 'Score in range';
$string['flagtype_duplicate_account'] = 'Duplicate account';
$string['flagtype_new_account']       = 'New account (age before exam)';
$string['flagseverity_info']          = 'Info';
$string['flagseverity_warning']       = 'Warning';
$string['flagseverity_critical']      = 'Critical';
$string['flag_threshold_minutes']     = 'Threshold (minutes)';
$string['flag_threshold_minutes_help']= 'Flag any attempt completed in this many minutes or fewer.';
$string['flag_duration_minutes']      = 'Target duration (minutes)';
$string['flag_tolerance_seconds']     = 'Tolerance (seconds)';
$string['flag_tolerance_seconds_help']= 'Match is within this many seconds of the target duration. 10s is a good default for auto-submitted exams.';
$string['flag_score_min']             = 'Minimum score (%)';
$string['flag_score_max']             = 'Maximum score (%)';
$string['flag_score_range_help']      = 'Both bounds are inclusive. Attempts with no grade do not match.';
$string['flag_score_range_invalid']   = 'Score range is invalid. Min must be 0–100, Max must be 0–100, and Min cannot exceed Max.';
$string['flag_max_days_before']       = 'Maximum account age at exam time (days)';
$string['flag_max_days_before_help']  = 'Flags the attempt if the user account was created fewer than this many days before the exam was taken.';
$string['flag_same_email_domain']     = 'Also require matching @domain in email';
$string['flag_duplicate_account_help']= 'Flags the attempt when another non-deleted user exists with the same first + last name. Optionally tighten the match by also requiring the same email domain.';
$string['flag_threshold_required']    = 'Please enter a threshold greater than 0 minutes.';
$string['flag_duration_required']     = 'Please enter a target duration greater than 0 minutes.';
$string['flag_maxdays_required']      = 'Please enter an account age threshold greater than 0 days.';
$string['flag_invalid_type']          = 'Invalid flag type.';
$string['flag_invalid_severity']      = 'Invalid severity.';
$string['flag_code_taken']            = 'That code is already in use by another flag.';
$string['flagsaved']                  = 'Flag saved.';
$string['flagdeleted']                = 'Flag deleted.';
$string['flagenabled']                = 'Flag enabled.';
$string['flagdisabled']               = 'Flag disabled.';
$string['flagenable']                 = 'Enable';
$string['flagdisable']                = 'Disable';
$string['flagdelete_confirm']         = 'Delete this flag? Past evaluations are not stored, so nothing is lost — you can recreate it later.';
$string['flagsloadpresets']           = 'Load preset flags';
$string['flagsloadpresets_help']      = 'Inserts the standard rubric of seven system flags (score 0, score ≤20, score ≥90, duration ≤20m, duration =2h, duplicate, new account). Existing flags with the same code are kept untouched.';
$string['flagsloadpresets_confirm']   = 'Insert any missing preset flags? Existing flags you have edited will be left alone.';
$string['flagspresetsloaded']         = 'Preset flags loaded: {$a} inserted.';

// ── Errors ───────────────────────────────────────────────────────────────────
$string['error_nocourse']       = 'Course not found.';
$string['error_nouser']         = 'User not found.';
$string['error_duplicatehash']  = 'Achievement already recorded (duplicate event hash).';
$string['error_plugindisabled'] = 'Completion History plugin is disabled.';
