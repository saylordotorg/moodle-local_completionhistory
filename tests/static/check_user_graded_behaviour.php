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

// ---------------------------------------------------------------------------
// Collaborators, pre-defined so the real callbacks.php resolves to these rather
// than reaching for an autoloader that is not present.
// ---------------------------------------------------------------------------
namespace local_completionhistory\local {

    class grade_snapshot_service {
        /** @var mixed What get_course_total should return for the case being run. */
        public static $total = null;
        public static function get_course_total(int $userid, int $courseid) {
            return self::$total;
        }
    }

    class outbox_service {
        /** @var array Records handed to enqueue_achievement. */
        public static $enqueued = [];
        /** @var int Next id to return; 0 emulates `enableoutbox` being off. */
        public static $nextid = 1;
        public static function enqueue_achievement(\stdClass $achievement): int {
            self::$enqueued[] = clone $achievement;
            return self::$nextid;
        }
    }
}

// The event class itself, so the observer's own type declaration is exercised rather than bypassed.
namespace core\event {

    class user_graded {
        public $relateduserid;
        public $courseid;
        public $other = [];
    }
}

namespace {

    define('MOODLE_INTERNAL', true);
    define('DEBUG_DEVELOPER', 15);

    $root = dirname(__DIR__, 2);

    /** Plugin settings for the case being run. */
    $CFGSTUB = ['enabled' => 1, 'capturegrades' => 1];

    function get_config($plugin, $name) {
        global $CFGSTUB;
        return $CFGSTUB[$name] ?? false;
    }

    /** Captured so a case can assert the observer complained rather than going quiet. */
    $DEBUGGING = [];

    function debugging($message, $level = null) {
        global $DEBUGGING;
        $DEBUGGING[] = $message;
        return true;
    }

    /** Records what the transaction was told to do, so a rollback cannot pass as a commit. */
    class fake_transaction {
        public $committed = false;
        public $rolledback = false;
        public function allow_commit() { $this->committed = true; }
        public function rollback($e) { $this->rolledback = true; throw $e; }
    }

    /**
     * A mutable $DB: a keyed store that reads and writes behave against, so a concurrent change is
     * expressible. Strict about tables — an unexpected one is recorded rather than silently
     * returning false, because a false sends the observer down an early return and would let a
     * broken one look like a correctly-skipping one.
     */
    class fake_db {
        public $gradeitems = [];      // id => row
        public $rows = [];            // achievement id => full row
        public $updates = [];         // objects passed to update_record
        public $transaction;
        public $unexpected = [];
        /** @var callable|null Runs when the transaction opens: a competing commit. */
        public $on_transaction = null;

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

