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
 * Verify cross-plugin service contributions against the installed source.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class service_definition {
    /**
     * Whether an installed plugin explicitly contributes this function to this service.
     *
     * The database's service membership is not proof: an arbitrary attachment or
     * stale services metadata must still fail the deploy-time comparison.
     *
     * @param string $component Component recorded for the registered external function.
     * @param string $functionname Registered external function name.
     * @param string $serviceshortname Service being checked.
     * @return bool
     */
    public static function is_declared_contribution(
        string $component,
        string $functionname,
        string $serviceshortname
    ): bool {
        if ($component === '' || !get_config($component, 'version')) {
            return false;
        }
        $directory = \core_component::get_component_directory($component);
        if (!$directory || !is_readable($directory . '/db/services.php')) {
            return false;
        }
        $functions = self::load_functions($directory . '/db/services.php');
        $services = $functions[$functionname]['services'] ?? [];
        return is_array($services) && in_array($serviceshortname, $services, true);
    }

    /**
     * Read declarations in a local scope, as Moodle does during upgrade.
     *
     * @param string $definitionpath Installed component's service-definition file.
     * @return array Declared external functions.
     */
    private static function load_functions(string $definitionpath): array {
        global $CFG;

        $functions = [];
        $services = [];
        include($definitionpath);
        return $functions;
    }
}
