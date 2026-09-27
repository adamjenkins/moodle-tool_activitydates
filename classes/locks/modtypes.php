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

namespace tool_activitydates\locks;

/**
 * Detects which activity module types have gradable (grade item) instances.
 * A module type is eligible iff at least one of its instances in the course
 * has an 'mod' itemtype grade item.
 */
class modtypes {
    /**
     * Check if a single course module instance has a grade item.
     *
     * @param string $modname The module name.
     * @param int $instanceid The module instance ID.
     * @param int $courseid The course ID.
     * @return bool True if the module instance has a grade item, false otherwise.
     */
    protected static function instance_has_grade_item(string $modname, int $instanceid, int $courseid): bool {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $gradeitem = \grade_item::fetch([
            'courseid' => $courseid,
            'itemtype' => 'mod',
            'itemmodule' => $modname,
            'iteminstance' => $instanceid,
        ]);
        return $gradeitem !== false;
    }

    /**
     * Check if a module type has at least one grade item in the course.
     *
     * @param string $modname The module name to check.
     * @param int $courseid The course ID.
     * @return bool True if at least one instance of this module type has a grade item, false otherwise.
     */
    public static function has_grade_items(string $modname, int $courseid): bool {
        $modinfo = get_fast_modinfo($courseid);
        foreach ($modinfo->get_instances_of($modname) as $cm) {
            if ($cm->deletioninprogress) {
                continue;
            }
            if (self::instance_has_grade_item($modname, $cm->instance, $courseid)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get eligible (gradable) module types for a course.
     *
     * Returns the module types that have at least one 'mod' itemtype grade
     * item present in the course, deduplicated and sorted by label.
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
            if (array_key_exists($cm->modname, $checked)) {
                continue;
            }
            $checked[$cm->modname] = true;
            if (self::has_grade_items($cm->modname, $courseid)) {
                $eligible[$cm->modname] = get_string('modulenameplural', $cm->modname);
            }
        }
        \core_collator::asort($eligible);
        return $eligible;
    }
}
