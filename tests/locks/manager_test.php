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
#[CoversClass(observer::class)]
final class manager_test extends \advanced_testcase {
    /**
     * Create a course with two graded quizzes.
     *
     * @return array [course, quiz 1, quiz 2, cmid 1, cmid 2]
     */
    private function create_two_quizzes(): array {
        $course = $this->getDataGenerator()->create_course();
        $q1 = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'grade' => 100]);
        $q2 = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'grade' => 100]);
        $cm1 = get_coursemodule_from_instance('quiz', $q1->id)->id;
        $cm2 = get_coursemodule_from_instance('quiz', $q2->id)->id;
        return [$course, $q1, $q2, $cm1, $cm2];
    }

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
     * apply_locks() must write the given lock timestamp to every
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
        $dates = [$cm1 => 2000000000 + 7 * DAYSECS, $cm2 => 2000000000 + 14 * DAYSECS];
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

        // First, lock quiz A.
        $mgr->apply_locks([$cma => 2000000000 + 7 * DAYSECS], 'quiz', $course->id, false);
        $gia = \grade_item::fetch([
            'courseid' => $course->id,
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => $qa->id,
        ]);
        $this->assertNotSame(0, (int) $gia->get_locktime());

        // Now apply with only quiz B selected and resetunselected = true.
        $mgr->apply_locks([$cmb => 2000000000 + 7 * DAYSECS], 'quiz', $course->id, true);

        $gia = \grade_item::fetch([
            'courseid' => $course->id,
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => $qa->id,
        ]);
        $this->assertSame(0, (int) $gia->get_locktime());
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

        $lockid = $DB->insert_record('tool_activitydates_lock', (object) ['courseid' => $course->id, 'lockmode' => 'session']);
        $DB->insert_record('tool_activitydates_lockitem', (object) ['lockid' => $lockid, 'cmid' => $cm1, 'shownote' => 1]);

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

    /**
     * current_locktime() is the earliest locktime of the cm's grade items, 0 without one;
     * has_grade_item() tells whether there is a grade item at all.
     */
    public function test_current_locktime(): void {
        $this->resetAfterTest();
        [$course, , , $cm1, $cm2] = $this->create_two_quizzes();
        $ungraded = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'grade' => 0]);
        $mgr = new manager();
        $mgr->apply_locks([$cm1 => 2000000000], 'quiz', $course->id, false);

        $modinfo = get_fast_modinfo($course->id);
        $this->assertSame(2000000000, $mgr->current_locktime($course->id, $modinfo->get_cm($cm1)));
        $this->assertSame(0, $mgr->current_locktime($course->id, $modinfo->get_cm($cm2)));
        $this->assertSame(0, $mgr->current_locktime($course->id, $modinfo->get_cm($ungraded->cmid)));
        $this->assertTrue($mgr->has_grade_item($course->id, $modinfo->get_cm($cm2)));
        $this->assertFalse($mgr->has_grade_item($course->id, $modinfo->get_cm($ungraded->cmid)));
    }
}
