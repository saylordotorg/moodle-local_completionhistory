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
 * Library functions for local_completionhistory.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Add "My Achievements" link to the user settings navigation.
 *
 * @param navigation_node $navigation
 * @param stdClass $user
 * @param context_user $context
 * @param stdClass $course
 * @param context_course $coursecontext
 */
function local_completionhistory_extend_navigation_user_settings(
    navigation_node $navigation,
    stdClass $user,
    context_user $context,
    stdClass $course,
    context_course $coursecontext
): void {
    global $USER;

    if (!get_config('local_completionhistory', 'enabled')) {
        return;
    }

    if (!get_config('local_completionhistory', 'enableuserachievements')) {
        return;
    }

    // Only show for own profile or users with viewall.
    $systemcontext = context_system::instance();
    if ($user->id == $USER->id) {
        if (!has_capability('local/completionhistory:viewown', $systemcontext)) {
            return;
        }
    } else {
        if (!has_capability('local/completionhistory:viewall', $systemcontext)) {
            return;
        }
    }

    $url = new moodle_url('/local/completionhistory/my_achievements.php', ['userid' => $user->id]);
    $navigation->add(
        get_string('myachievements', 'local_completionhistory'),
        $url,
        navigation_node::TYPE_SETTING
    );
}
