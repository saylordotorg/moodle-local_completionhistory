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
 * Every function's docblock documents exactly the parameters the function has.
 *
 * WHY THIS EXISTS. The Catalyst review ran Moodle's PHPDoc checker and found "31 incomplete parameter
 * lists". The Moodle coding-standard sniffs (moodle-cs) flag a MISSING docblock but not a docblock whose
 * parameter tags disagree with the signature, and the PHPDoc checker itself needs an installed Moodle
 * with a database to run. This check needs only the source tree, so it can run in the same breath as
 * the other static checks and in CI, and a docblock that drifts from its signature fails here first.
 *
 * WHAT IT CHECKS, for every named function and method in the plugin (closures and arrow functions are
 * skipped, as the PHPDoc checker skips them):
 *   - a docblock exists;
 *   - it carries one parameter tag per parameter, naming the same variable, in the same order;
 *   - it carries no parameter tag for a parameter that does not exist;
 *   - each parameter tag has a type before the variable name.
 *
 * Usage:  php tests/static/check_phpdoc_params.php [path ...]
 * Exit:   0 = clean, 1 = at least one defect (each is printed as file:line message).
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.MoodleInternal.MoodleInternalGlobalState -- standalone check, no Moodle bootstrap (see header).

$root = dirname(__DIR__, 2);
$targets = array_slice($argv, 1) ?: [$root];

/**
 * All PHP files under a path, excluding vendor and node_modules trees.
 *
 * @param string $path A file or directory.
 * @return string[]
 */
function lch_phpdoc_files(string $path): array {
    if (is_file($path)) {
        return [$path];
    }
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            function (SplFileInfo $current): bool {
                $name = $current->getFilename();
                if ($current->isDir()) {
                    return !in_array($name, ['vendor', 'node_modules', '.git', 'build'], true);
                }
                return substr($name, -4) === '.php';
            }
        )
    );
    foreach ($iterator as $file) {
        $files[] = $file->getPathname();
    }
    sort($files);
    return $files;
}

/**
 * Find every named function/method in a file and compare its docblock to its signature.
 *
 * @param string $file Path to a PHP file.
 * @return string[] Defects, each "file:line message".
 */
function lch_phpdoc_check_file(string $file): array {
    $defects = [];
    $tokens = token_get_all((string) file_get_contents($file));
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (!is_array($token) || $token[0] !== T_FUNCTION) {
            continue;
        }
        // The next significant token after `function` is the name, or `(`/`&` for a closure.
        $j = $i + 1;
        while ($j < $count && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT], true)) {
            $j++;
        }
        if ($j >= $count || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING) {
            continue; // Closure or reference-returning closure: no docblock expected.
        }
        $name = $tokens[$j][1];
        $line = $tokens[$j][2];

        // Signature parameters: variables between the opening and matching closing parenthesis,
        // ignoring anything nested (default values, attributes).
        $params = [];
        $k = $j;
        while ($k < $count && $tokens[$k] !== '(') {
            $k++;
        }
        $depth = 0;
        for (; $k < $count; $k++) {
            $t = $tokens[$k];
            if ($t === '(' || $t === '[') {
                $depth++;
            } else if ($t === ')' || $t === ']') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            } else if ($depth === 1 && is_array($t) && $t[0] === T_VARIABLE) {
                $params[] = $t[1];
            }
        }

        // The docblock: walk back over whitespace, modifiers and attributes to the nearest comment.
        $doc = null;
        $skip = [T_WHITESPACE, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL];
        if (defined('T_READONLY')) {
            $skip[] = T_READONLY;
        }
        $attrdepth = 0;
        for ($b = $i - 1; $b >= 0; $b--) {
            $t = $tokens[$b];
            if ($t === ']' && $attrdepth === 0 && isset($tokens[$b - 1])) {
                // Possibly the end of an attribute `#[...]`; walk back to its start.
                $attrdepth = 1;
                continue;
            }
            if ($attrdepth > 0) {
                if (is_array($t) && $t[0] === T_ATTRIBUTE) {
                    $attrdepth = 0;
                }
                continue;
            }
            if (is_array($t) && in_array($t[0], $skip, true)) {
                continue;
            }
            if (is_array($t) && $t[0] === T_DOC_COMMENT) {
                $doc = $t[1];
            }
            break;
        }

        if ($doc === null) {
            $defects[] = "{$file}:{$line} {$name}() has no docblock";
            continue;
        }

        preg_match_all('/@param\s+(\S+)?\s*(\$\w+|\.\.\.\$\w+)?/', $doc, $matches, PREG_SET_ORDER);
        $documented = [];
        foreach ($matches as $m) {
            $type = $m[1] ?? '';
            $var = $m[2] ?? '';
            if ($type !== '' && $type[0] === '$') {
                // A `@param $foo` line with no type.
                $defects[] = "{$file}:{$line} {$name}(): @param {$type} has no type";
                $var = $type;
            } else if ($var === '') {
                $defects[] = "{$file}:{$line} {$name}(): @param '{$type}' names no variable";
                continue;
            }
            $documented[] = ltrim($var, '.');
        }

        if ($documented !== $params) {
            $missing = array_diff($params, $documented);
            $extra = array_diff($documented, $params);
            $detail = [];
            if ($missing) {
                $detail[] = 'undocumented: ' . implode(', ', $missing);
            }
            if ($extra) {
                $detail[] = 'not a parameter: ' . implode(', ', $extra);
            }
            if (!$detail) {
                $detail[] = 'order differs from the signature';
            }
            $defects[] = "{$file}:{$line} {$name}() @param list is incomplete (" . implode('; ', $detail) . ')';
        }
    }

    return $defects;
}

$defects = [];
$checked = 0;
foreach ($targets as $target) {
    foreach (lch_phpdoc_files($target) as $file) {
        $checked++;
        $defects = array_merge($defects, lch_phpdoc_check_file($file));
    }
}

foreach ($defects as $defect) {
    echo str_replace($root . DIRECTORY_SEPARATOR, '', $defect), "\n";
}
echo sprintf("%d file(s) checked, %d docblock defect(s).\n", $checked, count($defects));
exit($defects ? 1 : 0);
