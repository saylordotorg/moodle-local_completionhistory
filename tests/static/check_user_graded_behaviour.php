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

/**
 * Behavioural check for callbacks::user_graded — the observer that carries a grade corrected after
 * completion through to the SIS.
 *
 * WHY NOT PHPUNIT. This belongs in tests/observer_test.php and would live there if the suite could
 * be run. It cannot: PHPUnit needs an initialised Moodle test database, and neither this checkout
 * nor the dev host has one (no phpunit.xml, no phpunit_dataroot in config.php). A test nobody can
 * execute is a claim, not coverage. So this harness pre-defines the collaborators and a fake $DB in
 * the process, then loads the real classes/callbacks.php and calls the real method — the logic under
 * test is the shipped code, not a copy of it.
 *
 * TWO DEFECTS GOT PAST THE FIRST VERSION OF THIS FILE, both found in review on PR #8, and both
 * because of what the FIXTURE looked like rather than what the assertions said:
 *
 *   1. It stored `grade_decimal` as a PHP float. A real read of a `number(10,5)` column returns a
 *      padded decimal STRING — '88.50000' — which never string-compared equal to the float-derived
 *      '88.5', so the idempotence guard inverted and every regrade event queued another sync. The
 *      store below now holds the strings a database actually returns.
 *   2. Its $DB was write-only: nothing could change underneath the observer. That made the
 *      read-before-transaction / write-inside-transaction race invisible, so a full-row
 *      `update_record` silently reverting `anonymize_users()` — restoring a deleted student's name
 *      and email, and publishing them — looked perfectly healthy. The store is now mutable and
 *      `on_transaction` lets a case commit a competing change at exactly the wrong moment.
 *
 * Usage:  php tests/static/check_user_graded_behaviour.php
 * Exit:   0 = all cases pass, 1 = at least one failed.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses, Generic.Classes.DuplicateClassName.Found -- standalone harness pre-defines fake collaborators; see header.
// phpcs:disable moodle.Files.MoodleInternal.MoodleInternalGlobalState -- standalone check, no Moodle bootstrap (see header).

// ---------------------------------------------------------------------------
// Collaborators, pre-defined so the real callbacks.php resolves to these rather
// than reaching for an autoloader that is not present.
namespace local_completionhistory\local {

    /**
     * Fake grade_snapshot_service: returns whatever total the case under test has set.
     *
     * @package    local_completionhistory
     * @copyright  2026 Saylor Academy
     * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
     */
    class grade_snapshot_service {
        /** @var mixed What get_course_total should return for the case being run. */
        public static $total = null;

        /**
         * Return the preset course total, ignoring who and which course is asked about.
         *
         * @param int $userid The user (ignored).
         * @param int $courseid The course (ignored).
         * @return mixed The preset total.
         */
        public static function get_course_total(int $userid, int $courseid) {
            return self::$total;
        }
    }

    /**
     * Fake outbox_service: records what the observer enqueues and returns a preset id.
     *
     * @package    local_completionhistory
     * @copyright  2026 Saylor Academy
     * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
     */
    class outbox_service {
        /** @var array Records handed to enqueue_achievement. */
        public static $enqueued = [];
        /** @var int Next id to return; 0 emulates `enableoutbox` being off. */
        public static $nextid = 1;

        /**
         * Record the achievement handed over and return the preset outbox id.
         *
         * @param \stdClass $achievement The record the observer wants published.
         * @return int The preset id; 0 means the outbox is off.
         */
        public static function enqueue_achievement(\stdClass $achievement): int {
            self::$enqueued[] = clone $achievement;
            return self::$nextid;
        }
    }

    /**
     * Fake ledger_service: the correction-history entry point the observer writes through.
     *
     * Applies the change to the fake $DB exactly as the real one does — an update_record carrying the
     * id plus the changed columns — so the "only the grade is written" assertions below still see the
     * write; and records what it was asked to record, so a case can assert the previous value is kept.
     *
     * @package    local_completionhistory
     * @copyright  2026 Saylor Academy
     * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
     */
    class ledger_service {
        /** @var string Mirrors the real constant the observer passes. */
        public const REVISION_GRADE_CORRECTED = 'grade_corrected';

        /** @var array Every revision requested: achievementid, changes, old values, reason, source. */
        public static $revisions = [];

        /**
         * Apply the changed columns to the row and record the revision.
         *
         * @param int $achievementid The row to revise.
         * @param array $changes Column => new value.
         * @param string $reason Why.
         * @param string $source What triggered it.
         * @return int Number of columns changed.
         */
        public static function revise_achievement(int $achievementid, array $changes, string $reason, string $source): int {
            global $DB;
            $current = $DB->rows[$achievementid] ?? null;
            $old = [];
            foreach (array_keys($changes) as $field) {
                $old[$field] = $current->$field ?? null;
            }
            self::$revisions[] = [
                'achievementid' => $achievementid,
                'changes' => $changes,
                'old' => $old,
                'reason' => $reason,
                'source' => $source,
            ];
            $DB->update_record('local_completionhistory_achievement', (object) (['id' => $achievementid] + $changes));
            return count($changes);
        }
    }
}

