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
 * Event observers for tool_activitydates.
 *
 * @package    tool_activitydates
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_activitydates;

/**
 * Observer class handling core events for tool_activitydates.
 */
class observer {
    /**
     * Clean up this plugin's course-level rows when a course is deleted.
     *
     * Moodle does not cascade foreign keys, so both modes' configuration and
     * selection rows, and the fixed-date flags, would otherwise be orphaned.
     * Children go first.
     *
     * @param \core\event\course_deleted $event The course_deleted event.
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        global $DB;
        $params = ['courseid' => $event->courseid];
        $DB->delete_records_select(
            'tool_activitydates_cmids',
            'activitydates IN (SELECT id FROM {tool_activitydates} WHERE courseid = :courseid)',
            $params
        );
        $DB->delete_records('tool_activitydates', $params);
        $DB->delete_records_select(
            'tool_activitydates_lockitem',
            'lockid IN (SELECT id FROM {tool_activitydates_lock} WHERE courseid = :courseid)',
            $params
        );
        $DB->delete_records('tool_activitydates_lock', $params);
        $DB->delete_records('tool_activitydates_fixed', $params);
    }

    /**
     * Clean up this plugin's rows for a course module when it is deleted.
     *
     * Its dates selection, grade-lock selection (with its notes) and fixed-date
     * flags would otherwise stay until the course is deleted. Each delete is
     * scoped to the module's course, so it uses that course's indexes.
     *
     * @param \core\event\course_module_deleted $event The course_module_deleted event.
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event): void {
        global $DB;
        $params = ['courseid' => $event->courseid, 'cmid' => $event->objectid];
        $DB->delete_records_select(
            'tool_activitydates_cmids',
            'coursemoduleid = :cmid AND activitydates IN (SELECT id FROM {tool_activitydates} WHERE courseid = :courseid)',
            $params
        );
        $DB->delete_records_select(
            'tool_activitydates_lockitem',
            'cmid = :cmid AND lockid IN (SELECT id FROM {tool_activitydates_lock} WHERE courseid = :courseid)',
            $params
        );
        $DB->delete_records('tool_activitydates_fixed', $params);
    }
}
