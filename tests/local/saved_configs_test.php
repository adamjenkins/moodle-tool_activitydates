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
 * Tests for the saved configurations.
 *
 * @package    tool_activitydates
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_activitydates\local;

use PHPUnit\Framework\Attributes\CoversClass;
use tool_activitydates\activitydates;

/**
 * Tests for the saved_configs class.
 */
#[CoversClass(saved_configs::class)]
final class saved_configs_test extends \advanced_testcase {
    /** @var array every field editable and holdable. */
    private const ALL = ['timeopen' => true, 'duedate' => true, 'timeclose' => true, 'timelock' => true];

    /**
     * Form data for settings_from_form(): quizzes, two per weekly session, no finish date.
     *
     * @param array $over values to override.
     * @return \stdClass
     */
    private function fromform(array $over = []): \stdClass {
        return (object) array_merge([
            'modtype' => 'quiz',
            'schedulestart' => strtotime('2030-01-01 09:00'),
            'schedulefinish' => 0,
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
            'lockmode' => 'days',
            'lockdays' => 10,
            'lockdate' => 0,
            'lockresetunselected' => 0,
        ], $over);
    }

    /**
     * Delete a course module now (Moodle 5.2 moved this into the course format actions).
     *
     * @param \stdClass $course the course.
     * @param int $cmid the course module id.
     */
    private function delete_cm(\stdClass $course, int $cmid): void {
        $cmactions = new \core_courseformat\local\cmactions($course);
        if (method_exists($cmactions, 'delete')) {
            $cmactions->delete($cmid);
        } else {
            course_delete_module($cmid);
        }
    }

    public function test_save_list_replace_and_delete(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();

        $this->assertFalse(saved_configs::save($course->id, 'Reading', ['modtype' => 'quiz']));
        $this->assertFalse(saved_configs::save($course->id, 'Autumn', ['modtype' => 'quiz']));
        // The same name is free in another course.
        $this->assertFalse(saved_configs::save($other->id, 'Reading', ['modtype' => 'choice']));
        $this->assertSame(['Autumn', 'Reading'], array_values(array_column(saved_configs::list($course->id), 'name')));

        // Saving under an existing name replaces it.
        $this->assertTrue(saved_configs::save($course->id, 'Reading', ['modtype' => 'feedback']));
        $this->assertSame(2, $DB->count_records(saved_configs::TABLE, ['courseid' => $course->id]));
        $reading = $DB->get_record(saved_configs::TABLE, ['courseid' => $course->id, 'name' => 'Reading']);
        $this->assertSame('feedback', saved_configs::decode($reading)['modtype']);

        // Another course's configuration can be neither read nor deleted through this course.
        $foreign = $DB->get_record(saved_configs::TABLE, ['courseid' => $other->id]);
        try {
            saved_configs::delete($course->id, (int) $foreign->id);
            $this->fail('A configuration of another course was deleted.');
        } catch (\dml_missing_record_exception $e) {
            $this->assertTrue($DB->record_exists(saved_configs::TABLE, ['id' => $foreign->id]));
        }
        $this->expectException(\dml_missing_record_exception::class);
        saved_configs::get($course->id, (int) $foreign->id);
    }

    public function test_delete_and_clean_name(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        saved_configs::save($course->id, 'Reading', []);
        $id = (int) $DB->get_field(saved_configs::TABLE, 'id', ['courseid' => $course->id]);

        $this->assertSame('Reading', saved_configs::delete($course->id, $id)->name);
        $this->assertSame([], saved_configs::list($course->id));

        // Tags are stripped and the ends trimmed.
        $this->assertSame('Reading x', saved_configs::clean_name("  Reading <b>x</b>\t"));
        $this->assertSame('', saved_configs::clean_name('   '));
        $this->assertSame(255, \core_text::strlen(saved_configs::clean_name(str_repeat('é', 300))));
    }

    public function test_snapshot_keeps_only_the_users_parts(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $settings = activitydates::settings_from_form($this->fromform(), $course->id, 0);
        $rows = ['timeopen' => [5 => '2030-01-02T09:00', 6 => ['x']]];
        $hold = ['timeclose' => [5 => 1, 6 => 0]];

        $manage = saved_configs::snapshot($settings, [5], $rows, $hold, [5], [5], true, false);
        $this->assertSame('quiz', $manage['modtype']);
        $this->assertArrayHasKey('sessionlength', $manage['settings']);
        $this->assertArrayNotHasKey('lockmode', $manage['settings']);
        $this->assertSame(0, $manage['settings']['schedulefinish']);
        $this->assertSame(['timeopen' => [5 => '2030-01-02T09:00']], $manage['rows']);
        $this->assertSame(['timeclose' => [5 => 1]], $manage['hold']);
        $this->assertSame([], $manage['notes']);

        $locks = saved_configs::snapshot($settings, [5], [], [], [5], [6], false, true);
        $this->assertSame(['lockmode', 'lockdays', 'lockdate', 'lockresetunselected'], array_keys($locks['settings']));
        $this->assertSame([5], $locks['notes']);
        $this->assertSame([6], $locks['coursenotes']);
    }