// The event class itself, so the observer's own type declaration is exercised rather than bypassed.
namespace core\event {

    /**
     * Fake user_graded event carrying only the properties the observer reads.
     *
     * @package    local_completionhistory
     * @copyright  2026 Saylor Academy
     * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
     */
    class user_graded {
        /** @var int|null The graded user. */
        public $relateduserid;
        /** @var int|null The course the grade belongs to. */
        public $courseid;
        /** @var array Event payload; the observer reads other['itemid']. */
        public $other = [];
    }
}

namespace {

    define('MOODLE_INTERNAL', true);
    define('DEBUG_DEVELOPER', 15);

    $root = dirname(__DIR__, 2);

    // Plugin settings for the case being run.
    $cfgstub = ['enabled' => 1, 'capturegrades' => 1];

    /**
     * Fake get_config: answers from the per-case settings stub.
     *
     * @param string $plugin The component (ignored; only this plugin's settings are stubbed).
     * @param string $name The setting name.
     * @return mixed The stubbed value, or false when unset.
     */
    function get_config($plugin, $name) {
        global $cfgstub;
        return $cfgstub[$name] ?? false;
    }

    // Captured so a case can assert the observer complained rather than going quiet.
    $debugging = [];

    /**
     * Fake debugging(): captures the message instead of printing it.
     *
     * @param string $message The debugging message.
     * @param int|null $level The debug level (ignored).
     * @return bool Always true, as the real function returns when it emits.
     */
    function debugging($message, $level = null) {
        global $debugging;
        $debugging[] = $message;
        return true;
    }

    /**
     * Records what the transaction was told to do, so a rollback cannot pass as a commit.
     *
     * @package    local_completionhistory
     * @copyright  2026 Saylor Academy
     * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
     */
    class fake_transaction {
        /** @var bool Whether allow_commit() was called. */
        public $committed = false;
        /** @var bool Whether rollback() was called. */
        public $rolledback = false;

        /**
         * Mark the transaction committed.
         */
        public function allow_commit() {
            $this->committed = true;
        }

        /**
         * Mark the transaction rolled back and rethrow, as the real one does.
         *
         * @param \Throwable $e The exception that caused the rollback.
         */
        public function rollback($e) {
            $this->rolledback = true;
            throw $e;
        }
    }

    /**
     * A mutable $DB: a keyed store that reads and writes behave against, so a concurrent change is
     * expressible. Strict about tables — an unexpected one is recorded rather than silently
     * returning false, because a false sends the observer down an early return and would let a
     * broken one look like a correctly-skipping one.
     *
     * @package    local_completionhistory
     * @copyright  2026 Saylor Academy
     * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
     */
    class fake_db {
        /** @var array Grade item rows, id => row. */
        public $gradeitems = [];
        /** @var array Achievement rows, achievement id => full row. */
        public $rows = [];
        /** @var array Objects passed to update_record, in order. */
        public $updates = [];
        /** @var fake_transaction|null The transaction handed out, if one was opened. */
        public $transaction;
        /** @var array Names of unexpected table reads and writes. */
        public $unexpected = [];
        /** @var callable|null Runs when the transaction opens: a competing commit. */
        public $ontransaction = null;

