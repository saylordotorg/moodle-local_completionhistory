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
 * Plugin version and other metadata.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_completionhistory';
// Bumped for SIS-29. A new external function is registered by the UPGRADE, not by the
// file that declares it — until this number changes, local_completionhistory_create_login_key
// exists in db/services.php and nowhere Moodle can call it.
//
// Ahead of main's 2026080100 (SIS-42, 0.4.0), which is what makes this merge an upgrade
// rather than a no-op. Moodle only runs the upgrade when this number INCREASES, so taking
// main's side here would have merged the function and registered nothing.
$plugin->version   = 2026080301;
$plugin->requires  = 2024100700; // Moodle 4.5.
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.4.2';