    public function test_snapshot_scopes_table_values_to_the_ticked_activities(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $settings = activitydates::settings_from_form($this->fromform(), $course->id, 0);
        $rows = ['timeopen' => [5 => '2030-01-02T09:00', 6 => '2030-01-03T09:00', 7 => str_repeat('9', 33)]];
        $hold = ['timeclose' => [5 => 1, 6 => 1, 99 => 1]];

        $snapshot = saved_configs::snapshot($settings, [5, 7], $rows, $hold, [], [], true, true);
        // 6 and 99 are not ticked; 7's input is longer than any date value.
        $this->assertSame(['timeopen' => [5 => '2030-01-02T09:00']], $snapshot['rows']);
        $this->assertSame(['timeclose' => [5 => 1]], $snapshot['hold']);
    }

    public function test_replace_keeps_the_parts_the_saver_cannot_edit(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $both = activitydates::settings_from_form($this->fromform(['sessionlength' => 3, 'lockmode' => 'date']), $course->id, 0);
        saved_configs::save($course->id, 'Plan', saved_configs::snapshot(
            $both,
            [5],
            ['timeopen' => [5 => '2030-01-02T09:00'], 'timelock' => [5 => '2030-02-01T09:00']],
            ['timeclose' => [5 => 1], 'timelock' => [5 => 1]],
            [5],
            [5],
            true,
            true
        ));
        $read = fn(): array => saved_configs::decode($DB->get_record(saved_configs::TABLE, ['name' => 'Plan']));

        // A :managelocks-only user replaces the lock part only.
        $locks = activitydates::settings_from_form($this->fromform(['sessionlength' => 9, 'lockmode' => 'days']), $course->id, 0);
        $this->assertTrue(saved_configs::save($course->id, 'Plan', saved_configs::snapshot(
            $locks,
            [6],
            ['timelock' => [6 => '2030-03-01T09:00']],
            [],
            [6],
            [],
            false,
            true
        ), false, true));
        $plan = $read();
        $this->assertSame(3, $plan['settings']['sessionlength']);
        $this->assertSame('days', $plan['settings']['lockmode']);
        $this->assertSame(['5' => '2030-01-02T09:00'], $plan['rows']['timeopen']);
        $this->assertSame(['6' => '2030-03-01T09:00'], $plan['rows']['timelock']);
        $this->assertSame(['5' => 1], $plan['hold']['timeclose']);
        $this->assertArrayNotHasKey('timelock', $plan['hold']);
        $this->assertSame([5], $plan['selected']);
        $this->assertSame([6], $plan['notes']);
        $this->assertSame([], $plan['coursenotes']);

        // A :manage-only user replaces the date part only.
        $dates = activitydates::settings_from_form($this->fromform(['sessionlength' => 4, 'lockmode' => 'none']), $course->id, 0);
        saved_configs::save($course->id, 'Plan', saved_configs::snapshot($dates, [7], [], [], [], [], true, false), true, false);
        $plan = $read();
        $this->assertSame(4, $plan['settings']['sessionlength']);
        $this->assertSame('days', $plan['settings']['lockmode']);
        $this->assertArrayNotHasKey('timeopen', $plan['rows']);
        $this->assertSame(['6' => '2030-03-01T09:00'], $plan['rows']['timelock']);
        $this->assertSame([7], $plan['selected']);
        $this->assertSame([6], $plan['notes']);
    }

    public function test_only_a_user_who_may_edit_every_part_may_delete(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $settings = activitydates::settings_from_form($this->fromform(), $course->id, 0);
        $record = fn(array $snapshot) => (object) ['data' => json_encode($snapshot)];
        $datesonly = $record(saved_configs::snapshot($settings, [5], [], [], [], [], true, false));
        $locksonly = $record(saved_configs::snapshot($settings, [5], [], [], [5], [], false, true));
        $both = $record(saved_configs::snapshot($settings, [5], [], [], [], [], true, true));

        $this->assertTrue(saved_configs::can_delete($datesonly, true, false));
        $this->assertFalse(saved_configs::can_delete($datesonly, false, true));
        $this->assertTrue(saved_configs::can_delete($locksonly, false, true));
        $this->assertFalse(saved_configs::can_delete($locksonly, true, false));
        $this->assertTrue(saved_configs::can_delete($both, true, true));
        $this->assertFalse(saved_configs::can_delete($both, true, false));
        $this->assertFalse(saved_configs::can_delete($both, false, true));
    }