        public function get_records_select($table, $select, array $params, $sort = '', $fields = '*',
                                          $offset = 0, $limit = 0) {
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
                    foreach (get_object_vars($copy) as $k => $_) {
                        if (!in_array($k, $keep, true)) {
                            unset($copy->$k);
                        }
                    }
                }
                $out[$copy->id ?? $i] = $copy;
            }
            return $out;
        }

        /** Applies only the properties present, exactly as a real UPDATE of those columns would. */
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

        public function start_delegated_transaction() {
            if ($this->on_transaction) {
                ($this->on_transaction)($this);
            }
            $this->transaction = new fake_transaction();
            return $this->transaction;
        }
    }

    require $root . '/classes/callbacks.php';

    use local_completionhistory\callbacks;
    use local_completionhistory\local\grade_snapshot_service;
    use local_completionhistory\local\outbox_service;

    $failures = [];
    $passes   = 0;

    /**
     * A complete achievement row, as the table defines it.
     *
     * Numeric columns hold the PADDED DECIMAL STRINGS a real read returns, not floats — the
     * distinction that hid a P1. grade_decimal is number(10,5).
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

    /** The gradebook total, shaped as grade_snapshot_service returns it (floats). */
    function total(float $grade = 88.5, ?int $passed = 1): \stdClass {
        return (object) ['finalgrade' => $grade, 'grademax' => 100.0, 'gradepass' => 70.0,
                         'passed' => $passed];
    }

    /** Run one case against the real observer. Returns the fake $DB. */
    function run_case(array $opts): \fake_db {
        global $DB, $CFGSTUB, $DEBUGGING;

        $CFGSTUB = $opts['config'] ?? ['enabled' => 1, 'capturegrades' => 1];
        $DEBUGGING = [];

        $DB = new \fake_db();
        $DB->gradeitems = $opts['gradeitems'] ?? [
            900 => (object) ['id' => 900, 'courseid' => 7, 'itemtype' => 'course'],
        ];
        foreach ($opts['achievements'] ?? [achievement_row()] as $r) {
            $DB->rows[(int) $r->id] = $r;
        }
        $DB->on_transaction = $opts['on_transaction'] ?? null;

        grade_snapshot_service::$total = array_key_exists('total', $opts) ? $opts['total'] : total();
        outbox_service::$enqueued = [];
        outbox_service::$nextid = $opts['outboxid'] ?? 1;

        $e = new \core\event\user_graded();
        $e->relateduserid = $opts['userid'] ?? 42;
        $e->courseid      = $opts['courseid'] ?? 7;
        $e->other         = $opts['other'] ?? ['itemid' => 900];

        callbacks::user_graded($e);
        return $DB;
    }

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
    check('a changed course total updates the ledger row', count($db->updates) === 1,
        count($db->updates) . ' updates');
    check('and enqueues exactly one outbox row', count(outbox_service::$enqueued) === 1,
        count(outbox_service::$enqueued) . ' enqueued');
    check('the stored grade is now the new one', (float) $db->rows[500]->grade_decimal === 88.5,
        'got ' . var_export($db->rows[500]->grade_decimal, true));
    check('the stored pass flag is now the new one', (int) $db->rows[500]->grade_passed === 1);
    check('grade_source records where the figure came from',
        $db->rows[500]->grade_source === 'gradebook');
    check('the transaction commits', ($db->transaction->committed ?? false) === true);
    check('the row is revised, not duplicated — one row still', count($db->rows) === 1);

    echo "\n  the UPDATE touches only the grade (PR #8 review)\n";
    // The guard against a full-row write: whatever else is true, update_record must not be handed
    // columns it has no business restoring.
    $written = array_keys(get_object_vars($db->updates[0]));
    sort($written);
    check('exactly id + the three grade columns are written',
        $written === ['grade_decimal', 'grade_passed', 'grade_source', 'id'],
        'wrote: ' . implode(', ', $written));
    foreach (['ledgeruuid', 'userid', 'firstname_snapshot', 'lastname_snapshot', 'email_snapshot',
              'useridnumber_snapshot', 'artifacturl', 'artifactstorage', 'source_event_hash',
              'timecreated'] as $f) {
        check("does not write {$f}", !property_exists($db->updates[0], $f));
    }
    check('source_event_hash still holds the completion it came from, so the backfill cannot '
        . 'insert a duplicate',
        $db->rows[500]->source_event_hash === 'hash-of-the-completion');
    check('timecreated is preserved, so the row keeps its identity',
        (int) $db->rows[500]->timecreated === 1750000001);

    echo "\n  the enqueued record describes what is now stored\n";
    $enq = outbox_service::$enqueued[0] ?? null;
    check('carries ledgeruuid — the key the SIS matches on',
        ($enq->ledgeruuid ?? '') === 'b1f0c0de-0000-4000-8000-000000000abc',
        'got ' . var_export($enq->ledgeruuid ?? null, true));
    check('carries the CORRECTED grade, not the old one', (float) ($enq->grade_decimal ?? -1) === 88.5,
        'got ' . var_export($enq->grade_decimal ?? null, true));
    foreach ([
        'useridnumber_snapshot'    => 'SU-2026-01149',
        'firstname_snapshot'       => 'Ada',
        'lastname_snapshot'        => 'Lovelace',
        'email_snapshot'           => 'ada@example.invalid',
        'courseidnumber_snapshot'  => 'MBA510',
        'coursename_snapshot'      => 'Managerial Economics',
        'completiontime'           => 1750000000,
        'exam_track'               => 'proctored',
        'artifactstorage'          => 'certificate:ABC123',
    ] as $field => $want) {
        check("still carries {$field}", ($enq->$field ?? null) == $want,
            'got ' . var_export($enq->$field ?? null, true));
    }

    echo "\n  idempotence across the string/float boundary (PR #8 review)\n";
    // THE P1. A real read gives '88.50000'; the snapshot gives the float 88.5. Compared as strings
    // these differ, and the guard inverts into a write-and-enqueue on every recalculation.
    $db = run_case(['achievements' => [achievement_row(['grade_decimal' => '88.50000',
                                                        'grade_passed' => '1'])]]);
    check("'88.50000' equals the float 88.5 — no write",
        $db->updates === [] && outbox_service::$enqueued === [],
        count($db->updates) . ' updates, ' . count(outbox_service::$enqueued) . ' enqueued');

    $db = run_case(['achievements' => [achievement_row(['grade_decimal' => '100.00000',
                                                        'grade_passed' => '1'])],
                    'total' => total(100.0, 1)]);
    check("'100.00000' equals the float 100.0 — no write", $db->updates === []);

    $db = run_case(['achievements' => [achievement_row(['grade_decimal' => '88.50000',
                                                        'grade_passed' => '1'])],
                    'total' => total(88.500001, 1)]);
    check('a change below the column\'s own precision is not a change', $db->updates === [],
        'wrote for a 1e-6 delta the column cannot store');

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
    // anonymize_users() sets userid = 0 and NULLs the identity snapshots. A full-row update_record
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
    check('an anonymized row is left alone entirely', $db->updates === [],
        count($db->updates) . ' updates');
    check('and nothing is enqueued', outbox_service::$enqueued === []);
    check('the deleted student\'s name is NOT restored', $db->rows[500]->firstname_snapshot === null,
        'got ' . var_export($db->rows[500]->firstname_snapshot, true));
    check('their email is NOT restored', $db->rows[500]->email_snapshot === null);
    check('their id number is NOT restored', $db->rows[500]->useridnumber_snapshot === null);
    check('userid stays anonymized', (int) $db->rows[500]->userid === 0);
    check('no PII is published', !array_filter(outbox_service::$enqueued,
        static fn($e) => ($e->email_snapshot ?? null) !== null));

    $db = run_case(['on_transaction' => function (\fake_db $db) { unset($db->rows[500]); }]);
    check('a row purged in the window is handled without a write',
        $db->updates === [] && outbox_service::$enqueued === []);
    check('and without throwing out of the observer',
        ($db->transaction->committed ?? false) === true);

    $db = run_case(['on_transaction' => function (\fake_db $db) {
        $db->rows[500]->grade_decimal = '88.50000';
        $db->rows[500]->grade_passed = '1';
    }]);
    check('a correction another event already applied is not applied twice',
        $db->updates === [] && outbox_service::$enqueued === [],
        count($db->updates) . ' updates, ' . count(outbox_service::$enqueued) . ' enqueued');

    echo "\n  when the outbox is off (the shipped default)\n";
    $db = run_case(['outboxid' => 0]);
    check('the ledger is still corrected — the record is right regardless of transport',
        (float) $db->rows[500]->grade_decimal === 88.5);
    check('and it says so out loud rather than dropping the correction silently',
        count($GLOBALS['DEBUGGING']) === 1, count($GLOBALS['DEBUGGING']) . ' messages');
    check('the warning names the setting to change',
        str_contains($GLOBALS['DEBUGGING'][0] ?? '', 'enableoutbox'),
        $GLOBALS['DEBUGGING'][0] ?? '(none)');
    $db = run_case([]);
    check('and stays quiet when the outbox worked', $GLOBALS['DEBUGGING'] === []);

    echo "\n  only the course total\n";
    $db = run_case(['gradeitems' => [
        900 => (object) ['id' => 900, 'courseid' => 7, 'itemtype' => 'mod'],
    ]]);
    check('an activity grade changes nothing',
        $db->updates === [] && outbox_service::$enqueued === [],
        count($db->updates) . ' updates, ' . count(outbox_service::$enqueued) . ' enqueued');

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
    check('no ledger row means nothing to correct',
        $db->updates === [] && outbox_service::$enqueued === []);

    echo "\n  a cleared total is left alone\n";
    $db = run_case(['total' => null]);
    check('a null course total does not erase a grade already awarded',
        $db->updates === [] && outbox_service::$enqueued === []);

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
    check('the most recent record is the one corrected', ($db->updates[0]->id ?? null) === 501,
        'corrected id ' . var_export($db->updates[0]->id ?? null, true));
    check('and the older one is untouched', (string) $db->rows[500]->grade_decimal === '72.00000');

    echo "\n  hygiene\n";
    check('no unexpected tables were touched', $db->unexpected === [],
        implode(', ', $db->unexpected));

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
