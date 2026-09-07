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

use local_completionhistory\form\ledger_filter_form;
use local_completionhistory\table\achievements_table;
use renderable;
use renderer_base;
use templatable;

/**
 * The Achievement Ledger staff page: the filter form followed by the results table.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ledger_page implements renderable, templatable {
    /** @var ledger_filter_form The filter form, already populated. */
    protected ledger_filter_form $form;

    /** @var achievements_table The results table, with its SQL and base URL set. */
    protected achievements_table $table;

    /** @var int Rows per page. */
    protected int $pagesize;

    /**
     * Constructor.
     *
     * @param ledger_filter_form $form The filter form, already populated.
     * @param achievements_table $table The results table, with its SQL and base URL set.
     * @param int $pagesize Rows per page.
     */
    public function __construct(ledger_filter_form $form, achievements_table $table, int $pagesize = 50) {
        $this->form     = $form;
        $this->table    = $table;
        $this->pagesize = $pagesize;
    }

    /**
     * Export the page for the ledger_page template.
     *
     * @param renderer_base $output The renderer.
     * @return array Template context.
     */
    public function export_for_template(renderer_base $output): array {
        ob_start();
        $this->table->out($this->pagesize, true);
        $tablehtml = ob_get_clean();

        return [
            'formhtml'  => $this->form->render(),
            'tablehtml' => $tablehtml,
        ];
    }
}
