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
 * One-off data conversions for the 2026092900 upgrade (stayavailable -> closemode).
 *
 * The upgrade step (db/upgrade.php, 2026092900) runs this live code against the
 * 2026092900 schema. Keep it to those columns and literal mode values.
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
}
