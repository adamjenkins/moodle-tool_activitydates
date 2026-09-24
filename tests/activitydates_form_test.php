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
    }
}
