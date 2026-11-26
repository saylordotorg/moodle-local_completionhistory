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
 * (course_exam_config.program_final_quizid) allowing the attempts made BEFORE THE RESET plus the
 * configured allowance (program_attempts_allowed, or the quiz's own limit when that is unset).
 * Moodle-native, so the earlier attempts stay visible to the student and to staff and nothing is
 * deleted. An existing user override's other settings (a longer time limit granted as an
 * accommodation, say) are written back unchanged beside the new `attempts`.
 *
 * A FIXED BASELINE. The SIS passes the moment it made the reset (`resetat`), and only attempts started
 * before it count as used. The target is therefore the same on every call for that reset: a retry made
 * after the learner has used some of the new attempts does not hand them more (PR #19 review).
 *
 * THE LEARNER'S EFFECTIVE LIMIT. Moodle resolves attempts setting by setting: the learner's own override
 * wins where it sets one, else the most permissive of their groups' overrides, else the quiz. That
 * effective limit (quiz_update_effective_access) is what is compared with the target, so a group
 * accommodation allowing as many or more is never cut back by a narrower user override (PR #19 review).
 *
 * IDEMPOTENT, NEVER LOWERS. 'already' when the learner's own override already allows the target;
 * 'not_needed', writing nothing, when their effective limit does otherwise (nothing used before the
 * reset, a group override, or unlimited attempts).
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
            'resetat'        => new external_value(PARAM_INT, 'When the SIS reset the course (Unix time): attempts '
                . 'started before it count as used'),
        ]);
    }

    /**
     * Grant a learner a fresh set of attempts at the course's program final exam.
     *
     * @param string $email Email address identifying the learner.
     * @param string $courseidnumber Course idnumber, falling back to a shortname match.
     * @param int $resetat When the SIS reset the course; attempts started before it count as used.
     * @return array status ('granted', 'already' or 'not_needed'), the quiz, the attempts used before the
     *               reset and the attempts the learner's own override allows.
     */
    public static function execute(string $email, string $courseidnumber, int $resetat): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'email'          => $email,
            'courseidnumber' => $courseidnumber,
            'resetat'        => $resetat,
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
        [$course, $quiz, $allowed] = self::program_final_quiz($params['courseidnumber']);

        $resetat = $params['resetat'] > 0 ? $params['resetat'] : time();
        $made = (int) $DB->count_records_select(
            'quiz_attempts',
            'quiz = :quiz AND userid = :userid AND preview = 0 AND timestart < :resetat',
            ['quiz' => $quiz->id, 'userid' => $user->id, 'resetat' => $resetat]
        );
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

        $existing = $DB->get_record('quiz_overrides', ['quiz' => $quiz->id, 'userid' => $user->id, 'groupid' => null]);
        $limit = (int) quiz_update_effective_access(clone $quiz, (int) $user->id)->attempts;
        if ($limit === 0 || $limit >= $target) {
            if ($existing && $existing->attempts !== null) {
                $result['overrideid'] = (int) $existing->id;
                $result['attempts'] = (int) $existing->attempts;
                return ['status' => 'already'] + $result;
            }
            return ['status' => 'not_needed'] + $result;
        }

        $overrideid = self::save_attempts_override($quiz, (int) $user->id, $target, $existing ?: null);
        return ['status' => 'granted', 'overrideid' => $overrideid, 'attempts' => $target] + $result;
    }

    /**
     * The course, its configured program final quiz (with cmid) and the attempt allowance for it.
     *
     * @param string $courseidnumber Course idnumber, falling back to a shortname match.
     * @return array [course, quiz, allowance]; an allowance of 0 means unlimited.
     */
    private static function program_final_quiz(string $courseidnumber): array {
        global $DB;
        $course = $DB->get_record('course', ['idnumber' => $courseidnumber]);
        if (!$course) {
            $course = $DB->get_record('course', ['shortname' => $courseidnumber], '*', MUST_EXIST);
        }
        $config = \local_completionhistory\local\course_config_service::get_config((int) $course->id);
        $quiz = empty($config->program_final_quizid)
            ? null
            : $DB->get_record('quiz', ['id' => $config->program_final_quizid, 'course' => $course->id]);
        $cm = $quiz ? get_coursemodule_from_instance('quiz', $quiz->id, $course->id) : null;
        if (!$quiz || !$cm) {
            throw new \moodle_exception('noprogramfinalquiz', 'local_completionhistory', '', $course->shortname);
        }
        $quiz->cmid = $cm->id;
        // The course's configured program-final attempts, else the quiz's own limit.
        $allowed = (int) $config->program_attempts_allowed > 0 ? (int) $config->program_attempts_allowed : (int) $quiz->attempts;
        return [$course, $quiz, $allowed];
    }

    /**
     * Write the learner's user override so it allows $target attempts.
     *
     * An existing user override's other settings are passed back unchanged: override_manager sets every
     * overrideable setting a call leaves out to NULL, so writing `attempts` alone would wipe them.
     *
     * @param \stdClass $quiz The quiz, with cmid.
     * @param int $userid The learner.
     * @param int $target Attempts to allow.
     * @param \stdClass|null $existing The learner's existing user override, if any.
     * @return int The override id.
     */
    private static function save_attempts_override(\stdClass $quiz, int $userid, int $target, ?\stdClass $existing): int {
        $manager = new \mod_quiz\local\override_manager($quiz, \context_module::instance($quiz->cmid));
        $data = ['userid' => $userid, 'attempts' => $target];
        if ($existing) {
            $data['id'] = (int) $existing->id;
            foreach (['timeopen', 'timeclose', 'timelimit', 'password'] as $key) {
                $data[$key] = $existing->$key;
            }
        }
        return (int) $manager->save_override($data);
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
            'attemptsmade' => new external_value(PARAM_INT, 'Attempts the learner started on that quiz before the reset'),
            'overrideid'   => new external_value(PARAM_INT, 'The learner\'s user override (0 when none was needed)'),
            'attempts'     => new external_value(PARAM_INT, 'Attempts the user override allows (0 when none was needed)'),
        ]);
    }
}
