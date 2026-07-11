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
 * Unit tests for the activitydates scheduling core.
 *
 * @package    tool_activitydates
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_activitydates;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the activitydates class.
 */
#[CoversClass(activitydates::class)]
final class activitydates_test extends \advanced_testcase {
    public function test_update_upserts_settings_and_selections(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $quiz1 = $generator->create_module('quiz', ['course' => $course->id]);
        $quiz2 = $generator->create_module('quiz', ['course' => $course->id]);
        $quiz3 = $generator->create_module('quiz', ['course' => $course->id]);

        $fromform = (object) [
            'modtype' => 'quiz',
            'schedulestart' => strtotime('2030-01-01 09:00'),
            'schedulefinish' => strtotime('2030-01-15 17:00'),
            'sessionlength' => 7,
            'activitiespersession' => 2,
            'stayavailable' => 0,
            'hideunselected' => 1,
            'resetunselected' => 0,
            'activitygroup' => [
                'activity_' . $quiz1->cmid => 1,
                'activity_' . $quiz2->cmid => 1,
            ],
        ];

        $manager = new activitydates();
        [$selections, $settings] = $manager->update($fromform, $course->id);

        // Use assertEquals for values that round-trip through the DB layer: the
        // mariadb native driver returns numeric columns as strings on fetch.
        $this->assertEquals($course->id, $settings->courseid);
        $this->assertSame('quiz', $settings->modtype);
        $this->assertSame($fromform->schedulestart, $settings->schedulestart);
        $this->assertSame($fromform->schedulefinish, $settings->schedulefinish);
        $this->assertSame(7, $settings->sessionlength);
        $this->assertSame(2, $settings->activitiespersession);
        $this->assertSame(0, $settings->stayavailable);
        $this->assertSame(1, $settings->hideunselected);
        $this->assertSame(0, $settings->resetunselected);
        $this->assertGreaterThan(0, $settings->id);
        $this->assertGreaterThan(0, $settings->timemodified);
        $this->assertCount(2, $selections);

        // Calling update() again with the same courseid must update, not duplicate.
        $fromform2 = clone $fromform;
        $fromform2->activitygroup = [
            'activity_' . $quiz3->cmid => 1,
        ];
        [$selections2, $settings2] = $manager->update($fromform2, $course->id);
        $this->assertEquals($settings->id, $settings2->id);
        $this->assertCount(1, $selections2);
        $this->assertCount(1, $DB->get_records('tool_activitydates', ['courseid' => $course->id]));
    }

    public function test_manage_selections_diff(): void {
        global $DB;
        $this->resetAfterTest();
        $activitydatesid = $DB->insert_record('tool_activitydates', (object) [
            'courseid' => 1,
            'modtype' => 'quiz',
            'schedulestart' => time(),
            'schedulefinish' => time(),
            'sessionlength' => 1,
            'activitiespersession' => 1,
            'stayavailable' => 0,
            'hideunselected' => 0,
            'resetunselected' => 0,
            'timemodified' => time(),
        ]);

        $manager = new activitydates();
        $fromform1 = (object) ['activitygroup' => [
            'activity_10' => 1,
            'activity_20' => 1,
        ]];
        $count1 = $manager->manage_selections($fromform1, $activitydatesid);
        $this->assertSame(2, $count1);
        $rows1 = $DB->get_records('tool_activitydates_cmids', ['activitydates' => $activitydatesid]);
        $this->assertCount(2, $rows1);
        $cmids1 = array_map(fn($r) => (int) $r->coursemoduleid, $rows1);
        sort($cmids1);
        $this->assertSame([10, 20], $cmids1);

        // Second call: keep 20, drop 10, add 30.
        $fromform2 = (object) ['activitygroup' => [
            'activity_20' => 1,
            'activity_30' => 1,
        ]];
        $count2 = $manager->manage_selections($fromform2, $activitydatesid);
        $this->assertSame(2, $count2);
        $rows2 = $DB->get_records('tool_activitydates_cmids', ['activitydates' => $activitydatesid]);
        $this->assertCount(2, $rows2);
        $cmids2 = array_map(fn($r) => (int) $r->coursemoduleid, $rows2);
        sort($cmids2);
        $this->assertSame([20, 30], $cmids2);
    }

