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

    /**
     * Render the form for a course with no saved configuration, as view.php does.
     *
     * @return string the rendered form.
     */
    private function render_new_course_form(): string {
        global $PAGE;
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $PAGE->set_context(\context_course::instance($course->id));
        $settings = activitydates_form::new_course_defaults($course->id);
        $settings->modtype = 'quiz';
        $form = new activitydates_form('x', [
            'courseid' => $course->id,
            'modules' => modtypes::eligible_course_modtypes($course->id),
            'modtype' => 'quiz',
            'settings' => $settings,
        ]);
        return $form->render();
    }

    /**
     * Whether the rendered form's finish date enable checkbox is ticked.
     *
     * @param string $html the rendered form.
     * @return bool
     */
    private function finish_checked(string $html): bool {
        $this->assertSame(1, preg_match('~<input[^>]*name="schedulefinish\[enabled\]"[^>]*>~', $html, $matches));
        return (bool) preg_match('~\schecked\b~', $matches[0]);
    }

    public function test_finish_disabled_by_default(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // No site default (a site that has not saved the setting yet): off.
        unset_config('finishenabled', 'tool_activitydates');
        $this->assertSame(0, activitydates_form::new_course_defaults(1)->finishenabled);
        $this->assertSame(0, activitydates_form::new_course_defaults(1)->schedulefinish);
        $this->assertFalse($this->finish_checked($this->render_new_course_form()));

        // The site default off: still off.
        set_config('finishenabled', 0, 'tool_activitydates');
        $this->assertSame(0, activitydates_form::new_course_defaults(1)->finishenabled);
        $this->assertFalse($this->finish_checked($this->render_new_course_form()));

        // The site default on: ticked, two weeks after the start.
        set_config('finishenabled', 1, 'tool_activitydates');
        $defaults = activitydates_form::new_course_defaults(1);
        $this->assertSame(1, $defaults->finishenabled);
        $this->assertSame($defaults->schedulestart + 14 * DAYSECS, $defaults->schedulefinish);
        $this->assertTrue($this->finish_checked($this->render_new_course_form()));
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

    /**
     * Render the form for a course with one quiz and one assignment.
     *
     * @param string $modtype the type shown.
     * @param array $flags the canmanage, canlocks, hasdates and hasdue customdata.
     * @param array $settings extra settings fields.
     * @return string the rendered form.
     */
    private function render_with(string $modtype, array $flags, array $settings = []): string {
        global $PAGE;
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $PAGE->set_context(\context_course::instance($course->id));
        $types = local\pagetypes::for_course($course->id, true, true);
        $form = new activitydates_form('x', [
            'courseid' => $course->id,
            'modules' => array_map(fn(array $type): string => $type['label'], $types),
            'modtype' => $modtype,
            'settings' => (object) ($settings + ['id' => 0, 'courseid' => $course->id, 'modtype' => $modtype]),
        ] + $flags);
        return $form->render();
    }

    /**
     * The opening tag of the Grade locks section's fieldset.
     *
     * @param string $html the rendered form.
     * @return string
     */
    private function lock_fieldset(string $html): string {
        $this->assertSame(1, preg_match('~<fieldset[^>]*id="id_gradelocksheader"[^>]*>~', $html, $matches));
        return $matches[0];
    }

    public function test_lock_section_collapsed_when_none(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $flags = ['canmanage' => true, 'canlocks' => true, 'hasdates' => true, 'hasdue' => false];

        // No lock: the section is collapsed.
        $html = $this->render_with('quiz', $flags, ['lockmode' => 'none']);
        $this->assertStringContainsString('collapsed', $this->lock_fieldset($html));
        $this->assertStringContainsString('>' . get_string('gradelocksheader', 'tool_activitydates') . '<', $html);

        // A lock mode in use: expanded.
        $html = $this->render_with('quiz', $flags, ['lockmode' => 'session']);
        $this->assertStringNotContainsString('collapsed', $this->lock_fieldset($html));

        // No saved lock mode and no site default: none, so collapsed.
        unset_config('lockmode', 'tool_activitydates');
        $html = $this->render_with('quiz', $flags);
        $this->assertStringContainsString('collapsed', $this->lock_fieldset($html));
    }

    public function test_controls_by_capability(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $datecontrols = ['name="closemode"', 'name="closedays"', 'name="hideunselected"', 'name="resetunselected"'];
        $lockcontrols = ['name="lockmode"', 'name="lockdays"', 'name="lockdate[day]"', 'name="lockresetunselected"',
            'id="id_gradelocksheader"'];

        // Both capabilities: everything.
        $html = $this->render_with('quiz', ['canmanage' => true, 'canlocks' => true, 'hasdates' => true, 'hasdue' => false]);
        foreach (array_merge($datecontrols, $lockcontrols) as $control) {
            $this->assertStringContainsString($control, $html);
        }
        // The grade-lock note options are per activity, in the table, not in the form.
        $this->assertStringNotContainsString('name="shownote"', $html);
        $this->assertStringNotContainsString('name="shownotecoursepage"', $html);

        // Without :manage: no date controls, the lock controls stay.
        $html = $this->render_with('quiz', ['canmanage' => false, 'canlocks' => true, 'hasdates' => true, 'hasdue' => true]);
        foreach ($datecontrols as $control) {
            $this->assertStringNotContainsString($control, $html);
        }
        $this->assertStringNotContainsString('name="duemode"', $html);
        foreach ($lockcontrols as $control) {
            $this->assertStringContainsString($control, $html);
        }

        // Without :managelocks: no lock controls, the date controls stay.
        $html = $this->render_with('quiz', ['canmanage' => true, 'canlocks' => false, 'hasdates' => true, 'hasdue' => false]);
        foreach ($datecontrols as $control) {
            $this->assertStringContainsString($control, $html);
        }
        foreach ($lockcontrols as $control) {
            $this->assertStringNotContainsString($control, $html);
        }

        // The schedule is there either way.
        foreach (['name="schedulestart[day]"', 'name="sessionlength"', 'name="activitiespersession"'] as $control) {
            $this->assertStringContainsString($control, $html);
        }
    }

    public function test_lock_only_type_has_only_lock_controls(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $html = $this->render_with('assign', ['canmanage' => true, 'canlocks' => true, 'hasdates' => false, 'hasdue' => false]);

        foreach (['closemode', 'closedays', 'duemode', 'hideunselected', 'resetunselected'] as $name) {
            $this->assertStringNotContainsString('name="' . $name . '"', $html);
        }
        $this->assertStringNotContainsString('id="id_advancedheader"', $html);
        foreach (['lockmode', 'lockdays', 'lockresetunselected', 'sessionlength'] as $name) {
            $this->assertStringContainsString('name="' . $name . '"', $html);
        }
    }

    /**
     * The form sections that contain the Save and Cancel buttons.
     *
     * @param string $html the rendered form.
     * @return string[] the ids of the collapsible fieldsets around the button row.
     */
    private function button_sections(string $html): array {
        $doc = new \DOMDocument();
        $this->assertTrue(@$doc->loadHTML($html));
        $buttons = (new \DOMXPath($doc))->query('//*[@id="fgroup_id_buttonar"]');
        $this->assertSame(1, $buttons->length);
        $sections = [];
        for ($node = $buttons->item(0)->parentNode; $node instanceof \DOMElement; $node = $node->parentNode) {
            if ($node->tagName === 'fieldset' && str_contains($node->getAttribute('class'), 'collapsible')) {
                $sections[] = $node->getAttribute('id');
            }
        }
        return $sections;
    }

    public function test_buttons_outside_collapsible_sections(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $cases = [
            // The last section is the collapsed Advanced section.
            ['quiz', ['canmanage' => true, 'canlocks' => true, 'hasdates' => true, 'hasdue' => false]],
            // The last section is the Grade locks section, collapsed while there is no lock.
            ['quiz', ['canmanage' => false, 'canlocks' => true, 'hasdates' => true, 'hasdue' => false]],
            ['assign', ['canmanage' => true, 'canlocks' => true, 'hasdates' => false, 'hasdue' => false]],
            ['quiz', ['canmanage' => true, 'canlocks' => false, 'hasdates' => true, 'hasdue' => false]],
        ];
        foreach ($cases as [$modtype, $flags]) {
            $html = $this->render_with($modtype, $flags, ['lockmode' => 'none']);
            $this->assertSame([], $this->button_sections($html), $modtype . ' ' . json_encode($flags));
        }
    }

    /**
     * The end offsets of the rendered form's collapsible sections.
     *
     * @param string $html the rendered form.
     * @return int[] the offset just after each collapsible fieldset's closing tag.
     */
    private function collapsible_section_ends(string $html): array {
        preg_match_all('~<fieldset\b[^>]*>|</fieldset>~', $html, $tags, PREG_OFFSET_CAPTURE);
        $stack = [];
        $ends = [];
        foreach ($tags[0] as [$tag, $offset]) {
            if ($tag !== '</fieldset>') {
                $stack[] = str_contains($tag, 'collapsible');
                continue;
            }
            $this->assertNotEmpty($stack, 'Unbalanced </fieldset>');
            if (array_pop($stack)) {
                $ends[] = $offset + strlen($tag);
            }
        }
        $this->assertSame([], $stack, 'Unclosed <fieldset>');
        return $ends;
    }

    public function test_buttons_outside_sections(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $cases = [
            ['quiz', ['canmanage' => true, 'canlocks' => true, 'hasdates' => true, 'hasdue' => false]],
            ['quiz', ['canmanage' => false, 'canlocks' => true, 'hasdates' => true, 'hasdue' => false]],
            ['assign', ['canmanage' => true, 'canlocks' => true, 'hasdates' => false, 'hasdue' => false]],
            ['quiz', ['canmanage' => true, 'canlocks' => false, 'hasdates' => true, 'hasdue' => false]],
        ];
        foreach ($cases as [$modtype, $flags]) {
            $html = $this->render_with($modtype, $flags, ['lockmode' => 'none']);
            $label = $modtype . ' ' . json_encode($flags);
            $ends = $this->collapsible_section_ends($html);
            // The Activity dates section at least, plus Grade locks and Advanced when shown.
            $this->assertNotEmpty($ends, $label);
            $button = strpos($html, 'name="submitbutton"');
            $this->assertNotFalse($button, $label);
            // The Save buttons come after the last collapsible section has closed.
            $this->assertGreaterThan(max($ends), $button, $label);
        }
    }

    public function test_lock_settings_validation(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $form = $this->make_form();

        // Lock days and lock date follow the close rules.
        $data = ['lockmode' => 'days', 'lockdays' => 0] + $this->valid_data();
        $this->assertArrayHasKey('lockdays', $form->validation($data, []));
        $data['lockdays'] = 1;
        $this->assertSame([], $form->validation($data, []));

        $data = ['lockmode' => 'date', 'lockdate' => make_timestamp(2030, 1, 7, 9, 0)] + $this->valid_data();
        $this->assertSame(
            get_string('errorlockdatebeforestart', 'tool_activitydates'),
            $form->validation($data, [])['lockdate'] ?? null
        );
        $data['lockdate'] = make_timestamp(2030, 1, 7, 9, 1);
        $this->assertSame([], $form->validation($data, []));

        // No lock ignores both.
        $data = ['lockmode' => 'none', 'lockdays' => 0, 'lockdate' => 0] + $this->valid_data();
        $this->assertSame([], $form->validation($data, []));
    }
}
