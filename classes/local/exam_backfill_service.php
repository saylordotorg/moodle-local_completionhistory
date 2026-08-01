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

namespace local_completionhistory\local;

use stdClass;

/**
 * Record historical quiz attempts that were never captured as exam attempts.
 *
 * WHY THIS EXISTS. The per-attempt log is written by an event observer on
 * \mod_quiz\event\attempt_submitted, and that observer declines any quiz which is not a
 * TRACKED exam for its course. So an attempt is captured only if the course's exam
 * configuration already existed when the attempt was submitted.
 *
 * That ordering is the problem. Configuring a course's exam track today captures nothing
 * that happened before today, and the site had 1,748 finished quiz attempts sitting behind
 * an empty configuration table — every one of them declined at the door, silently. The gap
 * grows with each course that gets configured, because each newly-mapped quiz brings its
 * own history of attempts that will never be recorded.
 *
 * WHAT IT DELIBERATELY DOES NOT DO. It does not write
 * local_completionhistory_exam_attempt directly, and it does not invent data. Every row
 * goes through the SAME derivation the observer uses — normalise the attempt grade to
 * 0-100 from quiz.sumgrades, take the pass threshold from the grade item, take the track
 * and attempts_allowed from course_exam_config — so a backfilled row and an
 * observer-written row for identical inputs are indistinguishable. Anything the observer
 * would have skipped is skipped here for the same reason.
 *
 * IDEMPOTENCY, and its honest limitation. The exam_attempt table has no column holding
 * the Moodle quiz_attempts.id, so there is no true foreign key to match on. This dedupes
 * on (userid, courseid, exam_track, timetaken), where timetaken is quiz_attempts.timefinish
 * — a user cannot submit two attempts of the same quiz in the same second, so the tuple is
 * unique in practice. A dedicated quizattemptid column would be strictly better and would
 * also let the observer become idempotent; it is not added here because it needs a schema
 * upgrade, and this service is useful without one. That is a known trade, not an oversight.
 *
 * ORDER MATTERS. record_attempt() derives attempt_number from a COUNT of existing rows on
 * the track, so inserting out of order produces attempt numbers that do not match the
 * order the student actually sat the exams. Attempts are therefore processed oldest-first
 * per learner, and a backfill that runs after some attempts are already recorded continues
 * the existing numbering rather than restarting it.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class exam_backfill_service {

    /**
     * Find finished quiz attempts on currently-tracked exam quizzes.
     *
     * Ordered by (userid, courseid, timefinish, id) so each learner's attempts are
     * processed oldest-first and the derived attempt_number reflects reality.
     *
     * @param int|null $courseid Restrict to one course.
     * @param int|null $userid   Restrict to one user.
     * @param int      $limit    0 for no limit.
     * @return array of attempt rows joined to their quiz + course + config
     */
    public static function candidates(?int $courseid = null, ?int $userid = null, int $limit = 0): array {
        global $DB;

        $params = [];
        $where = ['qa.state = :finished'];
        $params['finished'] = 'finished';

        // Only quizzes that are a tracked exam on their course, matching exactly what
        // course_config_service::get_track_for_quiz resolves.
        $where[] = '(cec.program_final_quizid = qa.quiz OR cec.dc_quizid = qa.quiz OR cec.cert_quizid = qa.quiz)';

        if ($courseid !== null) {
            $where[] = 'q.course = :courseid';
            $params['courseid'] = $courseid;
        }
        if ($userid !== null) {
            $where[] = 'qa.userid = :userid';
            $params['userid'] = $userid;
        }

        $sql = "SELECT qa.id AS quizattemptid, qa.userid, qa.quiz AS quizid, qa.attempt AS moodleattempt,
                       qa.sumgrades, qa.timestart, qa.timefinish,
                       q.course AS courseid, q.sumgrades AS quizsumgrades, q.grade AS quizgrade,
                       cec.id AS configid,
                       cec.program_final_quizid, cec.dc_quizid, cec.cert_quizid,
                       cec.program_attempts_allowed, cec.dc_attempts_allowed, cec.cert_attempts_allowed
                  FROM {quiz_attempts} qa
                  JOIN {quiz} q ON q.id = qa.quiz
                  JOIN {local_completionhistory_course_exam_config} cec ON cec.courseid = q.course
                 WHERE " . implode(' AND ', $where) . "
              ORDER BY qa.userid, q.course, qa.timefinish, qa.id";

        return $DB->get_records_sql($sql, $params, 0, $limit > 0 ? $limit : 0);
    }

    /**
     * Which track a candidate belongs to, and how many attempts that track allows.
     *
     * Mirrors course_config_service::get_track_for_quiz rather than re-deciding, so the
     * two cannot disagree about what a quiz is.
     *
     * @param stdClass $row A row from candidates().
     * @return array{0:string,1:int} [track, attempts_allowed]
     */
    public static function track_of(stdClass $row): array {
        $quizid = (int) $row->quizid;
        if ((int) $row->program_final_quizid === $quizid) {
            return [course_config_service::TRACK_PROGRAM_FINAL, (int) $row->program_attempts_allowed];
        }
        if ((int) $row->dc_quizid === $quizid) {
            return [course_config_service::TRACK_DIRECT_CREDIT, (int) $row->dc_attempts_allowed];
        }
        return [course_config_service::TRACK_CERTIFICATE, (int) $row->cert_attempts_allowed];
    }

    /**
     * Already recorded? See the class docblock on why this tuple and not an attempt id.
     *
     * @param int    $userid
     * @param int    $courseid
     * @param string $track
     * @param int    $timetaken
     * @return bool
     */
    public static function already_recorded(int $userid, int $courseid, string $track, int $timetaken): bool {
        global $DB;

        return $DB->record_exists('local_completionhistory_exam_attempt', [
            'userid'     => $userid,
            'courseid'   => $courseid,
            'exam_track' => $track,
            'timetaken'  => $timetaken,
        ]);
    }

    /**
     * Normalise an attempt's grade to 0-100, exactly as the observer does.
     *
     * @param stdClass $row
     * @return float|null Null when the attempt has no grade or the quiz has no total.
     */
    public static function grade_of(stdClass $row): ?float {
        if ($row->sumgrades === null || (float) $row->quizsumgrades <= 0) {
            return null;
        }
        return ((float) $row->sumgrades / (float) $row->quizsumgrades) * 100.0;
    }

    /**
     * Pass/fail against the grade item's threshold, on the same 0-100 scale.
     *
     * Returns null when there is no grade or no threshold — "no pass mark" is a third
     * state, and collapsing it to a fail would invent a failure on an academic record.
     *
     * @param stdClass   $row
     * @param float|null $grade
     * @return bool|null
     */
    public static function passed_of(stdClass $row, ?float $grade): ?bool {
        global $DB;

        if ($grade === null) {
            return null;
        }
        $gitem = $DB->get_record('grade_items', [
            'itemtype'     => 'mod',
            'itemmodule'   => 'quiz',
            'iteminstance' => (int) $row->quizid,
            'courseid'     => (int) $row->courseid,
        ]);
        if (!$gitem || (float) $gitem->gradepass <= 0 || (float) $row->quizgrade <= 0) {
            return null;
        }
        $threshold = ((float) $gitem->gradepass / (float) $row->quizgrade) * 100.0;
        return $grade >= $threshold;
    }

    /**
     * Duration in seconds, or null when the timestamps cannot support one.
     *
     * @param stdClass $row
     * @return int|null
     */
    public static function duration_of(stdClass $row): ?int {
        $start = (int) $row->timestart;
        $finish = (int) $row->timefinish;
        return ($start > 0 && $finish > $start) ? ($finish - $start) : null;
    }

    /**
     * Back-fill the candidates.
     *
     * @param bool     $dryrun   Report without writing.
     * @param int|null $courseid Restrict to one course.
     * @param int|null $userid   Restrict to one user.
     * @param int      $limit    0 for no limit.
     * @param callable|null $log Called with a message per candidate when verbose.
     * @return array{scanned:int,recorded:int,skipped:int,nograde:int,rows:array}
     */
    public static function run(
        bool $dryrun = true,
        ?int $courseid = null,
        ?int $userid = null,
        int $limit = 0,
        ?callable $log = null
    ): array {
        $candidates = self::candidates($courseid, $userid, $limit);
        $scanned = 0;
        $recorded = 0;
        $skipped = 0;
        $nograde = 0;
        $rows = [];

        foreach ($candidates as $row) {
            $scanned++;
            [$track, $allowed] = self::track_of($row);
            $timetaken = (int) ($row->timefinish ?: 0);

            if ($timetaken <= 0) {
                // Without a submission time the row cannot be deduped or ordered, and a
                // fabricated timestamp would be worse than the missing attempt.
                $skipped++;
                if ($log) {
                    $log(sprintf('skip  quizattempt=%d user=%d: no timefinish', $row->quizattemptid, $row->userid));
                }
                continue;
            }

            if (self::already_recorded((int) $row->userid, (int) $row->courseid, $track, $timetaken)) {
                $skipped++;
                if ($log) {
                    $log(sprintf('skip  quizattempt=%d user=%d course=%d %s: already recorded',
                        $row->quizattemptid, $row->userid, $row->courseid, $track));
                }
                continue;
            }

            $grade = self::grade_of($row);
            if ($grade === null) {
                $nograde++;
            }
            $passed = self::passed_of($row, $grade);
            $duration = self::duration_of($row);

            $rows[] = [
                'quizattemptid' => (int) $row->quizattemptid,
                'userid'        => (int) $row->userid,
                'courseid'      => (int) $row->courseid,
                'quizid'        => (int) $row->quizid,
                'track'         => $track,
                'grade'         => $grade,
                'passed'        => $passed,
                'timetaken'     => $timetaken,
            ];

            if ($log) {
                $log(sprintf('%s quizattempt=%d user=%d course=%d %s grade=%s passed=%s taken=%d',
                    $dryrun ? 'would' : 'write',
                    $row->quizattemptid, $row->userid, $row->courseid, $track,
                    $grade === null ? 'null' : number_format($grade, 2),
                    $passed === null ? 'null' : ($passed ? '1' : '0'),
                    $timetaken));
            }

            if (!$dryrun) {
                exam_attempt_service::record_attempt(
                    (int) $row->userid,
                    (int) $row->courseid,
                    (int) $row->quizid,
                    $track,
                    $allowed,
                    $grade,
                    $passed,
                    $timetaken,
                    $duration
                );
            }
            $recorded++;
        }

        return [
            'scanned'  => $scanned,
            'recorded' => $recorded,
            'skipped'  => $skipped,
            'nograde'  => $nograde,
            'rows'     => $rows,
        ];
    }
}
