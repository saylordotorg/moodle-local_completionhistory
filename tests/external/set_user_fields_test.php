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
 * Tests for the SIS field-mapping writer and the field list the console reads.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_completionhistory\external\set_user_fields
 * @covers \local_completionhistory\external\get_profile_fields
 * @covers \local_completionhistory\local\profile_field_catalogue
 */
final class set_user_fields_test extends advanced_testcase {
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
     * Index the per-field results by name.
     *
     * @param array $result The execute() result.
     * @return array<string, array>
     */
    private function by_name(array $result): array {
        return array_column($result['results'], null, 'name');
    }

    /**
     * A stored datetime as the calendar date Moodle displays, in the timezone core used to store it.
     *
     * @param int|string $ts The stored timestamp.
     * @param \stdClass|null $user Whose timezone to read it in; the current user when null.
     * @return string YYYY-MM-DD
     */
    private function day(int|string $ts, ?\stdClass $user = null): string {
        return (new \DateTime('@' . (int) $ts))->setTimezone(\core_date::get_user_timezone_object($user))->format('Y-m-d');
    }

    /**
     * The SIS is authoritative: an existing ID number is overwritten, and nothing unmapped changes.
     */
    public function test_id_number_is_overwritten(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user([
            'email' => 'adopted@example.com',
            'idnumber' => '11439d4a-1051-700a-cognito',
            'firstname' => 'Kept',
        ]);

        $result = set_user_fields::execute('adopted@example.com', [['name' => 'idnumber', 'value' => 'SU-2026-01043']]);
        $this->assertTrue($result['success']);
        $this->assertSame('changed', $this->by_name($result)['idnumber']['status']);
        $stored = $DB->get_record('user', ['id' => $user->id], '*', MUST_EXIST);
        $this->assertSame('SU-2026-01043', $stored->idnumber);
        $this->assertSame('Kept', $stored->firstname);

        $again = set_user_fields::execute('adopted@example.com', [['name' => 'idnumber', 'value' => 'SU-2026-01043']]);
        $this->assertSame('unchanged', $this->by_name($again)['idnumber']['status'], 're-sending is a no-op');
    }

    /**
     * An ID number another account holds is never duplicated.
     */
    public function test_id_number_held_elsewhere_is_refused(): void {
        global $DB;

        $this->getDataGenerator()->create_user(['email' => 'holder@example.com', 'idnumber' => 'SU-2026-01045']);
        $other = $this->getDataGenerator()->create_user(['email' => 'other@example.com', 'idnumber' => 'OLD-1']);

        $result = set_user_fields::execute('other@example.com', [['name' => 'idnumber', 'value' => 'SU-2026-01045']]);
        $row = $this->by_name($result)['idnumber'];
        $this->assertSame('refused', $row['status']);
        $this->assertStringContainsString('another account', $row['message']);
        $this->assertSame('OLD-1', $DB->get_field('user', 'idnumber', ['id' => $other->id]));
    }

    /**
     * An empty value never clears what Moodle holds.
     */
    public function test_empty_value_is_skipped(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user(['email' => 'legacy@example.com', 'institution' => 'Typed by student']);

        $result = set_user_fields::execute('legacy@example.com', [['name' => 'institution', 'value' => '  ']]);
        $this->assertSame('skipped', $this->by_name($result)['institution']['status']);
        $this->assertSame('Typed by student', $DB->get_field('user', 'institution', ['id' => $user->id]));
    }

