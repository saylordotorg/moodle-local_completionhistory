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
use local_completionhistory\local\course_config_service;
use moodle_url;
use stdClass;

/**
 * The course exam configuration list: one row per configured course.
 *
 * Rendered through templates/course_exam_config_list.mustache.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_exam_config_list implements renderable, templatable {
    /** @var string Path of the page that lists, edits and deletes configurations. */
    const PAGE = '/local/completionhistory/course_exam_config.php';

    /** @var stdClass[] Configuration records for the current page. */
    private array $configs;
    /** @var int Total number of configuration records. */
    private int $total;
    /** @var int Current 0-based page. */
    private int $page;
    /** @var int Records per page. */
    private int $perpage;

    /**
     * Constructor.
     *
     * @param stdClass[] $configs Configuration records for the current page.
     * @param int        $total   Total number of configuration records.
     * @param int        $page    Current 0-based page.
     * @param int        $perpage Records per page.
     */
    public function __construct(array $configs, int $total, int $page, int $perpage) {
        $this->configs = $configs;
        $this->total = $total;
        $this->page = $page;
        $this->perpage = $perpage;
    }

    /**
     * Bootstrap badge modifier class for each course type.
     *
     * @param string $coursetype One of the course_config_service::TYPE_* constants.
     * @return string Badge CSS class.
     */
    public static function type_badge_class(string $coursetype): string {
        return match ($coursetype) {
            course_config_service::TYPE_PROGRAM   => 'badge-primary',
            course_config_service::TYPE_OPEN_DUAL => 'badge-info',
            course_config_service::TYPE_OPEN_CERT => 'badge-success',
            default                               => 'badge-secondary',
        };
    }

    /**
     * Export the list for the template.
     *
     * @param renderer_base $output Renderer.
     * @return stdClass Template context.
     */
    public function export_for_template(renderer_base $output): stdClass {
        global $DB;

        $data = new stdClass();
        $data->addurl = (new moodle_url(self::PAGE, ['action' => 'edit', 'courseid' => 0]))->out(false);
        $data->listurl = (new moodle_url(self::PAGE))->out(false);
        $data->sesskey = sesskey();
        $data->hasconfigs = !empty($this->configs);
        $data->rows = [];
        $data->deleteconfirm = (object) [
            'title'   => json_encode(['delete', 'core']),
            'content' => json_encode(['examconfig_confirmdelete', 'local_completionhistory']),
            'yes'     => json_encode(['delete', 'core']),
        ];
        $data->pagingbar = $output->paging_bar($this->total, $this->page, $this->perpage, new moodle_url(self::PAGE));

        if (!$data->hasconfigs) {
            return $data;
        }

        // One query each for the courses and quizzes the page refers to.
        $courseids = array_map(static fn(stdClass $cfg): int => (int) $cfg->courseid, $this->configs);
        $courses = $DB->get_records_list('course', 'id', $courseids, '', 'id, fullname, shortname');

        $quizids = [];
        foreach ($this->configs as $cfg) {
            foreach (['program_final_quizid', 'dc_quizid', 'cert_quizid'] as $field) {
                if (!empty($cfg->$field)) {
                    $quizids[] = (int) $cfg->$field;
                }
            }
        }
        $quizzes = $quizids ? $DB->get_records_list('quiz', 'id', array_unique($quizids), '', 'id, name') : [];

        $typelabels = course_config_service::type_labels();
        $notmapped = get_string('examconfig_notmapped_short', 'local_completionhistory');
        $quizname = static function (?int $quizid) use ($quizzes, $notmapped): string {
            if (!$quizid) {
                return $notmapped;
            }
            if (isset($quizzes[$quizid])) {
                return format_string($quizzes[$quizid]->name);
            }
            return get_string('examconfig_quiz_missing', 'local_completionhistory', $quizid);
        };

        foreach ($this->configs as $cfg) {
            $course = $courses[$cfg->courseid] ?? null;
            $certattempts = (int) $cfg->cert_attempts_allowed === 0
                ? get_string('examconfig_unlimited', 'local_completionhistory')
                : (string) $cfg->cert_attempts_allowed;

            $row = new stdClass();
            $row->courseid = (int) $cfg->courseid;
            $row->coursename = $course
                ? format_string($course->fullname)
                : get_string('examconfig_course_missing', 'local_completionhistory', $cfg->courseid);
            $row->shortname = $course ? $course->shortname : $notmapped;
            $row->typelabel = $typelabels[$cfg->course_type] ?? $cfg->course_type;
            $row->typebadgeclass = self::type_badge_class($cfg->course_type);
            $row->programquiz = $quizname($cfg->program_final_quizid ? (int) $cfg->program_final_quizid : null);
            $row->dcquiz = $quizname($cfg->dc_quizid ? (int) $cfg->dc_quizid : null);
            $row->certquiz = $quizname($cfg->cert_quizid ? (int) $cfg->cert_quizid : null);
            $row->attempts = get_string('examconfig_attempts_summary', 'local_completionhistory', (object) [
                'program' => $cfg->program_attempts_allowed,
                'dc'      => $cfg->dc_attempts_allowed,
                'cert'    => $certattempts,
            ]);
            $row->editurl = (new moodle_url(self::PAGE, ['action' => 'edit', 'courseid' => $cfg->courseid]))->out(false);
            $data->rows[] = $row;
        }

        return $data;
    }
}