        /**
         * Fake get_record over the two tables the observer reads.
         *
         * @param string $table The table name.
         * @param array $conditions Only 'id' is honoured.
         * @param string $fields Ignored; whole rows are returned.
         * @return \stdClass|false A clone of the row, or false.
         */
        public function get_record($table, array $conditions, $fields = '*') {
            if ($table === 'grade_items') {
                $row = $this->gradeitems[(int) $conditions['id']] ?? false;
                return $row ? clone $row : false;
            }
            if ($table === 'local_completionhistory_achievement') {
                $row = $this->rows[(int) $conditions['id']] ?? false;
                return $row ? clone $row : false;
            }
            $this->unexpected[] = "get_record({$table})";
            return false;
        }

        /**
         * Fake get_records_select over the achievement table, honouring sort, paging and field narrowing.
         *
         * @param string $table The table name.
         * @param string $select Ignored; the filter is taken from $params.
         * @param array $params Must carry 'userid' and 'courseid'.
         * @param string $sort Only 'completiontime DESC' is honoured.
         * @param string $fields Comma-separated columns to keep, or '*'.
         * @param int $offset Rows to skip when $limit is set.
         * @param int $limit Maximum rows; 0 for all.
         * @return array Rows keyed by id.
         */
        public function get_records_select(
            $table,
            $select,
            array $params,
            $sort = '',
            $fields = '*',
            $offset = 0,
            $limit = 0
        ) {
            if ($table !== 'local_completionhistory_achievement') {
                $this->unexpected[] = "get_records_select({$table})";
                return [];
            }
            $rows = array_values(array_filter($this->rows, static function ($a) use ($params) {
                return (int) $a->userid === (int) $params['userid']
                    && (int) $a->courseid === (int) $params['courseid'];
            }));
            // Honour the sort the caller asked for, so "newest completion wins" is genuinely
            // exercised rather than accidentally satisfied by fixture order.
            if (str_starts_with((string) $sort, 'completiontime DESC')) {
                usort($rows, static function ($x, $y) {
                    return [$y->completiontime, $y->id] <=> [$x->completiontime, $x->id];
                });
            }
            if ($limit > 0) {
                $rows = array_slice($rows, $offset, $limit);
            }
            // Mimic the real column narrowing, so a select that omits something the caller then
            // reads cannot pass here while failing in production.
            $out = [];
            foreach ($rows as $i => $r) {
                $copy = clone $r;
                if ($fields !== '*') {
                    $keep = array_map('trim', explode(',', $fields));
                    foreach (get_object_vars($copy) as $k => $unused) {
                        if (!in_array($k, $keep, true)) {
                            unset($copy->$k);
                        }
                    }
                }
                $out[$copy->id ?? $i] = $copy;
            }
            return $out;
        }

        /**
         * Applies only the properties present, exactly as a real UPDATE of those columns would.
         *
         * @param string $table The table name.
         * @param \stdClass $row The columns to write; must carry id.
         * @return bool True when the table is the achievement table.
         */
        public function update_record($table, $row) {
            if ($table !== 'local_completionhistory_achievement') {
                $this->unexpected[] = "update_record({$table})";
                return false;
            }
            $this->updates[] = clone $row;
            $id = (int) $row->id;
            if (isset($this->rows[$id])) {
                foreach (get_object_vars($row) as $k => $v) {
                    $this->rows[$id]->$k = $v;
                }
            }
            return true;
        }

        /**
         * Open a fake transaction, first running any competing change the case has registered.
         *
         * @return fake_transaction The transaction handed to the observer.
         */
        public function start_delegated_transaction() {
            if ($this->ontransaction) {
                ($this->ontransaction)($this);
            }
            $this->transaction = new fake_transaction();
            return $this->transaction;
        }
    }

    require($root . '/classes/callbacks.php');

    use local_completionhistory\callbacks;
    use local_completionhistory\local\grade_snapshot_service;
    use local_completionhistory\local\ledger_service;
    use local_completionhistory\local\outbox_service;

