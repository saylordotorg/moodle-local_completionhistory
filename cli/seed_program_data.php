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
 * Seed Pre-MBA and MBA program data with 15 students.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/lib/enrollib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/grade/grade_item.php');
require_once($CFG->libdir . '/grade/grade_grade.php');

use enrol_programs\local\program;
use enrol_programs\local\content\top;
use enrol_programs\local\source\manual;
use local_completionhistory\local\backfill_service;

// Set admin user for capability checks.
$USER = get_admin();

cli_writeln('=== MBA Program Data Seeder ===');
cli_writeln('');

// ─── 1. Course definitions ───────────────────────────────────────────

$premba_course_defs = [
    ['shortname' => 'BUS110', 'fullname' => 'Business Communication',    'idnumber' => 'BUS110'],
    ['shortname' => 'ACC201', 'fullname' => 'Financial Accounting',       'idnumber' => 'ACC201'],
    ['shortname' => 'STAT200', 'fullname' => 'Business Statistics',       'idnumber' => 'STAT200'],
    ['shortname' => 'ECON101', 'fullname' => 'Principles of Economics',   'idnumber' => 'ECON101'],
];

$mba_course_defs = [
    ['shortname' => 'MBA510', 'fullname' => 'Marketing Management',       'idnumber' => 'MBA510'],
    ['shortname' => 'MBA520', 'fullname' => 'Organizational Behavior',    'idnumber' => 'MBA520'],
    ['shortname' => 'MBA530', 'fullname' => 'Corporate Finance',          'idnumber' => 'MBA530'],
    ['shortname' => 'MBA540', 'fullname' => 'Strategic Management',       'idnumber' => 'MBA540'],
    ['shortname' => 'MBA550', 'fullname' => 'Operations Management',      'idnumber' => 'MBA550'],
];

// ─── 2. Create courses ───────────────────────────────────────────────

function seed_create_course(array $def): object {
    global $DB;
    $existing = $DB->get_record('course', ['shortname' => $def['shortname']]);
    if ($existing) {
        cli_writeln("  Course {$def['shortname']} already exists (id {$existing->id})");
        return $existing;
    }
    $data = new stdClass();
    $data->fullname = $def['fullname'];
    $data->shortname = $def['shortname'];
    $data->idnumber = $def['idnumber'];
    $data->category = 1;
    $data->format = 'topics';
    $data->enablecompletion = 1;
    $data->numsections = 4;
    $course = create_course($data);
    cli_writeln("  Created course {$def['shortname']} (id {$course->id})");
    return $course;
}

cli_writeln('Creating Pre-MBA courses...');
$premba_courses = [];
foreach ($premba_course_defs as $def) {
    $premba_courses[] = seed_create_course($def);
}

cli_writeln('Creating MBA courses...');
$mba_courses = [];
foreach ($mba_course_defs as $def) {
    $mba_courses[] = seed_create_course($def);
}

// ─── 3. Create programs ──────────────────────────────────────────────

cli_writeln('');
cli_writeln('Creating programs...');

$syscontext = context_system::instance();

// Pre-MBA program.
$premba_program = $DB->get_record('enrol_programs_programs', ['idnumber' => 'PREMBA']);
if (!$premba_program) {
    $premba_program = program::add_program((object) [
        'fullname'  => 'Pre-MBA Prerequisites',
        'idnumber'  => 'PREMBA',
        'contextid' => $syscontext->id,
        'description' => 'Foundation courses required before entering the MBA program. Students must complete all four courses.',
        'descriptionformat' => FORMAT_PLAIN,
        'public'    => 1,
        'archived'  => 0,
    ]);
    cli_writeln("  Created Pre-MBA program (id {$premba_program->id})");
} else {
    cli_writeln("  Pre-MBA program already exists (id {$premba_program->id})");
}

// MBA program.
$mba_program = $DB->get_record('enrol_programs_programs', ['idnumber' => 'MBA']);
if (!$mba_program) {
    $mba_program = program::add_program((object) [
        'fullname'  => 'Master of Business Administration',
        'idnumber'  => 'MBA',
        'contextid' => $syscontext->id,
        'description' => 'The MBA program builds on Pre-MBA prerequisites with advanced business courses. Completion of Pre-MBA is required for admission.',
        'descriptionformat' => FORMAT_PLAIN,
        'public'    => 1,
        'archived'  => 0,
    ]);
    cli_writeln("  Created MBA program (id {$mba_program->id})");
} else {
    cli_writeln("  MBA program already exists (id {$mba_program->id})");
}

