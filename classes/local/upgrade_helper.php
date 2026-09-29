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
 * One-off data conversions for the 2026092900 upgrade (stayavailable -> closemode), the
 * 2026092901 upgrade (grade locks on the shared schedule) and the 2026092902 upgrade
 * (per-activity course-page notes).
 *
 * The upgrade steps (db/upgrade.php, 2026092900, 2026092901 and 2026092902) run this live
 * code against their own schema. Keep it to those columns, config names and literal values.
 *
 * @package    tool_activitydates
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class upgrade_helper {
    /**
     * Convert the removed stayavailable flag, per course row and in the site config.
     *
     * Rows are converted only while the old column still exists, so this is safe to call
     * twice; the config is converted only once, because the old setting is unset.
     */
    public static function convert_stayavailable(): void {
        global $DB;
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('tool_activitydates');
        if ($dbman->field_exists($table, new \xmldb_field('stayavailable'))) {
            $DB->execute("UPDATE {tool_activitydates} SET closemode = ? WHERE stayavailable = 1", ['none']);
            $DB->execute(
                "UPDATE {tool_activitydates} SET closemode = ? WHERE stayavailable <> 1 OR stayavailable IS NULL",
                ['session']
            );
        }
        $old = get_config('tool_activitydates', 'stayavailable');
        if (get_config('tool_activitydates', 'closemode') === false) {
            set_config('closemode', $old ? 'none' : 'session', 'tool_activitydates');
        }
        if (get_config('tool_activitydates', 'duemode') === false) {
            set_config('duemode', 'none', 'tool_activitydates');
        }
        unset_config('stayavailable', 'tool_activitydates');
    }

    /**
     * Convert the site config for grade locks on the shared schedule.
     *
     * The lock page's own session length and activities per session are removed. The
     * lock mode defaults to "no lock", so existing grade locks stay as they are, and the
     * session finish date defaults to off. Settings already present are kept, so this is
     * safe to call twice.
     */
    public static function convert_lock_config(): void {
        unset_config('locksessionlength', 'tool_activitydates');
        unset_config('lockactivitiespersession', 'tool_activitydates');
        $defaults = ['lockmode' => 'none', 'lockdays' => 7, 'finishenabled' => 0];
        foreach ($defaults as $name => $value) {
            if (get_config('tool_activitydates', $name) === false) {
                set_config($name, $value, 'tool_activitydates');
            }
        }
    }

    /**
     * Carry the course-wide course-page note option over to each noted activity.
     *
     * An item whose note is on, in a course whose tool_activitydates_lock row had
     * shownotecoursepage = 1, gets its own shownotecoursepage = 1. Runs only while the
     * old column still exists, so this is safe to call twice.
     */
    public static function migrate_course_page_notes(): void {
        global $DB;
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('tool_activitydates_lock');
        if (!$dbman->field_exists($table, new \xmldb_field('shownotecoursepage'))) {
            return;
        }
        $DB->execute(
            "UPDATE {tool_activitydates_lockitem}
                SET shownotecoursepage = 1
              WHERE shownote = 1
                AND lockid IN (SELECT id FROM {tool_activitydates_lock} WHERE shownotecoursepage = 1)"
        );
    }
}
