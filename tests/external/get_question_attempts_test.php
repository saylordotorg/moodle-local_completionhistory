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

use advanced_testcase;

/**
 * Tests for the question-level exam feed (SIS-242).
 *
 *   ONLY RECORDED EXAM ATTEMPTS. A quiz attempt with no exam attempt row is not in the feed.
 *
 *   EACH QUESTION'S FINAL STATE. Right, wrong and the mark, keyed to the exam attempt id the SIS
 *   already holds, with the question bank entry to aggregate on.
 *
 *   PAGES DO NOT LOSE OR REPEAT A ROW, even when a page ends inside one exam attempt.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_completionhistory\external\get_question_attempts
 */
final class get_question_attempts_test extends advanced_testcase {
    /** @var \stdClass The exam quiz. */
    private \stdClass $quiz;

    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var \stdClass[] Its questions: a true/false, a second true/false and a description. */
    private array $questions = [];

    /**
     * A course with a three-slot quiz: two true/false questions and a description.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        $this->resetAfterTest();
        set_config('enabled', 1, 'local_completionhistory');
        $this->setAdminUser();

        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course(['idnumber' => 'CL601']);
        $this->quiz = $gen->create_module('quiz', [
            'course' => $this->course->id,
            'questionsperpage' => 0,
            'grade' => 100,
            'sumgrades' => 2,
        ]);
        $qgen = $gen->get_plugin_generator('core_question');
        $category = $qgen->create_question_category(['name' => 'CL601 final bank']);
        $this->questions[] = $qgen->create_question('truefalse', null, ['category' => $category->id, 'name' => 'Q1 cash flow']);
        $this->questions[] = $qgen->create_question('truefalse', null, ['category' => $category->id, 'name' => 'Q2 NPV']);
        $this->questions[] = $qgen->create_question('description', null, ['category' => $category->id, 'name' => 'Instructions']);
        foreach ($this->questions as $question) {
            quiz_add_quiz_question($question->id, $this->quiz);
        }
    }

    /**
     * A learner sits the quiz, answering Q1 true and Q2 false, and the attempt is finished.
     *
     * @param \stdClass $user Learner.
     * @param int $finish When the attempt finished.
     * @return \stdClass The quiz attempt row.
     */
    private function sit(\stdClass $user, int $finish): \stdClass {
        global $DB;
        $this->getDataGenerator()->enrol_user($user->id, $this->course->id, 'student');
        $quizobj = \mod_quiz\quiz_settings::create($this->quiz->id, $user->id);
        $quba = \question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
        $quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);
        $attempt = quiz_create_attempt($quizobj, 1, false, $finish - 600, false, $user->id);
        quiz_start_new_attempt($quizobj, $quba, $attempt, 1, $finish - 600);
        quiz_attempt_save_started($quizobj, $quba, $attempt);

        $attemptobj = \mod_quiz\quiz_attempt::create($attempt->id);
        $attemptobj->process_submitted_actions($finish - 60, false, [1 => ['answer' => '1'], 2 => ['answer' => '0']]);
        if (method_exists($attemptobj, 'process_submit')) {
            $attemptobj->process_submit($finish, false);
            $attemptobj->process_grade_submission($finish);
        } else {
            $attemptobj->process_finish($finish, false);
        }
        return $DB->get_record('quiz_attempts', ['id' => $attempt->id], '*', MUST_EXIST);
    }

    /**
     * Record a quiz attempt as an exam attempt, as the observer or the backfill would.
     *
     * @param \stdClass $attempt The quiz attempt.
     * @return int Exam attempt id.
     */
    private function record(\stdClass $attempt): int {
        global $DB;
        return (int) $DB->insert_record('local_completionhistory_exam_attempt', (object) [
            'userid' => $attempt->userid,
            'courseid' => $this->course->id,
            'quizid' => $this->quiz->id,
            'exam_track' => 'program_final',
            'attempt_number' => 1,
            'attempts_allowed' => 3,
            'grade_decimal' => 50,
            'grade_passed' => 0,
            'resulted_in_completion' => 0,
            'timetaken' => $attempt->timefinish,
            'duration' => 600,
            'timecreated' => time(),
        ]);
    }

    /**
     * Call through the external_api contract.
     *
     * @param int $since Cursor.
     * @param int $sinceid Cursor tie-break.
     * @param int $limit Page size.
     * @return array
     */
    private function page(int $since = 0, int $sinceid = 0, int $limit = 500): array {
        return \core_external\external_api::clean_returnvalue(
            get_question_attempts::execute_returns(),
            get_question_attempts::execute($since, $sinceid, $limit)
        );
    }

    /**
     * Each marked question of a recorded attempt, with its final state; the description is left out.
     */
    public function test_each_question_of_a_recorded_attempt_with_its_result(): void {
        $learner = $this->getDataGenerator()->create_user();
        $examattemptid = $this->record($this->sit($learner, time() - DAYSECS));

        $page = $this->page();
        $this->assertCount(2, $page['questions'], 'two marked questions; the description is not one');
        $this->assertFalse($page['truncated']);
        [$q1, $q2] = $page['questions'];
        $this->assertSame($examattemptid, $q1['examattemptid']);
        $this->assertSame('Q1 cash flow', $q1['questionname']);
        $this->assertSame('truefalse', $q1['qtype']);
        $this->assertSame('CL601 final bank', $q1['categoryname']);
        $this->assertSame('gradedright', $q1['state']);
        $this->assertEquals(1.0, $q1['fraction']);
        $this->assertEquals($q1['maxmark'], $q1['mark']);
        $this->assertSame('gradedwrong', $q2['state']);
        $this->assertEquals(0.0, $q2['mark']);
        $this->assertGreaterThan(0, $q1['questionbankentryid']);
        $this->assertNotSame($q1['questionbankentryid'], $q2['questionbankentryid']);
    }

    /**
     * A quiz attempt that is not a recorded exam attempt is not in the feed.
     */
    public function test_an_unrecorded_attempt_is_not_in_the_feed(): void {
        $this->sit($this->getDataGenerator()->create_user(), time() - DAYSECS);
        $this->assertSame([], $this->page()['questions']);
    }

    /**
     * Paging one row at a time walks every row once, across a boundary inside one exam attempt.
     */
    public function test_pages_neither_lose_nor_repeat_a_row(): void {
        $gen = $this->getDataGenerator();
        $first = $this->record($this->sit($gen->create_user(), time() - 2 * DAYSECS));
        $second = $this->record($this->sit($gen->create_user(), time() - DAYSECS));

        $seen = [];
        $since = 0;
        $sinceid = 0;
        for ($i = 0; $i < 10; $i++) {
            $page = $this->page($since, $sinceid, 1);
            foreach ($page['questions'] as $row) {
                $seen[] = [$row['examattemptid'], $row['id']];
            }
            [$since, $sinceid] = [$page['next_since'], $page['next_since_id']];
            if (!$page['truncated']) {
                break;
            }
        }
        $this->assertCount(4, $seen);
        $this->assertCount(4, array_unique(array_map('json_encode', $seen)));
        $this->assertSame([$first, $first, $second, $second], array_column($seen, 0));
    }

    /**
     * The integration capability is required.
     */
    public function test_the_integration_capability_is_required(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        get_question_attempts::execute();
    }
}