// ─── 4. Add courses to programs ──────────────────────────────────────

cli_writeln('');
cli_writeln('Adding courses to programs...');

function seed_add_courses_to_program(object $program, array $courses, string $label): void {
    global $DB;

    // Check if courses are already added.
    $existing_items = $DB->get_records('enrol_programs_items', ['programid' => $program->id]);
    $existing_courseids = [];
    foreach ($existing_items as $item) {
        if ($item->courseid) {
            $existing_courseids[] = (int) $item->courseid;
        }
    }

    $top = top::load($program->id);

    // Set sequence type to "all in order".
    $top->update_set($top, ['sequencetype' => 'allinorder']);

    foreach ($courses as $course) {
        if (in_array((int) $course->id, $existing_courseids)) {
            cli_writeln("  {$label}: {$course->shortname} already in program");
            continue;
        }
        $top->append_course($top, (int) $course->id);
        cli_writeln("  {$label}: added {$course->shortname}");
    }
}

seed_add_courses_to_program($premba_program, $premba_courses, 'Pre-MBA');
seed_add_courses_to_program($mba_program, $mba_courses, 'MBA');

// ─── 5. Create manual allocation sources ─────────────────────────────

cli_writeln('');
cli_writeln('Setting up allocation sources...');

function seed_get_or_create_manual_source(object $program): object {
    global $DB;
    $source = $DB->get_record('enrol_programs_sources', [
        'programid' => $program->id,
        'type'      => 'manual',
    ]);
    if (!$source) {
        $rec = new stdClass();
        $rec->programid = $program->id;
        $rec->type = 'manual';
        $rec->datajson = json_encode(new stdClass());
        $rec->id = $DB->insert_record('enrol_programs_sources', $rec);
        $source = $DB->get_record('enrol_programs_sources', ['id' => $rec->id]);
        cli_writeln("  Created manual source for program {$program->idnumber} (id {$source->id})");
    } else {
        cli_writeln("  Manual source exists for {$program->idnumber} (id {$source->id})");
    }
    return $source;
}

$premba_source = seed_get_or_create_manual_source($premba_program);
$mba_source = seed_get_or_create_manual_source($mba_program);

// ─── 6. Create 15 students ──────────────────────────────────────────

cli_writeln('');
cli_writeln('Creating students...');

$student_defs = [
    // Group 1: Failed/dropped Pre-MBA (never enter MBA)
    ['username' => 'nathan.price',      'firstname' => 'Nathan',    'lastname' => 'Price'],
    ['username' => 'olivia.foster',     'firstname' => 'Olivia',    'lastname' => 'Foster'],
    ['username' => 'marcus.chen',       'firstname' => 'Marcus',    'lastname' => 'Chen'],
    // Group 2: In progress Pre-MBA (partially complete, never enter MBA)
    ['username' => 'sophia.rivera',     'firstname' => 'Sophia',    'lastname' => 'Rivera'],
    ['username' => 'liam.patel',        'firstname' => 'Liam',      'lastname' => 'Patel'],
    ['username' => 'ava.nakamura',      'firstname' => 'Ava',       'lastname' => 'Nakamura'],
    // Group 3: Completed Pre-MBA, MBA in progress
    ['username' => 'ethan.brooks',      'firstname' => 'Ethan',     'lastname' => 'Brooks'],
    ['username' => 'maya.washington',    'firstname' => 'Maya',      'lastname' => 'Washington'],
    ['username' => 'lucas.hernandez',   'firstname' => 'Lucas',     'lastname' => 'Hernandez'],
    // Group 4: Completed Pre-MBA, failed/dropped MBA
    ['username' => 'zara.mitchell',     'firstname' => 'Zara',      'lastname' => 'Mitchell'],
    ['username' => 'ryan.oconnor',      'firstname' => 'Ryan',      'lastname' => "O'Connor"],
    ['username' => 'priya.sharma',      'firstname' => 'Priya',     'lastname' => 'Sharma'],
    // Group 5: Graduated from both (completed Pre-MBA + MBA)
    ['username' => 'daniel.kim',        'firstname' => 'Daniel',    'lastname' => 'Kim'],
    ['username' => 'emma.johansson',    'firstname' => 'Emma',      'lastname' => 'Johansson'],
    ['username' => 'carlos.mendez',     'firstname' => 'Carlos',    'lastname' => 'Mendez'],
];

