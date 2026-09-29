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
     * Two quizzes, one per session, with the finish date between the sessions.
     *
     * Quiz1's session starts on schedulestart and quiz2's 7 days later, after
     * schedulefinish, so quiz2 is not scheduled. Settings and selection are saved.
     *
     * @return array [quiz1, quiz2, schedulestart, manager, settings, tabledata, selections]
     */
    private function two_sessions_past_finish(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $quiz1 = $generator->create_module('quiz', ['course' => $course->id, 'name' => 'Quiz1']);
        $quiz2 = $generator->create_module('quiz', ['course' => $course->id, 'name' => 'Quiz2']);
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
        return [$quiz1, $quiz2, strtotime('2030-01-01 09:00'), $manager, $settings, $tabledata, $selections];
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
            // No lock mode in the settings: no lock date.
            'timelock' => null,
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
        $generator->create_module('quiz', ['course' => $course->id, 'name' => 'Quiz2']);
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

        // The table's current column reads the written due date back.
        $rows = array_values(array_filter(
            $manager->get_table_data($settings, $this->cmids($selections)),
            fn($row) => !$row['isheader']
        ));
        if ($hasdue) {
            $this->assertSame($due, $rows[0]['duedate']);
            $this->assertSame(userdate($due, get_string('dateformat', 'tool_activitydates')), $rows[0]['duedateformatted']);
            $this->assertNotSame('', $rows[0]['duedateattr']);
        } else {
            $this->assertNull($rows[0]['duedate']);
            $this->assertSame('', $rows[0]['duedateformatted']);
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
        [$quiz1, $quiz2, $start, $manager, $settings, $tabledata] = $this->two_sessions_past_finish();
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
        [, , $start, $manager, $settings, $tabledata, $selections] = $this->two_sessions_past_finish();

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

    public function test_saved_selection_drops_invalid_cmids(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $quiz1 = $generator->create_module('quiz', ['course' => $course->id]);
        $quiz2 = $generator->create_module('quiz', ['course' => $course->id]);
        $quiz3 = $generator->create_module('quiz', ['course' => $course->id]);
        $assign = $generator->create_module('assign', ['course' => $course->id]);

        $manager = new activitydates();
        [, $settings] = $manager->update($this->fromform(['activitygroup' => [
            'activity_' . $quiz1->cmid => 1,
            'activity_' . $quiz2->cmid => 1,
            'activity_' . $quiz3->cmid => 1,
        ]]), $course->id);
        // Leftover rows no longer valid for the displayed type: a cm that no
        // longer exists, and a cm of another type.
        foreach ([999999, (int) $assign->cmid] as $cmid) {
            $DB->insert_record('tool_activitydates_cmids', (object) [
                'activitydates' => $settings->id,
                'coursemoduleid' => $cmid,
            ]);
        }
        // A cm being deleted (recycle bin) is not valid either.
        $DB->set_field('course_modules', 'deletioninprogress', 1, ['id' => $quiz2->cmid]);
        \rebuild_course_cache($course->id, true);

        $validcmids = array_keys(activitydates::get_modules($settings));
        $saved = activitydates::saved_selection((int) $settings->id, $validcmids);
        $this->assertSame([(int) $quiz1->cmid, (int) $quiz3->cmid], $saved);

        // Saving the page straight after load posts the same ticks back, so the
        // GET selection must fingerprint the same as the POST selection.
        $fromform = $this->fromform(['activitygroup' => array_fill_keys(
            array_map(fn($cmid) => 'activity_' . $cmid, $saved),
            1
        )]);
        $this->assertSame(
            local\fingerprint::dates($settings, $saved, false),
            local\fingerprint::dates($settings, activitydates::selected_from_form($fromform, $validcmids), false)
        );
        $this->assertSame([], activitydates::saved_selection(0, $validcmids));
    }

    /**
     * A course with graded quizzes.
     *
     * @param int $count the number of quizzes.
     * @return array [course, quizzes]
     */
    private function graded_quizzes(int $count): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $quizzes = [];
        for ($i = 1; $i <= $count; $i++) {
            $quizzes[] = $generator->create_module('quiz', ['course' => $course->id, 'grade' => 100, 'name' => "Quiz$i"]);
        }
        return [$course, $quizzes];
    }

    /**
     * The locktime of an activity's grade item.
     *
     * @param int $courseid the course id.
     * @param string $modname the module name.
     * @param int $instanceid the module instance id.
     * @return int
     */
    private function locktime(int $courseid, string $modname, int $instanceid): int {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $item = \grade_item::fetch(['courseid' => $courseid, 'itemtype' => 'mod', 'itemmodule' => $modname,
            'iteminstance' => $instanceid]);
        $this->assertNotFalse($item);
        return (int) $item->get_locktime();
    }

    /**
     * The activitygroup checkboxes ticking the given cms.
     *
     * @param array $cms module records with a cmid.
     * @return array
     */
    private function ticks(array $cms): array {
        return array_fill_keys(array_map(fn($cm) => 'activity_' . $cm->cmid, $cms), 1);
    }

    /**
     * Lock mode "No lock" leaves the existing grade locks of the selected rows alone.
     */
    public function test_lockmode_none_leaves_locks(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, [$quiz1, $quiz2]] = $this->graded_quizzes(2);
        $existing = strtotime('2031-01-01 09:00');
        (new locks\manager())->apply_locks([$quiz1->cmid => $existing, $quiz2->cmid => $existing], 'quiz', $course->id, false);

        $fromform = $this->fromform(['lockmode' => 'none', 'activitygroup' => $this->ticks([$quiz1, $quiz2])]);
        $manager = new activitydates();
        $settings = activitydates::settings_from_form($fromform, $course->id, 0);
        $tabledata = $manager->get_table_data($settings, [$quiz1->cmid, $quiz2->cmid]);
        // In none mode the table proposes no lock date (and posts none).
        $values = $this->proposed_values($tabledata);
        $this->assertNull($values[$quiz1->cmid]['timelock']);

        $result = $manager->save($fromform, $course->id, $tabledata, $values, [], [], [], true, true, true);

        $this->assertSame(0, $result['locks']);
        $this->assertSame(2, $result['dates']);
        $this->assertSame($existing, $this->locktime($course->id, 'quiz', $quiz1->id));
        $this->assertSame($existing, $this->locktime($course->id, 'quiz', $quiz2->id));
        // The lock configuration and selection are still saved.
        $lock = $DB->get_record('tool_activitydates_lock', ['courseid' => $course->id], '*', MUST_EXIST);
        $this->assertSame('none', $lock->lockmode);
        $this->assertSame(2, $DB->count_records('tool_activitydates_lockitem', ['lockid' => $lock->id]));
    }

    /**
     * Each part of a Save is written only with its capability, whatever the submission holds.
     */
    public function test_apply_respects_capabilities(): void {
        global $DB;
        $this->resetAfterTest();
        $manager = new activitydates();
        $oldopen = strtotime('2029-06-01 09:00');
        $oldclose = strtotime('2029-06-08 09:00');
        $oldlock = strtotime('2029-07-01 09:00');

        // A :managelocks-only user posting dates, hide and reset: no activity date or visibility changes.
        [$course, [$quiz1, $quiz2]] = $this->graded_quizzes(2);
        foreach ([$quiz1, $quiz2] as $quiz) {
            $DB->update_record('quiz', (object) ['id' => $quiz->id, 'timeopen' => $oldopen, 'timeclose' => $oldclose]);
        }
        $fromform = $this->fromform([
            'hideunselected' => 1,
            'resetunselected' => 1,
            'lockmode' => 'session',
            'activitygroup' => $this->ticks([$quiz1]),
        ]);
        $settings = activitydates::settings_from_form($fromform, $course->id, 0);
        $tabledata = $manager->get_table_data($settings, [$quiz1->cmid]);
        $values = $this->proposed_values($tabledata);
        $this->assertNotEquals($oldopen, $values[$quiz1->cmid]['timeopen']);

        $result = $manager->save($fromform, $course->id, $tabledata, $values, [$quiz1->cmid], [], [], false, true, true);

        $this->assertSame(0, $result['dates']);
        foreach ([$quiz1, $quiz2] as $quiz) {
            $record = $DB->get_record('quiz', ['id' => $quiz->id]);
            $this->assertEquals($oldopen, $record->timeopen);
            $this->assertEquals($oldclose, $record->timeclose);
            $this->assertEquals(1, $DB->get_field('course_modules', 'visible', ['id' => $quiz->cmid]));
        }
        $this->assertFalse($DB->record_exists('tool_activitydates', ['courseid' => $course->id]));
        $this->assertSame(0, $DB->count_records('tool_activitydates_cmids'));
        // The lock part is written.
        $this->assertSame(1, $result['locks']);
        $this->assertSame(strtotime('2030-01-08 09:00'), $this->locktime($course->id, 'quiz', $quiz1->id));

        // A :manage-only user posting lock dates, notes and the lock reset: no grade item or lock item changes.
        [$course, [$quiz1, $quiz2]] = $this->graded_quizzes(2);
        (new locks\manager())->apply_locks([$quiz2->cmid => $oldlock], 'quiz', $course->id, false);
        $fromform = $this->fromform([
            'lockmode' => 'session',
            'lockresetunselected' => 1,
            'activitygroup' => $this->ticks([$quiz1]),
        ]);
        $settings = activitydates::settings_from_form($fromform, $course->id, 0);
        $tabledata = $manager->get_table_data($settings, [$quiz1->cmid]);
        $values = $this->proposed_values($tabledata);
        $this->assertSame(strtotime('2030-01-08 09:00'), $values[$quiz1->cmid]['timelock']);

        $result = $manager->save($fromform, $course->id, $tabledata, $values, [$quiz1->cmid], [], [], true, false, true);

        $this->assertSame(0, $result['locks']);
        $this->assertSame(0, $this->locktime($course->id, 'quiz', $quiz1->id));
        $this->assertSame($oldlock, $this->locktime($course->id, 'quiz', $quiz2->id));
        $this->assertFalse($DB->record_exists('tool_activitydates_lock', ['courseid' => $course->id]));
        // Only the first course's lock item exists.
        $this->assertSame(1, $DB->count_records('tool_activitydates_lockitem'));
        $this->assertFalse(
            $DB->record_exists_select('tool_activitydates_lockitem', 'cmid IN (?, ?)', [$quiz1->cmid, $quiz2->cmid])
        );
        // The dates part is written.
        $this->assertSame(1, $result['dates']);
        $this->assertEquals(strtotime('2030-01-01 09:00'), $DB->get_field('quiz', 'timeopen', ['id' => $quiz1->id]));
    }

    /**
     * A lock-only type (assign has no timeopen/timeclose) never gets date writes, even when
     * the submission holds dates; its lock is written.
     */
    public function test_lock_only_type_ignores_date_inputs(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $assign1 = $generator->create_module('assign', ['course' => $course->id, 'duedate' => strtotime('2029-05-01 09:00')]);
        $assign2 = $generator->create_module('assign', ['course' => $course->id]);
        // The premise: assign has a duedate column (so a date write would change it) but no open/close.
        $this->assertTrue(activitydates::has_duedate('assign'));
        $this->assertFalse(modtypes::has_date_columns('assign'));
        $before = $DB->get_records('assign', ['course' => $course->id]);

        $fromform = $this->fromform([
            'modtype' => 'assign',
            'hideunselected' => 1,
            'resetunselected' => 1,
            'lockmode' => 'days',
            'lockdays' => 3,
            'activitygroup' => $this->ticks([$assign1]),
        ]);
        $manager = new activitydates();
        $settings = activitydates::settings_from_form($fromform, $course->id, 0);
        $tabledata = $manager->get_table_data($settings, [$assign1->cmid], false);
        $lock = strtotime('2030-01-04 09:00');
        $rows = array_values(array_filter($tabledata, fn($row) => !$row['isheader']));
        $this->assertSame(['timeopen' => null, 'duedate' => null, 'timeclose' => null, 'timelock' => $lock], $rows[0]['proposed']);
        $this->assertNull($rows[0]['duedate']);
        $this->assertTrue($rows[0]['hasgradeitem']);

        $forged = strtotime('2030-02-01 09:00');
        $values = [$assign1->cmid => ['timeopen' => $forged, 'duedate' => $forged, 'timeclose' => $forged, 'timelock' => $lock]];
        $result = $manager->save($fromform, $course->id, $tabledata, $values, [], [], [], true, true, false);

        $this->assertSame(['dates' => 0, 'locks' => 1], $result);
        $this->assertEquals($before, $DB->get_records('assign', ['course' => $course->id]));
        $this->assertEquals(1, $DB->get_field('course_modules', 'visible', ['id' => $assign2->cmid]));
        $this->assertSame($lock, $this->locktime($course->id, 'assign', $assign1->id));
    }

    /**
     * Save writes each selected row's lock value (an empty one clears the lock) and
     * the posted note ticks, and keeps the lock selection of other types.
     */
    public function test_save_writes_lock_and_notes(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, [$quiz1, $quiz2, $quiz3]] = $this->graded_quizzes(3);
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $lockid = $DB->insert_record('tool_activitydates_lock', (object) ['courseid' => $course->id]);
        $DB->insert_record('tool_activitydates_lockitem', (object) ['lockid' => $lockid, 'cmid' => $assign->cmid, 'shownote' => 1]);
        $lockmanager = new locks\manager();
        $lockmanager->apply_locks([$quiz3->cmid => strtotime('2031-01-01 09:00')], 'quiz', $course->id, false);

        $fromform = $this->fromform([
            'lockmode' => 'session',
            'activitygroup' => $this->ticks([$quiz1, $quiz2, $quiz3]),
        ]);
        $manager = new activitydates();
        $settings = activitydates::settings_from_form($fromform, $course->id, 0);
        $tabledata = $manager->get_table_data($settings, [$quiz1->cmid, $quiz2->cmid, $quiz3->cmid]);
        $values = $this->proposed_values($tabledata);
        $this->assertSame(strtotime('2030-01-08 09:00'), $values[$quiz1->cmid]['timelock']);
        $custom = strtotime('2030-02-01 10:00');
        $values[$quiz2->cmid]['timelock'] = $custom;
        $values[$quiz3->cmid]['timelock'] = 0;

        $sink = $this->redirectEvents();
        $result = $manager->save($fromform, $course->id, $tabledata, $values, [$quiz1->cmid], [$quiz2->cmid], [], true, true, true);
        $events = array_map(fn($event) => get_class($event), $sink->get_events());
        $sink->close();

        $this->assertSame(['dates' => 3, 'locks' => 3], $result);
        $this->assertContains(event\dates_updated::class, $events);
        $this->assertContains(event\locks_updated::class, $events);
        $this->assertSame(strtotime('2030-01-08 09:00'), $this->locktime($course->id, 'quiz', $quiz1->id));
        $this->assertSame($custom, $this->locktime($course->id, 'quiz', $quiz2->id));
        $this->assertSame(0, $this->locktime($course->id, 'quiz', $quiz3->id));

        $lock = $DB->get_record('tool_activitydates_lock', ['courseid' => $course->id], '*', MUST_EXIST);
        $this->assertEquals($lockid, $lock->id);
        $this->assertSame('session', $lock->lockmode);
        $this->assertEquals(0, $lock->resetunselected);
        $notes = $DB->get_records_menu('tool_activitydates_lockitem', ['lockid' => $lockid], '', 'cmid, shownote');
        $this->assertEquals([
            $assign->cmid => 1,
            $quiz1->cmid => 1,
            $quiz2->cmid => 0,
            $quiz3->cmid => 0,
        ], $notes);
        $coursenotes = $DB->get_records_menu('tool_activitydates_lockitem', ['lockid' => $lockid], '', 'cmid, shownotecoursepage');
        $this->assertEquals([
            $assign->cmid => 0,
            $quiz1->cmid => 0,
            $quiz2->cmid => 1,
            $quiz3->cmid => 0,
        ], $coursenotes);

        // The table shows the current lock state and the saved note settings.
        $rows = array_values(array_filter(
            $manager->get_table_data($settings, [$quiz1->cmid, $quiz2->cmid, $quiz3->cmid]),
            fn($row) => !$row['isheader']
        ));
        $this->assertSame([strtotime('2030-01-08 09:00'), $custom, 0], array_column($rows, 'locktime'));
        $this->assertSame([true, true, true], array_column($rows, 'hasgradeitem'));
        $this->assertSame([true, false, false], array_column($rows, 'shownote'));
        $this->assertSame([false, true, false], array_column($rows, 'shownotecoursepage'));
    }

    /**
     * An unselected row's lock is cleared with the lock reset option, whatever the lock mode;
     * a row without a grade item is skipped.
     */
    public function test_save_lock_reset_unselected(): void {
        $this->resetAfterTest();
        [$course, [$quiz1, $quiz2]] = $this->graded_quizzes(2);
        // A quiz with grade 0 has no grade item.
        $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'grade' => 0]);
        $existing = strtotime('2031-01-01 09:00');
        (new locks\manager())->apply_locks([$quiz1->cmid => $existing, $quiz2->cmid => $existing], 'quiz', $course->id, false);

        $fromform = $this->fromform([
            'lockmode' => 'none',
            'lockresetunselected' => 1,
            'activitygroup' => $this->ticks([$quiz1]),
        ]);
        $manager = new activitydates();
        $settings = activitydates::settings_from_form($fromform, $course->id, 0);
        $tabledata = $manager->get_table_data($settings, [$quiz1->cmid]);
        $rows = array_values(array_filter($tabledata, fn($row) => !$row['isheader']));
        $this->assertSame([true, true, false], array_column($rows, 'hasgradeitem'));

        $values = $this->proposed_values($tabledata);
        $result = $manager->save($fromform, $course->id, $tabledata, $values, [], [], [], false, true, true);

        $this->assertSame(1, $result['locks']);
        $this->assertSame($existing, $this->locktime($course->id, 'quiz', $quiz1->id));
        $this->assertSame(0, $this->locktime($course->id, 'quiz', $quiz2->id));
    }

    /**
     * settings_from_form() reads the lock fields when present, else the saved lock
     * configuration, else the site defaults.
     */
    public function test_settings_from_form_lock_fields(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $settings = activitydates::settings_from_form($this->fromform(), $course->id, 0);
        $this->assertSame(0, $settings->lockid);
        $this->assertSame('none', $settings->lockmode);
        $this->assertSame(7, $settings->lockdays);
        $this->assertSame(0, $settings->lockdate);
        $this->assertSame(0, $settings->lockresetunselected);
        // The note options are per row now, not settings.
        $this->assertFalse(property_exists($settings, 'shownote'));
        $this->assertFalse(property_exists($settings, 'shownotecoursepage'));

        $lockid = $DB->insert_record('tool_activitydates_lock', (object) [
            'courseid' => $course->id,
            'lockmode' => 'date',
            'lockdays' => 4,
            'lockdate' => 1900000000,
            'resetunselected' => 1,
        ]);
        $settings = activitydates::settings_from_form($this->fromform(), $course->id, 0);
        $this->assertSame((int) $lockid, $settings->lockid);
        $this->assertSame('date', $settings->lockmode);
        $this->assertSame(4, $settings->lockdays);
        $this->assertSame(1900000000, $settings->lockdate);
        $this->assertSame(1, $settings->lockresetunselected);

        $settings = activitydates::settings_from_form($this->fromform([
            'lockmode' => 'days',
            'lockdays' => '9',
            'lockdate' => '1900000060',
            'shownote' => '1',
            'shownotecoursepage' => '1',
            'lockresetunselected' => '0',
        ]), $course->id, 0);
        $this->assertSame('days', $settings->lockmode);
        $this->assertSame(9, $settings->lockdays);
        $this->assertSame(1900000060, $settings->lockdate);
        $this->assertSame(0, $settings->lockresetunselected);
        $this->assertFalse(property_exists($settings, 'shownote'));
        $this->assertFalse(property_exists($settings, 'shownotecoursepage'));

        // An unknown lock mode falls back to none.
        $settings = activitydates::settings_from_form($this->fromform(['lockmode' => 'bogus']), $course->id, 0);
        $this->assertSame('none', $settings->lockmode);
    }

    /**
     * load_settings() merges the dates row, the lock row and the defaults, floored to the minute.
     */
    public function test_load_settings_merges_lock_config(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        // Nothing saved: the defaults, finish off, lock mode none.
        $settings = activitydates::load_settings($course->id, 'choice');
        $this->assertSame(0, (int) $settings->id);
        $this->assertSame('choice', $settings->modtype);
        $this->assertSame(0, $settings->finishenabled);
        $this->assertSame(0, $settings->lockid);
        $this->assertSame('none', $settings->lockmode);
        $this->assertSame(7, $settings->lockdays);
        $this->assertSame(0, $settings->schedulestart % MINSECS);
        $this->assertSame(0, $settings->lockdate % MINSECS);
        $this->assertSame((int) $settings->schedulestart + 14 * DAYSECS, $settings->lockdate);

        // Both rows saved.
        [, $saved] = (new activitydates())->update($this->fromform([
            'schedulestart' => strtotime('2030-01-01 09:00') + 42,
            'closemode' => 'days',
            'closedays' => 4,
        ]), $course->id);
        $lockid = $DB->insert_record('tool_activitydates_lock', (object) [
            'courseid' => $course->id,
            'lockmode' => 'date',
            'lockdays' => 3,
            'lockdate' => strtotime('2030-03-01 10:00') + 42,
            'resetunselected' => 1,
        ]);
        $settings = activitydates::load_settings($course->id, 'quiz');
        $this->assertEquals($saved->id, $settings->id);
        $this->assertSame('quiz', $settings->modtype);
        $this->assertSame(strtotime('2030-01-01 09:00'), $settings->schedulestart);
        $this->assertSame(1, $settings->finishenabled);
        $this->assertSame(strtotime('2030-01-15 17:00'), $settings->schedulefinish);
        $this->assertSame('days', $settings->closemode);
        $this->assertSame(4, $settings->closedays);
        $this->assertSame((int) $lockid, $settings->lockid);
        $this->assertSame('date', $settings->lockmode);
        $this->assertSame(3, $settings->lockdays);
        $this->assertSame(strtotime('2030-03-01 10:00'), $settings->lockdate);
        $this->assertSame(1, $settings->lockresetunselected);

        // An unset lock date defaults to the finish date.
        $DB->set_field('tool_activitydates_lock', 'lockdate', 0, ['id' => $lockid]);
        $this->assertSame(strtotime('2030-01-15 17:00'), activitydates::load_settings($course->id, 'quiz')->lockdate);
    }

    /**
     * The saved Fix flags of a course, as sorted "cmid:field" strings.
     *
     * @param int $courseid the course id.
     * @return string[]
     */
    private function flags(int $courseid): array {
        global $DB;
        $flags = array_map(
            fn($record) => $record->cmid . ':' . $record->field,
            $DB->get_records('tool_activitydates_fixed', ['courseid' => $courseid])
        );
        sort($flags);
        return array_values($flags);
    }

    /**
     * Fix flags are stored only for the fields the user may set, only for the selected
     * cms of the course; other flags are kept.
     */
    public function test_fix_flags_capabilities_and_scope(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, [$quiz1, $quiz2]] = $this->graded_quizzes(2);
        [$other, [$otherquiz]] = $this->graded_quizzes(1);
        // A saved flag on the unselected quiz2.
        $DB->insert_record('tool_activitydates_fixed', (object) [
            'courseid' => $course->id,
            'cmid' => $quiz2->cmid,
            'field' => 'timeclose',
        ]);
        $manager = new activitydates();
        $fromform = $this->fromform(['lockmode' => 'session', 'activitygroup' => $this->ticks([$quiz1])]);
        $settings = activitydates::settings_from_form($fromform, $course->id, 0);
        $tabledata = $manager->get_table_data($settings, [$quiz1->cmid]);
        $values = $this->proposed_values($tabledata);
        $posted = [
            'timeopen' => [$quiz1->cmid => 1, $quiz2->cmid => 1, $otherquiz->cmid => 1],
            'timeclose' => [$otherquiz->cmid => 1],
            'timelock' => [$quiz1->cmid => 1, $otherquiz->cmid => 1],
        ];

        // A :manage-only user: the open flag only; no lock flag, no flag of an unselected or foreign cm.
        $manager->save($fromform, $course->id, $tabledata, $values, [], [], $posted, true, false, true);
        $this->assertSame([$quiz1->cmid . ':timeopen', $quiz2->cmid . ':timeclose'], $this->flags($course->id));
        $this->assertSame([], $this->flags($other->id));
        $this->assertSame(0, $DB->count_records_select('tool_activitydates_fixed', 'cmid = ?', [$otherquiz->cmid]));

        // A :managelocks-only user: the lock flag; the open flag is neither cleared nor added to.
        $posted = [
            'timeclose' => [$quiz1->cmid => 1],
            'timelock' => [$quiz1->cmid => 1, $otherquiz->cmid => 1],
        ];
        $manager->save($fromform, $course->id, $tabledata, $values, [], [], $posted, false, true, true);
        $this->assertSame(
            [$quiz1->cmid . ':timelock', $quiz1->cmid . ':timeopen', $quiz2->cmid . ':timeclose'],
            $this->flags($course->id)
        );
        $this->assertSame(0, $DB->count_records_select('tool_activitydates_fixed', 'cmid = ?', [$otherquiz->cmid]));

        // Unticking clears the flags the user may set.
        $manager->save($fromform, $course->id, $tabledata, $values, [], [], [], true, true, true);
        $this->assertSame([$quiz2->cmid . ':timeclose'], $this->flags($course->id));

        // The save_fixed() method itself ignores cmids that are not given as valid.
        $manager->save_fixed($course->id, [$quiz1->cmid], ['timeopen' => [$otherquiz->cmid => 1]], true, true);
        $this->assertSame([$quiz2->cmid . ':timeclose'], $this->flags($course->id));
        $this->assertSame([], $this->flags($other->id));

        // The load_fixed() method reads them back, and the rows carry them.
        $this->assertSame(
            [(int) $quiz2->cmid => ['timeclose' => true]],
            activitydates::load_fixed($course->id, [$quiz1->cmid, $quiz2->cmid, $otherquiz->cmid])
        );
        $rows = array_values(array_filter($manager->get_table_data($settings, [$quiz1->cmid]), fn($row) => !$row['isheader']));
        $this->assertSame(
            ['timeopen' => false, 'duedate' => false, 'timeclose' => true, 'timelock' => false],
            $rows[1]['fixed']
        );
    }

    /**
     * Load the page as a user with both capabilities, post back exactly what it shows
     * (the enabled inputs, and the ticked, enabled Fix and note checkboxes), and save.
     *
     * @param int $courseid the course id.
     * @param activitydates $manager the manager.
     * @return array the save() result.
     */
    private function save_as_loaded(int $courseid, activitydates $manager): array {
        $tz = \core_date::get_user_timezone_object();
        $settings = activitydates::load_settings($courseid, 'quiz');
        $validcmids = array_map('intval', array_keys(activitydates::get_modules($settings)));
        $selected = activitydates::saved_selection((int) $settings->id, $validcmids);
        $tabledata = $manager->get_table_data($settings, $selected);
        $hasdue = activitydates::has_duedate('quiz');
        $haslocks = $settings->lockmode !== local\schedule::MODE_NONE;
        $editable = ['timeopen' => true, 'duedate' => $hasdue, 'timeclose' => true, 'timelock' => $haslocks];
        $rendered = output\preview_rows::dates($tabledata, $tz, [
            'fields' => $hasdue ? activitydates::FIELDS : ['timeopen', 'timeclose', 'timelock'],
            'editable' => $editable,
            'fixable' => ['timeopen' => true, 'duedate' => $hasdue, 'timeclose' => true, 'timelock' => true],
        ]);
        $inputs = [];
        $fixposted = [];
        $allowed = [];
        $notes = [];
        $coursenotes = [];
        foreach ($rendered as $row) {
            if ($row['isheader']) {
                continue;
            }
            $cmid = (int) $row['id'];
            if ($row['editable']) {
                $allowed[$cmid] = true;
            }
            foreach ($row['fields'] as $entry) {
                if (!$entry['disabled']) {
                    $inputs[$entry['field']][$cmid] = $entry['value'];
                }
                if (!$entry['fixdisabled'] && $entry['fixed']) {
                    $fixposted[$entry['field']][$cmid] = 1;
                }
            }
            if (!$row['notedisabled'] && $row['shownote']) {
                $notes[] = $cmid;
            }
            if (!$row['notedisabled'] && $row['shownotecoursepage']) {
                $coursenotes[] = $cmid;
            }
        }
        [$values, $errors] = local\datefields::validate_dates($inputs, $allowed, $hasdue, $haslocks, $tz);
        $this->assertSame([], $errors);
        $ticks = array_fill_keys(array_map(fn($cmid) => 'activity_' . $cmid, $selected), 1);
        $fromform = (object) (['activitygroup' => $ticks] + (array) $settings);
        return $manager->save($fromform, $courseid, $tabledata, $values, $notes, $coursenotes, $fixposted, true, true, true);
    }

    /**
     * Save straight after load writes back the current values: the dates, locks,
     * notes and Fix flags are unchanged, and an unset date is posted empty.
     */
    public function test_save_after_load_is_noop(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, [$quiz1, $quiz2, $quiz3]] = $this->graded_quizzes(3);
        $manager = new activitydates();
        $manager->update($this->fromform(['activitygroup' => $this->ticks([$quiz1, $quiz2, $quiz3])]), $course->id);
        $DB->update_record('quiz', (object) [
            'id' => $quiz1->id,
            'timeopen' => strtotime('2029-03-01 09:00'),
            'timeclose' => strtotime('2029-03-09 18:30'),
        ]);
        $DB->update_record('quiz', (object) ['id' => $quiz2->id, 'timeopen' => strtotime('2029-04-01 09:00'), 'timeclose' => 0]);
        $DB->update_record('quiz', (object) ['id' => $quiz3->id, 'timeopen' => 0, 'timeclose' => 0]);
        (new locks\manager())->apply_locks([$quiz1->cmid => strtotime('2029-05-01 12:00')], 'quiz', $course->id, false);
        $lockid = $DB->insert_record('tool_activitydates_lock', (object) [
            'courseid' => $course->id,
            'lockmode' => 'session',
            'lockdays' => 7,
            'lockdate' => strtotime('2030-02-01 09:00'),
        ]);
        foreach ([[$quiz1, 1, 1], [$quiz2, 0, 0], [$quiz3, 1, 0]] as [$quiz, $shownote, $coursepage]) {
            $DB->insert_record('tool_activitydates_lockitem', (object) [
                'lockid' => $lockid,
                'cmid' => $quiz->cmid,
                'shownote' => $shownote,
                'shownotecoursepage' => $coursepage,
            ]);
        }
        foreach ([[$quiz1, 'timeclose'], [$quiz2, 'timelock']] as [$quiz, $field]) {
            $DB->insert_record('tool_activitydates_fixed', (object) [
                'courseid' => $course->id,
                'cmid' => $quiz->cmid,
                'field' => $field,
            ]);
        }
        $hasdue = activitydates::has_duedate('quiz');
        $snapshot = function () use ($DB, $course, $lockid, $hasdue): array {
            $datefields = 'id, timeopen, timeclose' . ($hasdue ? ', duedate' : '');
            $quizzes = $DB->get_records('quiz', ['course' => $course->id], 'id', $datefields);
            $locks = [];
            foreach (activitydates::get_modules((object) ['courseid' => $course->id, 'modtype' => 'quiz']) as $cm) {
                $locks[$cm->id] = (new locks\manager())->current_locktime((int) $course->id, $cm);
            }
            return [
                'quizzes' => $quizzes,
                'locks' => $locks,
                'visible' => $DB->get_records_menu('course_modules', ['course' => $course->id], 'id', 'id, visible'),
                'notes' => $DB->get_records(
                    'tool_activitydates_lockitem',
                    ['lockid' => $lockid],
                    'cmid',
                    'cmid, shownote, shownotecoursepage'
                ),
                'fixed' => $this->flags($course->id),
                'selection' => $DB->get_fieldset_sql(
                    'SELECT coursemoduleid FROM {tool_activitydates_cmids} ORDER BY coursemoduleid'
                ),
                'lock' => $DB->get_record(
                    'tool_activitydates_lock',
                    ['id' => $lockid],
                    'lockmode, lockdays, lockdate, resetunselected'
                ),
            ];
        };
        $before = $snapshot();

        // The page load: the table and the POST it makes.
        $tz = \core_date::get_user_timezone_object();
        $settings = activitydates::load_settings($course->id, 'quiz');
        $validcmids = array_map('intval', array_keys(activitydates::get_modules($settings)));
        $rows = array_values(array_filter(
            $manager->get_table_data($settings, activitydates::saved_selection((int) $settings->id, $validcmids)),
            fn($row) => !$row['isheader']
        ));
        $this->assertSame([
            'timeopen' => strtotime('2029-03-01 09:00'),
            'duedate' => $hasdue ? 0 : null,
            'timeclose' => strtotime('2029-03-09 18:30'),
            'timelock' => strtotime('2029-05-01 12:00'),
        ], $rows[0]['current']);
        $this->assertSame(
            ['timeopen' => 0, 'duedate' => $hasdue ? 0 : null, 'timeclose' => 0, 'timelock' => 0],
            $rows[2]['current']
        );
        $this->assertSame('', local\datefields::to_input($rows[2]['current']['timeclose'], $tz));

        $result = $this->save_as_loaded($course->id, $manager);

        $this->assertSame(['dates' => 3, 'locks' => 3], $result);
        $this->assertEquals($before, $snapshot());
    }

    /**
     * Save straight after load keeps the lock selection of an activity that is in the
     * lock store only (as a 2.0 course can have it after the upgrade): the page shows
     * it unticked because its ticks come from the dates store, so Save must neither
     * drop its lock row and notes nor clear its lock, even with "reset unselected".
     */
    public function test_save_after_load_keeps_lock_only_selection(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, [$quiz1, $quiz2, $quiz3]] = $this->graded_quizzes(3);
        $manager = new activitydates();
        // The dates store: quiz1 and quiz3.
        $manager->update($this->fromform(['activitygroup' => $this->ticks([$quiz1, $quiz3])]), $course->id);
        // The lock store: quiz1 and quiz2, clearing the locks of unselected activities.
        $lockid = $DB->insert_record('tool_activitydates_lock', (object) [
            'courseid' => $course->id,
            'lockmode' => 'none',
            'resetunselected' => 1,
        ]);
        foreach ([[$quiz1, 1, 0], [$quiz2, 1, 1]] as [$quiz, $shownote, $coursepage]) {
            $DB->insert_record('tool_activitydates_lockitem', (object) [
                'lockid' => $lockid,
                'cmid' => $quiz->cmid,
                'shownote' => $shownote,
                'shownotecoursepage' => $coursepage,
            ]);
        }
        $locked = strtotime('2029-05-01 12:00');
        (new locks\manager())->apply_locks([$quiz2->cmid => $locked], 'quiz', $course->id, false);

        $this->save_as_loaded($course->id, $manager);

        // Quiz2 keeps its lock, its lock row and both notes.
        $this->assertSame($locked, $this->locktime($course->id, 'quiz', $quiz2->id));
        $item = $DB->get_record('tool_activitydates_lockitem', ['lockid' => $lockid, 'cmid' => $quiz2->cmid]);
        $this->assertNotFalse($item);
        $this->assertSame([1, 1], [(int) $item->shownote, (int) $item->shownotecoursepage]);
        $this->assertTrue(locks\local\locknote::shows_note((int) $quiz2->cmid, true));
        // Quiz1 keeps its notes; quiz3, shown ticked, joins the lock selection.
        $this->assertTrue(locks\local\locknote::shows_note((int) $quiz1->cmid, false));
        $this->assertFalse(locks\local\locknote::shows_note((int) $quiz1->cmid, true));
        $this->assertTrue($DB->record_exists('tool_activitydates_lockitem', ['lockid' => $lockid, 'cmid' => $quiz3->cmid]));
        // The dates selection is what the page showed.
        $settingsid = $DB->get_field('tool_activitydates', 'id', ['courseid' => $course->id]);
        $this->assertEqualsCanonicalizing(
            [$quiz1->cmid, $quiz3->cmid],
            $DB->get_fieldset_select('tool_activitydates_cmids', 'coursemoduleid', 'activitydates = ?', [$settingsid])
        );
    }

    /**
     * Save straight after load keeps different lock dates of one activity's grade
     * items: the Locked input shows the earliest, and an unchanged value is not written.
     */
    public function test_save_after_load_keeps_grade_item_locktimes(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, [$quiz]] = $this->graded_quizzes(1);
        $manager = new activitydates();
        $manager->update($this->fromform(['activitygroup' => $this->ticks([$quiz])]), $course->id);
        $DB->insert_record('tool_activitydates_lock', (object) ['courseid' => $course->id, 'lockmode' => 'session']);
        // A second grade item of the quiz, locked later than the first.
        $first = \grade_item::fetch(['courseid' => $course->id, 'itemtype' => 'mod', 'itemmodule' => 'quiz',
            'iteminstance' => $quiz->id, 'itemnumber' => 0]);
        $second = new \grade_item(['courseid' => $course->id, 'itemtype' => 'mod', 'itemmodule' => 'quiz',
            'iteminstance' => $quiz->id, 'itemnumber' => 1, 'itemname' => 'Second', 'categoryid' => $first->categoryid], false);
        $second->insert();
        $first->set_locktime(strtotime('2029-05-01 12:00'));
        $second->set_locktime(strtotime('2029-06-01 12:00'));

        $result = $this->save_as_loaded($course->id, $manager);

        $this->assertSame(1, $result['locks']);
        $locktimes = [];
        foreach (\grade_item::fetch_all(['courseid' => $course->id, 'itemtype' => 'mod', 'iteminstance' => $quiz->id]) as $item) {
            $locktimes[(int) $item->itemnumber] = (int) $item->get_locktime();
        }
        ksort($locktimes);
        $this->assertSame([strtotime('2029-05-01 12:00'), strtotime('2029-06-01 12:00')], $locktimes);
    }

    /**
     * Unticking an activity that the page showed ticked still removes it from the lock
     * selection, and "reset unselected" clears its lock.
     */
    public function test_untick_removes_lock_selection(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, [$quiz1, $quiz2]] = $this->graded_quizzes(2);
        $manager = new activitydates();
        $fromform = $this->fromform([
            'lockmode' => 'none',
            'lockresetunselected' => 1,
            'activitygroup' => $this->ticks([$quiz1, $quiz2]),
        ]);
        $settings = activitydates::settings_from_form($fromform, $course->id, 0);
        $tabledata = $manager->get_table_data($settings, [$quiz1->cmid, $quiz2->cmid]);
        $manager->save($fromform, $course->id, $tabledata, $this->proposed_values($tabledata), [], [], [], true, true, true);
        $locked = strtotime('2029-05-01 12:00');
        (new locks\manager())->apply_locks([$quiz2->cmid => $locked], 'quiz', $course->id, false);

        // Untick quiz2.
        $fromform->activitygroup = $this->ticks([$quiz1]);
        $settings = activitydates::settings_from_form($fromform, $course->id, (int) $settings->id);
        $tabledata = $manager->get_table_data($settings, [$quiz1->cmid]);
        $manager->save($fromform, $course->id, $tabledata, $this->proposed_values($tabledata), [], [], [], true, true, true);

        $this->assertSame(0, $this->locktime($course->id, 'quiz', $quiz2->id));
        $this->assertFalse($DB->record_exists('tool_activitydates_lockitem', ['cmid' => $quiz2->cmid]));
        $this->assertTrue($DB->record_exists('tool_activitydates_lockitem', ['cmid' => $quiz1->cmid]));
    }

    /**
     * Each row carries its own activity-page and course-page note, saved per row;
     * rows without a saved item show the site defaults.
     */
    public function test_note_flags_per_row(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('lockshownote', 0, 'tool_activitydates');
        set_config('lockshownotecoursepage', 1, 'tool_activitydates');
        [$course, [$quiz1, $quiz2, $quiz3]] = $this->graded_quizzes(3);
        $manager = new activitydates();
        $fromform = $this->fromform(['lockmode' => 'none', 'activitygroup' => $this->ticks([$quiz1, $quiz2, $quiz3])]);
        $settings = activitydates::settings_from_form($fromform, $course->id, 0);
        $all = [$quiz1->cmid, $quiz2->cmid, $quiz3->cmid];
        $datarows = fn() => array_values(array_filter($manager->get_table_data($settings, $all), fn($row) => !$row['isheader']));

        // Nothing saved: the site defaults.
        $rows = $datarows();
        $this->assertSame([false, false, false], array_column($rows, 'shownote'));
        $this->assertSame([true, true, true], array_column($rows, 'shownotecoursepage'));

        $tabledata = $manager->get_table_data($settings, $all);
        $manager->save(
            $fromform,
            $course->id,
            $tabledata,
            $this->proposed_values($tabledata),
            [$quiz1->cmid, $quiz2->cmid],
            [$quiz2->cmid, $quiz3->cmid],
            [],
            true,
            true,
            true
        );

        $items = $DB->get_records('tool_activitydates_lockitem', null, 'cmid', 'cmid, shownote, shownotecoursepage');
        $this->assertEquals([
            $quiz1->cmid => (object) ['cmid' => $quiz1->cmid, 'shownote' => 1, 'shownotecoursepage' => 0],
            $quiz2->cmid => (object) ['cmid' => $quiz2->cmid, 'shownote' => 1, 'shownotecoursepage' => 1],
            $quiz3->cmid => (object) ['cmid' => $quiz3->cmid, 'shownote' => 0, 'shownotecoursepage' => 1],
        ], $items);
        $rows = $datarows();
        $this->assertSame([true, true, false], array_column($rows, 'shownote'));
        $this->assertSame([false, true, true], array_column($rows, 'shownotecoursepage'));
        // The course page shows only the note of an item with both ticks.
        $this->assertTrue(locks\local\locknote::shows_note((int) $quiz1->cmid, false));
        $this->assertFalse(locks\local\locknote::shows_note((int) $quiz1->cmid, true));
        $this->assertTrue(locks\local\locknote::shows_note((int) $quiz2->cmid, true));
        $this->assertFalse(locks\local\locknote::shows_note((int) $quiz3->cmid, true));
    }
}
