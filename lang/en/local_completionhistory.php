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
$string['completionhistory:viewcertificates'] = 'Read learners\' issued certificates through the integration';
$string['completionhistory:viewown']         = 'View own achievement history';
$string['completionhistory:viewall']         = 'View all achievement history';
$string['completionhistory:manage']          = 'Manage achievement history';
$string['completionhistory:managecoursemap'] = 'Manage course replacement mappings';
$string['completionhistory:runbackfill']     = 'Run achievement backfill';
$string['completionhistory:integrate']       = 'Access completion-history integration data';
$string['completionhistory:provisionusers']  = 'Provision users through the integration';
$string['completionhistory:resetpasswords']  = 'Complete initial password setup through the integration';
$string['completionhistory:createloginkeys'] = 'Create learner single sign-on keys';
$string['completionhistory:updateprofiles']  = 'Update learner profiles through the integration';
$string['completionhistory:enrolusers']      = 'Enrol learners through the integration';
$string['catalogtoolarge'] = 'The catalog is too large for the snapshot API. Use a paginated integration endpoint.';
$string['programregistrytoolarge'] = 'The program registry is too large for the snapshot API. Use a paginated integration endpoint.';
$string['inprogresscoursestoolarge'] = 'This learner has too many in-progress courses for one response.';
$string['activitydetailtoolarge'] = 'The requested per-course activity detail is too large. Request fewer users.';
$string['manualenrolunavailable'] = 'Manual enrolment is not installed on this site.';
$string['studentrolemissing'] = 'No role with shortname "student" exists, so the integration cannot grant student permissions.';
$string['provisioninglockunavailable'] = 'Account provisioning is already in progress for this email address.';

// Events.
$string['event_sso_login'] = 'Signed in from the SIS with a single-use key';