$students = [];
foreach ($student_defs as $def) {
    $existing = $DB->get_record('user', ['username' => $def['username']]);
    if ($existing) {
        cli_writeln("  {$def['username']} already exists (id {$existing->id})");
        $students[] = $existing;
        continue;
    }
    $user = new stdClass();
    $user->username  = $def['username'];
    $user->firstname = $def['firstname'];
    $user->lastname  = $def['lastname'];
    $user->email     = $def['username'] . '@saylor.test';
    $user->password  = 'Test1234!';
    $user->confirmed = 1;
    $user->mnethostid = $CFG->mnet_localhost_id;
    $user->id = user_create_user($user, true, false);
    $user = $DB->get_record('user', ['id' => $user->id]);
    cli_writeln("  Created {$def['username']} (id {$user->id})");
    $students[] = $user;
}

// ─── 7. Student completion profiles ──────────────────────────────────
//
// Each profile: [premba_completions => [course_index => grade], mba_completions => [...] | null]
// null mba_completions = not allocated to MBA.
// Courses are indexed 0..3 for Pre-MBA, 0..4 for MBA.
//
// Timestamps: Pre-MBA period Oct 2024 – Mar 2025, MBA period Apr 2025 – Feb 2026.

$ts_premba_base = [
    strtotime('2024-11-15'), // BUS110
    strtotime('2025-01-10'), // ACC201
    strtotime('2025-02-15'), // STAT200
    strtotime('2025-03-20'), // ECON101
];

$ts_mba_base = [
    strtotime('2025-06-01'), // MBA510
    strtotime('2025-08-01'), // MBA520
    strtotime('2025-10-01'), // MBA530
    strtotime('2025-12-01'), // MBA540
    strtotime('2026-02-01'), // MBA550
];

// Per-student day offsets for variety.
$day = 86400;
$student_offsets = [0, 3, -2, 5, -4, 7, 1, -3, 6, -1, 4, -5, 2, -6, 3];

$profiles = [
    // Group 1: Failed/dropped Pre-MBA
    // Nathan: completed BUS110 only
    0  => ['premba' => [0 => 72],                             'mba' => null],
    // Olivia: completed BUS110 and STAT200 (skipped ACC201)
    1  => ['premba' => [0 => 68, 2 => 75],                    'mba' => null],
    // Marcus: completed 3 of 4 (BUS110, ACC201, STAT200), stuck on ECON101
    2  => ['premba' => [0 => 81, 1 => 65, 2 => 70],           'mba' => null],

    // Group 2: In progress Pre-MBA
    // Sophia: completed BUS110 and ACC201
    3  => ['premba' => [0 => 78, 1 => 71],                    'mba' => null],
    // Liam: completed BUS110, ACC201, STAT200
    4  => ['premba' => [0 => 85, 1 => 77, 2 => 82],           'mba' => null],
    // Ava: completed BUS110 only (just started)
    5  => ['premba' => [0 => 69],                             'mba' => null],

    // Group 3: Completed Pre-MBA, MBA in progress
    // Ethan: all Pre-MBA done, MBA510 done
    6  => ['premba' => [0 => 78, 1 => 76, 2 => 80, 3 => 79], 'mba' => [0 => 74]],
    // Maya: all Pre-MBA done, MBA510-530 done
    7  => ['premba' => [0 => 85, 1 => 80, 2 => 78, 3 => 84], 'mba' => [0 => 80, 1 => 76, 2 => 72]],
    // Lucas: all Pre-MBA done, MBA510-520 done
    8  => ['premba' => [0 => 72, 1 => 74, 2 => 76, 3 => 78], 'mba' => [0 => 71, 1 => 68]],

    // Group 4: Completed Pre-MBA, failed/dropped MBA
    // Zara: all Pre-MBA done, MBA510-520 done then dropped
    9  => ['premba' => [0 => 82, 1 => 79, 2 => 81, 3 => 77], 'mba' => [0 => 77, 1 => 73]],
    // Ryan: all Pre-MBA done, MBA510 only then dropped
    10 => ['premba' => [0 => 70, 1 => 72, 2 => 74, 3 => 76], 'mba' => [0 => 65]],
    // Priya: all Pre-MBA done, MBA510-530 done then dropped
    11 => ['premba' => [0 => 80, 1 => 78, 2 => 76, 3 => 82], 'mba' => [0 => 82, 1 => 78, 2 => 70]],

    // Group 5: Graduated from both
    // Daniel: high achiever
    12 => ['premba' => [0 => 90, 1 => 88, 2 => 86, 3 => 89], 'mba' => [0 => 92, 1 => 88, 2 => 85, 3 => 87, 4 => 90]],
    // Emma: solid performer
    13 => ['premba' => [0 => 84, 1 => 82, 2 => 80, 3 => 85], 'mba' => [0 => 81, 1 => 79, 2 => 83, 3 => 80, 4 => 82]],
    // Carlos: passed with moderate grades
    14 => ['premba' => [0 => 75, 1 => 74, 2 => 78, 3 => 77], 'mba' => [0 => 72, 1 => 70, 2 => 74, 3 => 71, 4 => 73]],
];

