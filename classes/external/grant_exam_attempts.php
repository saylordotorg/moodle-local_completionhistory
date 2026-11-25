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
 * External function: give a learner a fresh set of attempts at a course's program final exam
 * (SIS-264 Part B).
 *
 * WHY. When a student who failed a course and left one programme is admitted to another, the SIS
 * resets that course for them: their earlier failure stops blocking them. Moodle still holds their
 * used attempts against the quiz's limit, so without this they would be admitted to retake a course
 * whose final exam they can no longer sit.
 *
 * HOW. A per-user quiz override on the course's configured program final quiz
 * (course_exam_config.program_final_quizid) allowing the attempts already made PLUS the configured
 * allowance (program_attempts_allowed, or the quiz's own limit when that is unset). Moodle-native, so
 * the earlier attempts stay visible to the student and to staff, nothing is deleted, and an existing
 * override's other settings (a longer time limit, say, granted as an accommodation) are written back
 * unchanged beside the new `attempts`.
 *
 * IDEMPOTENT. Calling it again changes nothing once the override allows at least the target, and
 * reports 'already'. It never lowers an override. When no override is needed (the student has made
 * no attempts on this quiz, or attempts are unlimited) it reports 'not_needed' and writes nothing.
 *
 * Capabilities: the integration capability and local/completionhistory:grantattempts, both at system
 * level. Moodle's own mod/quiz:manageoverrides is not required of the service account, in line with
 * the other write endpoints here, which gate on this plugin's narrow capabilities instead.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grant_exam_attempts extends external_api {
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
     * Grant a learner a fresh set of attempts at the course's program final exam.
     *
     * @param string $email Email address identifying the learner.
     * @param string $courseidnumber Course idnumber, falling back to a shortname match.
     * @return array status ('granted', 'already' or 'not_needed'), the quiz, the attempts made and the
     *               attempts the override now allows.
     */
    public static function execute(string $email, string $courseidnumber): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'email'          => $email,
            'courseidnumber' => $courseidnumber,
        ]);

        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);
        \local_completionhistory\local\security::require_enabled();
        require_capability('local/completionhistory:integrate', $systemcontext);
        require_capability('local/completionhistory:grantattempts', $systemcontext);
        $user = \local_completionhistory\local\security::get_unique_local_user_by_email($params['email']);
        if (!$user || !\local_completionhistory\local\security::is_learner_account($user)) {
            throw new \moodle_exception('invaliduser', 'error');
        }
        $course = $DB->get_record('course', ['idnumber' => $params['courseidnumber']]);
        if (!$course) {
            $course = $DB->get_record('course', ['shortname' => $params['courseidnumber']], '*', MUST_EXIST);
        }

        $config = \local_completionhistory\local\course_config_service::get_config((int) $course->id);
        if (empty($config->program_final_quizid)) {
            throw new \moodle_exception('noprogramfinalquiz', 'local_completionhistory', '', $course->shortname);
        }
        $quiz = $DB->get_record('quiz', ['id' => $config->program_final_quizid, 'course' => $course->id]);
        $cm = $quiz ? get_coursemodule_from_instance('quiz', $quiz->id, $course->id) : null;
        if (!$quiz || !$cm) {
            throw new \moodle_exception('noprogramfinalquiz', 'local_completionhistory', '', $course->shortname);
        }
        $quiz->cmid = $cm->id;

        // The allowance: the course's configured program-final attempts, else the quiz's own limit.
        // 0 means unlimited, and an unlimited quiz never needs more.
        $allowed = (int) $config->program_attempts_allowed > 0 ? (int) $config->program_attempts_allowed : (int) $quiz->attempts;
        $made = (int) $DB->count_records('quiz_attempts', ['quiz' => $quiz->id, 'userid' => $user->id, 'preview' => 0]);
        $result = [
            'courseid'     => (int) $course->id,
            'quizid'       => (int) $quiz->id,
            'attemptsmade' => $made,
            'overrideid'   => 0,
            'attempts'     => 0,
        ];
        if ($allowed <= 0) {
            return ['status' => 'not_needed'] + $result;
        }
        $target = $made + $allowed;

        // The learner's limit today: their own override's attempts when it sets one, else the quiz's. An
        // override whose attempts is NULL (one that only extends the time limit, say) uses the quiz's limit.
        $existing = $DB->get_record('quiz_overrides', ['quiz' => $quiz->id, 'userid' => $user->id, 'groupid' => null]);
        $ownlimit = $existing && $existing->attempts !== null;
        $limit = $ownlimit ? (int) $existing->attempts : (int) $quiz->attempts;
        if ($limit === 0 || $limit >= $target) {
            // Unlimited, or already allows the target. Never lowered. 'already' when their own override is
            // what allows it; 'not_needed' when the quiz's limit does (nothing used yet on this quiz: the
            // failure was under an old course code).
            return $ownlimit
                ? ['status' => 'already', 'overrideid' => (int) $existing->id, 'attempts' => $limit] + $result
                : ['status' => 'not_needed'] + $result;
        }

        $manager = new \mod_quiz\local\override_manager($quiz, \context_module::instance($cm->id));
        $data = ['userid' => (int) $user->id, 'attempts' => $target];
        if ($existing) {
            // An existing override's other settings (a longer time limit granted as an accommodation, say)
            // are passed back unchanged. override_manager sets every overrideable setting the call leaves
            // out to NULL, so writing `attempts` alone would silently wipe the accommodation.
            $data['id'] = (int) $existing->id;
            foreach (['timeopen', 'timeclose', 'timelimit', 'password'] as $key) {
                $data[$key] = $existing->$key;
            }
        }
        $overrideid = $manager->save_override($data);
        return ['status' => 'granted', 'overrideid' => (int) $overrideid, 'attempts' => $target] + $result;
    }

    /**
     * Describe the structure execute() returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status'       => new external_value(PARAM_ALPHAEXT, "'granted', 'already' or 'not_needed'"),
            'courseid'     => new external_value(PARAM_INT, 'Moodle course id'),
            'quizid'       => new external_value(PARAM_INT, 'The program final quiz'),
            'attemptsmade' => new external_value(PARAM_INT, 'Attempts the learner has made on that quiz'),
            'overrideid'   => new external_value(PARAM_INT, 'The user override (0 when none was needed)'),
            'attempts'     => new external_value(PARAM_INT, 'Attempts the override allows (0 when none was needed or unlimited)'),
        ]);
    }
}
