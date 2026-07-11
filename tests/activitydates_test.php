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

    public function test_apply_dates_roundtrip(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $quizzes = [];
        for ($i = 1; $i <= 4; $i++) {
            $quizzes[] = $generator->create_module('quiz', ['course' => $course->id, 'name' => 'Quiz' . $i]);
        }
        $start = strtotime('2030-01-01 09:00');
        $finish = strtotime('2030-01-15 17:00');
        $fromform = (object) [
            'modtype' => 'quiz', 'schedulestart' => $start, 'schedulefinish' => $finish,
            'sessionlength' => 7, 'activitiespersession' => 2,
            'stayavailable' => 0, 'hideunselected' => 0, 'resetunselected' => 0,
            'activitygroup' => [],
        ];
        foreach ($quizzes as $quiz) {
            $fromform->activitygroup['activity_' . $quiz->cmid] = 1;
        }

        $manager = new activitydates();
        [$selections, $settings] = $manager->update($fromform, $course->id);
        $this->assertCount(4, $selections);

        $count = $manager->apply_dates($manager->get_table_data($settings), $settings);
        $this->assertSame(4, $count);

        // Session 1 = quizzes 1-2, session 2 = quizzes 3-4.
        $expectedopen  = [$start, $start, strtotime('2030-01-08 09:00'), strtotime('2030-01-08 09:00')];
        $expectedclose = [strtotime('2030-01-07 17:00'), strtotime('2030-01-07 17:00'),
                          strtotime('2030-01-14 17:00'), strtotime('2030-01-14 17:00')];
        foreach ($quizzes as $i => $quiz) {
            $record = $DB->get_record('quiz', ['id' => $quiz->id], '*', MUST_EXIST);
            $this->assertEquals($expectedopen[$i], $record->timeopen, "Quiz $i timeopen");
            $this->assertEquals($expectedclose[$i], $record->timeclose, "Quiz $i timeclose");

            // Calendar events must exist and match (created by quiz_refresh_events).
            $open = $DB->get_records(
                'event',
                ['modulename' => 'quiz', 'instance' => $quiz->id, 'eventtype' => 'open']
            );
            $this->assertCount(1, $open, "Quiz $i open event");
            $this->assertEquals($record->timeopen, reset($open)->timestart);
            $close = $DB->get_records(
                'event',
                ['modulename' => 'quiz', 'instance' => $quiz->id, 'eventtype' => 'close']
            );
            $this->assertCount(1, $close, "Quiz $i close event");
            $this->assertEquals($record->timeclose, reset($close)->timestart);
        }
    }

    public function test_apply_dates_stayavailable(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        // Pre-set a close date to prove stayavailable overwrites it with 0.
        $quiz = $generator->create_module('quiz', ['course' => $course->id,
            'timeopen' => strtotime('2029-01-01'), 'timeclose' => strtotime('2029-02-01')]);
        $fromform = (object) [
            'modtype' => 'quiz',
            'schedulestart' => strtotime('2030-01-01 09:00'),
            'schedulefinish' => strtotime('2030-01-15 17:00'),
            'sessionlength' => 7, 'activitiespersession' => 1,
            'stayavailable' => 1, 'hideunselected' => 0, 'resetunselected' => 0,
            'activitygroup' => ['activity_' . $quiz->cmid => 1],
        ];
        $manager = new activitydates();
        [, $settings] = $manager->update($fromform, $course->id);
        $manager->apply_dates($manager->get_table_data($settings), $settings);

        $record = $DB->get_record('quiz', ['id' => $quiz->id], '*', MUST_EXIST);
        $this->assertEquals(strtotime('2030-01-01 09:00'), $record->timeopen);
        $this->assertEquals(0, $record->timeclose);
        $this->assertCount(0, $DB->get_records(
            'event',
            ['modulename' => 'quiz', 'instance' => $quiz->id, 'eventtype' => 'close']
        ));
    }

    public function test_apply_dates_skips_past_schedulefinish(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        // One quiz per session (activitiespersession = 1): quiz1's session starts on
        // schedulestart, quiz2's session starts 7 days later. schedulefinish sits
        // between the two, so quiz2's window start exceeds schedulefinish.
        $quiz1 = $generator->create_module('quiz', ['course' => $course->id, 'name' => 'Quiz1']);
        $quiz2 = $generator->create_module('quiz', ['course' => $course->id, 'name' => 'Quiz2']);
        $start = strtotime('2030-01-01 09:00');
        $finish = strtotime('2030-01-05 17:00');
        $fromform = (object) [
            'modtype' => 'quiz', 'schedulestart' => $start, 'schedulefinish' => $finish,
            'sessionlength' => 7, 'activitiespersession' => 1,
            'stayavailable' => 0, 'hideunselected' => 0, 'resetunselected' => 0,
            'activitygroup' => [
                'activity_' . $quiz1->cmid => 1,
                'activity_' . $quiz2->cmid => 1,
            ],
        ];

        $manager = new activitydates();
        [, $settings] = $manager->update($fromform, $course->id);
        $count = $manager->apply_dates($manager->get_table_data($settings), $settings);

        // Only quiz1's window (start = schedulestart) is within schedulefinish.
        $this->assertSame(1, $count);

        $record1 = $DB->get_record('quiz', ['id' => $quiz1->id], '*', MUST_EXIST);
        $this->assertEquals($start, $record1->timeopen);
        $this->assertEquals(strtotime('2030-01-07 17:00'), $record1->timeclose);
        $this->assertCount(1, $DB->get_records(
            'event',
            ['modulename' => 'quiz', 'instance' => $quiz1->id, 'eventtype' => 'open']
        ));

        // Quiz2's window start (2030-01-08 09:00) exceeds schedulefinish, so it must
        // be skipped entirely: no write, no calendar events, no hide/reset side effects.
        $record2 = $DB->get_record('quiz', ['id' => $quiz2->id], '*', MUST_EXIST);
        $this->assertEquals(0, $record2->timeopen);
        $this->assertEquals(0, $record2->timeclose);
        $this->assertCount(0, $DB->get_records(
            'event',
            ['modulename' => 'quiz', 'instance' => $quiz2->id, 'eventtype' => 'open']
        ));
        $this->assertCount(0, $DB->get_records(
            'event',
            ['modulename' => 'quiz', 'instance' => $quiz2->id, 'eventtype' => 'close']
        ));
    }

    public function test_process_unselected_hide_and_reset(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $quiz1 = $generator->create_module('quiz', ['course' => $course->id, 'name' => 'Quiz1']);
        $quiz2 = $generator->create_module('quiz', ['course' => $course->id, 'name' => 'Quiz2']);
        $start = strtotime('2030-02-01 09:00');
        $finish = strtotime('2030-02-15 17:00');

        // Pass 1: select both quizzes to seed real dates and calendar events.
        $seedform = (object) [
            'modtype' => 'quiz', 'schedulestart' => $start, 'schedulefinish' => $finish,
            'sessionlength' => 7, 'activitiespersession' => 2,
            'stayavailable' => 0, 'hideunselected' => 0, 'resetunselected' => 0,
            'activitygroup' => [
                'activity_' . $quiz1->cmid => 1,
                'activity_' . $quiz2->cmid => 1,
            ],
        ];
        $manager = new activitydates();
        [, $seedsettings] = $manager->update($seedform, $course->id);
        $manager->apply_dates($manager->get_table_data($seedsettings), $seedsettings);

        $expectedopen = $start;
        $expectedclose = strtotime('2030-02-07 17:00');
        $this->assertEquals(
            $expectedopen,
            $DB->get_record('quiz', ['id' => $quiz2->id], '*', MUST_EXIST)->timeopen
        );
        $this->assertCount(1, $DB->get_records(
            'event',
            ['modulename' => 'quiz', 'instance' => $quiz2->id, 'eventtype' => 'open']
        ));

        // Pass 2: keep only quiz1 selected, with hideunselected and resetunselected on.
        $fromform = (object) [
            'modtype' => 'quiz', 'schedulestart' => $start, 'schedulefinish' => $finish,
            'sessionlength' => 7, 'activitiespersession' => 2,
            'stayavailable' => 0, 'hideunselected' => 1, 'resetunselected' => 1,
            'activitygroup' => [
                'activity_' . $quiz1->cmid => 1,
            ],
        ];
        [, $settings] = $manager->update($fromform, $course->id);
        $manager->apply_dates($manager->get_table_data($settings), $settings);

        // Unselected quiz2: hidden, dates zeroed, calendar events deleted.
        $cm2 = $DB->get_record('course_modules', ['id' => $quiz2->cmid], '*', MUST_EXIST);
        $this->assertEquals(0, $cm2->visible);
        $record2 = $DB->get_record('quiz', ['id' => $quiz2->id], '*', MUST_EXIST);
        $this->assertEquals(0, $record2->timeopen);
        $this->assertEquals(0, $record2->timeclose);
        $this->assertCount(0, $DB->get_records(
            'event',
            ['modulename' => 'quiz', 'instance' => $quiz2->id, 'eventtype' => 'open']
        ));
        $this->assertCount(0, $DB->get_records(
            'event',
            ['modulename' => 'quiz', 'instance' => $quiz2->id, 'eventtype' => 'close']
        ));

        // Control: selected quiz1 still has its dates and calendar events.
        $cm1 = $DB->get_record('course_modules', ['id' => $quiz1->cmid], '*', MUST_EXIST);
        $this->assertEquals(1, $cm1->visible);
        $record1 = $DB->get_record('quiz', ['id' => $quiz1->id], '*', MUST_EXIST);
        $this->assertEquals($expectedopen, $record1->timeopen);
        $this->assertEquals($expectedclose, $record1->timeclose);
        $this->assertCount(1, $DB->get_records(
            'event',
            ['modulename' => 'quiz', 'instance' => $quiz1->id, 'eventtype' => 'open']
        ));
        $this->assertCount(1, $DB->get_records(
            'event',
            ['modulename' => 'quiz', 'instance' => $quiz1->id, 'eventtype' => 'close']
        ));
    }
}
