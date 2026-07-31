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

namespace local_completionhistory\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * External function: manually enrol a user (by email) into a course (by
 * idnumber or shortname) as a student. Used by the SIS course-window pacer
 * (SIS-53/66 — students activate 3-4 courses at a time and the SIS opens
 * exactly those) and by conferral (alumni resource center enrolment).
 *
 * Idempotent: an existing active enrolment is reported, not duplicated.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrol_user_in_course extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'email'          => new external_value(PARAM_EMAIL, 'User email (identity key)'),
            'courseidnumber' => new external_value(PARAM_RAW, 'Course idnumber (falls back to shortname match)'),
        ]);
    }

    public static function execute(string $email, string $courseidnumber): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/lib/enrollib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'email'          => $email,
            'courseidnumber' => $courseidnumber,
        ]);

        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);
        require_capability('local/completionhistory:manage', $systemcontext);

        $user = $DB->get_record('user', [
            'email' => \core_text::strtolower($params['email']),
            'deleted' => 0,
            'mnethostid' => $CFG->mnet_localhost_id,
        ], '*', MUST_EXIST);

        $course = $DB->get_record('course', ['idnumber' => $params['courseidnumber']]);
        if (!$course) {
            $course = $DB->get_record('course', ['shortname' => $params['courseidnumber']], '*', MUST_EXIST);
        }

        // Already actively enrolled (any method) — idempotent success.
        $context = \context_course::instance($course->id);
        if (is_enrolled($context, $user, '', true)) {
            return ['ok' => true, 'courseid' => (int) $course->id, 'already' => true, 'warning' => ''];
        }

        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual', 'status' => ENROL_INSTANCE_ENABLED]);
        $warning = '';
        if (!$instance) {
            // Add (or re-enable) a manual instance so the SIS can always place students.
            $plugin = enrol_get_plugin('manual');
            $disabled = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
            if ($disabled) {
                $plugin->update_status($disabled, ENROL_INSTANCE_ENABLED);
                $instance = $DB->get_record('enrol', ['id' => $disabled->id], '*', MUST_EXIST);
                $warning = 'Re-enabled the manual enrolment instance.';
            } else {
                $instanceid = $plugin->add_instance($course, $plugin->get_instance_defaults());
                $instance = $DB->get_record('enrol', ['id' => $instanceid], '*', MUST_EXIST);
                $warning = 'Created a manual enrolment instance.';
            }
        }

        // Resolved by shortname, and a FAILURE if there is no such role. The previous
        // `?: 5` fell back to a database-specific numeric id: on a site with customised
        // roles, id 5 may be an unrelated role or none at all, so the endpoint would
        // report a successful student enrolment while granting the wrong permissions —
        // or none. Reporting success for an enrolment that did not grant student access
        // is worse than refusing, because nobody goes looking.
        $studentroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student']);
        if ($studentroleid <= 0) {
            throw new \moodle_exception(
                'No role with shortname "student" exists on this site, so the learner cannot be '
                . 'enrolled with student permissions. Create or rename the role, or tell the SIS which '
                . 'role to use.'
            );
        }
        enrol_get_plugin('manual')->enrol_user($instance, $user->id, $studentroleid, time(), 0, ENROL_USER_ACTIVE);

        return ['ok' => true, 'courseid' => (int) $course->id, 'already' => false, 'warning' => $warning];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok'       => new external_value(PARAM_BOOL, 'Enrolment succeeded'),
            'courseid' => new external_value(PARAM_INT, 'Moodle course id'),
            'already'  => new external_value(PARAM_BOOL, 'User was already actively enrolled'),
            'warning'  => new external_value(PARAM_RAW, 'Non-fatal warning, if any'),
        ]);
    }
}