    /**
     * Custom profile fields of each supported type are written, and bad values refused.
     */
    public function test_custom_fields_are_written_and_checked(): void {
        global $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');

        $gen = $this->getDataGenerator();
        $gen->create_custom_profile_field(['datatype' => 'text', 'shortname' => 'program', 'name' => 'Program']);
        $gen->create_custom_profile_field([
            'datatype' => 'menu', 'shortname' => 'level', 'name' => 'Level', 'param1' => "Undergraduate\nGraduate",
            'defaultdata' => '',
        ]);
        $gen->create_custom_profile_field(['datatype' => 'checkbox', 'shortname' => 'matriculated', 'name' => 'Matriculated']);
        $gen->create_custom_profile_field([
            'datatype' => 'datetime', 'shortname' => 'enrolled', 'name' => 'Enrolled', 'param1' => 2000, 'param2' => 2050,
        ]);
        $user = $gen->create_user(['email' => 'custom@example.com']);

        $result = set_user_fields::execute('custom@example.com', [
            ['name' => 'profile_field_program', 'value' => 'Bachelor of Arts in Business Administration'],
            ['name' => 'profile_field_level', 'value' => 'Graduate'],
            ['name' => 'profile_field_matriculated', 'value' => 'true'],
            ['name' => 'profile_field_enrolled', 'value' => '2026-09-24'],
        ]);
        $rows = $this->by_name($result);
        foreach (['profile_field_program', 'profile_field_level', 'profile_field_matriculated', 'profile_field_enrolled'] as $f) {
            $this->assertSame('changed', $rows[$f]['status'], "{$f}: {$rows[$f]['message']}");
        }

        $stored = profile_user_record($user->id, false);
        $this->assertSame('Bachelor of Arts in Business Administration', $stored->program);
        $this->assertSame('Graduate', $stored->level);
        $this->assertSame('1', (string) $stored->matriculated);
        $this->assertSame('2026-09-24', $this->day($stored->enrolled));

        $bad = set_user_fields::execute('custom@example.com', [
            ['name' => 'profile_field_level', 'value' => 'Doctoral'],
            ['name' => 'profile_field_matriculated', 'value' => 'maybe'],
            ['name' => 'profile_field_enrolled', 'value' => 'next week'],
            ['name' => 'profile_field_nosuchfield', 'value' => 'x'],
        ]);
        foreach ($this->by_name($bad) as $name => $row) {
            $this->assertSame('refused', $row['status'], "{$name} should be refused");
        }
        $this->assertSame('Graduate', profile_user_record($user->id, false)->level, 'a refused value changes nothing');

        $same = set_user_fields::execute('custom@example.com', [
            ['name' => 'profile_field_program', 'value' => 'Bachelor of Arts in Business Administration'],
            ['name' => 'profile_field_enrolled', 'value' => '2026-09-24'],
        ]);
        foreach ($this->by_name($same) as $name => $row) {
            $this->assertSame('unchanged', $row['status'], "{$name} re-sent should be unchanged");
        }
    }

