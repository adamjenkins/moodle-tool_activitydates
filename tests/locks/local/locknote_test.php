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
        global $DB;
        $lockid = $DB->insert_record('tool_activitydates_lock', (object) [
            'courseid' => $courseid,
            'shownote' => 0,
            'shownotecoursepage' => $coursepage,
            'resetunselected' => 0,
        ]);
        foreach ($cmids as $cmid) {
            $DB->insert_record('tool_activitydates_lockitem', (object) [
                'lockid' => $lockid,
                'cmid' => $cmid,
                'shownote' => in_array($cmid, $notecmids) ? 1 : 0,
            ]);
        }
        $mgr = new \tool_activitydates\locks\manager();
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
        [, $cms] = $this->create_fixture();

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
        [$course, $cms, $student] = $this->create_fixture();
        $this->configure($course->id, [$cms[0]->id, $cms[1]->id], [$cms[0]->id], 0);

        // As the student, who can open all three quizzes.
        $this->setUser($student);
        $modinfo = get_fast_modinfo($course->id);
        $this->assertSame(['islocked' => false, 'time' => self::LOCKTIME], locknote::for_cm($modinfo->get_cm($cms[0]->id)));
        $this->assertNull(locknote::for_cm($modinfo->get_cm($cms[1]->id)));
        $this->assertNull(locknote::for_cm($modinfo->get_cm($cms[2]->id)));
    }

    /**
     * for_cm() shows nothing for an activity the current user cannot open, such as
     * one whose access restriction is not yet met: core's restricted-activity page
     * sets that activity as the page's cm, and the course page shows it no note either.
     */
    public function test_for_cm_restricted_activity(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->enableavailability = 1;
        [$course, $cms, $student] = $this->create_fixture();
        $availability = json_encode(\core_availability\tree::get_root_json(
            [\availability_date\condition::get_json('>=', time() + 30 * DAYSECS)],
            \core_availability\tree::OP_AND,
            true
        ));
        $restricted = $this->getDataGenerator()->create_module(
            'quiz',
            ['course' => $course->id, 'grade' => 100, 'availability' => $availability]
        );
        $this->configure($course->id, [$cms[0]->id, $restricted->cmid], [$cms[0]->id, $restricted->cmid], 1);

        $this->setUser($student);
        $modinfo = get_fast_modinfo($course->id);
        $restrictedcm = $modinfo->get_cm($restricted->cmid);
        $this->assertFalse($restrictedcm->uservisible);
        $this->assertTrue($restrictedcm->is_visible_on_course_page());

        $this->assertNull(locknote::for_cm($restrictedcm));
        $this->assertSame(['islocked' => false, 'time' => self::LOCKTIME], locknote::for_cm($modinfo->get_cm($cms[0]->id)));
        $this->assertArrayNotHasKey($restricted->cmid, locknote::course_page_notes($course->id));
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

    /**
     * shows_note() answers for the activity page and the course page.
     */
    public function test_shows_note(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $cms] = $this->create_fixture();
        $lockid = $DB->insert_record('tool_activitydates_lock', (object) [
            'courseid' => $course->id, 'shownotecoursepage' => 0,
        ]);
        $DB->insert_record('tool_activitydates_lockitem', (object) ['lockid' => $lockid, 'cmid' => $cms[0]->id, 'shownote' => 1]);
        $DB->insert_record('tool_activitydates_lockitem', (object) ['lockid' => $lockid, 'cmid' => $cms[1]->id, 'shownote' => 0]);

        // Every placement x note row x course option: $cms[0] has its note on,
        // $cms[1] has it off, $cms[2] has no row. Only the note switched on shows,
        // and on the course page only when the course option is on as well.
        foreach ([0, 1] as $coursepageoption) {
            $DB->set_field('tool_activitydates_lock', 'shownotecoursepage', $coursepageoption, ['id' => $lockid]);
            foreach ([false, true] as $coursepage) {
                $where = ($coursepage ? 'course' : 'activity') . " page, course option $coursepageoption";
                $this->assertSame(
                    !$coursepage || $coursepageoption === 1,
                    locknote::shows_note((int) $cms[0]->id, $coursepage),
                    "note on, $where"
                );
                $this->assertFalse(locknote::shows_note((int) $cms[1]->id, $coursepage), "note off, $where");
                $this->assertFalse(locknote::shows_note((int) $cms[2]->id, $coursepage), "no row, $where");
            }
        }
    }
}