// ─── 8. Allocate students to programs ────────────────────────────────

cli_writeln('');
cli_writeln('Allocating students to programs...');

// All 15 go into Pre-MBA.
$premba_userids = array_map(fn($s) => (int) $s->id, $students);
$already_allocated = $DB->get_fieldset_select(
    'enrol_programs_allocations', 'userid',
    'programid = :pid', ['pid' => $premba_program->id]
);
$new_premba = array_diff($premba_userids, $already_allocated);
if (!empty($new_premba)) {
    manual::allocate_users(
        (int) $premba_program->id,
        (int) $premba_source->id,
        array_values($new_premba),
        ['timestart' => strtotime('2024-10-01')]
    );
    cli_writeln("  Allocated " . count($new_premba) . " students to Pre-MBA");
} else {
    cli_writeln("  All students already allocated to Pre-MBA");
}

// Students 7-15 (indices 6-14) go into MBA.
$mba_userids = [];
for ($i = 6; $i <= 14; $i++) {
    $mba_userids[] = (int) $students[$i]->id;
}
$already_mba = $DB->get_fieldset_select(
    'enrol_programs_allocations', 'userid',
    'programid = :pid', ['pid' => $mba_program->id]
);
$new_mba = array_diff($mba_userids, $already_mba);
if (!empty($new_mba)) {
    manual::allocate_users(
        (int) $mba_program->id,
        (int) $mba_source->id,
        array_values($new_mba),
        ['timestart' => strtotime('2025-04-01')]
    );
    cli_writeln("  Allocated " . count($new_mba) . " students to MBA");
} else {
    cli_writeln("  All MBA students already allocated");
}

// ─── 9. Ensure manual enrolment in courses ───────────────────────────

cli_writeln('');
cli_writeln('Enrolling students in courses...');

$manualenrol = enrol_get_plugin('manual');
$studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student']);

function seed_ensure_enrolled(object $course, int $userid, int $timestart): void {
    global $DB, $manualenrol, $studentroleid;

    // Check if already enrolled.
    $context = context_course::instance($course->id);
    if (is_enrolled($context, $userid)) {
        return;
    }

    $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
    if (!$instance) {
        $instanceid = $manualenrol->add_default_instance($course);
        $instance = $DB->get_record('enrol', ['id' => $instanceid]);
    }
    $manualenrol->enrol_user($instance, $userid, $studentroleid, $timestart, 0);
}

$enrolcount = 0;
foreach ($profiles as $si => $profile) {
    $userid = (int) $students[$si]->id;

    // Enrol in all Pre-MBA courses (they were allocated to the program).
    foreach ($premba_courses as $ci => $course) {
        seed_ensure_enrolled($course, $userid, strtotime('2024-10-01'));
        $enrolcount++;
    }

    // Enrol in MBA courses if allocated.
    if ($profile['mba'] !== null) {
        foreach ($mba_courses as $ci => $course) {
            seed_ensure_enrolled($course, $userid, strtotime('2025-04-01'));
            $enrolcount++;
        }
    }
}
cli_writeln("  Processed {$enrolcount} enrolment checks");

// ─── 10. Set course completions and grades ───────────────────────────

cli_writeln('');
cli_writeln('Setting course completions and grades...');

$completioncount = 0;
$gradecount = 0;

