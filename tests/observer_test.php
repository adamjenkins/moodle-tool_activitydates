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
 * Tests for the course-deletion observer.
 *
 * @package    tool_activitydates
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_activitydates;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the observer class.
 */
#[CoversClass(observer::class)]
final class observer_test extends \advanced_testcase {
    public function test_course_deleted_clears_all_four_tables(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $doomed = $gen->create_course();
        $kept = $gen->create_course();

        foreach ([$doomed->id, $kept->id] as $courseid) {
            $datesid = $DB->insert_record('tool_activitydates', (object) ['courseid' => $courseid, 'modtype' => 'quiz']);
            $DB->insert_record('tool_activitydates_cmids', (object) ['activitydates' => $datesid, 'coursemoduleid' => 1]);
            $lockid = $DB->insert_record('tool_activitydates_lock', (object) ['courseid' => $courseid]);
            $DB->insert_record('tool_activitydates_lockitem', (object) ['lockid' => $lockid, 'cmid' => 1, 'shownote' => 1]);
        }

        delete_course($doomed, false);

        foreach (['tool_activitydates', 'tool_activitydates_lock'] as $table) {
            $this->assertSame(0, $DB->count_records($table, ['courseid' => $doomed->id]));
            $this->assertSame(1, $DB->count_records($table, ['courseid' => $kept->id]));
        }
        $this->assertSame(1, $DB->count_records('tool_activitydates_cmids'));
        $this->assertSame(1, $DB->count_records('tool_activitydates_lockitem'));
    }
}
