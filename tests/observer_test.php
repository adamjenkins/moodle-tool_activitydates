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
 * Tests for the course and course module deletion observers.
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
    public function test_course_deleted_clears_all_tables(): void {
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
            foreach (['timeopen', 'timelock'] as $field) {
                $DB->insert_record(
                    'tool_activitydates_fixed',
                    (object) ['courseid' => $courseid, 'cmid' => $courseid, 'field' => $field, 'timemodified' => 1]
                );
            }
        }

        delete_course($doomed, false);

        $expected = ['tool_activitydates' => 1, 'tool_activitydates_lock' => 1, 'tool_activitydates_fixed' => 2];
        foreach ($expected as $table => $count) {
            $this->assertSame(0, $DB->count_records($table, ['courseid' => $doomed->id]));
            $this->assertSame($count, $DB->count_records($table, ['courseid' => $kept->id]));
        }
        $this->assertSame(1, $DB->count_records('tool_activitydates_cmids'));
        $this->assertSame(1, $DB->count_records('tool_activitydates_lockitem'));
    }

    public function test_course_module_deleted_clears_its_rows(): void {
        global $DB;
        $this->resetAfterTest();
        // Delete the module now, not after a recycle bin backup.
        set_config('coursebinenable', 0, 'tool_recyclebin');
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $doomed = $gen->create_module('quiz', ['course' => $course->id]);
        $kept = $gen->create_module('quiz', ['course' => $course->id]);

        $datesid = $DB->insert_record('tool_activitydates', (object) ['courseid' => $course->id, 'modtype' => 'quiz']);
        $lockid = $DB->insert_record('tool_activitydates_lock', (object) ['courseid' => $course->id]);
        foreach ([$doomed->cmid, $kept->cmid] as $cmid) {
            $DB->insert_record('tool_activitydates_cmids', (object) ['activitydates' => $datesid, 'coursemoduleid' => $cmid]);
            $DB->insert_record('tool_activitydates_lockitem', (object) ['lockid' => $lockid, 'cmid' => $cmid, 'shownote' => 1]);
            $DB->insert_record(
                'tool_activitydates_fixed',
                (object) ['courseid' => $course->id, 'cmid' => $cmid, 'field' => 'timeopen', 'timemodified' => 1]
            );
        }

        // Moodle 5.2 moved the deletion into the course format actions.
        $cmactions = new \core_courseformat\local\cmactions($course);
        if (method_exists($cmactions, 'delete')) {
            $cmactions->delete($doomed->cmid);
        } else {
            course_delete_module($doomed->cmid);
        }

        $left = fn(string $table, string $field): array => array_map('intval', $DB->get_fieldset($table, $field));
        $this->assertSame([(int) $kept->cmid], $left('tool_activitydates_cmids', 'coursemoduleid'));
        $this->assertSame([(int) $kept->cmid], $left('tool_activitydates_lockitem', 'cmid'));
        $this->assertSame([(int) $kept->cmid], $left('tool_activitydates_fixed', 'cmid'));
        // The course's configuration stays.
        $this->assertTrue($DB->record_exists('tool_activitydates', ['id' => $datesid]));
        $this->assertTrue($DB->record_exists('tool_activitydates_lock', ['id' => $lockid]));
    }
}
