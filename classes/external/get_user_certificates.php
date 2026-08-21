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
 * External function listing a learner's issued tool_certificate certificates.
 *
 * WHY THIS LIVES IN completionhistory RATHER THAN ITS OWN PLUGIN. This function
 * exists for a site this plugin was not originally written for: learn.saylor.org,
 * where course certificates are issued by tool_certificate (the Certificate
 * manager) — which registers NO web-service function that lists a user's issues.
 * Its externals are all admin writes: revoke, regenerate, template edits. The read
 * the SIS needs simply does not exist to be granted, so it is added here, in the
 * plugin the SIS already speaks and the team already reviews, rather than in a
 * second plugin whose whole content would be this file.
 *
 * IDENTIFIED BY EMAIL, UNLIKE EVERY OTHER FUNCTION HERE, and the difference is the
 * point. The SIS knows its own Moodle's user ids because it provisions those
 * accounts. On learn.saylor.org it provisions nothing and knows nobody's id — the
 * only identity both systems share is the email address. Resolution goes through
 * security::get_unique_local_user_by_email, which throws on a duplicate rather
 * than picking an account, because "some other person with the same address got
 * my certificates" is not a defensible failure mode.
 *
 * NOT CALLABLE FROM PAGE JAVASCRIPT. An AJAX-exposed by-email read would let any
 * logged-in browser session enumerate other people's certificate codes, and a
 * certificate code is a bearer credential for the public verify page. Server-to-
 * server only, behind its own capability, so a token can be scoped to exactly
 * this and nothing else this plugin can do.
 *
 * RETURNS EMPTY, NOT AN ERROR, WHERE EMPTINESS IS THE TRUTH. An unknown email is
 * a learner with no account here; an account with no issues has no certificates.
 * The one condition reported distinctly is tool_certificate not being installed
 * (`available: false`) — on the SIS's own Moodle it is not, and a caller must be
 * able to tell "this site cannot answer" from "the answer is none".
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_user_certificates extends external_api {

    /** Hard ceiling on rows per call, matching the plugin's other reads. */
    private const MAX_LIMIT = 500;

    /** Prevent pathological deep offsets from causing expensive scans. */
    private const MAX_OFFSET = 100000;

    /**
     * Parameters definition.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'email' => new external_value(PARAM_EMAIL, 'Email address identifying the learner'),
            'limit' => new external_value(PARAM_INT, 'Maximum records to return', VALUE_DEFAULT, 100),
            'offset' => new external_value(PARAM_INT, 'Offset for pagination', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Execute the function.
     *
     * @param string $email Learner email.
     * @param int $limit Page size.
     * @param int $offset Page offset.
     * @return array available flag plus certificate list, newest first.
     */
    public static function execute(string $email, int $limit = 100, int $offset = 0): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'email' => $email,
            'limit' => $limit,
            'offset' => $offset,
        ]);

        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);
        \local_completionhistory\local\security::require_enabled();
        require_capability('local/completionhistory:viewcertificates', $systemcontext);

        // The table, not the component directory, because the table is what gets
        // queried: a half-uninstalled plugin whose code is gone but whose tables
        // remain would still answer truthfully.
        $manager = $DB->get_manager();
        if (!$manager->table_exists('tool_certificate_issues')) {
            return ['available' => false, 'certificates' => []];
        }

        $user = \local_completionhistory\local\security::get_unique_local_user_by_email($params['email']);
        if (!$user) {
            return ['available' => true, 'certificates' => []];
        }

        $limit = max(1, min(self::MAX_LIMIT, (int) $params['limit']));
        $offset = max(0, min(self::MAX_OFFSET, (int) $params['offset']));

        /*
         * `courseid` on the issues table arrived in a later tool_certificate than
         * some sites run, so its presence is checked rather than assumed — the
         * function has to work on whatever version the managed host carries.
         */
        $hascourse = $manager->field_exists(
            new \xmldb_table('tool_certificate_issues'),
            new \xmldb_field('courseid')
        );

        $coursejoin = $hascourse ? 'LEFT JOIN {course} c ON c.id = i.courseid' : '';
        $coursefields = $hascourse
            ? 'i.courseid, c.fullname AS coursename, c.shortname AS courseshortname,'
            : 'NULL AS courseid, NULL AS coursename, NULL AS courseshortname,';

        $issues = $DB->get_records_sql(
            "SELECT i.id, i.code, i.timecreated, i.expires, i.component, i.data,
                    {$coursefields}
                    t.name AS templatename
               FROM {tool_certificate_issues} i
               JOIN {tool_certificate_templates} t ON t.id = i.templateid
              WHERE i.userid = :userid
           ORDER BY i.timecreated DESC, i.id DESC",
            ['userid' => $user->id],
            $offset,
            $limit
        );

        $result = [];
        foreach ($issues as $issue) {
            $coursename = $issue->coursename;
            $courseshortname = $issue->courseshortname;
            if ($coursename === null && !empty($issue->data)) {
                // Older issues carry course identity only inside the data blob the
                // issuer snapshotted. Read, never trusted for anything but display.
                $data = json_decode($issue->data, true);
                if (is_array($data)) {
                    $coursename = isset($data['coursefullname']) ? (string) $data['coursefullname'] : null;
                    $courseshortname = isset($data['courseshortname']) ? (string) $data['courseshortname'] : null;
                }
            }

            $result[] = [
                'id' => (int) $issue->id,
                'name' => (string) $issue->templatename,
                'code' => (string) $issue->code,
                // The public verify page for this exact issue. The SIS links here
                // rather than re-verifying, so each system vouches only for the
                // documents it issued.
                'verifyurl' => (new \moodle_url('/admin/tool/certificate/index.php',
                    ['code' => $issue->code]))->out(false),
                'courseid' => $issue->courseid !== null ? (int) $issue->courseid : 0,
                'coursename' => $coursename ?? '',
                'courseshortname' => $courseshortname ?? '',
                'issuedat' => (int) $issue->timecreated,
                'expires' => (int) $issue->expires,
                'component' => (string) ($issue->component ?? ''),
            ];
        }

        return ['available' => true, 'certificates' => $result];
    }

    /**
     * Return definition.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'available' => new external_value(PARAM_BOOL,
                'False when this site has no certificate manager to ask'),
            'certificates' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Issue id'),
                    'name' => new external_value(PARAM_RAW, 'Certificate (template) name'),
                    'code' => new external_value(PARAM_ALPHANUM, 'Public verification code'),
                    'verifyurl' => new external_value(PARAM_URL, 'Public verification URL for this issue'),
                    'courseid' => new external_value(PARAM_INT, 'Course id (0 when unknown)'),
                    'coursename' => new external_value(PARAM_RAW, 'Course full name, empty when unknown'),
                    'courseshortname' => new external_value(PARAM_RAW, 'Course short name, empty when unknown'),
                    'issuedat' => new external_value(PARAM_INT, 'Issue timestamp'),
                    'expires' => new external_value(PARAM_INT, 'Expiry timestamp, 0 for never'),
                    'component' => new external_value(PARAM_RAW, 'Issuing component, empty when manual'),
                ])
            ),
        ]);
    }
}