// Single sign-on (SIS-29).
$string['sso_linkexpired'] = 'That sign-in link has expired or was already used. Please sign in and you will be taken where you were going.';

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
$string['setting_sourcesite']        = 'Source site identifier';
$string['setting_sourcesite_desc']   = 'Identifies this Moodle site in every SIS sync payload (the sourcesite field), so the SIS can tell which install a record came from when the plugin runs on more than one site. Defaults to this site\'s URL; if left blank, payloads fall back to the site URL.';
$string['ambiguousemail']            = 'More than one local account uses that email address. The integration cannot safely choose an account.';
$string['sso_alreadysignedin']       = 'This sign-on link was for a different account. Your current session was not changed.';
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
$string['privacy:metadata:achievement:ledgeruuid']               = 'A stable pseudonymous identifier for the achievement.';
$string['privacy:metadata:achievement:userid']                   = 'The user who completed the course.';
$string['privacy:metadata:achievement:useridnumber_snapshot']    = 'The user idnumber at the time of completion.';
$string['privacy:metadata:achievement:firstname_snapshot']       = 'The user\'s first name at the time of completion.';
$string['privacy:metadata:achievement:lastname_snapshot']        = 'The user\'s last name at the time of completion.';
$string['privacy:metadata:achievement:email_snapshot']           = 'The user\'s email address at the time of completion.';
$string['privacy:metadata:achievement:courseid']                 = 'The course associated with the user\'s achievement.';
$string['privacy:metadata:achievement:courseidnumber_snapshot']  = 'The course idnumber at the time of completion.';
$string['privacy:metadata:achievement:courseshortname_snapshot'] = 'The course short name at the time of completion.';
$string['privacy:metadata:achievement:coursename_snapshot']      = 'The course name at the time of completion.';
$string['privacy:metadata:achievement:completiontime']           = 'When the course was completed.';
$string['privacy:metadata:achievement:enrolledtime_snapshot']    = 'When the user first enrolled in the course.';
$string['privacy:metadata:achievement:grade_decimal']            = 'The final grade at the time of completion.';
$string['privacy:metadata:achievement:grade_passed']             = 'Whether the final grade passed the configured threshold.';
$string['privacy:metadata:achievement:grade_source']             = 'The source from which the user\'s grade was captured.';
$string['privacy:metadata:achievement:exam_track']               = 'The assessment track on which the user completed the course.';
$string['privacy:metadata:achievement:attempts_used']            = 'The number of assessment attempts used by the user.';
$string['privacy:metadata:achievement:attempts_allowed']         = 'The assessment-attempt limit applying to the user.';
$string['privacy:metadata:achievement:artifacturl']              = 'The user-specific certificate or transcript URL.';
$string['privacy:metadata:achievement:artifactstorage']          = 'The user-specific certificate storage reference.';
$string['privacy:metadata:achievement:source_component']         = 'The component that recorded the user\'s achievement.';
$string['privacy:metadata:achievement:source_event']             = 'The event that recorded the user\'s achievement.';
$string['privacy:metadata:achievement:source_event_hash']        = 'A keyed pseudonymous deduplication identifier for the user\'s achievement.';
$string['privacy:metadata:achievement:timecreated']              = 'When the user\'s achievement record was created.';
$string['privacy:metadata:ach_program']                          = 'Program associations for achievement records.';
$string['privacy:metadata:ach_program:achievementid']            = 'The achievement associated with the user\'s program snapshot.';
$string['privacy:metadata:ach_program:allocationid']             = 'The user\'s program-allocation reference at capture time.';
$string['privacy:metadata:ach_program:programid']                = 'The program associated with the user\'s achievement.';
$string['privacy:metadata:ach_program:programidnumber_snapshot'] = 'The program idnumber associated with the user\'s achievement.';
$string['privacy:metadata:ach_program:programname_snapshot']     = 'The program name associated with the user\'s achievement.';
$string['privacy:metadata:ach_program:timecreated']              = 'When the user\'s program association was captured.';
$string['privacy:metadata:purge_audit']                          = 'Audit trail of completion purge events.';
$string['privacy:metadata:purge_audit:userid']                   = 'The user affected by the purge.';
$string['privacy:metadata:purge_audit:programid']                = 'The program associated with the user-data purge.';
$string['privacy:metadata:purge_audit:reason']                   = 'The reason the user data was purged.';
$string['privacy:metadata:purge_audit:detailsjson']              = 'Operational details about the user data affected by the purge.';
$string['privacy:metadata:purge_audit:timecreated']              = 'When the user-data purge was recorded.';
$string['privacy:metadata:exam_attempt']                         = 'Per-attempt academic history for configured assessments.';
$string['privacy:metadata:exam_attempt:userid']                  = 'The user who made the attempt.';
$string['privacy:metadata:exam_attempt:courseid']                = 'The course in which the user made the attempt.';
$string['privacy:metadata:exam_attempt:quizid']                  = 'The assessment the user attempted.';
$string['privacy:metadata:exam_attempt:exam_track']              = 'The assessment track used for the attempt.';
$string['privacy:metadata:exam_attempt:attempt_number']          = 'The user\'s attempt number within the assessment track.';
$string['privacy:metadata:exam_attempt:attempts_allowed']        = 'The attempt limit applying to the user on this track.';
$string['privacy:metadata:exam_attempt:grade_decimal']           = 'The grade earned on the attempt.';
$string['privacy:metadata:exam_attempt:grade_passed']            = 'Whether the attempt passed the configured threshold.';
$string['privacy:metadata:exam_attempt:resulted_in_completion']  = 'Whether the user\'s attempt completed the course.';
$string['privacy:metadata:exam_attempt:achievementid']           = 'The achievement produced by the user\'s attempt, if any.';
$string['privacy:metadata:exam_attempt:timetaken']               = 'When the user submitted the attempt.';
$string['privacy:metadata:exam_attempt:duration']                = 'How long the user spent on the attempt.';
$string['privacy:metadata:exam_attempt:timecreated']             = 'When the user\'s attempt record was created.';
$string['privacy:metadata:outbox']                               = 'Queued copies of achievement data sent to the external SIS.';
$string['privacy:metadata:outbox:entitytype']                    = 'The type of user record queued for synchronization.';
$string['privacy:metadata:outbox:entityid']                      = 'The achievement associated with the queued personal data.';
$string['privacy:metadata:outbox:payloadjson']                   = 'A JSON copy of the user identity, course, grade, program and certificate data to synchronize.';
$string['privacy:metadata:outbox:status']                        = 'The delivery status of the queued user data.';
$string['privacy:metadata:outbox:retrycount']                    = 'The number of attempts made to deliver the queued user data.';
$string['privacy:metadata:outbox:lasterror']                     = 'The most recent delivery error, which may include details about rejected user data.';
$string['privacy:metadata:outbox:timecreated']                   = 'When the queued copy of user data was created.';
$string['privacy:metadata:outbox:timemodified']                  = 'When the queued copy of user data was last updated.';
$string['privacy:metadata:saylor_sis']                           = 'The plugin exchanges learner, enrolment, activity and academic-record data with the configured Saylor SIS web-service client.';
$string['privacy:metadata:saylor_sis:userid']                    = 'The Moodle user id is sent as the learner identity key.';
$string['privacy:metadata:saylor_sis:useridnumber']              = 'The user idnumber is sent as an institutional identity key.';
$string['privacy:metadata:saylor_sis:name']                      = 'The user\'s first and last names are sent for identity matching.';
$string['privacy:metadata:saylor_sis:email']                     = 'The user\'s email address is sent for identity matching and account provisioning.';
$string['privacy:metadata:saylor_sis:enrolment']                 = 'Program and course enrolment information is exchanged for provisioning and progression.';
$string['privacy:metadata:saylor_sis:grades']                    = 'Grades, results, attempts, completions and certificate references are sent as academic records.';
$string['privacy:metadata:saylor_sis:activity']                  = 'Login and course-access timestamps are sent for learner engagement reporting.';
$string['privacy:metadata:preference:ledger_cols']               = 'The user\'s saved achievement-ledger column layout.';
$string['privacy:metadata:preference:attempts_cols']             = 'The user\'s saved exam-attempt column layout.';
$string['privacy:export:exam_attempts']                          = 'Exam attempts';
$string['privacy:export:outbox']                                 = 'SIS synchronization queue';
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
