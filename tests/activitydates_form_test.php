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
 * Tests for the activity dates form.
 *
 * @package    tool_activitydates
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_activitydates;

use PHPUnit\Framework\Attributes\CoversClass;
use tool_activitydates\form\activitydates_form;

/**
 * Tests for the activitydates_form class.
 */
#[CoversClass(activitydates_form::class)]
final class activitydates_form_test extends \advanced_testcase {
    public function test_header_escapes_course_shortname(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['shortname' => 'SN<img src=x onerror=alert(1)>']);
        $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $PAGE->set_context(\context_course::instance($course->id));

        $form = new activitydates_form('x', [
            'courseid' => $course->id,
            'modules' => modtypes::eligible_course_modtypes($course->id),
            'modtype' => 'quiz',
            'settings' => (object) ['id' => 0, 'courseid' => $course->id, 'modtype' => 'quiz'],
        ]);
        $html = $form->render();

        $this->assertStringNotContainsString('<img src=x onerror', $html);
        // The header shows the shortname through format_string(), which strips the markup.
        $this->assertStringContainsString('Activity dates for SN</legend>', $html);
        // Preview (in the activity type group) and the shared button row
        // (tool_activitydates\local\action_buttons).
        foreach (['preview', 'submitbutton2', 'submitbutton', 'cancel'] as $button) {
            $this->assertStringContainsString('name="' . $button . '"', $html);
        }
        $this->assertStringNotContainsString('name="refresh"', $html);
        $this->assertStringContainsString('id="' . activitydates_form::FORM_ID . '"', $html);
    }

    /**
     * Build the form for a course with the given number of quizzes.
     *
     * @param int $quizzes how many quizzes to create.
     * @return activitydates_form
     */
    private function make_form(int $quizzes = 2): activitydates_form {
        global $PAGE;
        $course = $this->getDataGenerator()->create_course();
        for ($i = 0; $i < $quizzes; $i++) {
            $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        }
        $PAGE->set_context(\context_course::instance($course->id));
        return new activitydates_form('x', [
            'courseid' => $course->id,
            'modules' => modtypes::eligible_course_modtypes($course->id),
            'modtype' => 'quiz',
            'settings' => (object) ['id' => 0, 'courseid' => $course->id, 'modtype' => 'quiz'],
        ]);
    }

    /**
     * Valid submitted data for make_form()'s form, finish date disabled.
     *
     * @return array
     */
    private function valid_data(): array {
        return [
            'modtype' => 'quiz',
            'schedulestart' => make_timestamp(2030, 1, 7, 9, 0),
            'schedulefinish' => 0,
            'sessionlength' => 7,
            'activitiespersession' => 1,
            'closemode' => 'session',
            'closedays' => 7,
            'closedate' => 0,
        ];
    }

    public function test_due_controls_only_with_column(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $html = $this->make_form()->render();

        // Due controls exist exactly when the quiz table has a duedate column (5.3+).
        $hasdue = activitydates::has_duedate('quiz');
        $this->assertSame($hasdue, str_contains($html, 'name="duemode"'));
        $this->assertSame($hasdue, str_contains($html, 'name="duedays"'));
        // The close controls are always there, and stayavailable is gone.
        $this->assertStringContainsString('name="closemode"', $html);
        $this->assertStringContainsString('name="closedays"', $html);
        $this->assertStringContainsString('name="closedate[day]"', $html);
        $this->assertStringNotContainsString('stayavailable', $html);
        // The finish date is optional: it has an enable checkbox.
        $this->assertStringContainsString('name="schedulefinish[enabled]"', $html);
    }

    public function test_finish_optional_validation(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $form = $this->make_form();

        // Disabled finish date (submitted as 0): no finish or session-length error.
        $errors = $form->validation($this->valid_data(), []);
        $this->assertSame([], $errors);

        // Enabled and before the start: both finish checks still apply.
        $data = $this->valid_data();
        $data['schedulefinish'] = $data['schedulestart'] - DAYSECS;
        $errors = $form->validation($data, []);
        $this->assertArrayHasKey('schedulefinish', $errors);
        $this->assertArrayHasKey('sessionlength', $errors);
    }

    public function test_mode_settings_validation(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $form = $this->make_form();

        // Days mode needs at least one day.
        $data = ['closemode' => 'days', 'closedays' => 0] + $this->valid_data();
        $this->assertArrayHasKey('closedays', $form->validation($data, []));
        $data['closedays'] = 1;
        $this->assertSame([], $form->validation($data, []));

        // Date mode needs a date after the start.
        $data = ['closemode' => 'date', 'closedate' => make_timestamp(2030, 1, 7, 9, 0)] + $this->valid_data();
        $this->assertArrayHasKey('closedate', $form->validation($data, []));
        $data['closedate'] = make_timestamp(2030, 1, 7, 9, 1);
        $this->assertSame([], $form->validation($data, []));

        // A days value is ignored in other modes.
        $data = ['closemode' => 'session', 'closedays' => 0] + $this->valid_data();
        $this->assertSame([], $form->validation($data, []));

        // Activities per session is bounded by the number of quizzes (2).
        $data = ['activitiespersession' => 3] + $this->valid_data();
        $this->assertArrayHasKey('activitiespersession', $form->validation($data, []));
    }

    public function test_form_defaults(): void {
        $this->resetAfterTest();
        set_config('closemode', 'none', 'tool_activitydates');
        $start = make_timestamp(2030, 1, 7, 9, 0, 42);

        // Seconds are dropped, a disabled finish is 0, and absent fields use the site config.
        $defaults = activitydates_form::form_defaults((object) [
            'schedulestart' => $start,
            'finishenabled' => 0,
            'schedulefinish' => $start + 7 * DAYSECS,
        ]);
        $this->assertSame($start - 42, $defaults->schedulestart);
        $this->assertSame(0, $defaults->schedulefinish);
        $this->assertSame('none', $defaults->closemode);
        $this->assertSame(0, $defaults->closedate % 60);

        // An enabled finish is kept, floored to the minute.
        $defaults = activitydates_form::form_defaults((object) [
            'schedulestart' => $start,
            'finishenabled' => 1,
            'schedulefinish' => $start + 7 * DAYSECS,
            'closemode' => 'days',
        ]);
        $this->assertSame($start - 42 + 7 * DAYSECS, $defaults->schedulefinish);
        $this->assertSame('days', $defaults->closemode);
    }
}
