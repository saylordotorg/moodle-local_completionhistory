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
 * execute is a claim, not coverage. So this harness pre-defines the two services and a fake $DB in
 * the process, then loads the real classes/callbacks.php and calls the real method — the logic under
 * test is the shipped code, not a copy of it.
 *
 * WHAT IT IS GUARDING. Every branch here is a way this observer can be wrong while looking healthy:
 *
 *  - Acting on activity grades would enqueue a sync per marked quiz question, and could overwrite
 *    the course figure with an activity's.
 *  - Writing unconditionally would re-send an identical grade on every gradebook recalculation;
 *    Moodle re-fires user_graded freely, including for every enrolled user when one page is saved.
 *  - Selecting only the columns this method writes would publish a correction with an empty
 *    ledgeruuid — the key the SIS matches on — and blank the student's name, email and course
 *    idnumber, because build_achievement_payload reads those straight off the record it is handed
 *    and coalesces anything absent to '' rather than loading it.
 *  - Rewriting source_event_hash would let backfill_service later insert a duplicate row for the
 *    same completion.
 *
 * Usage:  php tests/static/check_user_graded_behaviour.php
 * Exit:   0 = all cases pass, 1 = at least one failed.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// ---------------------------------------------------------------------------
// The two services, pre-defined so the real callbacks.php resolves to these
// rather than reaching for an autoloader that is not present.
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
        public static function enqueue_achievement(\stdClass $achievement): int {
            self::$enqueued[] = clone $achievement;
            return count(self::$enqueued);
        }
    }
}

// The event class itself, so the observer's own type declaration is exercised rather than bypassed.
// If the signature is ever widened or the event renamed, this harness stops compiling — which is the
// correct outcome for a check whose whole subject is a registration matching a method.
namespace core\event {

    class user_graded {
        public $relateduserid;
        public $courseid;
        public $other = [];
    }
}

namespace {

    define('MOODLE_INTERNAL', true);

    $root = dirname(__DIR__, 2);

    /** Plugin settings for the case being run. */
    $CFGSTUB = ['enabled' => 1, 'capturegrades' => 1];

    function get_config($plugin, $name) {
        global $CFGSTUB;
        return $CFGSTUB[$name] ?? false;
    }

    /** Records what the transaction was told to do, so a rollback cannot pass as a commit. */
    class fake_transaction {
        public $committed = false;
        public $rolledback = false;
        public function allow_commit() { $this->committed = true; }
        public function rollback($e) { $this->rolledback = true; throw $e; }
    }

    /**
     * A $DB that answers from fixtures and records writes. Deliberately strict: an unexpected table
     * is recorded rather than silently returning false, because a false would send the observer down
     * an early return and let a broken one look like a correctly-skipping one.
     */
    class fake_db {
        public $gradeitems = [];      // id => row
        public $achievements = [];    // list of rows
        public $updates = [];         // rows passed to update_record
        public $transaction;
        public $unexpected = [];

        public function get_record($table, array $conditions, $fields = '*') {
            if ($table === 'grade_items') {
                $row = $this->gradeitems[(int) $conditions['id']] ?? false;
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
            $rows = array_values(array_filter($this->achievements, static function ($a) use ($params) {
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
            // Mimic the real narrowing, so a partial select cannot pass this harness while failing
            // in production. This is what the "keeps its identity" cases rest on.
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

        public function update_record($table, $row) {
            if ($table !== 'local_completionhistory_achievement') {
                $this->unexpected[] = "update_record({$table})";
                return false;
            }
            $this->updates[] = clone $row;
            return true;
        }

        public function start_delegated_transaction() {
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

    /** A complete achievement row, as the table actually defines it. */
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
            'grade_decimal'            => 72.00,
            'grade_passed'             => 0,
            'grade_source'             => 'gradebook',
            'exam_track'               => 'proctored',
            'attempts_used'            => 1,
            'attempts_allowed'         => 3,
            'artifacturl'              => '',
            'artifactstorage'          => '',
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
     * Run one case against the real observer. Returns the fake $DB so assertions can read what was
     * written.
     */
    function run_case(array $opts): \fake_db {
        global $DB, $CFGSTUB;

        $CFGSTUB = $opts['config'] ?? ['enabled' => 1, 'capturegrades' => 1];

        $DB = new \fake_db();
        $DB->gradeitems   = $opts['gradeitems'] ?? [
            900 => (object) ['id' => 900, 'courseid' => 7, 'itemtype' => 'course'],
        ];
        $DB->achievements = $opts['achievements'] ?? [achievement_row()];

        grade_snapshot_service::$total = array_key_exists('total', $opts)
            ? $opts['total']
            : (object) ['finalgrade' => 88.5, 'grademax' => 100.0, 'gradepass' => 70.0, 'passed' => 1];
        outbox_service::$enqueued = [];

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
    check('the new grade is written', ($db->updates[0]->grade_decimal ?? null) == 88.5,
        'got ' . var_export($db->updates[0]->grade_decimal ?? null, true));
    check('the new pass flag is written', ($db->updates[0]->grade_passed ?? null) == 1,
        'got ' . var_export($db->updates[0]->grade_passed ?? null, true));
    check('grade_source records where the figure came from',
        ($db->updates[0]->grade_source ?? null) === 'gradebook');
    check('the transaction commits', ($db->transaction->committed ?? false) === true);
    check('the row is revised, not duplicated — same id', ($db->updates[0]->id ?? null) === 500);
    check('source_event_hash is left alone, so the backfill cannot insert a duplicate',
        ($db->updates[0]->source_event_hash ?? null) === 'hash-of-the-completion',
        'got ' . var_export($db->updates[0]->source_event_hash ?? null, true));

    echo "\n  the enqueued record keeps its identity\n";
    $enq = outbox_service::$enqueued[0] ?? null;
    check('carries ledgeruuid — the key the SIS matches on',
        ($enq->ledgeruuid ?? '') === 'b1f0c0de-0000-4000-8000-000000000abc',
        'got ' . var_export($enq->ledgeruuid ?? null, true));
    foreach ([
        'useridnumber_snapshot'    => 'SU-2026-01149',
        'firstname_snapshot'       => 'Ada',
        'lastname_snapshot'        => 'Lovelace',
        'email_snapshot'           => 'ada@example.invalid',
        'courseidnumber_snapshot'  => 'MBA510',
        'courseshortname_snapshot' => 'MBA510',
        'coursename_snapshot'      => 'Managerial Economics',
        'completiontime'           => 1750000000,
        'exam_track'               => 'proctored',
    ] as $field => $want) {
        check("still carries {$field}", ($enq->$field ?? null) == $want,
            'got ' . var_export($enq->$field ?? null, true));
    }

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
    check('a course total belonging to another course changes nothing',
        $db->updates === [] && outbox_service::$enqueued === []);

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

    echo "\n  idempotence — Moodle re-fires this event freely\n";
    $db = run_case([
        'achievements' => [achievement_row(['grade_decimal' => 88.5, 'grade_passed' => 1])],
    ]);
    check('an unchanged grade writes nothing',
        $db->updates === [] && outbox_service::$enqueued === [],
        count($db->updates) . ' updates, ' . count(outbox_service::$enqueued) . ' enqueued');

    $db = run_case([
        'achievements' => [achievement_row(['grade_decimal' => 88.5, 'grade_passed' => 0])],
    ]);
    check('a pass flag flipping alone is still a correction', count($db->updates) === 1);

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
