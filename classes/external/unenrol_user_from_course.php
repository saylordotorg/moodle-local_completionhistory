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
 * External function: close a learner's access to a course they have left in the
 * SIS (SIS-171). The counterpart of enrol_user_in_course, and the missing half of
 * the mySaylor "Leave course" link: releasing the learning-window slot freed the
 * slot and left the course sitting in the student's Moodle course list, still
 * open, which reads as the button not having worked.
 *
 * SUSPENDS, IT DOES NOT DELETE, and that distinction is the whole design.
 * Moodle's unenrol_user() is destructive: it unassigns roles, drops group
 * membership, and calls grade_user_unenrol(), which removes the learner's grades
 * for the course. Quiz attempts survive the row but stop being reachable through
 * the gradebook, and course completion state is cleared. Hanging that off a link
 * labelled "Leave" would make an ordinary, reversible pacing decision cost a
 * student their graded work — and the SIS promises the opposite in the same
 * sentence it offers the link. A suspended enrolment achieves everything the
 * student asked for: the course leaves "My courses", the course page refuses
 * entry, and no notification or activity follows them. Everything they did is
 * still there if they come back, and coming back needs no repair work —
 * enrol_user_in_course sees a non-active enrolment and reactivates that same row.
 *
 * ONLY TOUCHES MANUAL ENROLMENTS — the method this integration uses. An enrolment
 * that arrived by self-enrolment, a cohort sync or a category role is somebody
 * else's decision, and suspending it here would silently undo it with no record
 * on the instance that owns it. Where such an enrolment is what is actually
 * holding the course open, the response says so through `warning` rather than
 * reporting a closure that did not happen.
 *
 * "MANUAL" IS NOT THE SAME CLAIM AS "OURS", and it is worth being exact about the
 * gap (raised in review on PR #12). enrol_user_in_course reuses whatever enabled
 * manual instance a course already has and writes no ownership marker, so a
 * manual enrolment a registrar made by hand is indistinguishable here from one the
 * SIS made, and this will suspend it too. Two things make that acceptable rather
 * than merely unnoticed:
 *
 *   The CALLER scopes it. /me/leave-course refuses any course that is not in the
 *   learner's own pathway (404) and any course they have completed (409), so the
 *   only enrolments reachable are the learner's own, in their own programme,
 *   released by the learner themselves.
 *
 *   Since SIS-165 the SIS — not Moodle — is the authority on programme membership.
 *   A hand-made manual enrolment in a degree course is not a parallel source of
 *   truth to be protected; it is the same fact recorded in the weaker place.
 *
 * The one signal Moodle does offer, `user_enrolments.modifierid`, deliberately is
 * NOT gated on: the 0.7.0 upgrade backfilled 170 enrolments under whichever
 * account ran the upgrade, so filtering by it would quietly stop closing courses
 * for most of the live cohort — a worse failure, and a silent one.
 *
 * Idempotent: nothing enrolled, or already suspended, is success with
 * `changed = false`.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class unenrol_user_from_course extends external_api {
    /**
     * Describe the parameters accepted by execute().
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'email'          => new external_value(PARAM_EMAIL, 'User email (identity key)'),
            'courseidnumber' => new external_value(PARAM_RAW, 'Course idnumber (falls back to shortname match)'),
        ]);
    }

    /**
     * Suspend a learner's manual enrolments in a course and report whether access remains open.
     *
     * @param string $email Email address identifying the learner.
     * @param string $courseidnumber Course idnumber, falling back to a shortname match.
     * @return array Outcome flags, the course id and a warning when the course is still accessible.
     */
    public static function execute(string $email, string $courseidnumber): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/lib/enrollib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'email'          => $email,
            'courseidnumber' => $courseidnumber,
        ]);

        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);
        \local_completionhistory\local\security::require_enabled();
        require_capability('local/completionhistory:integrate', $systemcontext);
        /*
         * DELIBERATELY THE SAME CAPABILITY AS ENROLLING, rather than a new
         * :unenrolusers of its own. A capability declared with no archetypes is
         * granted to nobody by an upgrade, and this integration has now been taken
         * down twice by exactly that (see tests/static/check_service_capability
         * _declarations.php for both dates). Suspending an enrolment this plugin
         * created is the same authority as creating it and strictly less dangerous,
         * so a new capability would buy no separation and would cost a deploy step
         * that has already been forgotten twice.
         */
        require_capability('local/completionhistory:enrolusers', $systemcontext);
        $user = \local_completionhistory\local\security::get_unique_local_user_by_email($params['email']);
        if (!$user) {
            throw new \moodle_exception('invaliduser', 'error');
        }
        if (!\local_completionhistory\local\security::is_learner_account($user)) {
            throw new \moodle_exception('invaliduser', 'error');
        }
        $course = $DB->get_record('course', ['idnumber' => $params['courseidnumber']]);
        if (!$course) {
            $course = $DB->get_record('course', ['shortname' => $params['courseidnumber']], '*', MUST_EXIST);
        }

        $plugin = enrol_get_plugin('manual');
        if (!$plugin) {
            throw new \moodle_exception('manualenrolunavailable', 'local_completionhistory');
        }

        // Every manual instance in the course, not just the first: a course can carry
        // more than one, and leaving a second active enrolment behind would close
        // nothing while reporting that it had.
        $changed = false;
        $instances = $DB->get_records('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
        foreach ($instances as $instance) {
            $ue = $DB->get_record('user_enrolments', ['enrolid' => $instance->id, 'userid' => $user->id]);
            if (!$ue || (int) $ue->status === ENROL_USER_SUSPENDED) {
                continue;
            }
            $plugin->update_user_enrol($instance, $user->id, ENROL_USER_SUSPENDED);
            $changed = true;
        }

        /*
         * WHAT IS STILL HOLDING THE COURSE OPEN, said out loud — the one check that can
         * tell the SIS its release did not actually close access, while the student is
         * about to be told in plain words that it did. Computed AFTER the suspensions.
         *
         * can_access_course(), NOT is_enrolled(): the question is whether the learner
         * can still open the course, and enrolment is only one of the two ways to be
         * able to. A role granting moodle/course:view at the course or an ancestor
         * category needs no enrolment at all, so is_enrolled() went false the moment we
         * suspended and reported a closure the learner could walk straight through
         * (caught in review on PR #12). is_viewing() inside can_access_course is what
         * covers that, and course visibility comes along for free.
         *
         * `$onlyactive = true` is load-bearing and NOT the default: with the default
         * false, the row we just suspended still counts as an enrolment and every call
         * would report the course as still open.
         */
        $warning = '';
        if (can_access_course($course, $user, '', true)) {
            $methods = [];
            foreach (enrol_get_instances($course->id, true) as $instance) {
                $active = $DB->record_exists_select(
                    'user_enrolments',
                    'enrolid = :eid AND userid = :uid AND status = :status',
                    ['eid' => $instance->id, 'uid' => $user->id, 'status' => ENROL_USER_ACTIVE]
                );
                if ($active) {
                    $methods[$instance->enrol] = true;
                }
            }
            // Access with no active enrolment behind it is role-based, and naming it that
            // way matters: an operator told "an enrolment" would go looking for a row
            // that is not there.
            $warning = $methods
                ? 'The course is still open through an enrolment this integration does not manage ('
                    . implode(', ', array_keys($methods)) . ').'
                : 'The course is still open through a role that grants course access without an '
                    . 'enrolment, which this integration does not manage.';
        }

        return [
            'ok'       => true,
            'courseid' => (int) $course->id,
            'changed'  => $changed,
            'warning'  => $warning,
        ];
    }

    /**
     * Describe the structure execute() returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok'       => new external_value(PARAM_BOOL, 'Request completed'),
            'courseid' => new external_value(PARAM_INT, 'Moodle course id'),
            'changed'  => new external_value(PARAM_BOOL, 'An active manual enrolment was suspended by this call'),
            'warning'  => new external_value(PARAM_RAW, 'Non-fatal warning, if any'),
        ]);
    }
}
