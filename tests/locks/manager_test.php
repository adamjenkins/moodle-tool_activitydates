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

namespace tool_activitydates\locks;

use PHPUnit\Framework\Attributes\CoversClass;
use tool_activitydates\local\course_order;
use tool_activitydates\observer;

/**
 * Unit tests for the tool_activitydates plugin.
 *
 * @package    tool_activitydates
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(manager::class)]
#[CoversClass(modtypes::class)]
#[CoversClass(course_order::class)]
#[CoversClass(observer::class)]
final class manager_test extends \advanced_testcase {
    /**
     * eligible_course_modtypes() should include module types that have a
     * grade item in the course, and exclude those that do not.
     */
    public function test_eligible_gradable_modtypes(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'grade' => 100]);
        $this->getDataGenerator()->create_module('label', ['course' => $course->id]);
        $eligible = \tool_activitydates\locks\modtypes::eligible_course_modtypes($course->id);
        $this->assertArrayHasKey('quiz', $eligible);
        $this->assertArrayNotHasKey('label', $eligible);
    }

    /**
     * compute_lockdates() must stagger lock dates by session, grouping
     * activities into sessions of $perssession, in the order given.
     */
    public function test_compute_lockdates_staggers_by_session(): void {
        $start = 1000000; // Arbitrary base.
        $day = DAYSECS;
        $cmids = [11, 12, 13, 14, 15];
        $mgr = new \tool_activitydates\locks\manager();
        // Session length 7 days, 2 activities per session.
        $dates = $mgr->compute_lockdates($cmids, $start, 7, 2);
        $this->assertSame($start + 1 * 7 * $day, $dates[11]);
        $this->assertSame($start + 1 * 7 * $day, $dates[12]);
        $this->assertSame($start + 2 * 7 * $day, $dates[13]);
        $this->assertSame($start + 2 * 7 * $day, $dates[14]);
        $this->assertSame($start + 3 * 7 * $day, $dates[15]);
    }

    /**
     * apply_locks() must write the computed lock timestamp to every
     * itemtype='mod' grade item of each selected course module.
     */
    public function test_apply_locks_writes_grade_item_locktime(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $q1 = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'grade' => 100]);
        $q2 = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'grade' => 100]);
        $mgr = new \tool_activitydates\locks\manager();
        $cm1 = get_coursemodule_from_instance('quiz', $q1->id)->id;
        $cm2 = get_coursemodule_from_instance('quiz', $q2->id)->id;
        $start = 2000000000;
        $dates = $mgr->compute_lockdates([$cm1, $cm2], $start, 7, 1);
        $count = $mgr->apply_locks($dates, 'quiz', $course->id, false);
        $this->assertSame(2, $count);
        $gi1 = \grade_item::fetch([
            'courseid' => $course->id,
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => $q1->id,
        ]);
        $this->assertSame($dates[$cm1], (int) $gi1->get_locktime());
    }

    /**
     * apply_locks() with $resetunselected must clear the locktime on
     * activities of the modtype that are not present in $lockdates.
     */
    public function test_apply_locks_resets_unselected(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $qa = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'grade' => 100]);
        $qb = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'grade' => 100]);
        $mgr = new \tool_activitydates\locks\manager();
        $cma = get_coursemodule_from_instance('quiz', $qa->id)->id;
        $cmb = get_coursemodule_from_instance('quiz', $qb->id)->id;
        $start = 2000000000;

        // First, lock quiz A.
        $initialdates = $mgr->compute_lockdates([$cma], $start, 7, 1);
        $mgr->apply_locks($initialdates, 'quiz', $course->id, false);
        $gia = \grade_item::fetch([
            'courseid' => $course->id,
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => $qa->id,
        ]);
        $this->assertNotSame(0, (int) $gia->get_locktime());

        // Now apply with only quiz B selected and resetunselected = true.
        $newdates = $mgr->compute_lockdates([$cmb], $start, 7, 1);
        $mgr->apply_locks($newdates, 'quiz', $course->id, true);

        $gia = \grade_item::fetch([
            'courseid' => $course->id,
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => $qa->id,
        ]);
        $this->assertSame(0, (int) $gia->get_locktime());
    }

    /**
     * update() must upsert the course's tool_activitydates_lock row and rebuild the
     * tool_activitydates_lockitem rows, including each item's own shownote value.
     */
    public function test_update_persists_config_and_items(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $q1 = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'grade' => 100]);
        $q2 = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'grade' => 100]);
        $cm1 = get_coursemodule_from_instance('quiz', $q1->id)->id;
        $cm2 = get_coursemodule_from_instance('quiz', $q2->id)->id;

        $mgr = new \tool_activitydates\locks\manager();
        $formdata = new \stdClass();
        $formdata->modtype = 'quiz';
        $formdata->schedulestart = 2000000000;
        $formdata->sessionlength = 7;
        $formdata->activitiespersession = 1;
        $formdata->shownote = 1;
        $formdata->shownotecoursepage = 1;
        $formdata->resetunselected = 0;
        $formdata->cmids = [$cm1, $cm2];
        $formdata->shownote_cmids = [$cm1];

        $settings = $mgr->update($formdata, $course->id);

        $this->assertNotEmpty($settings->id);
        $row = $DB->get_record('tool_activitydates_lock', ['courseid' => $course->id]);
        $this->assertNotFalse($row);
        $this->assertSame('quiz', $row->modtype);
        $this->assertSame(2000000000, (int) $row->schedulestart);
        $this->assertSame(7, (int) $row->sessionlength);
        $this->assertSame(1, (int) $row->activitiespersession);
        $this->assertSame(1, (int) $row->shownote);
        $this->assertSame(1, (int) $row->shownotecoursepage);

        $items = $DB->get_records('tool_activitydates_lockitem', ['lockid' => $row->id], 'cmid ASC');
        $this->assertCount(2, $items);
        $items = array_values($items);
        $this->assertSame((int) $cm1, (int) $items[0]->cmid);
        $this->assertSame(1, (int) $items[0]->shownote);
        $this->assertSame((int) $cm2, (int) $items[1]->cmid);
        $this->assertSame(0, (int) $items[1]->shownote);
    }

    /**
     * get_table_data() must return one row per gradable activity of the
     * settings' modtype, in course order, with the exact expected keys and
     * correct selection state. The locktime field must reflect the earliest
     * future locktime from the activity's grade items.
     */
    public function test_get_table_data(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $q1 = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'grade' => 100]);
        $q2 = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'grade' => 100]);
        $cm1 = get_coursemodule_from_instance('quiz', $q1->id)->id;
        $cm2 = get_coursemodule_from_instance('quiz', $q2->id)->id;

        $mgr = new \tool_activitydates\locks\manager();
        $formdata = new \stdClass();
        $formdata->modtype = 'quiz';
        $formdata->schedulestart = 2000000000;
        $formdata->sessionlength = 7;
        $formdata->activitiespersession = 1;
        $formdata->shownote = 1;
        $formdata->resetunselected = 0;
        $formdata->cmids = [$cm1];
        $formdata->shownote_cmids = [$cm1];
        $settings = $mgr->update($formdata, $course->id);

        // Apply locks to exercise the aggregation logic: cm1 gets a future locktime,
        // cm2 remains unlocked.
        $start = 2000000000;
        $lockdates = $mgr->compute_lockdates([$cm1], $start, 7, 1);
        $mgr->apply_locks($lockdates, 'quiz', $course->id, false);
        $expectedlocktime = $start + 7 * DAYSECS;

        $rows = $mgr->get_table_data($settings);

        $this->assertCount(2, $rows);
        $this->assertSame((int) $cm1, (int) $rows[0]['cmid']);
        $this->assertSame((int) $cm2, (int) $rows[1]['cmid']);

        foreach ($rows as $row) {
            $this->assertSame(
                ['cmid', 'name', 'gradeitemids', 'locktime', 'selected', 'shownote'],
                array_keys($row)
            );
        }

        $this->assertTrue($rows[0]['selected']);
        $this->assertFalse($rows[1]['selected']);
        $this->assertNotEmpty($rows[0]['gradeitemids']);
        $this->assertIsInt($rows[0]['locktime']);
        // Verify the earliest-future-locktime aggregation: cm1 should have the applied locktime.
        $this->assertSame($expectedlocktime, $rows[0]['locktime']);
        // CM2 should be unlocked (0).
        $this->assertSame(0, $rows[1]['locktime']);
    }

    /**
     * get_table_data() must list activities in COURSE-APPEARANCE order
     * (section + position), not creation/instance-id order. This matters
     * because the returned row order also drives compute_lockdates()'s
     * session assignment.
     */
    public function test_get_table_data_course_order(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['numsections' => 3], ['createsections' => true]);
        // Create the quizzes into sections 3, 1, 2 respectively, so that their
        // course-appearance order (by section: q2, q3, q1) is deliberately
        // different from creation order (q1, q2, q3) = cmid-ascending order.
        // Placing them at creation avoids any cm-move API: cmactions::move_before()
        // is Moodle 5.2 only (MDL-86854) and moveto_module() is deprecated there,
        // whereas the generator's section option behaves the same on 5.0-5.2.
        $q1 = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'grade' => 100, 'section' => 3]);
        $q2 = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'grade' => 100, 'section' => 1]);
        $q3 = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'grade' => 100, 'section' => 2]);
        $cm1 = (int) get_coursemodule_from_instance('quiz', $q1->id)->id;
        $cm2 = (int) get_coursemodule_from_instance('quiz', $q2->id)->id;
        $cm3 = (int) get_coursemodule_from_instance('quiz', $q3->id)->id;

        $mgr = new \tool_activitydates\locks\manager();
        $settings = (object) [
            'id' => 0,
            'courseid' => $course->id,
            'modtype' => 'quiz',
            'shownote' => 0,
        ];

        $rows = $mgr->get_table_data($settings);

        $this->assertCount(3, $rows);
        $actualcmids = array_map('intval', array_column($rows, 'cmid'));
        $this->assertSame([$cm2, $cm3, $cm1], $actualcmids);
        // Guard the test's own premise: course order must differ from creation
        // order, otherwise this would pass even on a creation-ordered result.
        $this->assertNotSame([$cm1, $cm2, $cm3], $actualcmids);
    }

    /**
     * Activities inside a subsection must be listed where the subsection sits
     * on the course page, not after every other section. A subsection's
     * content lives in a delegated section numbered after all listed
     * sections, so walking sections by number puts it at the very end.
     */
    public function test_get_table_data_subsection_order(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['numsections' => 2], ['createsections' => true]);

        // Section 1: q1, then the subsection (holding q2), then q3. Section 2: q4.
        $q1 = $generator->create_module('quiz', ['course' => $course->id, 'grade' => 100, 'section' => 1]);
        $subsection = $generator->create_module('subsection', ['course' => $course->id, 'section' => 1]);
        $delegated = get_fast_modinfo($course->id)->get_section_info_by_component('mod_subsection', $subsection->id);
        $q2 = $generator->create_module(
            'quiz',
            ['course' => $course->id, 'grade' => 100, 'section' => $delegated->section]
        );
        $q3 = $generator->create_module('quiz', ['course' => $course->id, 'grade' => 100, 'section' => 1]);
        $q4 = $generator->create_module('quiz', ['course' => $course->id, 'grade' => 100, 'section' => 2]);

        $mgr = new \tool_activitydates\locks\manager();
        $settings = (object) [
            'id' => 0,
            'courseid' => $course->id,
            'modtype' => 'quiz',
            'shownote' => 0,
        ];
        $rows = $mgr->get_table_data($settings);

        $expected = array_map('intval', [$q1->cmid, $q2->cmid, $q3->cmid, $q4->cmid]);
        $this->assertSame($expected, array_map('intval', array_column($rows, 'cmid')));
    }

    /**
     * Deleting a course must clean up its tool_activitydates_lock configuration row
     * and all associated tool_activitydates_lockitem rows, leaving no orphans.
     */
    public function test_course_deleted_cleans_plugin_rows(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $q1 = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'grade' => 100]);
        $cm1 = get_coursemodule_from_instance('quiz', $q1->id)->id;

        $mgr = new \tool_activitydates\locks\manager();
        $formdata = new \stdClass();
        $formdata->modtype = 'quiz';
        $formdata->schedulestart = 2000000000;
        $formdata->sessionlength = 7;
        $formdata->activitiespersession = 1;
        $formdata->shownote = 1;
        $formdata->resetunselected = 0;
        $formdata->cmids = [$cm1];
        $formdata->shownote_cmids = [$cm1];
        $settings = $mgr->update($formdata, $course->id);
        $lockid = $settings->id;

        $this->assertSame(1, $DB->count_records('tool_activitydates_lock', ['courseid' => $course->id]));
        $this->assertSame(1, $DB->count_records('tool_activitydates_lockitem', ['lockid' => $lockid]));

        delete_course($course, false);

        $this->assertSame(0, $DB->count_records('tool_activitydates_lock', ['courseid' => $course->id]));
        $this->assertSame(0, $DB->count_records('tool_activitydates_lockitem', ['lockid' => $lockid]));
    }

    /**
     * The locks_updated event must construct and trigger correctly with a
     * course context, matching \tool_activitydates\event\locks_updated.
     */
    public function test_locks_updated_event(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);

        $sink = $this->redirectEvents();
        \tool_activitydates\event\locks_updated::create(['context' => $context])->trigger();
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertInstanceOf(\tool_activitydates\event\locks_updated::class, $event);
        $this->assertEquals($context, $event->get_context());
    }

    /**
     * update() must drop cmids from another course or another activity type.
     */
    public function test_update_drops_foreign_cmids(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $other = $gen->create_course();
        $quiz = $gen->create_module('quiz', ['course' => $course->id, 'grade' => 100]);
        $assign = $gen->create_module('assign', ['course' => $course->id]);
        $foreign = $gen->create_module('quiz', ['course' => $other->id, 'grade' => 100]);

        $settings = (new manager())->update((object) [
            'modtype' => 'quiz',
            'schedulestart' => 2000000000,
            'sessionlength' => 7,
            'activitiespersession' => 1,
            'shownote' => 1,
            'shownotecoursepage' => 0,
            'resetunselected' => 0,
            'cmids' => [$quiz->cmid, $assign->cmid, $foreign->cmid],
            'shownote_cmids' => [$quiz->cmid, $assign->cmid, $foreign->cmid],
        ], $course->id);

        $saved = $DB->get_fieldset_select('tool_activitydates_lockitem', 'cmid', 'lockid = ?', [$settings->id]);
        $this->assertSame([(int) $quiz->cmid], array_map('intval', $saved));
    }

    /**
     * apply_locks() must skip a selected activity that has no grade item.
     */
    public function test_apply_locks_skips_activity_without_grade_item(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $graded = $gen->create_module('quiz', ['course' => $course->id, 'grade' => 100]);
        $ungraded = $gen->create_module('quiz', ['course' => $course->id, 'grade' => 0]);
        // A quiz with grade 0 has no grade item; make sure of it rather than assume.
        $this->assertFalse(\grade_item::fetch(['courseid' => $course->id, 'itemtype' => 'mod',
            'itemmodule' => 'quiz', 'iteminstance' => $ungraded->id]));

        $count = (new manager())->apply_locks(
            [$graded->cmid => 2000000000, $ungraded->cmid => 2000000000],
            'quiz',
            $course->id,
            false
        );

        $this->assertSame(1, $count);
    }
}
