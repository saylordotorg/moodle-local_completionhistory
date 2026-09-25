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

/**
 * The throwaway password a provisioned account is created with.
 *
 * WHY THIS EXISTS. provision_applicant used `bin2hex(random_bytes(16)) . 'Aa1!'`. That passes
 * Moodle core's policy, but degrees.saylor.org also runs a password-policy plugin that refuses
 * a "numeric sequence longer than 2" and "repeated characters longer than 2" — and 32 random hex
 * characters nearly always contain three digits in a row. user_create_user() then threw, so
 * account creation failed on that site for almost every applicant (found 2026-09-24, while
 * preparing the SIS to make degrees its primary Moodle). dev.sylr.org has no such plugin, which
 * is why it never showed there.
 *
 * So the password is built to be unobjectionable by construction — no character repeated
 * back to back, no two digits adjacent, every character class present and at least the site's
 * minimum counts and length — and is then confirmed against check_password_policy(), which runs
 * core's rules AND every plugin's check_password_policy callback. A site whose rules cannot be
 * met is reported by name instead of creating nothing with a generic error.
 *
 * Nobody ever types this password: the account is signed in through a login key and
 * auth_forcepasswordchange is set. It only has to be long, random and accepted.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class initial_password {
    /** @var string Lower-case letters, without the ones easily misread. */
    private const LOWER = 'abcdefghijkmnpqrstuvwxyz';
    /** @var string Upper-case letters, without the ones easily misread. */
    private const UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    /** @var string Digits, without 0 and 1. */
    private const DIGITS = '23456789';
    /** @var string Symbols every Moodle policy counts as non-alphanumeric. */
    private const SYMBOLS = '!#%*+-=?@';
    /** @var int Candidates tried before the site's policy is declared unsatisfiable. */
    private const ATTEMPTS = 10;

    /**
     * A password the site's full policy accepts for this user.
     *
     * @param \stdClass $user The account being created (policy plugins may compare against it).
     * @return string The password.
     * @throws \moodle_exception When no candidate satisfies the site's policy.
     */
    public static function generate(\stdClass $user): string {
        global $CFG;
        $errmsg = '';
        for ($i = 0; $i < self::ATTEMPTS; $i++) {
            $candidate = self::candidate(
                (int) ($CFG->minpasswordlength ?? 0),
                (int) ($CFG->minpasswordlower ?? 0),
                (int) ($CFG->minpasswordupper ?? 0),
                (int) ($CFG->minpassworddigits ?? 0),
                (int) ($CFG->minpasswordnonalphanum ?? 0)
            );
            $errmsg = '';
            if (check_password_policy($candidate, $errmsg, $user)) {
                return $candidate;
            }
        }
        throw new \moodle_exception(
            'initialpasswordrefused',
            'local_completionhistory',
            '',
            trim(strip_tags(str_replace('<br>', ' ', (string) $errmsg)))
        );
    }

    /**
     * One candidate: at least the given counts per class, no character repeated back to back,
     * and no two digits adjacent. Pure, so the static harness can check it without Moodle.
     *
     * @param int $minlength Site minimum length.
     * @param int $minlower Site minimum lower-case letters.
     * @param int $minupper Site minimum upper-case letters.
     * @param int $mindigits Site minimum digits.
     * @param int $minsymbols Site minimum non-alphanumeric characters.
     * @return string The candidate.
     */
    public static function candidate(int $minlength, int $minlower, int $minupper, int $mindigits, int $minsymbols): string {
        $digits = max(2, $mindigits);
        $upper = max(2, $minupper);
        $symbols = max(2, $minsymbols);
        $lower = max(2, $minlower);
        $length = max(24, $minlength, $digits + $upper + $symbols + $lower);
        // Digits must fit with a non-digit between every pair.
        $length = max($length, 2 * $digits - 1);
        $lower += $length - ($digits + $upper + $symbols + $lower);

        $classes = array_merge(
            array_fill(0, $digits, self::DIGITS),
            array_fill(0, $upper, self::UPPER),
            array_fill(0, $symbols, self::SYMBOLS),
            array_fill(0, $lower, self::LOWER)
        );
        // Place the digits first, on positions that are never adjacent, then shuffle the rest in.
        $nondigits = array_slice($classes, $digits);
        self::shuffle($nondigits);
        $slots = range(0, count($nondigits));
        self::shuffle($slots);
        $digitslots = array_flip(array_slice($slots, 0, $digits));
        $layout = [];
        foreach ($nondigits as $index => $class) {
            if (isset($digitslots[$index])) {
                $layout[] = self::DIGITS;
            }
            $layout[] = $class;
        }
        if (isset($digitslots[count($nondigits)])) {
            $layout[] = self::DIGITS;
        }

        $password = '';
        $previous = '';
        foreach ($layout as $set) {
            do {
                $char = $set[random_int(0, strlen($set) - 1)];
            } while ($char === $previous);
            $password .= $char;
            $previous = $char;
        }
        return $password;
    }

    /**
     * Fisher–Yates with random_int(): str_shuffle and shuffle() are not cryptographically secure.
     *
     * @param array $items The array to shuffle in place.
     */
    private static function shuffle(array &$items): void {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }
    }
}
