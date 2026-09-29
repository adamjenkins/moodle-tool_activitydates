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

use core\hook\output\before_footer_html_generation;
use core\hook\output\before_standard_top_of_body_html_generation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Unit tests for the tool_activitydates hook callbacks.
 *
 * @package    tool_activitydates
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(hook_callbacks::class)]
final class hook_callbacks_test extends \advanced_testcase {
    /**
     * Pages (script and page type as core sets them), and whether the
     * course-page notes belong on them.
     *
     * @return array
     */
    public static function page_provider(): array {
        return [
            'course page' => ['/course/view.php', 'course-view-topics', true],
            'single-section page' => ['/course/section.php', 'course-view-section-topics', true],
            // These core pages set the course page's exact page type.
            'backup' => ['/backup/backup.php', 'course-view-topics', false],
            'reports' => ['/report/view.php', 'course-view-topics', false],
            'grade export' => ['/grade/export/index.php', 'course-view-topics', false],
            'participants' => ['/user/index.php', 'course-view-participants', false],
            'activity page' => ['/mod/quiz/view.php', 'mod-quiz-view', false],
        ];
    }

    /**
     * The course-page notes are added only on the course and single-section
     * pages, never on other course pages sharing their page type.
     *
     * @param string $script The page's script path.
     * @param string $pagetype The page type core sets on that page.
     * @param bool $expected Whether the notes should be added.
     */
    #[DataProvider('page_provider')]
    public function test_add_course_page_lock_notes_pages(string $script, string $pagetype, bool $expected): void {
        global $DB, $PAGE;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'topics']);
        $quiz = $generator->create_module('quiz', ['course' => $course->id, 'grade' => 100]);

        $lockid = $DB->insert_record('tool_activitydates_lock', (object) [
            'courseid' => $course->id,
            'resetunselected' => 0,
        ]);
        $DB->insert_record(
            'tool_activitydates_lockitem',
            (object) ['lockid' => $lockid, 'cmid' => $quiz->cmid, 'shownote' => 1, 'shownotecoursepage' => 1]
        );
        $mgr = new manager();
        $mgr->apply_locks([$quiz->cmid => 2000000000 + 7 * DAYSECS], 'quiz', $course->id, false);
        $this->setAdminUser();

        $PAGE->set_course($course);
        $PAGE->set_url(new \moodle_url($script, ['id' => $course->id]));
        $PAGE->set_pagetype($pagetype);
        $hook = new before_footer_html_generation($PAGE->get_renderer('core'));
        hook_callbacks::add_course_page_lock_notes($hook);

        if ($expected) {
            $this->assertStringContainsString('data-cmid="' . $quiz->cmid . '"', $hook->get_output());
            $this->assertStringContainsString('Grades lock after', $hook->get_output());
        } else {
            $this->assertSame('', $hook->get_output());
        }
    }

    /**
     * Without header extras (Moodle 5.0/5.1) the note goes into the hook's output.
     */
    public function test_place_activity_note_fallback(): void {
        global $PAGE;
        $this->resetAfterTest();
        $hook = new before_standard_top_of_body_html_generation($PAGE->get_renderer('core'));
        hook_callbacks::place_activity_note($hook, '<div class="probe">note</div>', false);
        $this->assertSame('<div class="probe">note</div>', $hook->get_output());
    }

    /**
     * With header extras (Moodle 5.2+) the note goes into the page header, not the hook.
     */
    public function test_place_activity_note_header_extras(): void {
        global $PAGE;
        if (!method_exists($PAGE, 'add_header_extras')) {
            $this->markTestSkipped('moodle_page::add_header_extras() needs Moodle 5.2+.');
        }
        $this->resetAfterTest();
        $hook = new before_standard_top_of_body_html_generation($PAGE->get_renderer('core'));
        hook_callbacks::place_activity_note($hook, '<div class="probe">note</div>', true);
        $this->assertSame('', $hook->get_output());
        $this->assertContains('<div class="probe">note</div>', $PAGE->get_header_extras());
    }
}
