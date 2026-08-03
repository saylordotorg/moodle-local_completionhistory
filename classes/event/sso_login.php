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

namespace local_completionhistory\event;

/**
 * A student was logged in by a single-use key minted by the SIS (SIS-29).
 *
 * WHY THIS EXISTS WHEN CORE ALREADY LOGS LOGINS. complete_user_login() triggers
 * \core\event\user_loggedin by itself, so the login is recorded either way — but that
 * record says only that the account signed in, not that it signed in without anyone
 * typing a password. Those are different facts, and the second is the one an
 * administrator reviewing this feature actually needs: "show me every session that a
 * web-service token created" is unanswerable from core's event alone.
 *
 * Deliberately NOT a subclass of the core login event: it is an additional record beside
 * core's, not a replacement for it, and both appear in the log.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sso_login extends \core\event\base {

    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'user';
    }

    public static function get_name() {
        return get_string('event_sso_login', 'local_completionhistory');
    }

    public function get_description() {
        return "The user with id '{$this->userid}' was logged in by a single-use key issued by the SIS.";
    }

    public function get_url() {
        return new \moodle_url('/user/profile.php', ['id' => $this->userid]);
    }
}
