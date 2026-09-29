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
 * Tests for the upgrade conversions (stayavailable -> closemode, grade locks on the shared schedule).
 *
 * @package    tool_activitydates
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_activitydates;

use PHPUnit\Framework\Attributes\CoversClass;
use tool_activitydates\local\upgrade_helper;

/**
 * Tests for the upgrade_helper class.
 */
#[CoversClass(upgrade_helper::class)]
final class upgrade_test extends \advanced_testcase {
    /**
     * Rows with stayavailable set get no close date; the others close with the session.
     */
    public function test_convert_rows(): void {
        global $DB;
        $this->resetAfterTest();

        // The test DB is already at the new schema: put the old column back for this test.
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('tool_activitydates');
        $field = new \xmldb_field('stayavailable', XMLDB_TYPE_INTEGER, '1', null, null, null, '0');
        $dbman->add_field($table, $field);
        try {
            $stay = $DB->insert_record(
                'tool_activitydates',
                (object) ['courseid' => 1001, 'modtype' => 'quiz', 'stayavailable' => 1, 'closemode' => 'days']
            );
            $close = $DB->insert_record(
                'tool_activitydates',
                (object) ['courseid' => 1002, 'modtype' => 'quiz', 'stayavailable' => 0, 'closemode' => 'days']
            );
            $unset = $DB->insert_record(
                'tool_activitydates',
                (object) ['courseid' => 1003, 'modtype' => 'quiz', 'stayavailable' => null, 'closemode' => 'days']
            );

            upgrade_helper::convert_stayavailable();

            $this->assertSame('none', $DB->get_field('tool_activitydates', 'closemode', ['id' => $stay]));
            $this->assertSame('session', $DB->get_field('tool_activitydates', 'closemode', ['id' => $close]));
            $this->assertSame('session', $DB->get_field('tool_activitydates', 'closemode', ['id' => $unset]));
        } finally {
            $dbman->drop_field($table, $field);
        }
    }

    /**
     * The site default is converted from the old setting, and the old setting is removed.
     */
    public function test_convert_config(): void {
        $this->resetAfterTest();

        foreach ([1 => 'none', 0 => 'session'] as $stayavailable => $closemode) {
            // Before the upgrade the new settings do not exist yet.
            unset_config('closemode', 'tool_activitydates');
            unset_config('duemode', 'tool_activitydates');
            set_config('stayavailable', $stayavailable, 'tool_activitydates');

            upgrade_helper::convert_stayavailable();

            $this->assertSame($closemode, get_config('tool_activitydates', 'closemode'));
            $this->assertSame('none', get_config('tool_activitydates', 'duemode'));
            $this->assertFalse(get_config('tool_activitydates', 'stayavailable'));
        }
    }

    /**
     * Without an old setting, the defaults are "end of session" and "no due date".
     */
    public function test_convert_config_absent(): void {
        $this->resetAfterTest();
        unset_config('closemode', 'tool_activitydates');
        unset_config('duemode', 'tool_activitydates');
        unset_config('stayavailable', 'tool_activitydates');

        upgrade_helper::convert_stayavailable();

        $this->assertSame('session', get_config('tool_activitydates', 'closemode'));
        $this->assertSame('none', get_config('tool_activitydates', 'duemode'));
        $this->assertFalse(get_config('tool_activitydates', 'stayavailable'));
    }

    /**
     * The lock page's own schedule settings go; lock mode, lock days and the finish default are set.
     */
    public function test_lock_config_upgrade(): void {
        global $DB;
        $this->resetAfterTest();

        // Before the upgrade: the 2.0 lock settings exist, the new ones do not.
        set_config('locksessionlength', 7, 'tool_activitydates');
        set_config('lockactivitiespersession', 5, 'tool_activitydates');
        unset_config('lockmode', 'tool_activitydates');
        unset_config('lockdays', 'tool_activitydates');
        unset_config('finishenabled', 'tool_activitydates');

        upgrade_helper::convert_lock_config();

        $this->assertFalse(get_config('tool_activitydates', 'locksessionlength'));
        $this->assertFalse(get_config('tool_activitydates', 'lockactivitiespersession'));
        $this->assertSame('none', get_config('tool_activitydates', 'lockmode'));
        $this->assertSame('7', get_config('tool_activitydates', 'lockdays'));
        $this->assertSame('0', get_config('tool_activitydates', 'finishenabled'));

        // Settings already present are kept.
        set_config('lockmode', 'days', 'tool_activitydates');
        set_config('finishenabled', 1, 'tool_activitydates');
        upgrade_helper::convert_lock_config();
        $this->assertSame('days', get_config('tool_activitydates', 'lockmode'));
        $this->assertSame('1', get_config('tool_activitydates', 'finishenabled'));

        // The lock configuration table is on the new schema.
        $columns = $DB->get_columns('tool_activitydates_lock');
        foreach (['lockmode', 'lockdays', 'lockdate'] as $name) {
            $this->assertArrayHasKey($name, $columns);
        }
        foreach (['modtype', 'schedulestart', 'sessionlength', 'activitiespersession'] as $name) {
            $this->assertArrayNotHasKey($name, $columns);
        }
        $this->assertSame('none', $columns['lockmode']->default_value);
        $this->assertEquals(0, $DB->get_columns('tool_activitydates')['finishenabled']->default_value);
    }
}
