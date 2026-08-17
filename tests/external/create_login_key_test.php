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
 * Tests for learner single sign-on key minting.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class create_login_key_test extends advanced_testcase {
    /**
     * Set up test state.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enabled', 1, 'local_completionhistory');
        $this->setAdminUser();
    }

    /**
     * Staff accounts cannot receive learner login keys.
     */
    public function test_staff_account_is_refused(): void {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $result = create_login_key::execute($teacher->id, '192.0.2.10');
        $this->assertSame('', $result['key']);
        $this->assertEquals(0, $result['expiresin']);
    }

    /**
     * Invalid IP restrictions are refused rather than weakened.
     */
    public function test_invalid_ip_is_refused(): void {
        $learner = $this->getDataGenerator()->create_user();

        $result = create_login_key::execute($learner->id, 'not-an-ip');
        $this->assertSame('', $result['key']);
        $this->assertEquals(0, $result['expiresin']);
    }

    /**
     * Minting a second key removes the first key for that learner.
     */
    public function test_only_one_live_key_exists_per_learner(): void {
        global $DB;

        $learner = $this->getDataGenerator()->create_user();
        $first = create_login_key::execute($learner->id, '192.0.2.10');
        $second = create_login_key::execute($learner->id, '192.0.2.10');

        $this->assertNotSame('', $first['key']);
        $this->assertNotSame($first['key'], $second['key']);
        $this->assertEquals(create_login_key::TTL, $second['expiresin']);
        $keys = $DB->get_records('user_private_key', [
            'script' => create_login_key::SCRIPT,
            'userid' => $learner->id,
        ]);
        $this->assertCount(1, $keys);
        $this->assertSame($second['key'], reset($keys)->value);
    }
}
