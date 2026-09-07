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

namespace local_completionhistory\form;

use local_completionhistory\local\flag_service;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

/**
 * Form for adding or editing a system flag definition.
 *
 * Each flag type has its own block of configuration fields; hideIf rules keyed
 * on the type select show only the block that applies. The page builds the
 * configjson from the fields of the selected type.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class flag_form extends \moodleform {
    /** @var int Maximum length of a flag name. */
    const NAME_MAXLENGTH = 100;
    /** @var int Maximum length of a flag code. */
    const CODE_MAXLENGTH = 50;
    /** @var string Pattern a flag code must match (letters, digits, underscore, hyphen). */
    const CODE_PATTERN = '/^[A-Za-z0-9_\-]+$/';

    /**
     * Form definition.
     *
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);

        // Name.
        $mform->addElement('text', 'name', get_string('flag_name', 'local_completionhistory'), [
            'size' => 50,
            'maxlength' => self::NAME_MAXLENGTH,
        ]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', get_string('required'), 'required', null, 'client');
        $mform->addRule(
            'name',
            get_string('maximumchars', '', self::NAME_MAXLENGTH),
            'maxlength',
            self::NAME_MAXLENGTH,
            'client'
        );

        // Code.
        $mform->addElement('text', 'code', get_string('flag_code', 'local_completionhistory'), [
            'size' => 30,
            'maxlength' => self::CODE_MAXLENGTH,
        ]);
        $mform->setType('code', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('code', 'flag_code', 'local_completionhistory');
        $mform->addRule('code', get_string('required'), 'required', null, 'client');
        $mform->addRule(
            'code',
            get_string('maximumchars', '', self::CODE_MAXLENGTH),
            'maxlength',
            self::CODE_MAXLENGTH,
            'client'
        );
        $mform->addRule(
            'code',
            get_string('flag_code_help', 'local_completionhistory'),
            'regex',
            self::CODE_PATTERN,
            'client'
        );

        // Description.
        $mform->addElement('textarea', 'description', get_string('flag_description', 'local_completionhistory'), [
            'rows' => 2,
            'cols' => 60,
        ]);
        $mform->setType('description', PARAM_TEXT);

        // Type.
        $mform->addElement(
            'select',
            'flag_type',
            get_string('flag_type', 'local_completionhistory'),
            flag_service::type_labels()
        );
        $mform->setType('flag_type', PARAM_ALPHANUMEXT);
        $mform->setDefault('flag_type', flag_service::TYPE_FAST_COMPLETION);

        // Severity.
        $mform->addElement(
            'select',
            'severity',
            get_string('flag_severity', 'local_completionhistory'),
            flag_service::severity_labels()
        );
        $mform->setType('severity', PARAM_ALPHA);
        $mform->setDefault('severity', flag_service::SEVERITY_WARNING);

        // Enabled.
        $mform->addElement('advcheckbox', 'enabled', get_string('flag_enabled', 'local_completionhistory'));
        $mform->setDefault('enabled', 1);

        // Type-specific configuration.
        $mform->addElement('header', 'configheader', get_string('flag_config', 'local_completionhistory'));
        $mform->setExpanded('configheader', true);

        // Fast completion.
        $mform->addElement(
            'text',
            'threshold_minutes',
            get_string('flag_threshold_minutes', 'local_completionhistory'),
            ['size' => 6]
        );
        $mform->setType('threshold_minutes', PARAM_INT);
        $mform->setDefault('threshold_minutes', 20);
        $mform->addHelpButton('threshold_minutes', 'flag_threshold_minutes', 'local_completionhistory');
        $mform->hideIf('threshold_minutes', 'flag_type', 'neq', flag_service::TYPE_FAST_COMPLETION);

        // Duration exact.
        $mform->addElement(
            'text',
            'duration_minutes',
            get_string('flag_duration_minutes', 'local_completionhistory'),
            ['size' => 6]
        );
        $mform->setType('duration_minutes', PARAM_INT);
        $mform->setDefault('duration_minutes', 120);
        $mform->hideIf('duration_minutes', 'flag_type', 'neq', flag_service::TYPE_DURATION_EXACT);

        $mform->addElement(
            'text',
            'tolerance_seconds',
            get_string('flag_tolerance_seconds', 'local_completionhistory'),
            ['size' => 6]
        );
        $mform->setType('tolerance_seconds', PARAM_INT);
        $mform->setDefault('tolerance_seconds', 10);
        $mform->addHelpButton('tolerance_seconds', 'flag_tolerance_seconds', 'local_completionhistory');
        $mform->hideIf('tolerance_seconds', 'flag_type', 'neq', flag_service::TYPE_DURATION_EXACT);

        // Score range.
        $mform->addElement('text', 'score_min', get_string('flag_score_min', 'local_completionhistory'), ['size' => 6]);
        $mform->setType('score_min', PARAM_INT);
        $mform->setDefault('score_min', 0);
        $mform->hideIf('score_min', 'flag_type', 'neq', flag_service::TYPE_SCORE_RANGE);

        $mform->addElement('text', 'score_max', get_string('flag_score_max', 'local_completionhistory'), ['size' => 6]);
        $mform->setType('score_max', PARAM_INT);
        $mform->setDefault('score_max', 100);
        $mform->hideIf('score_max', 'flag_type', 'neq', flag_service::TYPE_SCORE_RANGE);

        $mform->addElement(
            'static',
            'score_range_help',
            '',
            get_string('flag_score_range_help', 'local_completionhistory')
        );
        $mform->hideIf('score_range_help', 'flag_type', 'neq', flag_service::TYPE_SCORE_RANGE);

        // Duplicate account.
        $mform->addElement(
            'advcheckbox',
            'same_email_domain',
            get_string('flag_same_email_domain', 'local_completionhistory')
        );
        $mform->setDefault('same_email_domain', 0);
        $mform->hideIf('same_email_domain', 'flag_type', 'neq', flag_service::TYPE_DUPLICATE_ACCOUNT);

        $mform->addElement(
            'static',
            'duplicate_account_help',
            '',
            get_string('flag_duplicate_account_help', 'local_completionhistory')
        );
        $mform->hideIf('duplicate_account_help', 'flag_type', 'neq', flag_service::TYPE_DUPLICATE_ACCOUNT);

        // New account.
        $mform->addElement(
            'text',
            'max_days_before',
            get_string('flag_max_days_before', 'local_completionhistory'),
            ['size' => 6]
        );
        $mform->setType('max_days_before', PARAM_INT);
        $mform->setDefault('max_days_before', 2);
        $mform->addHelpButton('max_days_before', 'flag_max_days_before', 'local_completionhistory');
        $mform->hideIf('max_days_before', 'flag_type', 'neq', flag_service::TYPE_NEW_ACCOUNT);

        $this->add_action_buttons(true, get_string('save'));
    }

    /**
     * Server-side validation, reproducing the checks the page used to run itself.
     *
     * @param array $data  Submitted data.
     * @param array $files Submitted files.
     * @return array Errors keyed by element name.
     */
    public function validation($data, $files): array {
        global $DB;

        $errors = parent::validation($data, $files);

        $type = $data['flag_type'] ?? '';
        if (!array_key_exists($type, flag_service::type_labels())) {
            $errors['flag_type'] = get_string('flag_invalid_type', 'local_completionhistory');
        }
        if (!array_key_exists($data['severity'] ?? '', flag_service::severity_labels())) {
            $errors['severity'] = get_string('flag_invalid_severity', 'local_completionhistory');
        }

        // Uniqueness on code (excluding self).
        $code = trim((string) ($data['code'] ?? ''));
        $existing = $DB->get_record('local_completionhistory_flag_def', ['code' => $code]);
        if ($existing && (int) $existing->id !== (int) ($data['id'] ?? 0)) {
            $errors['code'] = get_string('flag_code_taken', 'local_completionhistory');
        }

        if ($type === flag_service::TYPE_FAST_COMPLETION) {
            if ((int) ($data['threshold_minutes'] ?? 0) <= 0) {
                $errors['threshold_minutes'] = get_string('flag_threshold_required', 'local_completionhistory');
            }
        } else if ($type === flag_service::TYPE_DURATION_EXACT) {
            if ((int) ($data['duration_minutes'] ?? 0) <= 0) {
                $errors['duration_minutes'] = get_string('flag_duration_required', 'local_completionhistory');
            }
        } else if ($type === flag_service::TYPE_SCORE_RANGE) {
            $min = (int) ($data['score_min'] ?? 0);
            $max = (int) ($data['score_max'] ?? 100);
            if ($min < 0 || $max > 100 || $min > $max) {
                $errors['score_min'] = get_string('flag_score_range_invalid', 'local_completionhistory');
            }
        } else if ($type === flag_service::TYPE_NEW_ACCOUNT) {
            if ((int) ($data['max_days_before'] ?? 0) <= 0) {
                $errors['max_days_before'] = get_string('flag_maxdays_required', 'local_completionhistory');
            }
        }

        return $errors;
    }

    /**
     * Build the type-specific configjson array from submitted form data.
     *
     * Mirrors the shape the page wrote before the form existed, so stored
     * definitions stay compatible with flag_service::matches().
     *
     * @param \stdClass $data Data returned by get_data().
     * @return array Configuration keyed by option name.
     */
    public static function build_config(\stdClass $data): array {
        $config = [];
        switch ($data->flag_type) {
            case flag_service::TYPE_FAST_COMPLETION:
                $config['threshold_minutes'] = (int) $data->threshold_minutes;
                break;
            case flag_service::TYPE_DURATION_EXACT:
                $config['duration_minutes']  = (int) $data->duration_minutes;
                $config['tolerance_seconds'] = max(0, (int) $data->tolerance_seconds);
                break;
            case flag_service::TYPE_SCORE_RANGE:
                $config['score_min'] = (int) $data->score_min;
                $config['score_max'] = (int) $data->score_max;
                break;
            case flag_service::TYPE_DUPLICATE_ACCOUNT:
                $config['same_email_domain'] = !empty($data->same_email_domain);
                break;
            case flag_service::TYPE_NEW_ACCOUNT:
                $config['max_days_before'] = (int) $data->max_days_before;
                break;
        }
        return $config;
    }
}
