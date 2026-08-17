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
 * Admin settings for local_completionhistory.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_completionhistory', get_string('pluginname', 'local_completionhistory'));

    // Enable plugin.
    $settings->add(new admin_setting_configcheckbox(
        'local_completionhistory/enabled',
        get_string('setting_enabled', 'local_completionhistory'),
        get_string('setting_enabled_desc', 'local_completionhistory'),
        1
    ));

    // Auto-capture on course_completed event.
    $settings->add(new admin_setting_configcheckbox(
        'local_completionhistory/autocapture',
        get_string('setting_autocapture', 'local_completionhistory'),
        get_string('setting_autocapture_desc', 'local_completionhistory'),
        1
    ));

    // Capture grade snapshots.
    $settings->add(new admin_setting_configcheckbox(
        'local_completionhistory/capturegrades',
        get_string('setting_capturegrades', 'local_completionhistory'),
        get_string('setting_capturegrades_desc', 'local_completionhistory'),
        1
    ));

    // Backfill batch size.
    $settings->add(new admin_setting_configtext(
        'local_completionhistory/backfillbatchsize',
        get_string('setting_backfillbatchsize', 'local_completionhistory'),
        get_string('setting_backfillbatchsize_desc', 'local_completionhistory'),
        1000,
        PARAM_INT
    ));

    // Enable purge-audit handling.
    $settings->add(new admin_setting_configcheckbox(
        'local_completionhistory/enablepurgeaudit',
        get_string('setting_enablepurgeaudit', 'local_completionhistory'),
        get_string('setting_enablepurgeaudit_desc', 'local_completionhistory'),
        1
    ));

    // Enable user achievements page.
    $settings->add(new admin_setting_configcheckbox(
        'local_completionhistory/enableuserachievements',
        get_string('setting_enableuserachievements', 'local_completionhistory'),
        get_string('setting_enableuserachievements_desc', 'local_completionhistory'),
        1
    ));

    // Artifact storage mode.
    $settings->add(new admin_setting_configselect(
        'local_completionhistory/artifactstoragemode',
        get_string('setting_artifactstoragemode', 'local_completionhistory'),
        get_string('setting_artifactstoragemode_desc', 'local_completionhistory'),
        'none',
        [
            'none' => get_string('artifactmode_none', 'local_completionhistory'),
            'url' => get_string('artifactmode_url', 'local_completionhistory'),
            'pluginfile' => get_string('artifactmode_pluginfile', 'local_completionhistory'),
        ]
    ));

    // Replacement notification mode.
    $settings->add(new admin_setting_configselect(
        'local_completionhistory/replacementnotification',
        get_string('setting_replacementnotification', 'local_completionhistory'),
        get_string('setting_replacementnotification_desc', 'local_completionhistory'),
        'badge',
        [
            'none' => get_string('replacementmode_none', 'local_completionhistory'),
            'badge' => get_string('replacementmode_badge', 'local_completionhistory'),
            'notification' => get_string('replacementmode_notification', 'local_completionhistory'),
        ]
    ));

    // GDPR anonymize on user deletion.
    $settings->add(new admin_setting_configcheckbox(
        'local_completionhistory/gdpranonymize',
        get_string('setting_gdpranonymize', 'local_completionhistory'),
        get_string('setting_gdpranonymize_desc', 'local_completionhistory'),
        0
    ));

    // Enable external SIS sync outbox (transactional outbox queue).
    $settings->add(new admin_setting_configcheckbox(
        'local_completionhistory/enableoutbox',
        get_string('setting_enableoutbox', 'local_completionhistory'),
        get_string('setting_enableoutbox_desc', 'local_completionhistory'),
        0
    ));

    // Source site identifier stamped into every SIS sync payload.
    $settings->add(new admin_setting_configtext(
        'local_completionhistory/sourcesite',
        get_string('setting_sourcesite', 'local_completionhistory'),
        get_string('setting_sourcesite_desc', 'local_completionhistory'),
        $CFG->wwwroot,
        PARAM_TEXT
    ));

    $ADMIN->add('localplugins', $settings);

    // External pages for admin navigation.
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_completionhistory_ledger',
        get_string('achievementledger', 'local_completionhistory'),
        new moodle_url('/local/completionhistory/achievement_ledger.php'),
        'local/completionhistory:viewall'
    ));

    $ADMIN->add('localplugins', new admin_externalpage(
        'local_completionhistory_coursemappings',
        get_string('coursemappings', 'local_completionhistory'),
        new moodle_url('/local/completionhistory/course_mappings.php'),
        'local/completionhistory:managecoursemap'
    ));
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_completionhistory_examconfig',
        get_string('courseexamconfig', 'local_completionhistory'),
        new moodle_url('/local/completionhistory/course_exam_config.php'),
        'local/completionhistory:manage'
    ));
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_completionhistory_attemptlog',
        get_string('examattemptlog', 'local_completionhistory'),
        new moodle_url('/local/completionhistory/exam_attempt_log.php'),
        'local/completionhistory:viewall'
    ));
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_completionhistory_manageflags',
        get_string('manageflags', 'local_completionhistory'),
        new moodle_url('/local/completionhistory/manage_flags.php'),
        'local/completionhistory:manage'
    ));
}