    $failures = [];
    $passes   = 0;

    /**
     * A complete achievement row, as the table defines it.
     *
     * Numeric columns hold the PADDED DECIMAL STRINGS a real read returns, not floats — the
     * distinction that hid a P1. grade_decimal is number(10,5).
     *
     * @param array $over Column values overriding the defaults.
     * @return \stdClass The row.
     */
    function achievement_row(array $over = []): \stdClass {
        $r = (object) [
            'id'                       => 500,
            'ledgeruuid'               => 'b1f0c0de-0000-4000-8000-000000000abc',
            'userid'                   => 42,
            'useridnumber_snapshot'    => 'SU-2026-01149',
            'firstname_snapshot'       => 'Ada',
            'lastname_snapshot'        => 'Lovelace',
            'email_snapshot'           => 'ada@example.invalid',
            'courseid'                 => 7,
            'courseidnumber_snapshot'  => 'MBA510',
            'courseshortname_snapshot' => 'MBA510',
            'coursename_snapshot'      => 'Managerial Economics',
            'completiontime'           => 1750000000,
            'enrolledtime_snapshot'    => 1740000000,
            'grade_decimal'            => '72.00000',
            'grade_passed'             => '0',
            'grade_source'             => 'gradebook',
            'exam_track'               => 'proctored',
            'attempts_used'            => 1,
            'attempts_allowed'         => 3,
            'artifacturl'              => 'https://example.invalid/cert/abc',
            'artifactstorage'          => 'certificate:ABC123',
            'source_component'         => 'local_completionhistory',
            'source_event'             => 'course_completed',
            'source_event_hash'        => 'hash-of-the-completion',
            'timecreated'              => 1750000001,
        ];
        foreach ($over as $k => $v) {
            $r->$k = $v;
        }
        return $r;
    }

    /**
     * The gradebook total, shaped as grade_snapshot_service returns it (floats).
     *
     * @param float $grade The final grade out of 100.
     * @param int|null $passed The pass flag, or null when the course has no pass grade.
     * @return \stdClass The total.
     */
    function total(float $grade = 88.5, ?int $passed = 1): \stdClass {
        return (object) ['finalgrade' => $grade, 'grademax' => 100.0, 'gradepass' => 70.0,
                         'passed' => $passed];
    }

    /**
     * Run one case against the real observer.
     *
     * @param array $opts Case options: config, gradeitems, achievements, on_transaction, total,
     *                    outboxid, userid, courseid, other. Each falls back to a passing default.
     * @return \fake_db The fake $DB after the observer has run, for assertions.
     */
    function run_case(array $opts): \fake_db {
        global $DB, $cfgstub, $debugging;

        $cfgstub = $opts['config'] ?? ['enabled' => 1, 'capturegrades' => 1];
        $debugging = [];

        $DB = new \fake_db();
        $DB->gradeitems = $opts['gradeitems'] ?? [
            900 => (object) ['id' => 900, 'courseid' => 7, 'itemtype' => 'course'],
        ];
        foreach ($opts['achievements'] ?? [achievement_row()] as $r) {
            $DB->rows[(int) $r->id] = $r;
        }
        $DB->ontransaction = $opts['on_transaction'] ?? null;

        grade_snapshot_service::$total = array_key_exists('total', $opts) ? $opts['total'] : total();
        outbox_service::$enqueued = [];
        outbox_service::$nextid = $opts['outboxid'] ?? 1;
        ledger_service::$revisions = [];

        $e = new \core\event\user_graded();
        $e->relateduserid = $opts['userid'] ?? 42;
        $e->courseid      = $opts['courseid'] ?? 7;
        $e->other         = $opts['other'] ?? ['itemid' => 900];

        callbacks::user_graded($e);
        return $DB;
    }

    /**
     * Record and print one assertion.
     *
     * @param string $name What is being asserted.
     * @param bool $ok Whether it held.
     * @param string $detail Extra context printed on failure.
     */
    function check(string $name, bool $ok, string $detail = '') {
        global $failures, $passes;
        if ($ok) {
            $passes++;
            printf("  ok    %s\n", $name);
        } else {
            $failures[] = $name . ($detail !== '' ? " — {$detail}" : '');
            printf("  FAIL  %s%s\n", $name, $detail !== '' ? " — {$detail}" : '');
        }
    }