    public function test_calculate_dates(): void {
        $this->resetAfterTest();
        $settings = (object) [
            'schedulestart' => strtotime('2030-01-01 09:00'),
            'schedulefinish' => strtotime('2030-01-15 17:00'),
            'sessionlength' => 7,
        ];
        $manager = new activitydates();

        $window0 = $manager->calculate_dates($settings, 0);
        $this->assertSame(1, $window0['sessionnumber']);
        $this->assertSame(strtotime('2030-01-01 09:00'), $window0['start']);
        $this->assertSame(strtotime('2030-01-07 17:00'), $window0['end']);

        $window1 = $manager->calculate_dates($settings, 1);
        $this->assertSame(2, $window1['sessionnumber']);
        $this->assertSame(strtotime('2030-01-08 09:00'), $window1['start']);
        $this->assertSame(strtotime('2030-01-14 17:00'), $window1['end']);
    }

    public function test_get_table_data_sessions_and_quirk_fix(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $quizzes = [];
        for ($i = 1; $i <= 4; $i++) {
            $quizzes[] = $generator->create_module('quiz', ['course' => $course->id, 'name' => 'Quiz' . $i]);
        }

        // Session 1 = quiz1,quiz2. Session 2 = quiz3,quiz4.
        // Deliberately leave quiz1 (session 1's first activity) unselected.
        $fromform = (object) [
            'modtype' => 'quiz',
            'schedulestart' => strtotime('2030-01-01 09:00'),
            'schedulefinish' => strtotime('2030-01-15 17:00'),
            'sessionlength' => 7,
            'activitiespersession' => 2,
            'stayavailable' => 0,
            'hideunselected' => 0,
            'resetunselected' => 0,
            'activitygroup' => [
                'activity_' . $quizzes[1]->cmid => 1, // Quiz2.
                'activity_' . $quizzes[2]->cmid => 1, // Quiz3.
                'activity_' . $quizzes[3]->cmid => 1, // Quiz4.
            ],
        ];

        $manager = new activitydates();
        [, $settings] = $manager->update($fromform, $course->id);

        $tabledata = $manager->get_table_data($settings);

        $headers = array_values(array_filter($tabledata, fn($row) => $row['isheader']));
        $this->assertCount(2, $headers);

        // Session 1 header still gets a real window (quiz2 is selected).
        $this->assertNotNull($headers[0]['dates']);
        $this->assertSame(1, $headers[0]['dates']['sessionnumber']);
        $this->assertSame(strtotime('2030-01-01 09:00'), $headers[0]['dates']['start']);

        // Session 2 header gets the NEXT window, not a reused/skipped one.
        $this->assertNotNull($headers[1]['dates']);
        $this->assertSame(2, $headers[1]['dates']['sessionnumber']);
        $this->assertSame(strtotime('2030-01-08 09:00'), $headers[1]['dates']['start']);
        $this->assertSame(
            $headers[0]['dates']['start'] + 7 * DAYSECS,
            $headers[1]['dates']['start']
        );

        // Data rows: exact key shape and selected flags.
        $datarows = array_values(array_filter($tabledata, fn($row) => !$row['isheader']));
        $this->assertCount(4, $datarows);
        foreach ($datarows as $row) {
            $this->assertArrayHasKey('cm', $row);
            $this->assertArrayHasKey('id', $row);
            $this->assertArrayHasKey('name', $row);
            $this->assertArrayHasKey('intro', $row);
            $this->assertArrayHasKey('selected', $row);
            $this->assertArrayHasKey('questioncount', $row);
            $this->assertArrayHasKey('dates', $row);
            $this->assertArrayHasKey('timeopen', $row);
            $this->assertArrayHasKey('timeclose', $row);
        }

        $byname = [];
        foreach ($datarows as $row) {
            $byname[$row['name']] = $row;
        }
        $this->assertSame('', $byname['Quiz1']['selected']);
        $this->assertSame('checked', $byname['Quiz2']['selected']);
        $this->assertSame('checked', $byname['Quiz3']['selected']);
        $this->assertSame('checked', $byname['Quiz4']['selected']);

        // Quiz1 and Quiz2 both belong to session 1's window (chunked by activitiespersession).
        $this->assertSame($headers[0]['dates'], $byname['Quiz1']['dates']);
        $this->assertSame($headers[0]['dates'], $byname['Quiz2']['dates']);
        $this->assertSame($headers[1]['dates'], $byname['Quiz3']['dates']);
        $this->assertSame($headers[1]['dates'], $byname['Quiz4']['dates']);
    }
}
