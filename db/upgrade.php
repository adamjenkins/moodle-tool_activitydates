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
 * Upgrade steps for tool_activitydates.
 *
 * @package    tool_activitydates
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the plugin.
 *
 * @param int $oldversion The version being upgraded from.
 * @return bool
 */
function xmldb_tool_activitydates_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026092600) {
        // Grade locks mode (2.0.0): configuration per course.
        $table = new xmldb_table('tool_activitydates_lock');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('modtype', XMLDB_TYPE_CHAR, '50', null, null, null, null);
        $table->add_field('schedulestart', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('sessionlength', XMLDB_TYPE_INTEGER, '10', null, null, null, '7');
        $table->add_field('activitiespersession', XMLDB_TYPE_INTEGER, '10', null, null, null, '5');
        $table->add_field('shownote', XMLDB_TYPE_INTEGER, '1', null, null, null, '1');
        $table->add_field('shownotecoursepage', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('resetunselected', XMLDB_TYPE_INTEGER, '1', null, null, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('courseid', XMLDB_KEY_UNIQUE, ['courseid']);
        $table->add_key('fk_course', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Grade locks mode: selected activities.
        $table = new xmldb_table('tool_activitydates_lockitem');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('lockid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('shownote', XMLDB_TYPE_INTEGER, '1', null, null, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('fk_lockid', XMLDB_KEY_FOREIGN, ['lockid'], 'tool_activitydates_lock', ['id']);
        $table->add_index('cmid', XMLDB_INDEX_NOTUNIQUE, ['cmid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Earlier versions had no course-deletion observer. This calls live code: see
        // the warning in cleanup's class docblock before changing orphans().
        \tool_activitydates\local\cleanup::orphans();

        upgrade_plugin_savepoint(true, 2026092600, 'tool', 'activitydates');
    }

    return true;
}