    public function test_form_data_keeps_current_values_the_user_cannot_edit(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $saved = activitydates::settings_from_form(
            $this->fromform(['sessionlength' => 3, 'closemode' => 'days', 'lockmode' => 'date']),
            $course->id,
            0
        );
        $snapshot = saved_configs::decode((object) [
            'data' => json_encode(saved_configs::snapshot($saved, [], [], [], [], [], true, true)),
        ]);
        $current = activitydates::settings_from_form($this->fromform(['sessionlength' => 9, 'lockmode' => 'none']), $course->id, 0);

        $both = saved_configs::form_data($snapshot, $current, true, true);
        $this->assertSame(3, $both->sessionlength);
        $this->assertSame('days', $both->closemode);
        $this->assertSame('date', $both->lockmode);

        // A :managelocks-only user loads the lock settings only; a :manage-only user the date ones only.
        $locksonly = saved_configs::form_data($snapshot, $current, false, true);
        $this->assertSame(9, $locksonly->sessionlength);
        $this->assertSame('date', $locksonly->lockmode);
        $datesonly = saved_configs::form_data($snapshot, $current, true, false);
        $this->assertSame(3, $datesonly->sessionlength);
        $this->assertSame('none', $datesonly->lockmode);
    }

    public function test_table_parts_drop_gone_activities_and_unpermitted_fields(): void {
        $snapshot = saved_configs::decode((object) ['data' => json_encode([
            'modtype' => 'quiz',
            'selected' => [30, 10, 99],
            'rows' => [
                'timeopen' => ['10' => '2030-01-02T09:00', '99' => '2030-01-03T09:00'],
                'timelock' => ['10' => '2030-02-01T09:00'],
            ],
            'hold' => ['timeopen' => ['10' => 1, '98' => 1], 'timelock' => ['10' => 1]],
            'notes' => [10, 97],
            'coursenotes' => [30],
        ])]);
        $editable = ['timeopen' => true, 'duedate' => false, 'timeclose' => true, 'timelock' => false];

        $parts = saved_configs::table_parts($snapshot, [10, 20, 30], $editable, $editable, true);
        // In course order, without the activities the course no longer has.
        $this->assertSame([10, 30], $parts['selected']);
        $this->assertSame([10 => '2030-01-02T09:00'], $parts['rows']['timeopen']);
        $this->assertSame([], $parts['rows']['timelock']);
        $this->assertSame([10 => 1], $parts['hold']['timeopen']);
        $this->assertSame([], $parts['hold']['timelock']);
        $this->assertSame([10], $parts['notes']);
        $this->assertSame([30], $parts['coursenotes']);
        // 99, 98 and 97 are gone.
        $this->assertSame(3, $parts['dropped']);

        $nolocks = saved_configs::table_parts($snapshot, [10, 20, 30], $editable, $editable, false);
        $this->assertSame([], $nolocks['notes']);
        $this->assertSame([], $nolocks['coursenotes']);
    }