foreach ($profiles as $si => $profile) {
    $userid = (int) $students[$si]->id;
    $offset = $student_offsets[$si] * $day;

    // Pre-MBA completions.
    foreach ($profile['premba'] as $ci => $grade) {
        $course = $premba_courses[$ci];
        $timecompleted = $ts_premba_base[$ci] + $offset;

        // Insert course_completions if not exists.
        $existing_cc = $DB->get_record('course_completions', [
            'userid' => $userid,
            'course' => $course->id,
        ]);
        if (!$existing_cc) {
            $cc = new stdClass();
            $cc->userid = $userid;
            $cc->course = $course->id;
            $cc->timeenrolled = strtotime('2024-10-01');
            $cc->timestarted = $timecompleted - (30 * $day);
            $cc->timecompleted = $timecompleted;
            $cc->reaggregate = 0;
            $DB->insert_record('course_completions', $cc);
            $completioncount++;
        }

        // Set grade (fetch_course_item auto-creates the grade item).
        $gi = grade_item::fetch_course_item($course->id);
        if ($gi) {
            if ($gi->gradepass != 60) {
                $gi->gradepass = 60;
                $gi->update();
            }
            $gi->update_final_grade($userid, $grade, 'import');
            $gradecount++;
        }
    }

    // MBA completions.
    if ($profile['mba'] !== null) {
        foreach ($profile['mba'] as $ci => $grade) {
            $course = $mba_courses[$ci];
            $timecompleted = $ts_mba_base[$ci] + $offset;

            $existing_cc = $DB->get_record('course_completions', [
                'userid' => $userid,
                'course' => $course->id,
            ]);
            if (!$existing_cc) {
                $cc = new stdClass();
                $cc->userid = $userid;
                $cc->course = $course->id;
                $cc->timeenrolled = strtotime('2025-04-01');
                $cc->timestarted = $timecompleted - (30 * $day);
                $cc->timecompleted = $timecompleted;
                $cc->reaggregate = 0;
                $DB->insert_record('course_completions', $cc);
                $completioncount++;
            }

            $gi = grade_item::fetch_course_item($course->id);
            if ($gi) {
                if ($gi->gradepass != 60) {
                    $gi->gradepass = 60;
                    $gi->update();
                }
                $gi->update_final_grade($userid, $grade, 'import');
                $gradecount++;
            }
        }
    }
}

cli_writeln("  Inserted {$completioncount} course completions");
cli_writeln("  Set {$gradecount} grades");

// ─── 11. Mark program item completions ───────────────────────────────

cli_writeln('');
cli_writeln('Marking program item completions...');

$progcompcount = 0;

// Build item maps: courseid -> item record for each program.
$premba_items = $DB->get_records('enrol_programs_items', ['programid' => $premba_program->id]);
$premba_course_items = [];
$premba_topitem = null;
foreach ($premba_items as $item) {
    if ($item->topitem) {
        $premba_topitem = $item;
    } elseif ($item->courseid) {
        $premba_course_items[(int) $item->courseid] = $item;
    }
}

$mba_items = $DB->get_records('enrol_programs_items', ['programid' => $mba_program->id]);
$mba_course_items = [];
$mba_topitem = null;
foreach ($mba_items as $item) {
    if ($item->topitem) {
        $mba_topitem = $item;
    } elseif ($item->courseid) {
        $mba_course_items[(int) $item->courseid] = $item;
    }
}

