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
 * Static check for the password provision_applicant creates accounts with.
 *
 * WHAT THIS GUARDS. degrees.saylor.org runs a password-policy plugin that refuses a "numeric
 * sequence longer than 2" and "repeated characters longer than 2". The old initial password,
 * bin2hex(random_bytes(16)) . 'Aa1!', nearly always contains three digits in a row, so
 * user_create_user() threw and account creation failed there (2026-09-24). dev.sylr.org has no
 * such plugin, so nothing in the ordinary test path could see it.
 *
 * The fake check_password_policy() below models that site: core's minimum counts plus the two
 * plugin rules, read at their strictest (ANY three digits in a row, ANY three identical
 * characters in a row). The first section proves the model reproduces the failure on the old
 * expression — a check whose fake policy accepted the old password would prove nothing.
 *
 * Usage:  php tests/static/check_initial_password.php
 * Exit:   0 = all checks pass, 1 = a check failed, 2 = the check itself is broken.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses -- standalone harness pre-defines fake collaborators; see header.
// phpcs:disable moodle.Files.MoodleInternal.MoodleInternalGlobalState -- standalone check, no Moodle bootstrap (see header).

namespace local_completionhistory\harness {
    /**
     * Fake moodle_exception: carries the error code and $a so the check can read them.
     *
     * @package    local_completionhistory
     * @copyright  2026 Saylor Academy
     * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
     */
    class moodle_exception extends \Exception {
        /** @var string The error code. */
        public $errorcode;
        /** @var mixed The $a value. */
        public $a;

        /**
         * Record the code and $a.
         *
         * @param string $errorcode The error code.
         * @param string $module The component.
         * @param string $link Unused.
         * @param mixed $a The $a value.
         */
        public function __construct($errorcode, $module = '', $link = '', $a = null) {
            parent::__construct($errorcode);
            $this->errorcode = $errorcode;
            $this->a = $a;
        }
    }

    class_alias(\local_completionhistory\harness\moodle_exception::class, 'moodle_exception');
}

namespace {
    set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
        fwrite(STDERR, "the check itself errored at {$file}:{$line}: {$msg}\n");
        exit(2);
    });

    $root = dirname(__DIR__, 2);
    $CFG = new stdClass();

    // When true the fake policy refuses everything (the unsatisfiable-site case).
    $refuseall = false;

    /**
     * The degrees.saylor.org policy, modelled at its strictest.
     *
     * @param string $password The candidate.
     * @param string|null $errmsg Set to the refusal text.
     * @param stdClass|null $user The account.
     * @return bool Whether it is accepted.
     */
    function check_password_policy(string $password, ?string &$errmsg, ?stdClass $user = null): bool {
        global $CFG, $refuseall;
        $errmsg = '';
        if ($refuseall) {
            $errmsg .= '<div>Refused by the test.<br></div>';
            return false;
        }
        if (strlen($password) < (int) ($CFG->minpasswordlength ?? 0)) {
            $errmsg .= 'Too short.<br>';
        }
        $counts = [
            'minpasswordlower' => preg_match_all('/[a-z]/', $password),
            'minpasswordupper' => preg_match_all('/[A-Z]/', $password),
            'minpassworddigits' => preg_match_all('/[0-9]/', $password),
            'minpasswordnonalphanum' => preg_match_all('/[^a-zA-Z0-9]/', $password),
        ];
        foreach ($counts as $setting => $have) {
            if ($have < (int) ($CFG->$setting ?? 0)) {
                $errmsg .= "Too few for {$setting}.<br>";
            }
        }
        if (preg_match('/[0-9]{3}/', $password)) {
            $errmsg .= 'Password contains numeric sequence longer than: 2.<br>';
        }
        if (preg_match('/(.)\1\1/', $password)) {
            $errmsg .= 'Password contains repeated characters longer than: 2.<br>';
        }
        if ($errmsg !== '') {
            $errmsg = '<div>' . $errmsg . '</div>';
            return false;
        }
        return true;
    }

    require($root . '/classes/local/initial_password.php');
    use local_completionhistory\local\initial_password;

    $passes = 0;
    $failures = [];
    $check = static function (string $name, bool $ok, string $detail = '') use (&$passes, &$failures): void {
        if ($ok) {
            $passes++;
            echo "  ok    {$name}\n";
        } else {
            $failures[] = $name . ($detail !== '' ? " ({$detail})" : '');
            echo "  FAIL  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
        }
    };

    $user = (object) ['username' => 'applicant@example.com', 'email' => 'applicant@example.com'];
    $runs = 5000;

    echo "the model reproduces the defect\n";
    $refused = 0;
    for ($i = 0; $i < 1000; $i++) {
        $old = bin2hex(random_bytes(16)) . 'Aa1!';
        $err = '';
        if (!check_password_policy($old, $err, $user)) {
            $refused++;
        }
    }
    $check('the old bin2hex password is refused by the modelled site', $refused >= 900, "{$refused}/1000 refused");

    $policies = [
        'no site minimums' => [],
        'Moodle defaults' => ['minpasswordlength' => 8, 'minpasswordlower' => 1, 'minpasswordupper' => 1,
            'minpassworddigits' => 1, 'minpasswordnonalphanum' => 1],
        'demanding site' => ['minpasswordlength' => 40, 'minpasswordlower' => 3, 'minpasswordupper' => 5,
            'minpassworddigits' => 9, 'minpasswordnonalphanum' => 6],
        'digits beyond half the length' => ['minpasswordlength' => 10, 'minpassworddigits' => 20],
    ];
    foreach ($policies as $label => $settings) {
        echo "\ncandidate(), {$label}\n";
        $CFG = (object) $settings;
        $bad = [];
        $seen = [];
        for ($i = 0; $i < $runs; $i++) {
            $p = initial_password::candidate(
                (int) ($CFG->minpasswordlength ?? 0),
                (int) ($CFG->minpasswordlower ?? 0),
                (int) ($CFG->minpasswordupper ?? 0),
                (int) ($CFG->minpassworddigits ?? 0),
                (int) ($CFG->minpasswordnonalphanum ?? 0)
            );
            $seen[$p] = true;
            $err = '';
            if (
                !check_password_policy($p, $err, $user) || preg_match('/[0-9]{2}/', $p) || preg_match('/(.)\1/', $p)
                    || strlen($p) < 24
            ) {
                $bad[] = $p;
            }
        }
        $check(
            "{$runs} candidates all accepted, none with adjacent digits or a doubled character",
            $bad === [],
            count($bad) . ' bad, e.g. ' . ($bad[0] ?? '')
        );
        $check('and they are all different', count($seen) === $runs, count($seen) . " distinct of {$runs}");
    }

    echo "\ngenerate()\n";
    $CFG = (object) $policies['Moodle defaults'];
    $p = initial_password::generate($user);
    $err = '';
    $check('returns a password the site accepts', check_password_policy($p, $err, $user), $err);

    $refuseall = true;
    try {
        initial_password::generate($user);
        $check('an unsatisfiable site is reported, not ignored', false, 'no exception');
    } catch (moodle_exception $e) {
        $check('an unsatisfiable site is reported by its own code', $e->errorcode === 'initialpasswordrefused', $e->errorcode);
        $check('with the policy text, markup removed', $e->a === 'Refused by the test.', var_export($e->a, true));
    }
    $refuseall = false;

    $lang = file_get_contents($root . '/lang/en/local_completionhistory.php');
    $check('the error code has a lang string', strpos($lang, "\$string['initialpasswordrefused']") !== false);

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