    /**
     * A date moved by one day is saved, not reported unchanged; invalid and out-of-range dates are refused.
     */
    public function test_dates_are_exact_and_validated(): void {
        global $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');

        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'datetime', 'shortname' => 'enrolled', 'name' => 'Enrolled', 'param1' => 2000, 'param2' => 2050,
        ]);
        $user = $this->getDataGenerator()->create_user(['email' => 'dates@example.com']);

        set_user_fields::execute('dates@example.com', [['name' => 'profile_field_enrolled', 'value' => '2026-09-24']]);
        $next = set_user_fields::execute('dates@example.com', [['name' => 'profile_field_enrolled', 'value' => '2026-09-25']]);
        $this->assertSame('changed', $this->by_name($next)['profile_field_enrolled']['status'], 'an adjacent day is a change');
        $this->assertSame('2026-09-25', $this->day(profile_user_record($user->id, false)->enrolled));

        foreach (['2026-02-30' => 'not a real calendar date', '2099-01-01' => 'year range'] as $value => $why) {
            $row = $this->by_name(set_user_fields::execute('dates@example.com', [
                ['name' => 'profile_field_enrolled', 'value' => $value],
            ]))['profile_field_enrolled'];
            $this->assertSame('refused', $row['status'], "{$value} must be refused");
            $this->assertStringContainsString($why, $row['message']);
        }
        $this->assertSame('2026-09-25', $this->day(profile_user_record($user->id, false)->enrolled));
    }

    /**
     * An ISO date is stored as that calendar day even where noon UTC is already tomorrow.
     */
    public function test_iso_date_survives_a_far_east_timezone(): void {
        global $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');

        $this->setTimezone('Pacific/Kiritimati'); // UTC+14.
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'datetime', 'shortname' => 'started', 'name' => 'Started', 'param1' => 2000, 'param2' => 2050,
        ]);
        $user = $this->getDataGenerator()->create_user(['email' => 'east@example.com']);

        set_user_fields::execute('east@example.com', [['name' => 'profile_field_started', 'value' => '2026-09-24']]);
        $this->assertSame('2026-09-24', $this->day(profile_user_record($user->id, false)->started));
    }

    /**
     * A date shows as the day the SIS sent in the LEARNER's own timezone, whatever the caller's is.
     */
    public function test_date_is_the_learners_calendar_day(): void {
        global $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');

        $this->setTimezone('UTC');
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'datetime', 'shortname' => 'started', 'name' => 'Started', 'param1' => 2000, 'param2' => 2050,
        ]);
        $learner = $this->getDataGenerator()->create_user(['email' => 'la@example.com', 'timezone' => 'America/Los_Angeles']);

        set_user_fields::execute('la@example.com', [['name' => 'profile_field_started', 'value' => '2026-09-24']]);
        $this->assertSame('2026-09-24', $this->day(profile_user_record($learner->id, false)->started, $learner));

        $again = set_user_fields::execute('la@example.com', [['name' => 'profile_field_started', 'value' => '2026-09-24']]);
        $this->assertSame('unchanged', $this->by_name($again)['profile_field_started']['status']);
    }

    /**
     * Markup-only text is empty once cleaned, so it never clears a stored value.
     */
    public function test_markup_only_text_is_not_written(): void {
        $this->getDataGenerator()->create_custom_profile_field(['datatype' => 'text', 'shortname' => 'program', 'name' => 'Program']);
        $user = $this->getDataGenerator()->create_user(['email' => 'markup@example.com']);
        set_user_fields::execute('markup@example.com', [['name' => 'profile_field_program', 'value' => 'MBA']]);

        $row = $this->by_name(set_user_fields::execute('markup@example.com', [
            ['name' => 'profile_field_program', 'value' => '<b></b>'],
        ]))['profile_field_program'];
        $this->assertSame('refused', $row['status']);
        $this->assertSame('MBA', profile_user_record($user->id, false)->program);
    }

    /**
     * A date field set to unique is shown unsupported and never written.
     */
    public function test_unique_date_field_is_unsupported(): void {
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'datetime', 'shortname' => 'udate', 'name' => 'Unique date', 'param1' => 2000, 'param2' => 2050,
            'forceunique' => 1,
        ]);
        $this->getDataGenerator()->create_user(['email' => 'udate@example.com']);

        $fields = array_column(get_profile_fields::execute()['fields'], null, 'name');
        $this->assertFalse($fields['profile_field_udate']['supported']);

        $row = $this->by_name(set_user_fields::execute('udate@example.com', [
            ['name' => 'profile_field_udate', 'value' => '2026-09-24'],
        ]))['profile_field_udate'];
        $this->assertSame('refused', $row['status']);
    }

    /**
     * A change to custom fields alone still stamps the user's modification time.
     */
    public function test_custom_only_change_stamps_timemodified(): void {
        global $DB;

        $this->getDataGenerator()->create_custom_profile_field(['datatype' => 'text', 'shortname' => 'program', 'name' => 'Program']);
        $user = $this->getDataGenerator()->create_user(['email' => 'stamp@example.com']);
        $DB->set_field('user', 'timemodified', 1000, ['id' => $user->id]);

        set_user_fields::execute('stamp@example.com', [['name' => 'profile_field_program', 'value' => 'MBA']]);
        $this->assertGreaterThan(1000, (int) $DB->get_field('user', 'timemodified', ['id' => $user->id]));
    }

    /**
     * A Unix timestamp is not an accepted date form: its calendar year depends on the timezone.
     */
    public function test_timestamp_dates_are_refused(): void {
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'datetime', 'shortname' => 'enrolled', 'name' => 'Enrolled', 'param1' => 2000, 'param2' => 2050,
        ]);
        $this->getDataGenerator()->create_user(['email' => 'ts@example.com']);

        $row = $this->by_name(set_user_fields::execute('ts@example.com', [
            ['name' => 'profile_field_enrolled', 'value' => '2556142200'],
        ]))['profile_field_enrolled'];
        $this->assertSame('refused', $row['status']);
    }

    /**
     * Two long forceunique values that share a 32-character prefix are different values.
     */
    public function test_forceunique_compares_the_whole_value(): void {
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text', 'shortname' => 'longref', 'name' => 'Long reference', 'forceunique' => 1,
        ]);
        $this->getDataGenerator()->create_user(['email' => 'long1@example.com']);
        $this->getDataGenerator()->create_user(['email' => 'long2@example.com']);
        $prefix = str_repeat('x', 40);

        set_user_fields::execute('long1@example.com', [['name' => 'profile_field_longref', 'value' => $prefix . 'A']]);
        $row = $this->by_name(set_user_fields::execute('long2@example.com', [
            ['name' => 'profile_field_longref', 'value' => $prefix . 'B'],
        ]))['profile_field_longref'];
        $this->assertSame('changed', $row['status'], $row['message']);
    }

    /**
     * A forceunique custom field never ends up with the same value on two accounts.
     */
    public function test_forceunique_custom_field_is_not_duplicated(): void {
        global $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');

        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text', 'shortname' => 'sisref', 'name' => 'SIS reference', 'forceunique' => 1,
        ]);
        $first = $this->getDataGenerator()->create_user(['email' => 'first@example.com']);
        $second = $this->getDataGenerator()->create_user(['email' => 'second@example.com']);

        $ok = set_user_fields::execute('first@example.com', [['name' => 'profile_field_sisref', 'value' => 'SU-2026-01050']]);
        $this->assertSame('changed', $this->by_name($ok)['profile_field_sisref']['status']);

        $dup = $this->by_name(set_user_fields::execute('second@example.com', [
            ['name' => 'profile_field_sisref', 'value' => 'SU-2026-01050'],
        ]))['profile_field_sisref'];
        $this->assertSame('refused', $dup['status']);
        $this->assertStringContainsString('must be unique', $dup['message']);
        $this->assertSame('', (string) (profile_user_record($second->id, false)->sisref ?? ''));
        $this->assertSame('SU-2026-01050', profile_user_record($first->id, false)->sisref);
    }

    /**
     * Identity and access fields are not writable, whatever the SIS sends.
     */
    public function test_identity_fields_are_refused(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user(['email' => 'safe@example.com', 'auth' => 'manual']);

        $result = set_user_fields::execute('safe@example.com', [
            ['name' => 'auth', 'value' => 'nologin'],
            ['name' => 'username', 'value' => 'hijack'],
            ['name' => 'firstname', 'value' => 'Changed'],
        ]);
        foreach ($this->by_name($result) as $name => $row) {
            $this->assertSame('refused', $row['status'], "{$name} must be refused");
        }
        $stored = $DB->get_record('user', ['id' => $user->id], '*', MUST_EXIST);
        $this->assertSame('manual', $stored->auth);
        $this->assertSame($user->username, $stored->username);
        $this->assertSame($user->firstname, $stored->firstname);
    }

    /**
     * A staff account is refused before anything is written.
     */
    public function test_staff_account_is_refused(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user(['email' => 'teacher@example.com', 'idnumber' => 'STAFF-9']);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $result = set_user_fields::execute('teacher@example.com', [['name' => 'idnumber', 'value' => 'SU-2026-01046']]);
        $this->assertFalse($result['success']);
        $this->assertSame('STAFF-9', $DB->get_field('user', 'idnumber', ['id' => $teacher->id]));
    }

    /**
     * The profile-updates capability is required, not just the base integration one.
     */
    public function test_update_capability_is_required(): void {
        $caller = $this->getDataGenerator()->create_user();
        $roleid = create_role('Integration reader', 'integrationreader', '');
        assign_capability('local/completionhistory:integrate', CAP_ALLOW, $roleid, \context_system::instance()->id);
        role_assign($roleid, $caller->id, \context_system::instance()->id);
        $this->getDataGenerator()->create_user(['email' => 'target@example.com']);
        $this->setUser($caller);

        $this->expectException(\required_capability_exception::class);
        set_user_fields::execute('target@example.com', [['name' => 'idnumber', 'value' => 'SU-2026-01047']]);
    }

    /**
     * The field list offers exactly what the writer accepts, custom fields included.
     */
    public function test_field_list_matches_the_writer(): void {
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'menu', 'shortname' => 'level', 'name' => 'Level', 'param1' => "Undergraduate\nGraduate",
        ]);

        $fields = array_column(get_profile_fields::execute()['fields'], null, 'name');
        $this->assertArrayHasKey('idnumber', $fields);
        $this->assertArrayNotHasKey('username', $fields);
        $this->assertArrayNotHasKey('firstname', $fields);
        $this->assertSame('custom', $fields['profile_field_level']['kind']);
        $this->assertSame(['Undergraduate', 'Graduate'], $fields['profile_field_level']['options']);
        $this->assertTrue($fields['profile_field_level']['supported']);
    }
}
