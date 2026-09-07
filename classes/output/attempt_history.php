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

namespace local_completionhistory\output;

use renderable;
use renderer_base;
use stdClass;
use templatable;

/**
 * The per-attempt history fragment shown under a ledger row.
 *
 * Built from local_completionhistory_exam_attempt records for one user and course;
 * served by ajax_get_attempts.php and rendered with the attempt_history template.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempt_history implements renderable, templatable {
    /** @var stdClass[] Attempt records ordered by track then attempt number. */
    protected array $attempts;

    /**
     * Constructor.
     *
     * @param stdClass[] $attempts Attempt records for one user and course.
     */
    public function __construct(array $attempts) {
        $this->attempts = $attempts;
    }

    /**
     * Export the attempts for the attempt_history template.
     *
     * @param renderer_base $output The renderer.
     * @return array Template context.
     */
    public function export_for_template(renderer_base $output): array {
        $rows = [];
        foreach ($this->attempts as $attempt) {
            $rows[] = $this->export_attempt($attempt);
        }
        return [
            'hasattempts' => !empty($rows),
            'attempts'    => $rows,
        ];
    }

    /**
     * Export one attempt record as a template row.
     *
     * @param stdClass $attempt The attempt record.
     * @return array Row context.
     */
    protected function export_attempt(stdClass $attempt): array {
        $number     = (int) $attempt->attempt_number;
        $allowed    = (int) $attempt->attempts_allowed;
        $completing = !empty($attempt->resulted_in_completion);

        if ($attempt->grade_decimal !== null) {
            $grade = number_format((float) $attempt->grade_decimal, 1) . '%';
        } else {
            $grade = get_string('emptyvalue', 'local_completionhistory');
        }

        $ispassed  = false;
        $isfailed  = false;
        $isunknown = false;
        if ($attempt->grade_passed === null || $attempt->grade_passed === '') {
            $isunknown   = true;
            $result      = get_string('gradeunknown', 'local_completionhistory');
            $resultclass = 'badge-secondary';
        } else if ((int) $attempt->grade_passed === 1) {
            $ispassed    = true;
            $result      = get_string('result_passed', 'local_completionhistory');
            $resultclass = 'badge-success';
        } else {
            $isfailed    = true;
            $result      = attempt_badges::is_exhausted($number, $allowed)
                ? get_string('result_failed_exhausted', 'local_completionhistory')
                : get_string('result_failed', 'local_completionhistory');
            $resultclass = 'badge-danger';
        }

        return [
            'track'       => attempt_badges::track_label($attempt->exam_track),
            'trackclass'  => attempt_badges::track_badge_class($attempt->exam_track),
            'attempt'     => attempt_badges::attempts_summary($number, $allowed),
            'grade'       => $grade,
            'result'      => $result,
            'resultclass' => $resultclass,
            'ispassed'    => $ispassed,
            'isfailed'    => $isfailed,
            'isunknown'   => $isunknown,
            'completing'  => $completing,
            'date'        => userdate((int) $attempt->timetaken, '%m/%d/%Y'),
        ];
    }
}
