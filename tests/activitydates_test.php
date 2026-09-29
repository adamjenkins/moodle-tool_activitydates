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
    /**
     * Submitted form data with the schedule defaults used by these tests.
     *
     * @param array $over fields to override.
     * @return \stdClass
     */
    private function fromform(array $over = []): \stdClass {
        return (object) array_merge([
            'modtype' => 'quiz',
            'schedulestart' => strtotime('2030-01-01 09:00'),
            'schedulefinish' => strtotime('2030-01-15 17:00'),
            'sessionlength' => 7,
            'activitiespersession' => 2,
            'closemode' => 'session',
            'closedays' => 7,
            'closedate' => 0,
            'duemode' => 'none',
            'duedays' => 7,
            'duedate' => 0,
            'hideunselected' => 0,
            'resetunselected' => 0,
            'activitygroup' => [],
        ], $over);
    }

    /**
     * The table's values as a teacher who accepts every proposal would submit them.
     *
     * @param array $tabledata rows from get_table_data().
     * @return array cmid => ['timeopen' => int, 'duedate' => ?int, 'timeclose' => int]
     */
    private function proposed_values(array $tabledata): array {
        $values = [];
        foreach ($tabledata as $row) {
            if (!$row['isheader'] && $row['selected'] === 'checked' && $row['scheduled']) {
                $values[(int) $row['id']] = $row['proposed'];
            }
        }
        return $values;
    }

    /**
     * The persisted selection as a list of cmids.
     *
     * @param array $selections update()'s selections.
     * @return int[]
     */
    private function cmids(array $selections): array {
        return array_map(fn($r) => (int) $r->coursemoduleid, $selections);
    }

    public function test_update_upserts_settings_and_selections(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $quiz1 = $generator->create_module('quiz', ['course' => $course->id]);
        $quiz2 = $generator->create_module('quiz', ['course' => $course->id]);
        $quiz3 = $generator->create_module('quiz', ['course' => $course->id]);

        $fromform = $this->fromform([
            'closemode' => 'days',
            'closedays' => 3,
            'hideunselected' => 1,
            'activitygroup' => [
                'activity_' . $quiz1->cmid => 1,
                'activity_' . $quiz2->cmid => 1,
            ],
        ]);

        $manager = new activitydates();
        [$selections, $settings] = $manager->update($fromform, $course->id);

        // Use assertEquals for values that round-trip through the DB layer: the
        // mariadb native driver returns numeric columns as strings on fetch.
        $this->assertEquals($course->id, $settings->courseid);
        $this->assertSame('quiz', $settings->modtype);
        $this->assertSame($fromform->schedulestart, $settings->schedulestart);
        $this->assertSame($fromform->schedulefinish, $settings->schedulefinish);
        $this->assertSame(1, $settings->finishenabled);
        $this->assertSame(7, $settings->sessionlength);
        $this->assertSame(2, $settings->activitiespersession);
        $this->assertSame('days', $settings->closemode);
        $this->assertSame(3, $settings->closedays);
        $this->assertSame('none', $settings->duemode);
        $this->assertSame(1, $settings->hideunselected);
        $this->assertSame(0, $settings->resetunselected);
        $this->assertObjectNotHasProperty('stayavailable', $settings);
        $this->assertGreaterThan(0, $settings->id);
        $this->assertGreaterThan(0, $settings->timemodified);
        $this->assertCount(2, $selections);

        // Every new field is persisted.
        $record = $DB->get_record('tool_activitydates', ['id' => $settings->id], '*', MUST_EXIST);
        $this->assertEquals(1, $record->finishenabled);
        $this->assertSame('days', $record->closemode);
        $this->assertEquals(3, $record->closedays);
        $this->assertSame('none', $record->duemode);

        // Calling update() again with the same courseid must update, not duplicate.
        $fromform2 = clone $fromform;
        $fromform2->schedulefinish = 0;
        $fromform2->activitygroup = [
            'activity_' . $quiz3->cmid => 1,
        ];
        [$selections2, $settings2] = $manager->update($fromform2, $course->id);
        $this->assertEquals($settings->id, $settings2->id);
        $this->assertCount(1, $selections2);
        $this->assertCount(1, $DB->get_records('tool_activitydates', ['courseid' => $course->id]));
        // A disabled finish date (the optional selector submits 0) disables the cap.
        $this->assertEquals(0, $DB->get_field('tool_activitydates', 'finishenabled', ['id' => $settings->id]));
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
            'finishenabled' => 1,
            'closemode' => 'session',
            'closedays' => 7,
            'closedate' => 0,
            'duemode' => 'none',
            'duedays' => 7,
            'duedate' => 0,
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

    public function test_get_table_data_sessions_and_quirk_fix(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $quizzes = [];
        for ($i = 1; $i <= 4; $i++) {
            $quizzes[] = $generator->create_module('quiz', [
                'course' => $course->id,
                'name' => 'Quiz' . $i,
                'intro' => '<p>Description of <strong>Quiz' . $i . '</strong></p>',
            ]);
        }

        // Session 1 = quiz1,quiz2. Session 2 = quiz3,quiz4.
        // Deliberately leave quiz1 (session 1's first activity) unselected.
        $fromform = $this->fromform([
            'activitygroup' => [
                'activity_' . $quizzes[1]->cmid => 1, // Quiz2.
                'activity_' . $quizzes[2]->cmid => 1, // Quiz3.
                'activity_' . $quizzes[3]->cmid => 1, // Quiz4.
            ],
        ]);

        $manager = new activitydates();
        [$selections, $settings] = $manager->update($fromform, $course->id);

        $tabledata = $manager->get_table_data($settings, $this->cmids($selections));

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
        // Behaviour change: a session now ends when the next one starts (it used to
        // end the day before, at the finish date's time of day).
        $this->assertSame($headers[1]['dates']['start'], $headers[0]['dates']['end']);

        // Windows carry pre-formatted display strings for the template.
        $this->assertStringContainsString('1 Jan 2030', $headers[0]['dates']['startformatted']);
        $this->assertSame('2030-01-01T09:00', $headers[0]['dates']['startattr']);
        $this->assertStringContainsString('8 Jan 2030', $headers[1]['dates']['startformatted']);
        $this->assertNotEmpty($headers[0]['dates']['endformatted']);
        $this->assertSame('2030-01-08T09:00', $headers[0]['dates']['endattr']);

        // Data rows: exact key shape and selected flags.
        $datarows = array_values(array_filter($tabledata, fn($row) => !$row['isheader']));
        $this->assertCount(4, $datarows);
        foreach ($datarows as $row) {
            foreach (
                ['cm', 'id', 'name', 'intro', 'selected', 'questioncount', 'dates', 'timeopen',
                    'timeopenformatted', 'timeopenattr', 'timeclose', 'timecloseformatted',
                    'timecloseattr', 'scheduled', 'proposed', 'status', 'duedate'] as $key
            ) {
                $this->assertArrayHasKey($key, $row);
            }
        }

        $byname = [];
        foreach ($datarows as $row) {
            $byname[$row['name']] = $row;
        }
        // The description is plain text for the template: HTML tags are stripped.
        $this->assertSame('Description of Quiz1', $byname['Quiz1']['intro']);

        $this->assertSame('', $byname['Quiz1']['selected']);
        $this->assertSame('checked', $byname['Quiz2']['selected']);
        $this->assertSame('checked', $byname['Quiz3']['selected']);
        $this->assertSame('checked', $byname['Quiz4']['selected']);

        // Quiz1 and Quiz2 both belong to session 1's window (chunked by activitiespersession).
        $this->assertSame($headers[0]['dates'], $byname['Quiz1']['dates']);
        $this->assertSame($headers[0]['dates'], $byname['Quiz2']['dates']);
        $this->assertSame($headers[1]['dates'], $byname['Quiz3']['dates']);
        $this->assertSame($headers[1]['dates'], $byname['Quiz4']['dates']);

        // Proposals: unselected quiz1 is not scheduled; the others open with their
        // session and (session mode) close when the next session starts.
        $this->assertFalse($byname['Quiz1']['scheduled']);
        $this->assertNull($byname['Quiz1']['proposed']);
        $this->assertSame('rowstatus_notscheduled', $byname['Quiz1']['status']);
        $this->assertTrue($byname['Quiz2']['scheduled']);
        $this->assertSame('', $byname['Quiz2']['status']);
        $this->assertSame([
            'timeopen' => strtotime('2030-01-01 09:00'),
            // Due mode none: no due date where the column exists, null without it.
            'duedate' => activitydates::has_duedate('quiz') ? 0 : null,
            'timeclose' => strtotime('2030-01-08 09:00'),
        ], $byname['Quiz2']['proposed']);
        $this->assertSame(strtotime('2030-01-15 09:00'), $byname['Quiz4']['proposed']['timeclose']);

        // The current due date is null exactly when the type has no duedate column.
        $this->assertSame(activitydates::has_duedate('quiz'), $byname['Quiz2']['duedate'] !== null);
    }

    public function test_get_table_data_unselected_status(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $quiz1 = $generator->create_module('quiz', ['course' => $course->id, 'name' => 'Quiz1']);
        $quiz2 = $generator->create_module('quiz', ['course' => $course->id, 'name' => 'Quiz2']);
        $manager = new activitydates();
        $selected = [(int) $quiz1->cmid];

        foreach (
            [
                [0, 0, 'rowstatus_notscheduled'],
                [1, 0, 'rowstatus_hidden'],
                [0, 1, 'rowstatus_reset'],
                [1, 1, 'rowstatus_reset'],
            ] as [$hide, $reset, $expected]
        ) {
            $settings = $manager->settings_from_form(
                $this->fromform(['hideunselected' => $hide, 'resetunselected' => $reset]),
                $course->id,
                0
            );
            $rows = array_values(array_filter(
                $manager->get_table_data($settings, $selected),
                fn($row) => !$row['isheader']
            ));
            $this->assertSame('', $rows[0]['status']);
            $this->assertSame($expected, $rows[1]['status'], "hide $hide reset $reset");
        }
    }

    /**
     * Activities inside a subsection are listed, and grouped into sessions,
     * where the subsection sits on the course page. Its content lives in a
     * delegated section numbered after all listed sections, so walking
     * sections by number would put it at the very end of the course.
     */
    public function test_get_modules_subsection_order(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['numsections' => 2], ['createsections' => true]);

        // Section 1: quiz1, then the subsection (holding quiz2), then quiz3. Section 2: quiz4.
        $quiz1 = $generator->create_module('quiz', ['course' => $course->id, 'section' => 1]);
        $subsection = $generator->create_module('subsection', ['course' => $course->id, 'section' => 1]);
        $delegated = get_fast_modinfo($course->id)->get_section_info_by_component('mod_subsection', $subsection->id);
        $quiz2 = $generator->create_module('quiz', ['course' => $course->id, 'section' => $delegated->section]);
        $quiz3 = $generator->create_module('quiz', ['course' => $course->id, 'section' => 1]);
        $quiz4 = $generator->create_module('quiz', ['course' => $course->id, 'section' => 2]);
        $expected = array_map('intval', [$quiz1->cmid, $quiz2->cmid, $quiz3->cmid, $quiz4->cmid]);

        $fromform = $this->fromform([
            'schedulefinish' => strtotime('2030-01-31 17:00'),
            'activitygroup' => [
                'activity_' . $quiz1->cmid => 1,
                'activity_' . $quiz2->cmid => 1,
                'activity_' . $quiz3->cmid => 1,
                'activity_' . $quiz4->cmid => 1,
            ],
        ]);
        $manager = new activitydates();
        [$selections, $settings] = $manager->update($fromform, $course->id);

        $this->assertSame($expected, array_map('intval', array_keys(activitydates::get_modules($settings))));

        // Sessions are chunked in that order: quiz1 + quiz2 open together in session 1.
        $datarows = array_values(array_filter(
            $manager->get_table_data($settings, $this->cmids($selections)),
            fn($row) => !$row['isheader']
        ));
        $this->assertSame($expected, array_map('intval', array_column($datarows, 'id')));
        $this->assertSame(1, $datarows[1]['dates']['sessionnumber']);
        $this->assertSame(2, $datarows[2]['dates']['sessionnumber']);
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
        $fromform = $this->fromform();
        foreach ($quizzes as $quiz) {
            $fromform->activitygroup['activity_' . $quiz->cmid] = 1;
        }

        $manager = new activitydates();
        [$selections, $settings] = $manager->update($fromform, $course->id);
        $this->assertCount(4, $selections);

        $tabledata = $manager->get_table_data($settings, $this->cmids($selections));
        $count = $manager->apply_dates($tabledata, $settings, $this->proposed_values($tabledata));
        $this->assertSame(4, $count);

        // Session 1 = quizzes 1-2, session 2 = quizzes 3-4. Behaviour change: a
        // session closes when the next one opens (it used to close the day before,
        // at the finish date's time of day).
        $expectedopen  = [$start, $start, strtotime('2030-01-08 09:00'), strtotime('2030-01-08 09:00')];
        $expectedclose = [strtotime('2030-01-08 09:00'), strtotime('2030-01-08 09:00'),
                          strtotime('2030-01-15 09:00'), strtotime('2030-01-15 09:00')];
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

    public function test_apply_dates_close_none(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        // Pre-set a close date to prove close mode "none" overwrites it with 0.
        $quiz = $generator->create_module('quiz', ['course' => $course->id,
            'timeopen' => strtotime('2029-01-01'), 'timeclose' => strtotime('2029-02-01')]);
        $fromform = $this->fromform([
            'activitiespersession' => 1,
            'closemode' => 'none',
            'activitygroup' => ['activity_' . $quiz->cmid => 1],
        ]);
        $manager = new activitydates();
        [$selections, $settings] = $manager->update($fromform, $course->id);
        $tabledata = $manager->get_table_data($settings, $this->cmids($selections));
        $manager->apply_dates($tabledata, $settings, $this->proposed_values($tabledata));

        $record = $DB->get_record('quiz', ['id' => $quiz->id], '*', MUST_EXIST);
        $this->assertEquals(strtotime('2030-01-01 09:00'), $record->timeopen);
        $this->assertEquals(0, $record->timeclose);
        $this->assertCount(0, $DB->get_records(
            'event',
            ['modulename' => 'quiz', 'instance' => $quiz->id, 'eventtype' => 'close']
        ));
    }

    public function test_apply_writes_exact_values(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $quiz = $generator->create_module('quiz', ['course' => $course->id]);
        $fromform = $this->fromform(['activitygroup' => ['activity_' . $quiz->cmid => 1]]);
        $manager = new activitydates();
        [$selections, $settings] = $manager->update($fromform, $course->id);
        $tabledata = $manager->get_table_data($settings, $this->cmids($selections));

        // The teacher edited both dates in the table: they differ from the proposal.
        $open = strtotime('2030-03-03 10:30');
        $close = strtotime('2030-03-20 16:45');
        $proposed = $this->proposed_values($tabledata)[(int) $quiz->cmid];
        $this->assertNotEquals($open, $proposed['timeopen']);
        $this->assertNotEquals($close, $proposed['timeclose']);

        $count = $manager->apply_dates($tabledata, $settings, [
            (int) $quiz->cmid => ['timeopen' => $open, 'duedate' => null, 'timeclose' => $close],
        ]);
        $this->assertSame(1, $count);
        $record = $DB->get_record('quiz', ['id' => $quiz->id], '*', MUST_EXIST);
        $this->assertEquals($open, $record->timeopen);
        $this->assertEquals($close, $record->timeclose);
        $this->assertEquals($close, $DB->get_field(
            'event',
            'timestart',
            ['modulename' => 'quiz', 'instance' => $quiz->id, 'eventtype' => 'close']
        ));
    }

    /**
     * Forged table values for an unselected cm, a selected cm past the finish cap
     * or another course's cm are never written (review focus 2), and a selected,
     * scheduled cm without a value keeps its dates.
     */
    public function test_apply_ignores_values_for_unselected_rows(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $other = $generator->create_course();
        // Two quizzes per session. Session 1: quiz1 (selected, value) and quiz2
        // (selected, no value). Session 2 starts after the finish date: quiz3
        // (unselected) and quiz4 (selected).
        $oldopen = strtotime('2029-01-01 09:00');
        $oldclose = strtotime('2029-02-01 09:00');
        $quiz1 = $generator->create_module('quiz', ['course' => $course->id, 'name' => 'Quiz1']);
        $quiz2 = $generator->create_module('quiz', ['course' => $course->id, 'name' => 'Quiz2',
            'timeopen' => $oldopen, 'timeclose' => $oldclose]);
        $quiz3 = $generator->create_module('quiz', ['course' => $course->id, 'name' => 'Quiz3']);
        $quiz4 = $generator->create_module('quiz', ['course' => $course->id, 'name' => 'Quiz4']);
        $foreign = $generator->create_module('quiz', ['course' => $other->id, 'name' => 'Foreign']);
        $fromform = $this->fromform([
            'schedulefinish' => strtotime('2030-01-05 17:00'),
            'activitygroup' => [
                'activity_' . $quiz1->cmid => 1,
                'activity_' . $quiz2->cmid => 1,
                'activity_' . $quiz4->cmid => 1,
            ],
        ]);
        $manager = new activitydates();
        [$selections, $settings] = $manager->update($fromform, $course->id);
        $tabledata = $manager->get_table_data($settings, $this->cmids($selections));

        $open = strtotime('2030-02-01 09:00');
        $close = strtotime('2030-02-08 09:00');
        $value = ['timeopen' => $open, 'duedate' => null, 'timeclose' => $close];
        $count = $manager->apply_dates($tabledata, $settings, [
            (int) $quiz1->cmid => $value,
            (int) $quiz3->cmid => $value,
            (int) $quiz4->cmid => $value,
            (int) $foreign->cmid => $value,
        ]);

        $this->assertSame(1, $count);
        $this->assertEquals($open, $DB->get_field('quiz', 'timeopen', ['id' => $quiz1->id]));
        $record2 = $DB->get_record('quiz', ['id' => $quiz2->id], '*', MUST_EXIST);
        $this->assertEquals($oldopen, $record2->timeopen);
        $this->assertEquals($oldclose, $record2->timeclose);
        foreach ([$quiz3, $quiz4, $foreign] as $quiz) {
            $record = $DB->get_record('quiz', ['id' => $quiz->id], '*', MUST_EXIST);
            $this->assertEquals(0, $record->timeopen, $quiz->name);
            $this->assertEquals(0, $record->timeclose, $quiz->name);
        }
    }

    /**
     * The due date is written, and reset, only where the type has the column
     * (quiz on 5.3+). Without it the write must still succeed.
     */
    public function test_duedate_written_only_with_column(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $quiz = $generator->create_module('quiz', ['course' => $course->id]);
        $hasdue = activitydates::has_duedate('quiz');
        $this->assertSame(isset($DB->get_columns('quiz')['duedate']), $hasdue);

        $fromform = $this->fromform([
            'duemode' => 'days',
            'duedays' => 2,
            'activitygroup' => ['activity_' . $quiz->cmid => 1],
        ]);
        $manager = new activitydates();
        [$selections, $settings] = $manager->update($fromform, $course->id);
        $tabledata = $manager->get_table_data($settings, $this->cmids($selections));
        $values = $this->proposed_values($tabledata);
        $open = strtotime('2030-01-01 09:00');
        $due = strtotime('2030-01-03 09:00');
        if ($hasdue) {
            $this->assertSame($due, $values[(int) $quiz->cmid]['duedate']);
        } else {
            $this->assertNull($values[(int) $quiz->cmid]['duedate']);
            // A due date in the values must not reach a table without the column.
            $values[(int) $quiz->cmid]['duedate'] = $due;
        }

        $this->assertSame(1, $manager->apply_dates($tabledata, $settings, $values));
        $record = $DB->get_record('quiz', ['id' => $quiz->id], '*', MUST_EXIST);
        $this->assertEquals($open, $record->timeopen);
        if ($hasdue) {
            $this->assertEquals($due, $record->duedate);
        } else {
            $this->assertObjectNotHasProperty('duedate', $record);
        }

        // Reset the now-unselected quiz: the due date is zeroed too.
        $fromform->activitygroup = [];
        $fromform->resetunselected = 1;
        [$selections, $settings] = $manager->update($fromform, $course->id);
        $tabledata = $manager->get_table_data($settings, $this->cmids($selections));
        $this->assertSame(0, $manager->apply_dates($tabledata, $settings, []));
        $record = $DB->get_record('quiz', ['id' => $quiz->id], '*', MUST_EXIST);
        $this->assertEquals(0, $record->timeopen);
        if ($hasdue) {
            $this->assertEquals(0, $record->duedate);
        }
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
        $fromform = $this->fromform([
            'schedulefinish' => strtotime('2030-01-05 17:00'),
            'activitiespersession' => 1,
            'activitygroup' => [
                'activity_' . $quiz1->cmid => 1,
                'activity_' . $quiz2->cmid => 1,
            ],
        ]);

        $manager = new activitydates();
        [$selections, $settings] = $manager->update($fromform, $course->id);
        $tabledata = $manager->get_table_data($settings, $this->cmids($selections));
        $count = $manager->apply_dates($tabledata, $settings, $this->proposed_values($tabledata));

        // Only quiz1's window (start = schedulestart) is within schedulefinish.
        $this->assertSame(1, $count);

        // Behaviour change: the session closes when the next one would open.
        $record1 = $DB->get_record('quiz', ['id' => $quiz1->id], '*', MUST_EXIST);
        $this->assertEquals($start, $record1->timeopen);
        $this->assertEquals(strtotime('2030-01-08 09:00'), $record1->timeclose);
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

    public function test_get_table_data_null_when_past_schedulefinish(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        // One quiz per session (activitiespersession = 1): quiz1's session starts on
        // schedulestart, quiz2's session starts 7 days later. schedulefinish sits
        // between the two, so quiz2's window start exceeds schedulefinish and
        // apply_dates() would skip it entirely.
        $quiz1 = $generator->create_module('quiz', ['course' => $course->id, 'name' => 'Quiz1']);
        $quiz2 = $generator->create_module('quiz', ['course' => $course->id, 'name' => 'Quiz2']);
        $start = strtotime('2030-01-01 09:00');
        $fromform = $this->fromform([
            'schedulefinish' => strtotime('2030-01-05 17:00'),
            'activitiespersession' => 1,
            'activitygroup' => [
                'activity_' . $quiz1->cmid => 1,
                'activity_' . $quiz2->cmid => 1,
            ],
        ]);

        $manager = new activitydates();
        [$selections, $settings] = $manager->update($fromform, $course->id);
        $tabledata = $manager->get_table_data($settings, $this->cmids($selections));

        $headers = array_values(array_filter($tabledata, fn($row) => $row['isheader']));
        $this->assertCount(2, $headers);

        // Session 1's window (start = schedulestart) is within schedulefinish: real window.
        $this->assertNotNull($headers[0]['dates']);
        $this->assertSame($start, $headers[0]['dates']['start']);

        // Session 2's window start (2030-01-08 09:00) exceeds schedulefinish: the
        // preview must not promise a window that apply_dates() will then skip.
        $this->assertNull($headers[1]['dates']);

        $datarows = array_values(array_filter($tabledata, fn($row) => !$row['isheader']));
        $byname = [];
        foreach ($datarows as $row) {
            $byname[$row['name']] = $row;
        }
        $this->assertNull($byname['Quiz2']['dates']);
        $this->assertFalse($byname['Quiz2']['scheduled']);
        $this->assertNull($byname['Quiz2']['proposed']);
        $this->assertSame('rowstatus_notscheduled', $byname['Quiz2']['status']);

        // With the finish date disabled there is no cap.
        $settings->finishenabled = 0;
        $tabledata = $manager->get_table_data($settings, $this->cmids($selections));
        $headers = array_values(array_filter($tabledata, fn($row) => $row['isheader']));
        $this->assertSame(strtotime('2030-01-08 09:00'), $headers[1]['dates']['start']);
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
        $seedform = $this->fromform([
            'schedulestart' => $start,
            'schedulefinish' => $finish,
            'activitygroup' => [
                'activity_' . $quiz1->cmid => 1,
                'activity_' . $quiz2->cmid => 1,
            ],
        ]);
        $manager = new activitydates();
        [$seedselections, $seedsettings] = $manager->update($seedform, $course->id);
        $seedtable = $manager->get_table_data($seedsettings, $this->cmids($seedselections));
        $manager->apply_dates($seedtable, $seedsettings, $this->proposed_values($seedtable));

        // Behaviour change: the session closes when the next one would open.
        $expectedopen = $start;
        $expectedclose = strtotime('2030-02-08 09:00');
        $this->assertEquals(
            $expectedopen,
            $DB->get_record('quiz', ['id' => $quiz2->id], '*', MUST_EXIST)->timeopen
        );
        $this->assertCount(1, $DB->get_records(
            'event',
            ['modulename' => 'quiz', 'instance' => $quiz2->id, 'eventtype' => 'open']
        ));

        // Pass 2: keep only quiz1 selected, with hideunselected and resetunselected on.
        $fromform = $this->fromform([
            'schedulestart' => $start,
            'schedulefinish' => $finish,
            'hideunselected' => 1,
            'resetunselected' => 1,
            'activitygroup' => [
                'activity_' . $quiz1->cmid => 1,
            ],
        ]);
        [$selections, $settings] = $manager->update($fromform, $course->id);
        $tabledata = $manager->get_table_data($settings, $this->cmids($selections));
        $manager->apply_dates($tabledata, $settings, $this->proposed_values($tabledata));

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

    public function test_settings_from_form_defaults_and_modes(): void {
        $this->resetAfterTest();
        $manager = new activitydates();

        // Valid modes pass through; every field is cast; nothing is persisted.
        $settings = $manager->settings_from_form($this->fromform([
            'sessionlength' => '5',
            'closemode' => 'days',
            'closedays' => '4',
            'duemode' => 'date',
            'duedate' => (string) strtotime('2030-02-01 09:00'),
        ]), 42, 7);
        $this->assertSame(7, $settings->id);
        $this->assertSame(42, $settings->courseid);
        $this->assertSame('quiz', $settings->modtype);
        $this->assertSame(5, $settings->sessionlength);
        $this->assertSame(1, $settings->finishenabled);
        $this->assertSame(strtotime('2030-01-15 17:00'), $settings->schedulefinish);
        $this->assertSame('days', $settings->closemode);
        $this->assertSame(4, $settings->closedays);
        $this->assertSame('date', $settings->duemode);
        $this->assertSame(strtotime('2030-02-01 09:00'), $settings->duedate);
        $this->assertSame(0, $settings->hideunselected);

        // Invalid modes fall back; a disabled finish (0) disables the cap.
        $settings = $manager->settings_from_form($this->fromform([
            'schedulefinish' => 0,
            'closemode' => 'bogus',
            'duemode' => 'bogus',
        ]), 42, 0);
        $this->assertSame(0, $settings->finishenabled);
        $this->assertSame(0, $settings->schedulefinish);
        $this->assertSame('session', $settings->closemode);
        $this->assertSame('none', $settings->duemode);

        // Absent due fields (a type without a duedate column) get the defaults.
        $fromform = $this->fromform();
        unset($fromform->duemode, $fromform->duedays, $fromform->duedate);
        $settings = $manager->settings_from_form($fromform, 42, 0);
        $this->assertSame('none', $settings->duemode);
        $this->assertSame(7, $settings->duedays);
        $this->assertSame(0, $settings->duedate);
        $this->assertObjectNotHasProperty('stayavailable', $settings);
    }

    public function test_selected_from_form_drops_foreign_cmids(): void {
        $manager = new activitydates();
        $fromform = (object) ['activitygroup' => [
            'activity_10' => 1,
            'activity_20' => 0,
            'activity_30' => '1',
            'activity_999' => 1,
            'bogus_40' => 1,
        ]];
        $this->assertSame([10, 30], $manager->selected_from_form($fromform, [10, 20, 30, 40]));
        $this->assertSame([], $manager->selected_from_form((object) [], [10, 20]));
    }
}
