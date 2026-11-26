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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * The questions of every recorded exam attempt, one row per question, for the SIS exam audit (SIS-242).
 *
 * WHICH ATTEMPTS. Only attempts already in local_completionhistory_exam_attempt, the tracked exams
 * (program finals and entrance exams), so a chair's report covers exactly the exams the SIS already
 * knows, and each row carries `examattemptid`, the id the SIS stores that attempt under
 * (exam_attempt.moodle_attemptid). That table holds no quiz attempt id: an exam attempt is matched
 * to its Moodle quiz attempt on (quiz, user, timefinish = timetaken), the key the exam backfill
 * deduplicates on, taking the lowest id if two ever shared a second.
 *
 * WHAT A ROW SAYS. The question as drawn (a random slot gives the real question), its question bank
 * entry and version (the identity to aggregate on across edits), its category, its maximum mark, and
 * the state and fraction of its final step. No response text, right answer or learner identity:
 * the report is aggregates only, and the SIS reaches the learner, if it ever needs to, through its
 * own exam_attempt row. Description items, which carry no mark, are left out.
 *
 * PAGING. Ordered by (exam attempt id, question attempt id) with a strict keyset predicate, so a
 * page boundary inside one exam attempt is safe. Paging on the exam attempt id rather than a time
 * means an attempt recorded later by the backfill, with an old timetaken, still arrives in order.
 * A question regraded or manually marked after it was read is only picked up by a re-read from 0.
 *
 * Capability: the integration capability, at system context, like the exam attempt feed itself.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_question_attempts extends external_api {
    /** Hard ceiling on rows per call, whatever the caller asks for. */
    private const MAX_LIMIT = 1000;

    /**
     * Advance the paging cursor past a page of rows.
     *
     * Rows arrive ordered by (examattemptid, id). The cursor is the LAST row's pair; pure and static
     * so tests/static/check_cursor_advance.php can exercise it without Moodle.
     *
     * @param array $rows Rows in query order, each with ->id and ->examattemptid.
     * @param int   $sincets Current cursor exam attempt id, returned unchanged when empty.
     * @param int   $sinceid Current cursor question attempt id, returned unchanged when empty.
     * @return array{0:int,1:int} [next_since, next_since_id]
     */
    public static function next_cursor(array $rows, int $sincets, int $sinceid): array {
        if (empty($rows)) {
            return [$sincets, $sinceid];
        }
        $last = end($rows);
        return [(int) $last->examattemptid, (int) $last->id];
    }

    /**
     * Describe the parameters accepted by execute().
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'since' => new external_value(
                PARAM_INT,
                'Return questions of exam attempts with an id AFTER this one (0 for all). Pass back next_since.',
                VALUE_DEFAULT,
                0
            ),
            'since_id' => new external_value(
                PARAM_INT,
                'Tie-break within since: questions of that exam attempt with a greater id. Pass back next_since_id.',
                VALUE_DEFAULT,
                0
            ),
            'limit' => new external_value(PARAM_INT, 'Rows per page (at most 1000)', VALUE_DEFAULT, 500),
        ]);
    }

    /**
     * One page of question attempts.
     *
     * @param int $since Exam attempt id cursor.
     * @param int $sinceid Question attempt id cursor.
     * @param int $limit Rows per page.
     * @return array
     */
    public static function execute(int $since = 0, int $sinceid = 0, int $limit = 500): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), [
            'since'    => $since,
            'since_id' => $sinceid,
            'limit'    => $limit,
        ]);
        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);
        \local_completionhistory\local\security::require_enabled();
        require_capability('local/completionhistory:integrate', $systemcontext);

        $since   = max(0, (int) $params['since']);
        $sinceid = max(0, (int) $params['since_id']);
        $limit   = max(1, min(self::MAX_LIMIT, (int) $params['limit']));

        $sql = "SELECT qatt.id,
                       ea.id AS examattemptid,
                       ea.quizid,
                       ea.courseid,
                       qatt.slot,
                       qatt.questionid,
                       qatt.maxmark,
                       qv.questionbankentryid,
                       qv.version AS questionversion,
                       q.name AS questionname,
                       q.qtype,
                       qc.id AS categoryid,
                       qc.name AS categoryname,
                       qas.state,
                       qas.fraction
                  FROM {local_completionhistory_exam_attempt} ea
                  JOIN {quiz_attempts} quiza
                       ON quiza.id = (
                           SELECT MIN(qa2.id)
                             FROM {quiz_attempts} qa2
                            WHERE qa2.quiz = ea.quizid
                              AND qa2.userid = ea.userid
                              AND qa2.timefinish = ea.timetaken
                              AND qa2.preview = 0
                       )
                  JOIN {question_attempts} qatt ON qatt.questionusageid = quiza.uniqueid
                  JOIN {question_attempt_steps} qas
                       ON qas.questionattemptid = qatt.id
                      AND qas.sequencenumber = (
                           SELECT MAX(s2.sequencenumber)
                             FROM {question_attempt_steps} s2
                            WHERE s2.questionattemptid = qatt.id
                      )
                  JOIN {question} q ON q.id = qatt.questionid
             LEFT JOIN {question_versions} qv ON qv.questionid = q.id
             LEFT JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
             LEFT JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                 WHERE (ea.id > :since
                        OR (ea.id = :sincets AND qatt.id > :sinceid))
                   AND q.qtype <> :description
              ORDER BY ea.id ASC, qatt.id ASC";
        $rows = $DB->get_records_sql(
            $sql,
            ['since' => $since, 'sincets' => $since, 'sinceid' => $sinceid, 'description' => 'description'],
            0,
            $limit
        );

        $out = [];
        foreach ($rows as $row) {
            $maxmark = (float) $row->maxmark;
            $fraction = $row->fraction === null ? null : (float) $row->fraction;
            $out[] = [
                'id'                  => (int) $row->id,
                'examattemptid'       => (int) $row->examattemptid,
                'quizid'              => (int) $row->quizid,
                'courseid'            => (int) $row->courseid,
                'slot'                => (int) $row->slot,
                'questionid'          => (int) $row->questionid,
                'questionbankentryid' => (int) ($row->questionbankentryid ?? 0),
                'questionversion'     => (int) ($row->questionversion ?? 0),
                'questionname'        => (string) $row->questionname,
                'qtype'               => (string) $row->qtype,
                'categoryid'          => (int) ($row->categoryid ?? 0),
                'categoryname'        => (string) ($row->categoryname ?? ''),
                'maxmark'             => $maxmark,
                'state'               => (string) $row->state,
                'fraction'            => $fraction,
                'mark'                => $fraction === null ? null : $fraction * $maxmark,
            ];
        }
        [$nextsince, $nextsinceid] = self::next_cursor($rows, $since, $sinceid);
        return [
            'questions'     => $out,
            'next_since'    => $nextsince,
            'next_since_id' => $nextsinceid,
            'truncated'     => count($rows) === $limit,
        ];
    }

    /**
     * Describe the return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'questions' => new external_multiple_structure(new external_single_structure([
                'id'                  => new external_value(PARAM_INT, 'Question attempt id'),
                'examattemptid'       => new external_value(PARAM_INT, 'Exam attempt id (the SIS exam_attempt.moodle_attemptid)'),
                'quizid'              => new external_value(PARAM_INT, 'Quiz id'),
                'courseid'            => new external_value(PARAM_INT, 'Course id'),
                'slot'                => new external_value(PARAM_INT, 'Slot in the attempt'),
                'questionid'          => new external_value(PARAM_INT, 'The question as drawn'),
                'questionbankentryid' => new external_value(PARAM_INT, 'Question bank entry: the question across versions'),
                'questionversion'     => new external_value(PARAM_INT, 'Version of that entry'),
                'questionname'        => new external_value(PARAM_TEXT, 'Question name'),
                'qtype'               => new external_value(PARAM_PLUGIN, 'Question type'),
                'categoryid'          => new external_value(PARAM_INT, 'Question bank category id'),
                'categoryname'        => new external_value(PARAM_TEXT, 'Question bank category name'),
                'maxmark'             => new external_value(PARAM_FLOAT, 'Maximum mark'),
                'state'               => new external_value(PARAM_ALPHANUMEXT, 'Final step state, e.g. gradedright'),
                'fraction'            => new external_value(
                    PARAM_FLOAT,
                    'Fraction of the mark awarded; null if not graded',
                    VALUE_REQUIRED,
                    null,
                    NULL_ALLOWED
                ),
                'mark'                => new external_value(
                    PARAM_FLOAT,
                    'Mark awarded; null if not graded',
                    VALUE_REQUIRED,
                    null,
                    NULL_ALLOWED
                ),
            ])),
            'next_since'    => new external_value(PARAM_INT, 'Pass back as since'),
            'next_since_id' => new external_value(PARAM_INT, 'Pass back as since_id'),
            'truncated'     => new external_value(PARAM_BOOL, 'A full page: call again with the cursor'),
        ]);
    }
}
