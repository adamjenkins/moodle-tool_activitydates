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
        global $PAGE;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'topics']);
        $quiz = $generator->create_module('quiz', ['course' => $course->id, 'grade' => 100]);

        $mgr = new manager();
        $mgr->update((object) [
            'modtype' => 'quiz',
            'schedulestart' => 2000000000,
            'sessionlength' => 7,
            'activitiespersession' => 1,
            'shownote' => 0,
            'shownotecoursepage' => 1,
            'resetunselected' => 0,
            'cmids' => [$quiz->cmid],
            'shownote_cmids' => [$quiz->cmid],
        ], $course->id);
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
}
