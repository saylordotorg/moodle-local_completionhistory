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

use local_completionhistory\form\exam_attempt_filter_form;
use local_completionhistory\local\course_config_service;
use local_completionhistory\table\exam_attempts_table;
use moodle_url;
use renderable;
use renderer_base;
use templatable;

/**
 * The Exam Attempt Log staff page: stats cards, a link back to the ledger, the
 * filter form and the results table.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class exam_attempt_log_page implements renderable, templatable {
    /** @var exam_attempt_filter_form The filter form, already populated. */
    protected exam_attempt_filter_form $form;

    /** @var exam_attempts_table The results table, with its SQL and base URL set. */
    protected exam_attempts_table $table;

    /** @var int[] Whole-dataset counts keyed total, passed, failed, exhausted, completing. */
    protected array $counts;

    /** @var int[] Exam track => attempt count across the whole dataset. */
    protected array $trackcounts;

    /** @var int Rows per page. */
    protected int $pagesize;

    /**
     * Constructor.
     *
     * @param exam_attempt_filter_form $form The filter form, already populated.
     * @param exam_attempts_table $table The results table, with its SQL and base URL set.
     * @param int[] $counts Whole-dataset counts keyed total, passed, failed, exhausted, completing.
     * @param int[] $trackcounts Exam track => attempt count across the whole dataset.
     * @param int $pagesize Rows per page.
     */
    public function __construct(
        exam_attempt_filter_form $form,
        exam_attempts_table $table,
        array $counts,
        array $trackcounts,
        int $pagesize = 50
    ) {
        $this->form        = $form;
        $this->table       = $table;
        $this->counts      = $counts;
        $this->trackcounts = $trackcounts;
        $this->pagesize    = $pagesize;
    }

    /**
     * Export the page for the exam_attempt_log_page template.
     *
     * @param renderer_base $output The renderer.
     * @return array Template context.
     */
    public function export_for_template(renderer_base $output): array {
        $stats = [
            ['total', 'stats_total', 'secondary'],
            ['passed', 'stats_passed', 'success'],
            ['failed', 'stats_failed', 'danger'],
            ['exhausted', 'stats_exhausted', 'warning'],
            ['completing', 'stats_completing', 'info'],
        ];
        $cards = [];
        foreach ($stats as [$key, $stringkey, $colour]) {
            $cards[] = [
                'value'  => number_format((int) ($this->counts[$key] ?? 0)),
                'label'  => get_string($stringkey, 'local_completionhistory'),
                'colour' => $colour,
            ];
        }

        $tracks = [
            course_config_service::TRACK_PROGRAM_FINAL,
            course_config_service::TRACK_DIRECT_CREDIT,
            course_config_service::TRACK_CERTIFICATE,
        ];
        foreach ($tracks as $track) {
            $cards[] = [
                'value'  => number_format((int) ($this->trackcounts[$track] ?? 0)),
                'label'  => attempt_badges::track_label($track),
                'colour' => attempt_badges::track_colour($track),
            ];
        }

        ob_start();
        $this->table->out($this->pagesize, true);
        $tablehtml = ob_get_clean();

        $ledgerurl = new moodle_url('/local/completionhistory/achievement_ledger.php');

        return [
            'stats'     => $cards,
            'ledgerurl' => $ledgerurl->out(false),
            'formhtml'  => $this->form->render(),
            'tablehtml' => $tablehtml,
        ];
    }
}