    echo "callbacks::user_graded\n\n  the correction itself\n";

    $db = run_case([]);
    check(
        'a changed course total updates the ledger row',
        count($db->updates) === 1,
        count($db->updates) . ' updates'
    );
    check(
        'and enqueues exactly one outbox row',
        count(outbox_service::$enqueued) === 1,
        count(outbox_service::$enqueued) . ' enqueued'
    );
    check(
        'the stored grade is now the new one',
        (float) $db->rows[500]->grade_decimal === 88.5,
        'got ' . var_export($db->rows[500]->grade_decimal, true)
    );
    check('the stored pass flag is now the new one', (int) $db->rows[500]->grade_passed === 1);
    check(
        'grade_source records where the figure came from',
        $db->rows[500]->grade_source === 'gradebook'
    );
    check('the transaction commits', ($db->transaction->committed ?? false) === true);
    check('the row is revised, not duplicated — one row still', count($db->rows) === 1);

    echo "\n  the correction goes through the ledger's history (Catalyst review)\n";
    $revision = ledger_service::$revisions[0] ?? null;
    check('exactly one revision is recorded', count(ledger_service::$revisions) === 1);
    check(
        'it is recorded as a grade correction triggered by user_graded',
        ($revision['reason'] ?? null) === ledger_service::REVISION_GRADE_CORRECTED
            && ($revision['source'] ?? null) === '\\core\\event\\user_graded',
        var_export($revision['reason'] ?? null, true) . ' / ' . var_export($revision['source'] ?? null, true)
    );
    check(
        'the previous grade is what the history is handed, so it stays recoverable',
        ($revision['old']['grade_decimal'] ?? null) === '72.00000',
        'old grade ' . var_export($revision['old']['grade_decimal'] ?? null, true)
    );

    echo "\n  the UPDATE touches only the grade (PR #8 review)\n";
    // The guard against a full-row write: whatever else is true, update_record must not be handed
    // columns it has no business restoring.
    $written = array_keys(get_object_vars($db->updates[0]));
    sort($written);
    check(
        'exactly id + the three grade columns are written',
        $written === ['grade_decimal', 'grade_passed', 'grade_source', 'id'],
        'wrote: ' . implode(', ', $written)
    );
    foreach (
        ['ledgeruuid', 'userid', 'firstname_snapshot', 'lastname_snapshot', 'email_snapshot',
              'useridnumber_snapshot', 'artifacturl', 'artifactstorage', 'source_event_hash',
              'timecreated'] as $f
    ) {
        check("does not write {$f}", !property_exists($db->updates[0], $f));
    }
    check(
        'source_event_hash still holds the completion it came from, so the backfill cannot '
        . 'insert a duplicate',
        $db->rows[500]->source_event_hash === 'hash-of-the-completion'
    );
    check(
        'timecreated is preserved, so the row keeps its identity',
        (int) $db->rows[500]->timecreated === 1750000001
    );

    echo "\n  the enqueued record describes what is now stored\n";
    $enq = outbox_service::$enqueued[0] ?? null;
    check(
        'carries ledgeruuid — the key the SIS matches on',
        ($enq->ledgeruuid ?? '') === 'b1f0c0de-0000-4000-8000-000000000abc',
        'got ' . var_export($enq->ledgeruuid ?? null, true)
    );
    check(
        'carries the CORRECTED grade, not the old one',
        (float) ($enq->grade_decimal ?? -1) === 88.5,
        'got ' . var_export($enq->grade_decimal ?? null, true)
    );
    foreach (
        [
        'useridnumber_snapshot'    => 'SU-2026-01149',
        'firstname_snapshot'       => 'Ada',
        'lastname_snapshot'        => 'Lovelace',
        'email_snapshot'           => 'ada@example.invalid',
        'courseidnumber_snapshot'  => 'MBA510',
        'coursename_snapshot'      => 'Managerial Economics',
        'completiontime'           => 1750000000,
        'exam_track'               => 'proctored',
        'artifactstorage'          => 'certificate:ABC123',
        ] as $field => $want
    ) {
        check(
            "still carries {$field}",
            ($enq->$field ?? null) == $want,
            'got ' . var_export($enq->$field ?? null, true)
        );
    }