foreach ($profiles as $si => $profile) {
    $userid = (int) $students[$si]->id;
    $offset = $student_offsets[$si] * $day;

    // Pre-MBA program item completions.
    $premba_alloc = $DB->get_record('enrol_programs_allocations', [
        'programid' => $premba_program->id,
        'userid'    => $userid,
    ]);
    if (!$premba_alloc) {
        cli_writeln("  WARNING: No Pre-MBA allocation for {$students[$si]->username}");
        continue;
    }

    $last_premba_time = 0;
    foreach ($profile['premba'] as $ci => $grade) {
        $course = $premba_courses[$ci];
        $timecompleted = $ts_premba_base[$ci] + $offset;
        $item = $premba_course_items[(int) $course->id] ?? null;
        if (!$item) {
            continue;
        }

        // Insert program item completion.
        $existing = $DB->get_record('enrol_programs_completions', [
            'itemid'       => $item->id,
            'allocationid' => $premba_alloc->id,
        ]);
        if (!$existing) {
            $DB->insert_record('enrol_programs_completions', (object) [
                'itemid'        => $item->id,
                'allocationid'  => $premba_alloc->id,
                'timecompleted' => $timecompleted,
            ]);
            $progcompcount++;
        }
        if ($timecompleted > $last_premba_time) {
            $last_premba_time = $timecompleted;
        }
    }

    // If all 4 Pre-MBA courses completed, mark top item + allocation.
    if (count($profile['premba']) === 4 && $premba_topitem) {
        $existing = $DB->get_record('enrol_programs_completions', [
            'itemid'       => $premba_topitem->id,
            'allocationid' => $premba_alloc->id,
        ]);
        if (!$existing) {
            $DB->insert_record('enrol_programs_completions', (object) [
                'itemid'        => $premba_topitem->id,
                'allocationid'  => $premba_alloc->id,
                'timecompleted' => $last_premba_time,
            ]);
        }
        if (empty($premba_alloc->timecompleted)) {
            $DB->set_field('enrol_programs_allocations', 'timecompleted', $last_premba_time,
                ['id' => $premba_alloc->id]);
        }
    }

    // MBA program item completions.
    if ($profile['mba'] === null) {
        continue;
    }

    $mba_alloc = $DB->get_record('enrol_programs_allocations', [
        'programid' => $mba_program->id,
        'userid'    => $userid,
    ]);
    if (!$mba_alloc) {
        cli_writeln("  WARNING: No MBA allocation for {$students[$si]->username}");
        continue;
    }

    $last_mba_time = 0;
    foreach ($profile['mba'] as $ci => $grade) {
        $course = $mba_courses[$ci];
        $timecompleted = $ts_mba_base[$ci] + $offset;
        $item = $mba_course_items[(int) $course->id] ?? null;
        if (!$item) {
            continue;
        }

        $existing = $DB->get_record('enrol_programs_completions', [
            'itemid'       => $item->id,
            'allocationid' => $mba_alloc->id,
        ]);
        if (!$existing) {
            $DB->insert_record('enrol_programs_completions', (object) [
                'itemid'        => $item->id,
                'allocationid'  => $mba_alloc->id,
                'timecompleted' => $timecompleted,
            ]);
            $progcompcount++;
        }
        if ($timecompleted > $last_mba_time) {
            $last_mba_time = $timecompleted;
        }
    }

    // If all 5 MBA courses completed, mark top item + allocation.
    if (count($profile['mba']) === 5 && $mba_topitem) {
        $existing = $DB->get_record('enrol_programs_completions', [
            'itemid'       => $mba_topitem->id,
            'allocationid' => $mba_alloc->id,
        ]);
        if (!$existing) {
            $DB->insert_record('enrol_programs_completions', (object) [
                'itemid'        => $mba_topitem->id,
                'allocationid'  => $mba_alloc->id,
                'timecompleted' => $last_mba_time,
            ]);
        }
        if (empty($mba_alloc->timecompleted)) {
            $DB->set_field('enrol_programs_allocations', 'timecompleted', $last_mba_time,
                ['id' => $mba_alloc->id]);
        }
    }
}

cli_writeln("  Inserted {$progcompcount} program item completions");

// ─── 12. Run achievement backfill ────────────────────────────────────

cli_writeln('');
cli_writeln('Running achievement backfill...');

$stats = backfill_service::scan_and_backfill(500, false, null, null, function (string $msg) {
    cli_writeln("  " . $msg);
});

cli_writeln('');
cli_writeln("Backfill results:");
cli_writeln("  Scanned:            {$stats->scanned}");
cli_writeln("  Inserted:           {$stats->inserted}");
cli_writeln("  Skipped (existing): {$stats->skipped}");
cli_writeln("  Errors:             {$stats->errors}");

// ─── 13. Summary ─────────────────────────────────────────────────────

cli_writeln('');
cli_writeln('=== Summary ===');

$total_achievements = $DB->count_records('local_completionhistory_achievement');
$total_programs_assoc = $DB->count_records('local_completionhistory_ach_program');
$achievements_with_programs = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT achievementid) FROM {local_completionhistory_ach_program}"
);

cli_writeln("Total achievements in ledger:        {$total_achievements}");
cli_writeln("Program association records:          {$total_programs_assoc}");
cli_writeln("Achievements with program context:    {$achievements_with_programs}");
cli_writeln('');
cli_writeln('Programs:');
cli_writeln("  Pre-MBA (PREMBA): {$premba_program->id}");
cli_writeln("  MBA (MBA):        {$mba_program->id}");
cli_writeln('');
cli_writeln('Student distribution:');
cli_writeln('  Students 1-3  (Nathan, Olivia, Marcus):   Pre-MBA incomplete, no MBA');
cli_writeln('  Students 4-6  (Sophia, Liam, Ava):        Pre-MBA in progress, no MBA');
cli_writeln('  Students 7-9  (Ethan, Maya, Lucas):        Pre-MBA DONE, MBA in progress');
cli_writeln('  Students 10-12 (Zara, Ryan, Priya):       Pre-MBA DONE, MBA dropped');
cli_writeln('  Students 13-15 (Daniel, Emma, Carlos):    GRADUATED from both');
cli_writeln('');
cli_writeln('All passwords: Test1234!');
cli_writeln('');
cli_writeln('Done!');
