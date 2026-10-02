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

    if ($oldversion < 2026092900) {
        // Close/due modes and the optional finish date replace stayavailable.
        $table = new xmldb_table('tool_activitydates');
        $fields = [
            new xmldb_field('finishenabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'resetunselected'),
            new xmldb_field('closemode', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'session', 'finishenabled'),
            new xmldb_field('closedays', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '7', 'closemode'),
            new xmldb_field('closedate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'closedays'),
            new xmldb_field('duemode', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'none', 'closedate'),
            new xmldb_field('duedays', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '7', 'duemode'),
            new xmldb_field('duedate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'duedays'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // Rows and the site default: stayavailable = 1 becomes "no close date".
        \tool_activitydates\local\upgrade_helper::convert_stayavailable();

        $field = new xmldb_field('stayavailable');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026092900, 'tool', 'activitydates');
    }

    if ($oldversion < 2026092901) {
        // Grade locks move onto the shared schedule: a lock mode replaces the lock
        // configuration's own type, start, session length and activities per session.
        $table = new xmldb_table('tool_activitydates_lock');
        $fields = [
            new xmldb_field('lockmode', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'none', 'resetunselected'),
            new xmldb_field('lockdays', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '7', 'lockmode'),
            new xmldb_field('lockdate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'lockdays'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        foreach (['modtype', 'schedulestart', 'sessionlength', 'activitiespersession'] as $name) {
            $field = new xmldb_field($name);
            if ($dbman->field_exists($table, $field)) {
                $dbman->drop_field($table, $field);
            }
        }

        // The session finish date is off by default; saved rows keep their value.
        $table = new xmldb_table('tool_activitydates');
        $field = new xmldb_field('finishenabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'resetunselected');
        $dbman->change_field_default($table, $field);

        \tool_activitydates\local\upgrade_helper::convert_lock_config();

        upgrade_plugin_savepoint(true, 2026092901, 'tool', 'activitydates');
    }

    if ($oldversion < 2026092902) {
        // Fixed dates: the scheduler leaves these activity dates as the teacher set them.
        $table = new xmldb_table('tool_activitydates_fixed');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('field', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('fk_course', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $table->add_index('cmidfield', XMLDB_INDEX_UNIQUE, ['cmid', 'field']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // The course-page note becomes a per-activity choice.
        $table = new xmldb_table('tool_activitydates_lockitem');
        $field = new xmldb_field('shownotecoursepage', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'shownote');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Items whose note showed on the course page keep showing it there.
        \tool_activitydates\local\upgrade_helper::migrate_course_page_notes();

        $table = new xmldb_table('tool_activitydates_lock');
        foreach (['shownote', 'shownotecoursepage'] as $name) {
            $field = new xmldb_field($name);
            if ($dbman->field_exists($table, $field)) {
                $dbman->drop_field($table, $field);
            }
        }

        upgrade_plugin_savepoint(true, 2026092902, 'tool', 'activitydates');
    }

    if ($oldversion < 2026100200) {
        // Named configurations of the page, saved per course.
        $table = new xmldb_table('tool_activitydates_saved');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('data', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('fk_course', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $table->add_index('coursename', XMLDB_INDEX_UNIQUE, ['courseid', 'name']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026100200, 'tool', 'activitydates');
    }

    return true;
}
