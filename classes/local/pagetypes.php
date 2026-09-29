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

namespace tool_activitydates\local;

use tool_activitydates\locks\modtypes as lockmodtypes;
use tool_activitydates\modtypes;

/**
 * The activity types offered on the Activity dates page.
 *
 * @package    tool_activitydates
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class pagetypes {
    /**
     * The types of a course the user can act on.
     *
     * The union of the types with open and close dates (modtypes) and the graded
     * types (locks\modtypes). A type with dates needs $canmanage and a graded type
     * needs $canlocks; a type the user can do nothing with is left out. hasdates
     * and hasgrades describe the type, whatever the user's capabilities.
     *
     * @param int $courseid the course id.
     * @param bool $canmanage whether the user has tool/activitydates:manage.
     * @param bool $canlocks whether the user has tool/activitydates:managelocks.
     * @return array modname => ['label' => string, 'hasdates' => bool, 'hasgrades' => bool], sorted by label.
     */
    public static function for_course(int $courseid, bool $canmanage, bool $canlocks): array {
        if (!$canmanage && !$canlocks) {
            return [];
        }
        $dates = modtypes::eligible_course_modtypes($courseid);
        $grades = lockmodtypes::eligible_course_modtypes($courseid);
        $types = [];
        foreach ($dates + $grades as $modname => $label) {
            $hasdates = isset($dates[$modname]);
            $hasgrades = isset($grades[$modname]);
            if (($hasdates && $canmanage) || ($hasgrades && $canlocks)) {
                $types[$modname] = ['label' => $label, 'hasdates' => $hasdates, 'hasgrades' => $hasgrades];
            }
        }
        \core_collator::asort_array_of_arrays_by_key($types, 'label');
        return $types;
    }
}
