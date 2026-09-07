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
use templatable;

/**
 * The column visibility and ordering widget shared by the staff ledger pages.
 *
 * Renders a checkbox grid (one per known column), a draggable badge list of the
 * visible columns in display order, optional category pills and a search box, and
 * the hidden "visiblecols" input that carries the ordered visible set on submit.
 * The behaviour lives in the local_completionhistory/column_manager AMD module,
 * which finds its parts through data-region attributes.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class column_manager implements renderable, templatable {
    /** @var string[] Every known column name => translated label. */
    protected array $alllabels;

    /** @var string[] Visible column names in display order (unknown names dropped). */
    protected array $visible;

    /** @var string[] Column name => category key. */
    protected array $categories;

    /** @var string[] Category key => translated label, in pill order. */
    protected array $categorylabels;

    /** @var bool Whether the layout came from the user's saved preference. */
    protected bool $fromsavedpref;

    /** @var bool Whether the layout came from the site-wide default. */
    protected bool $fromsitedefault;

    /**
     * Constructor.
     *
     * @param string[] $alllabels Every known column name => translated label.
     * @param string[] $visiblecols Requested visible column names in display order.
     * @param string[] $categories Column name => category key (empty to hide the pills).
     * @param string[] $categorylabels Category key => translated label, in pill order.
     * @param bool $fromsavedpref Whether the layout came from the user's saved preference.
     * @param bool $fromsitedefault Whether the layout came from the site-wide default.
     */
    public function __construct(
        array $alllabels,
        array $visiblecols,
        array $categories = [],
        array $categorylabels = [],
        bool $fromsavedpref = false,
        bool $fromsitedefault = false
    ) {
        $this->alllabels       = $alllabels;
        $this->categories      = $categories;
        $this->categorylabels  = $categorylabels;
        $this->fromsavedpref   = $fromsavedpref;
        $this->fromsitedefault = $fromsitedefault;

        $this->visible = [];
        foreach ($visiblecols as $col) {
            if (isset($alllabels[$col]) && !in_array($col, $this->visible, true)) {
                $this->visible[] = $col;
            }
        }
    }

    /**
     * The visible column names in display order, restricted to known columns.
     *
     * @return string[] Column names.
     */
    public function get_visible_columns(): array {
        return $this->visible;
    }

    /**
     * The comma-separated value the hidden "visiblecols" input carries.
     *
     * @return string The ordered visible column names joined by commas.
     */
    public function get_visiblecols_value(): string {
        return implode(',', $this->visible);
    }

    /**
     * Export the widget for the column_manager template.
     *
     * @param renderer_base $output The renderer.
     * @return array Template context.
     */
    public function export_for_template(renderer_base $output): array {
        $columns = [];
        foreach ($this->alllabels as $name => $label) {
            $columns[] = [
                'name'     => $name,
                'label'    => $label,
                'category' => $this->categories[$name] ?? 'other',
                'visible'  => in_array($name, $this->visible, true),
            ];
        }

        $visiblecolumns = [];
        foreach ($this->visible as $name) {
            $visiblecolumns[] = ['name' => $name, 'label' => $this->alllabels[$name]];
        }

        $categories = [];
        foreach ($this->categorylabels as $key => $label) {
            $categories[] = ['key' => $key, 'label' => $label];
        }

        return [
            'columns'          => $columns,
            'visiblecolumns'   => $visiblecolumns,
            'visiblecolsvalue' => $this->get_visiblecols_value(),
            'hascategories'    => !empty($categories),
            'categories'       => $categories,
            'fromsavedpref'    => $this->fromsavedpref,
            'fromsitedefault'  => $this->fromsitedefault,
        ];
    }
}
