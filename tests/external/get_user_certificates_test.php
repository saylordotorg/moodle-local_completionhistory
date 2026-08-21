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
use context_system;

/**
 * Tests for the get_user_certificates external function.
 *
 * WHAT CI CAN AND CANNOT EXERCISE, stated so nobody reads more into a green run
 * than it says. The CI Moodle does not install tool_certificate, so the row-reading
 * path never executes here — what CI proves is the boundary around it: the enabled
 * gate, the capability, the email discipline, and that a site without a certificate
 * manager says so rather than answering "none". The row path is exercised manually
 * against a site that has the plugin, and its shape is pinned by the static check
 * in tests/static/check_certificates_boundary.php.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_completionhistory\external\get_user_certificates
 */
final class get_user_certificates_test extends advanced_testcase {

    /** Grant the certificate-read capability to the current user via a fresh role. */
    private function grant_capability(): void {
        global $DB;
        $roleid = create_role('Cert reader', 'certreader', '');
        assign_capability('local/completionhistory:viewcertificates', CAP_ALLOW,
            $roleid, context_system::instance()->id);
        role_assign($roleid, $DB->get_field('user', 'id', ['username' => 'certcaller']),
            context_system::instance()->id);
    }

    /** A logged-in caller holding exactly the read capability. */
    private function login_caller(): void {
        $caller = $this->getDataGenerator()->create_user(['username' => 'certcaller']);
        $this->setUser($caller);
        $this->grant_capability();
    }

    public function test_disabled_plugin_refuses(): void {
        $this->resetAfterTest();
        set_config('enabled', 0, 'local_completionhistory');
        $this->login_caller();

        $this->expectException(\moodle_exception::class);
        get_user_certificates::execute('someone@example.com');
    }

    public function test_capability_is_required(): void {
        $this->resetAfterTest();
        set_config('enabled', 1, 'local_completionhistory');
        // A plain user with no granted role: the by-email read must be refused.
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\required_capability_exception::class);
        get_user_certificates::execute('someone@example.com');
    }

    public function test_site_without_certificate_manager_says_so(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('enabled', 1, 'local_completionhistory');
        $this->login_caller();
        $learner = $this->getDataGenerator()->create_user(['email' => 'grad@example.com']);

        $result = get_user_certificates::execute('grad@example.com');
        $result = \core_external\external_api::clean_returnvalue(
            get_user_certificates::execute_returns(), $result);

        if ($DB->get_manager()->table_exists('tool_certificate_issues')) {
            // A CI image that DOES carry tool_certificate answers available=true;
            // the assertion below would then be asserting the wrong thing.
            $this->assertTrue($result['available']);
        } else {
            // The distinction under test: "this site cannot answer" is not "none".
            $this->assertFalse($result['available']);
        }
        $this->assertSame([], $result['certificates']);
    }

    public function test_ambiguous_email_is_refused_not_resolved(): void {
        global $CFG;
        $this->resetAfterTest();
        set_config('enabled', 1, 'local_completionhistory');
        $this->login_caller();

        // Moodle permits duplicate emails when configured to; the function must
        // refuse rather than hand one account's certificates to another.
        $CFG->allowaccountssameemail = 1;
        $this->getDataGenerator()->create_user(['email' => 'twin@example.com']);
        $this->getDataGenerator()->create_user(['email' => 'twin@example.com']);

        // Only reachable when the issues table exists — without it the function
        // correctly answers "unavailable" before ever looking at the email. The
        // email-ambiguity contract is owned (and tested) by
        // security::get_unique_local_user_by_email either way; this documents the
        // call order rather than re-proving the helper.
        global $DB;
        if (!$DB->get_manager()->table_exists('tool_certificate_issues')) {
            $result = \core_external\external_api::clean_returnvalue(
                get_user_certificates::execute_returns(),
                get_user_certificates::execute('twin@example.com'));
            $this->assertFalse($result['available']);
            return;
        }

        $this->expectException(\moodle_exception::class);
        get_user_certificates::execute('twin@example.com');
    }
}
