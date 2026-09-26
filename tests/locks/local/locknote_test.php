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

namespace tool_activitydates\locks\local;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Unit tests for the student-facing lock note data.
 *
 * @package    tool_activitydates
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(locknote::class)]
final class locknote_test extends \advanced_testcase {
    /** @var int A future timestamp used as the scheduled lock date. */
    private const LOCKTIME = 2000000000;

    /**
     * Set up a course with three graded quizzes and a student.
     *
     * @return array [course, [cm_info, cm_info, cm_info], student]
     */
    private function create_fixture(): array {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $cms = [];
        foreach ([1, 2, 3] as $n) {
            $quiz = $generator->create_module('quiz', ['course' => $course->id, 'grade' => 100, 'name' => "Quiz $n"]);
            $cms[] = get_fast_modinfo($course->id)->get_cm($quiz->cmid);
        }
        $student = $generator->create_and_enrol($course, 'student');
        return [$course, $cms, $student];
    }

    /**
     * Save a Grade locks configuration and apply its lock dates.
     *
     * @param int $courseid The course ID.
     * @param array $cmids Course module IDs to select.
     * @param array $notecmids Course module IDs whose note is switched on.
     * @param int $coursepage The shownotecoursepage value to save.
     */
    private function configure(int $courseid, array $cmids, array $notecmids, int $coursepage): void {
        $mgr = new \tool_activitydates\locks\manager();
        $mgr->update((object) [
            'modtype' => 'quiz',
            'schedulestart' => self::LOCKTIME - 7 * DAYSECS,
            'sessionlength' => 7,
            'activitiespersession' => 10,
            'shownote' => 0,
            'shownotecoursepage' => $coursepage,
            'resetunselected' => 0,
            'cmids' => $cmids,
            'shownote_cmids' => $notecmids,
        ], $courseid);
        $mgr->apply_locks(array_fill_keys($cmids, self::LOCKTIME), 'quiz', $courseid, false);
    }

    /**
     * Fetch a course module's grade items.
     *
     * @param \cm_info $cm The course module.
     * @return array grade_item objects.
     */
    private function grade_items(\cm_info $cm): array {
        return \grade_item::fetch_all([
            'courseid' => $cm->course,
            'itemtype' => 'mod',
            'itemmodule' => $cm->modname,
            'iteminstance' => $cm->instance,
        ]) ?: [];
    }

    /**
     * lock_state() reports nothing without a lock, the scheduled date for a
     * future lock, and the lock date once the grade item is locked.
     */
    public function test_lock_state(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $cms] = $this->create_fixture();

        $this->assertNull(locknote::lock_state($this->grade_items($cms[0])));

        foreach ($this->grade_items($cms[0]) as $gradeitem) {
            $gradeitem->set_locktime(self::LOCKTIME);
        }
        $this->assertSame(['islocked' => false, 'time' => self::LOCKTIME], locknote::lock_state($this->grade_items($cms[0])));

        // Mark the item locked directly: set_locked() only schedules a lock while
        // the item still needs regrading, which a fresh test course's item does.
        $lockedat = self::LOCKTIME - DAYSECS;
        foreach ($this->grade_items($cms[0]) as $gradeitem) {
            $DB->set_field('grade_items', 'locked', $lockedat, ['id' => $gradeitem->id]);
        }
        $this->assertSame(['islocked' => true, 'time' => $lockedat], locknote::lock_state($this->grade_items($cms[0])));
    }

    /**
     * for_cm() returns a note only for an activity whose note is switched on.
     */
    public function test_for_cm(): void {
        $this->resetAfterTest();
        [$course, $cms] = $this->create_fixture();
        $this->configure($course->id, [$cms[0]->id, $cms[1]->id], [$cms[0]->id], 0);

        $this->assertSame(['islocked' => false, 'time' => self::LOCKTIME], locknote::for_cm($cms[0]));
        $this->assertNull(locknote::for_cm($cms[1]));
        $this->assertNull(locknote::for_cm($cms[2]));
    }

    /**
     * course_page_notes() is empty unless the course-page option is on.
     */
    public function test_course_page_notes_option_off(): void {
        $this->resetAfterTest();
        [$course, $cms, $student] = $this->create_fixture();
        $this->configure($course->id, [$cms[0]->id], [$cms[0]->id], 0);
        $this->setUser($student);

        $this->assertSame([], locknote::course_page_notes($course->id));
    }

    /**
     * With the option on, course_page_notes() returns the notes of exactly
     * the activities whose note is switched on, keyed by cmid.
     */
    public function test_course_page_notes_option_on(): void {
        $this->resetAfterTest();
        [$course, $cms, $student] = $this->create_fixture();
        $this->configure($course->id, [$cms[0]->id, $cms[1]->id], [$cms[0]->id], 1);
        $this->setUser($student);

        $notes = locknote::course_page_notes($course->id);

        $this->assertSame([(int) $cms[0]->id], array_keys($notes));
        $this->assertSame(['islocked' => false, 'time' => self::LOCKTIME], $notes[(int) $cms[0]->id]);
    }

    /**
     * A note is never sent for an activity the viewer cannot see.
     */
    public function test_course_page_notes_skip_hidden_activity(): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $this->resetAfterTest();
        [$course, $cms, $student] = $this->create_fixture();
        $this->configure($course->id, [$cms[0]->id, $cms[1]->id], [$cms[0]->id, $cms[1]->id], 1);
        set_coursemodule_visible($cms[1]->id, 0);

        $this->setUser($student);
        $this->assertSame([(int) $cms[0]->id], array_keys(locknote::course_page_notes($course->id)));

        $this->setAdminUser();
        $this->assertSame(
            [(int) $cms[0]->id, (int) $cms[1]->id],
            array_keys(locknote::course_page_notes($course->id))
        );
    }

    /**
     * A stealth activity (available, but not shown on the course page) gets
     * no course-page note: its cmid and lock date must not reach the page.
     */
    public function test_course_page_notes_skip_stealth_activity(): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $this->resetAfterTest();
        set_config('allowstealth', 1);
        [$course, $cms, $student] = $this->create_fixture();
        $this->configure($course->id, [$cms[0]->id, $cms[1]->id], [$cms[0]->id, $cms[1]->id], 1);
        set_coursemodule_visible($cms[1]->id, 1, 0);

        $this->setUser($student);
        // Premise: the stealth activity is still available to the student.
        $this->assertTrue(get_fast_modinfo($course->id)->get_cm($cms[1]->id)->uservisible);
        $this->assertSame([(int) $cms[0]->id], array_keys(locknote::course_page_notes($course->id)));
    }

    /**
     * Another course's configuration never produces notes in this course.
     */
    public function test_course_page_notes_other_course(): void {
        $this->resetAfterTest();
        [$course, $cms] = $this->create_fixture();
        $this->configure($course->id, [$cms[0]->id], [$cms[0]->id], 1);
        $other = $this->getDataGenerator()->create_course();
        $this->setAdminUser();

        $this->assertSame([], locknote::course_page_notes($other->id));
    }
}
