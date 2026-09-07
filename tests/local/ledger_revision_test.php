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

namespace local_completionhistory\local;

use advanced_testcase;

/**
 * The achievement ledger's correction history.
 *
 * The Catalyst review pointed out that a ledger documented as immutable was being revised in place by
 * the grade-correction observer and set_exam_context(), with no trace of the previous value. These
 * tests pin the replacement contract: a revision writes the new value to the row AND one history row
 * per changed column, unchanged values leave no trace, identity columns cannot be revised, and
 * erasure scrubs the certificate values out of the history without deleting the fact of the revision.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_completionhistory\local\ledger_service
 */
final class ledger_revision_test extends advanced_testcase {
    /**
     * Reset and enable the plugin for every test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enabled', 1, 'local_completionhistory');
    }

    /**
     * One achievement row through the plugin generator.
     *
     * @param array $overrides Column overrides.
     * @return \stdClass
     */
    private function achievement(array $overrides = []): \stdClass {
        return $this->getDataGenerator()
            ->get_plugin_generator('local_completionhistory')
            ->create_achievement($overrides);
    }

    /**
     * A revision updates the row and records the previous and new value of every changed column.
     */
    public function test_revision_updates_row_and_records_history(): void {
        global $DB;
        $ach = $this->achievement(['grade_decimal' => 72.5, 'grade_passed' => 0]);

        $changed = ledger_service::revise_achievement((int) $ach->id, [
            'grade_decimal' => 84.0,
            'grade_passed' => 1,
            'grade_source' => 'gradebook',
        ], ledger_service::REVISION_GRADE_CORRECTED, '\\core\\event\\user_graded');

        $row = $DB->get_record('local_completionhistory_achievement', ['id' => $ach->id], '*', MUST_EXIST);
        $this->assertEqualsWithDelta(84.0, (float) $row->grade_decimal, 0.00001);
        $this->assertSame(1, (int) $row->grade_passed);
        $this->assertSame('gradebook', $row->grade_source);

        $revisions = ledger_service::get_revisions((int) $ach->id);
        $byfield = array_column($revisions, null, 'fieldname');
        $this->assertEqualsWithDelta(72.5, (float) $byfield['grade_decimal']->oldvalue, 0.00001);
        $this->assertEqualsWithDelta(84.0, (float) $byfield['grade_decimal']->newvalue, 0.00001);
        $this->assertSame('0', $byfield['grade_passed']->oldvalue);
        $this->assertSame('1', $byfield['grade_passed']->newvalue);
        foreach ($revisions as $revision) {
            $this->assertSame(ledger_service::REVISION_GRADE_CORRECTED, $revision->reason);
            $this->assertSame('\\core\\event\\user_graded', $revision->source);
            $this->assertSame((int) $ach->id, (int) $revision->achievementid);
        }
        // The grade_source column may or may not have changed depending on the generator's default;
        // the count of history rows must equal the count of columns actually changed either way.
        $this->assertSame($changed, count($revisions));
        $this->assertGreaterThanOrEqual(2, $changed);
    }

    /**
     * Writing the values the row already holds is a no-op: no update, no history.
     *
     * The stored grade comes back from the database as a padded decimal string, so this is also the
     * test that numeric comparison is done as numbers.
     */
    public function test_unchanged_values_leave_no_trace(): void {
        global $DB;
        $ach = $this->achievement(['grade_decimal' => 84.0, 'grade_passed' => 1, 'grade_source' => 'gradebook']);

        $changed = ledger_service::revise_achievement((int) $ach->id, [
            'grade_decimal' => 84.0,
            'grade_passed' => 1,
            'grade_source' => 'gradebook',
        ], ledger_service::REVISION_GRADE_CORRECTED, 'test');

        $this->assertSame(0, $changed);
        $this->assertSame(0, $DB->count_records('local_completionhistory_ach_revision'));
    }

    /**
     * NULL and 0 are different facts for grade_passed and must be recorded as a change.
     */
    public function test_null_to_zero_is_a_change(): void {
        $ach = $this->achievement(['grade_passed' => null]);

        $changed = ledger_service::revise_achievement(
            (int) $ach->id,
            ['grade_passed' => 0],
            ledger_service::REVISION_GRADE_CORRECTED,
            'test'
        );

        $this->assertSame(1, $changed);
        $revision = ledger_service::get_revisions((int) $ach->id)[0];
        $this->assertNull($revision->oldvalue);
        $this->assertSame('0', $revision->newvalue);
    }

    /**
     * The identity of a completion is never revisable.
     */
    public function test_identity_columns_cannot_be_revised(): void {
        $ach = $this->achievement();

        $this->expectException(\coding_exception::class);
        ledger_service::revise_achievement((int) $ach->id, ['completiontime' => time()], 'tampering', 'test');
    }

    /**
     * set_exam_context is a recorded revision, not three silent writes.
     */
    public function test_set_exam_context_is_recorded(): void {
        global $DB;
        $ach = $this->achievement(['exam_track' => null, 'attempts_used' => null, 'attempts_allowed' => null]);

        $changed = ledger_service::set_exam_context((int) $ach->id, 'program_final', 2, 3, 'cli_backfill_exam_attempts');

        $this->assertSame(3, $changed);
        $row = $DB->get_record('local_completionhistory_achievement', ['id' => $ach->id], '*', MUST_EXIST);
        $this->assertSame('program_final', $row->exam_track);
        $this->assertSame(2, (int) $row->attempts_used);
        $this->assertSame(3, (int) $row->attempts_allowed);
        foreach (ledger_service::get_revisions((int) $ach->id) as $revision) {
            $this->assertSame(ledger_service::REVISION_EXAM_CONTEXT, $revision->reason);
            $this->assertSame('cli_backfill_exam_attempts', $revision->source);
            $this->assertNull($revision->oldvalue);
        }
    }

    /**
     * Erasure keeps the fact that a certificate was attached but scrubs its user-specific values,
     * and leaves the grade history intact — grades are institutional records.
     */
    public function test_anonymization_scrubs_certificate_values_from_history(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $ach = $this->achievement(['userid' => $user->id, 'grade_decimal' => 70.0]);

        ledger_service::revise_achievement((int) $ach->id, [
            'artifacturl' => 'https://example.com/cert/ABC123',
            'artifactstorage' => 'tool_certificate:ABC123',
        ], ledger_service::REVISION_CERTIFICATE_ATTACHED, 'test');
        ledger_service::revise_achievement(
            (int) $ach->id,
            ['grade_decimal' => 75.0],
            ledger_service::REVISION_GRADE_CORRECTED,
            'test'
        );

        ledger_service::anonymize_users([$user->id]);

        $revisions = ledger_service::get_revisions((int) $ach->id);
        $this->assertCount(3, $revisions, 'Erasure must not delete the history.');
        foreach ($revisions as $revision) {
            if (in_array($revision->fieldname, ['artifacturl', 'artifactstorage'], true)) {
                $this->assertNull($revision->oldvalue);
                $this->assertNull($revision->newvalue);
            } else {
                $this->assertEqualsWithDelta(70.0, (float) $revision->oldvalue, 0.00001);
                $this->assertEqualsWithDelta(75.0, (float) $revision->newvalue, 0.00001);
            }
        }
        $this->assertSame(0, (int) $DB->get_field('local_completionhistory_achievement', 'userid', ['id' => $ach->id]));
    }
}
