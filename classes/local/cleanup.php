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

/**
 * Removes this plugin's rows that belong to courses which no longer exist.
 *
 * Versions before 2.0.0 had no course-deletion observer, so a deleted course
 * left its configuration behind. The 2.0.0 upgrade calls this once.
 *
 * @package    tool_activitydates
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup {
    /**
     * Delete the configuration rows (and their child rows) of missing courses.
     *
     * @return int The number of rows deleted, across all four tables.
     */
    public static function orphans(): int {
        global $DB;
        $deleted = 0;
        $pairs = [
            // Parent table, child table, child's foreign-key column.
            ['tool_activitydates', 'tool_activitydates_cmids', 'activitydates'],
            ['tool_activitydates_lock', 'tool_activitydates_lockitem', 'lockid'],
        ];
        foreach ($pairs as [$parent, $child, $fk]) {
            $orphanids = $DB->get_fieldset_sql(
                "SELECT p.id FROM {{$parent}} p LEFT JOIN {course} c ON c.id = p.courseid WHERE c.id IS NULL"
            );
            if (!$orphanids) {
                continue;
            }
            [$insql, $params] = $DB->get_in_or_equal($orphanids);
            $deleted += $DB->count_records_select($child, "$fk $insql", $params);
            $DB->delete_records_select($child, "$fk $insql", $params);
            $deleted += count($orphanids);
            $DB->delete_records_select($parent, "id $insql", $params);
        }
        return $deleted;
    }
}
