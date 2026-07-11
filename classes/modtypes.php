<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Module eligibility detector for the tool_activitydates plugin.
 *
 * @package    tool_activitydates
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_activitydates;

/**
 * Detects which activity module types support timeopen/timeclose dates.
 * A module type is eligible iff its instance table has BOTH columns.
 */
class modtypes {
    /**
     * Check if a module type has timeopen and timeclose columns.
     *
     * @param string $modname The module name to check.
     * @return bool True if the module has both timeopen and timeclose columns, false otherwise.
     */
    public static function has_date_columns(string $modname): bool {
        global $DB;
        // Get_columns() returns [] for a missing table and is metadata-cached.
        $columns = $DB->get_columns($modname);
        return isset($columns['timeopen'], $columns['timeclose']);
    }

    /**
     * Get eligible module types for a course.
     *
     * Returns the module types that have both timeopen and timeclose columns,
     * present in the course, deduplicated and sorted by label.
     *
     * @param int $courseid The course ID.
     * @return array Module name => localised plural name, present on course, label-sorted.
     */
    public static function eligible_course_modtypes(int $courseid): array {
        $modinfo = get_fast_modinfo($courseid);
        $checked = [];
        $eligible = [];
        foreach ($modinfo->cms as $cm) {
            if ($cm->deletioninprogress) {
                continue;
            }
            if (!array_key_exists($cm->modname, $checked)) {
                $checked[$cm->modname] = self::has_date_columns($cm->modname);
            }
            if ($checked[$cm->modname]) {
                $eligible[$cm->modname] = get_string('modulenameplural', $cm->modname);
            }
        }
        \core_collator::asort($eligible);
        return $eligible;
    }
}
