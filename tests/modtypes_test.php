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
 * Unit tests for the modtypes module eligibility detector.
 *
 * @package    tool_activitydates
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_activitydates;

/**
 * Tests for modtypes class.
 */
final class modtypes_test extends \advanced_testcase {
    public function test_has_date_columns(): void {
        $this->assertTrue(modtypes::has_date_columns('quiz'));
        $this->assertTrue(modtypes::has_date_columns('choice'));
        $this->assertFalse(modtypes::has_date_columns('assign'));   // Uses allowsubmissionsfromdate.
        $this->assertFalse(modtypes::has_date_columns('page'));
        $this->assertFalse(modtypes::has_date_columns('nosuchmodule'));
    }

    public function test_eligible_course_modtypes(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $generator->create_module('quiz', ['course' => $course->id]);
        $generator->create_module('quiz', ['course' => $course->id]);
        $generator->create_module('choice', ['course' => $course->id]);
        $generator->create_module('assign', ['course' => $course->id]);
        $generator->create_module('page', ['course' => $course->id]);

        $types = modtypes::eligible_course_modtypes($course->id);
        $this->assertSame(['choice', 'quiz'], array_keys($types)); // Deduped, label-sorted, assign/page excluded.
    }
}