    /**
     * The owner's case: save a configuration, delete one of its activities and add a new
     * one, then load it and save. The deleted activity is left out everywhere, the new
     * one is unticked, and the loaded dates are written to the activities that remain.
     */
    public function test_load_after_activities_were_deleted_and_added(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('coursebinenable', 0, 'tool_recyclebin');
        $tz = \core_date::get_user_timezone_object();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        [$quiz1, $quiz2, $quiz3] = [
            $gen->create_module('quiz', ['course' => $course->id, 'name' => 'Reading 1']),
            $gen->create_module('quiz', ['course' => $course->id, 'name' => 'Reading 2']),
            $gen->create_module('quiz', ['course' => $course->id, 'name' => 'Listening 1']),
        ];

        // Save: quiz1 and quiz2 ticked, each with hand-edited dates and a Hold tick.
        $settings = activitydates::settings_from_form($this->fromform(), $course->id, 0);
        $rows = [
            'timeopen' => [$quiz1->cmid => '2030-02-03T10:30', $quiz2->cmid => '2030-02-04T10:30'],
            'timeclose' => [$quiz1->cmid => '2030-02-10T17:00', $quiz2->cmid => '2030-02-11T17:00'],
        ];
        $hold = ['timeclose' => [$quiz1->cmid => 1], 'timeopen' => [$quiz2->cmid => 1]];
        saved_configs::save($course->id, 'Reading', saved_configs::snapshot(
            $settings,
            [$quiz1->cmid, $quiz2->cmid],
            $rows,
            $hold,
            [],
            [],
            true,
            false
        ));

        // The course changes: quiz2 is deleted, quiz4 is added.
        $this->delete_cm($course, (int) $quiz2->cmid);
        $quiz4 = $gen->create_module('quiz', ['course' => $course->id, 'name' => 'Reading 3']);

        // Load, as view.php does for a :manage-only user.
        $current = activitydates::load_settings($course->id, 'quiz');
        $validcmids = array_map('intval', array_keys(activitydates::get_modules($current)));
        $record = $DB->get_record(saved_configs::TABLE, ['courseid' => $course->id, 'name' => 'Reading']);
        $snapshot = saved_configs::decode(saved_configs::get($course->id, (int) $record->id));
        $loaded = activitydates::settings_from_form(saved_configs::form_data($snapshot, $current, true, false), $course->id, 0);
        $editable = ['timeopen' => true, 'duedate' => false, 'timeclose' => true, 'timelock' => false];
        $parts = saved_configs::table_parts($snapshot, $validcmids, $editable, $editable, false);

        $this->assertSame(1, $parts['dropped']);
        $this->assertSame([(int) $quiz1->cmid], $parts['selected']);
        $this->assertSame([(int) $quiz1->cmid => '2030-02-03T10:30'], $parts['rows']['timeopen']);
        $this->assertSame([(int) $quiz1->cmid => 1], $parts['hold']['timeclose']);
        $this->assertSame([], $parts['hold']['timeopen']);
        $this->assertSame(strtotime('2030-01-01 09:00'), $loaded->schedulestart);

        // The table: quiz1 shows the loaded dates; the new quiz4 is unticked with its current dates.
        $manager = new activitydates();
        $tabledata = $manager->get_table_data($loaded, $parts['selected']);
        $rendered = \tool_activitydates\output\preview_rows::dates($tabledata, $tz, [
            'source' => \tool_activitydates\output\preview_rows::SOURCE_LOADED,
            'fields' => ['timeopen', 'timeclose'],
            'editable' => $editable,
            'fixable' => $editable,
            'rowinputs' => $parts['rows'],
            'fixposted' => $parts['hold'],
        ]);
        $byid = array_column(array_filter($rendered, fn($row) => !$row['isheader']), null, 'id');
        $this->assertSame([(int) $quiz1->cmid, (int) $quiz3->cmid, (int) $quiz4->cmid], array_keys($byid));
        $fields = array_column($byid[$quiz1->cmid]['fields'], null, 'field');
        $this->assertSame('2030-02-03T10:30', $fields['timeopen']['value']);
        $this->assertSame('2030-02-10T17:00', $fields['timeclose']['value']);
        $this->assertTrue($fields['timeclose']['fixed']);
        $this->assertSame('', $byid[$quiz4->cmid]['selected']);
        $this->assertTrue(array_column($byid[$quiz4->cmid]['fields'], null, 'field')['timeopen']['disabled']);

        // Save what was loaded: only quiz1 gets dates; nothing refers to the deleted quiz2.
        $allowed = [(int) $quiz1->cmid => true];
        [$values, $errors] = datefields::validate_dates(
            $parts['rows'],
            $allowed,
            false,
            false,
            $tz,
            datefields::known_values($tabledata)
        );
        $this->assertSame([], $errors);
        $submitted = (object) ((array) $this->fromform() + ['activitygroup' => ['activity_' . $quiz1->cmid => 1]]);
        $manager->save($submitted, $course->id, $tabledata, $values, [], [], $parts['hold'], true, false, true);

        $quiz1row = $DB->get_record('quiz', ['id' => $quiz1->id]);
        $this->assertSame(datefields::from_input('2030-02-03T10:30', $tz, []), (int) $quiz1row->timeopen);
        $this->assertSame(datefields::from_input('2030-02-10T17:00', $tz, []), (int) $quiz1row->timeclose);
        $this->assertSame(0, (int) $DB->get_field('quiz', 'timeopen', ['id' => $quiz4->id]));
        $this->assertSame(0, $DB->count_records('tool_activitydates_fixed', ['cmid' => $quiz2->cmid]));
        $this->assertSame(0, $DB->count_records('tool_activitydates_cmids', ['coursemoduleid' => $quiz2->cmid]));
        $this->assertTrue($DB->record_exists('tool_activitydates_fixed', ['cmid' => $quiz1->cmid, 'field' => 'timeclose']));
    }
}