    echo "\n  idempotence across the string/float boundary (PR #8 review)\n";
    // THE P1. A real read gives '88.50000'; the snapshot gives the float 88.5. Compared as strings
    // these differ, and the guard inverts into a write-and-enqueue on every recalculation.
    $db = run_case(['achievements' => [achievement_row(['grade_decimal' => '88.50000',
                                                        'grade_passed' => '1'])]]);
    check(
        "'88.50000' equals the float 88.5 — no write",
        $db->updates === [] && outbox_service::$enqueued === [],
        count($db->updates) . ' updates, ' . count(outbox_service::$enqueued) . ' enqueued'
    );

    $db = run_case(['achievements' => [achievement_row(['grade_decimal' => '100.00000',
                                                        'grade_passed' => '1'])],
                    'total' => total(100.0, 1)]);
    check("'100.00000' equals the float 100.0 — no write", $db->updates === []);

    $db = run_case(['achievements' => [achievement_row(['grade_decimal' => '88.50000',
                                                        'grade_passed' => '1'])],
                    'total' => total(88.500001, 1)]);
    check(
        'a change below the column\'s own precision is not a change',
        $db->updates === [],
        'wrote for a 1e-6 delta the column cannot store'
    );

    $db = run_case(['achievements' => [achievement_row(['grade_decimal' => '88.50000',
                                                        'grade_passed' => '1'])],
                    'total' => total(88.51, 1)]);
    check('a change the column CAN store is a change', count($db->updates) === 1);

    $db = run_case(['achievements' => [achievement_row(['grade_decimal' => null,
                                                        'grade_passed' => null])]]);
    check('a never-captured grade is a change', count($db->updates) === 1);

    // NULL (never captured) must stay distinct from 0 (captured as a fail); the old string cast
    // flattened both to ''.
    $db = run_case(['achievements' => [achievement_row(['grade_decimal' => '88.50000',
                                                        'grade_passed' => null])],
                    'total' => total(88.5, 0)]);
    check('pass flag NULL -> 0 is a change', count($db->updates) === 1);

    $db = run_case(['achievements' => [achievement_row(['grade_decimal' => '88.50000',
                                                        'grade_passed' => '0'])],
                    'total' => total(88.5, 1)]);
    check('pass flag flipping alone is a change', count($db->updates) === 1);

    echo "\n  a competing commit between the decision and the write (PR #8 review)\n";
    // The anonymize_users() call sets userid = 0 and NULLs the identity snapshots. A full-row update_record
    // built from the pre-transaction read would restore all of it AND publish it.
    $db = run_case(['on_transaction' => function (\fake_db $db) {
        $r = $db->rows[500];
        $r->userid = 0;
        $r->useridnumber_snapshot = null;
        $r->firstname_snapshot = null;
        $r->lastname_snapshot = null;
        $r->email_snapshot = null;
        $r->artifacturl = null;
        $r->artifactstorage = null;
    }]);
    check(
        'an anonymized row is left alone entirely',
        $db->updates === [],
        count($db->updates) . ' updates'
    );
    check('and nothing is enqueued', outbox_service::$enqueued === []);
    check(
        'the deleted student\'s name is NOT restored',
        $db->rows[500]->firstname_snapshot === null,
        'got ' . var_export($db->rows[500]->firstname_snapshot, true)
    );
    check('their email is NOT restored', $db->rows[500]->email_snapshot === null);
    check('their id number is NOT restored', $db->rows[500]->useridnumber_snapshot === null);
    check('userid stays anonymized', (int) $db->rows[500]->userid === 0);
    check('no PII is published', !array_filter(
        outbox_service::$enqueued,
        static fn($e) => ($e->email_snapshot ?? null) !== null
    ));

