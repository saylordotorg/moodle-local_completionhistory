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
use local_completionhistory\local\flag_service;
use moodle_url;
use stdClass;

/**
 * The system flags list with its add, load-presets, edit, toggle and delete controls.
 *
 * Rendered through templates/flags_list.mustache.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class flags_list implements renderable, templatable {
    /** @var string Path of the list page, which also receives the POST actions. */
    const LIST_PAGE = '/local/completionhistory/manage_flags.php';
    /** @var string Path of the add/edit page. */
    const EDIT_PAGE = '/local/completionhistory/edit_flag.php';

    /** @var stdClass[] Flag definition records, already ordered for display. */
    private array $flags;

    /**
     * Constructor.
     *
     * @param stdClass[] $flags Flag definition records, already ordered for display.
     */
    public function __construct(array $flags) {
        $this->flags = $flags;
    }

    /**
     * Summarise a decoded configjson as "key=value" pairs.
     *
     * @param array $config Decoded configuration.
     * @return string Localised, comma-separated summary ('' when there is nothing to show).
     */
    public static function config_summary(array $config): string {
        $pairs = [];
        foreach ($config as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? get_string('yes') : get_string('no');
            }
            $pairs[] = get_string('flag_config_pair', 'local_completionhistory', (object) [
                'key'   => $key,
                'value' => (string) $value,
            ]);
        }
        return implode(get_string('listsep', 'langconfig') . ' ', $pairs);
    }

    /**
     * Export the list for the template.
     *
     * @param renderer_base $output Renderer.
     * @return stdClass Template context.
     */
    public function export_for_template(renderer_base $output): stdClass {
        $data = new stdClass();
        $data->addurl = (new moodle_url(self::EDIT_PAGE))->out(false);
        $data->listurl = (new moodle_url(self::LIST_PAGE))->out(false);
        $data->sesskey = sesskey();
        $data->hasflags = !empty($this->flags);
        $data->presetsconfirm = (object) [
            'title'   => json_encode(['flagsloadpresets', 'local_completionhistory']),
            'content' => json_encode(['flagsloadpresets_confirm', 'local_completionhistory']),
            'yes'     => json_encode(['flagsloadpresets', 'local_completionhistory']),
        ];
        $data->deleteconfirm = (object) [
            'title'   => json_encode(['delete', 'core']),
            'content' => json_encode(['flagdelete_confirm', 'local_completionhistory']),
            'yes'     => json_encode(['delete', 'core']),
        ];
        $data->rows = [];

        $typelabels = flag_service::type_labels();
        $sevlabels = flag_service::severity_labels();

        foreach ($this->flags as $flag) {
            $config = json_decode($flag->configjson ?? '', true) ?: [];

            $row = new stdClass();
            $row->id = (int) $flag->id;
            $row->name = $flag->name;
            $row->code = $flag->code;
            $row->typelabel = $typelabels[$flag->flag_type] ?? $flag->flag_type;
            $row->sevlabel = $sevlabels[$flag->severity] ?? $flag->severity;
            $row->sevclass = flag_service::severity_badge_class($flag->severity);
            $row->configsummary = self::config_summary($config);
            $row->hasconfig = $row->configsummary !== '';
            $row->enabled = (bool) $flag->enabled;
            $row->editurl = (new moodle_url(self::EDIT_PAGE, ['id' => $flag->id]))->out(false);
            $row->togglelabel = $flag->enabled
                ? get_string('flagdisable', 'local_completionhistory')
                : get_string('flagenable', 'local_completionhistory');
            $row->toggleclass = $flag->enabled ? 'btn-outline-warning' : 'btn-outline-success';
            $data->rows[] = $row;
        }

        return $data;
    }
}
