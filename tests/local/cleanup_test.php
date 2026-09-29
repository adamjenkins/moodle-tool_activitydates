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
 * Tests for the orphaned-row cleanup.
 *
 * @package    tool_activitydates
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_activitydates\local;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the cleanup class.
 */
#[CoversClass(cleanup::class)]
final class cleanup_test extends \advanced_testcase {
    public function test_orphans_removes_only_rows_of_missing_courses(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $missing = $DB->get_field_sql('SELECT MAX(id) FROM {course}') + 1000;

        foreach ([$course->id, $missing] as $courseid) {
            $datesid = $DB->insert_record('tool_activitydates', (object) ['courseid' => $courseid, 'modtype' => 'quiz']);
            $DB->insert_record('tool_activitydates_cmids', (object) ['activitydates' => $datesid, 'coursemoduleid' => 1]);
            $lockid = $DB->insert_record('tool_activitydates_lock', (object) ['courseid' => $courseid]);
            $DB->insert_record('tool_activitydates_lockitem', (object) ['lockid' => $lockid, 'cmid' => 1, 'shownote' => 1]);
        }

        $this->assertSame(4, cleanup::orphans());

        foreach (['tool_activitydates', 'tool_activitydates_lock'] as $table) {
            $this->assertSame(1, $DB->count_records($table, ['courseid' => $course->id]));
            $this->assertSame(0, $DB->count_records($table, ['courseid' => $missing]));
        }
        $this->assertSame(1, $DB->count_records('tool_activitydates_cmids'));
        $this->assertSame(1, $DB->count_records('tool_activitydates_lockitem'));
    }
}