    $db = run_case(['on_transaction' => function (\fake_db $db) {
        unset($db->rows[500]);
    }]);
    check(
        'a row purged in the window is handled without a write',
        $db->updates === [] && outbox_service::$enqueued === []
    );
    check(
        'and without throwing out of the observer',
        ($db->transaction->committed ?? false) === true
    );

    $db = run_case(['on_transaction' => function (\fake_db $db) {
        $db->rows[500]->grade_decimal = '88.50000';
        $db->rows[500]->grade_passed = '1';
    }]);
    check(
        'a correction another event already applied is not applied twice',
        $db->updates === [] && outbox_service::$enqueued === [],
        count($db->updates) . ' updates, ' . count(outbox_service::$enqueued) . ' enqueued'
    );

    echo "\n  when the outbox is off (the shipped default)\n";
    $db = run_case(['outboxid' => 0]);
    check(
        'the ledger is still corrected — the record is right regardless of transport',
        (float) $db->rows[500]->grade_decimal === 88.5
    );
    check(
        'and it says so out loud rather than dropping the correction silently',
        count($GLOBALS['debugging']) === 1,
        count($GLOBALS['debugging']) . ' messages'
    );
    check(
        'the warning names the setting to change',
        str_contains($GLOBALS['debugging'][0] ?? '', 'enableoutbox'),
        $GLOBALS['debugging'][0] ?? '(none)'
    );
    $db = run_case([]);
    check('and stays quiet when the outbox worked', $GLOBALS['debugging'] === []);

    echo "\n  only the course total\n";
    $db = run_case(['gradeitems' => [
        900 => (object) ['id' => 900, 'courseid' => 7, 'itemtype' => 'mod'],
    ]]);
    check(
        'an activity grade changes nothing',
        $db->updates === [] && outbox_service::$enqueued === [],
        count($db->updates) . ' updates, ' . count(outbox_service::$enqueued) . ' enqueued'
    );

    $db = run_case(['gradeitems' => [
        900 => (object) ['id' => 900, 'courseid' => 99, 'itemtype' => 'course'],
    ]]);
    check('a course total belonging to another course changes nothing', $db->updates === []);

    $db = run_case(['other' => []]);
    check('an event with no itemid changes nothing', $db->updates === []);

    $db = run_case(['gradeitems' => []]);
    check('an itemid that no longer exists changes nothing', $db->updates === []);

    echo "\n  only an already-ledgered completion\n";
    $db = run_case(['achievements' => []]);
    check(
        'no ledger row means nothing to correct',
        $db->updates === [] && outbox_service::$enqueued === []
    );

    echo "\n  a cleared total is left alone\n";
    $db = run_case(['total' => null]);
    check(
        'a null course total does not erase a grade already awarded',
        $db->updates === [] && outbox_service::$enqueued === []
    );

    echo "\n  the switches\n";
    $db = run_case(['config' => ['enabled' => 0, 'capturegrades' => 1]]);
    check('disabled plugin does nothing', $db->updates === []);
    $db = run_case(['config' => ['enabled' => 1, 'capturegrades' => 0]]);
    check('grade capture off does nothing — there is no grade to correct', $db->updates === []);

    echo "\n  a course completed more than once\n";
    $db = run_case(['achievements' => [
        achievement_row(['id' => 500, 'completiontime' => 1700000000, 'ledgeruuid' => 'older']),
        achievement_row(['id' => 501, 'completiontime' => 1750000000, 'ledgeruuid' => 'newer']),
    ]]);
    check(
        'the most recent record is the one corrected',
        ($db->updates[0]->id ?? null) === 501,
        'corrected id ' . var_export($db->updates[0]->id ?? null, true)
    );
    check('and the older one is untouched', (string) $db->rows[500]->grade_decimal === '72.00000');

    echo "\n  hygiene\n";
    check(
        'no unexpected tables were touched',
        $db->unexpected === [],
        implode(', ', $db->unexpected)
    );

    printf("\n%d passed, %d failed\n", $passes, count($failures));
    if ($failures) {
        echo "\nFAIL:\n";
        foreach ($failures as $f) {
            echo "  - {$f}\n";
        }
        exit(1);
    }
    echo "OK\n";
    exit(0);
}
