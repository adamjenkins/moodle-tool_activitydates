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
 * Tests for the tab row.
 *
 * @package    tool_activitydates
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_activitydates\local;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for the tabs class.
 */
#[CoversClass(tabs::class)]
final class tabs_test extends \advanced_testcase {
    /**
     * Capability combinations and what the user should get.
     *
     * @return array
     */
    public static function capability_provider(): array {
        return [
            'both' => [true, true, true, 'view.php'],
            'dates only' => [true, false, false, 'view.php'],
            'locks only' => [false, true, false, 'locks.php'],
            'neither' => [false, false, false, null],
        ];
    }

    /**
     * The tab row appears only with both capabilities; first_url() picks the first page the user can open.
     *
     * @param bool $dates Whether the user has tool/activitydates:manage.
     * @param bool $locks Whether the user has tool/activitydates:managelocks.
     * @param bool $hastabs Whether a tab row is expected.
     * @param string|null $firstscript The expected first page, or null for none.
     */
    #[DataProvider('capability_provider')]
    public function test_render_and_first_url(bool $dates, bool $locks, bool $hastabs, ?string $firstscript): void {
        global $PAGE;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('tool/activitydates:manage', $dates ? CAP_ALLOW : CAP_PROHIBIT, $roleid, $context);
        assign_capability('tool/activitydates:managelocks', $locks ? CAP_ALLOW : CAP_PROHIBIT, $roleid, $context);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $roleid);
        $this->setUser($user);
        $PAGE->set_context($context);

        $html = tabs::render((int) $course->id, 'dates');
        if ($hastabs) {
            $this->assertStringContainsString('Activity dates', $html);
            $this->assertStringContainsString('Grade locks', $html);
        } else {
            $this->assertSame('', $html);
        }

        $url = tabs::first_url((int) $course->id);
        if ($firstscript === null) {
            $this->assertNull($url);
        } else {
            $this->assertStringEndsWith('/admin/tool/activitydates/' . $firstscript, $url->out_omit_querystring());
        }
    }

    /**
     * The tab row marks the current page's tab, and only that one, as active.
     */
    public function test_render_marks_current_tab(): void {
        global $PAGE;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($user);
        $PAGE->set_context(\context_course::instance($course->id));

        $labels = ['dates' => 'Activity dates', 'locks' => 'Grade locks'];
        foreach ($labels as $current => $label) {
            $html = tabs::render((int) $course->id, $current);
            foreach ($labels as $id => $otherlabel) {
                $pattern = '~class="nav-link active"[^>]*>' . preg_quote($otherlabel, '~') . '</a>~';
                if ($id === $current) {
                    $this->assertMatchesRegularExpression($pattern, $html);
                } else {
                    $this->assertDoesNotMatchRegularExpression($pattern, $html);
                    $this->assertStringContainsString('>' . $otherlabel . '</a>', $html);
                }
            }
        }
    }

    /**
     * Users who can open the Grade locks page, and the page their navigation entry points at.
     *
     * @return array
     */
    public static function locks_user_provider(): array {
        return [
            'both' => [true, 'view.php'],
            'locks only' => [false, 'locks.php'],
        ];
    }

    /**
     * On the Grade locks page, the course-administration "Activity dates" entry is the
     * active navigation node, whichever page that entry points at for the user.
     *
     * @param bool $dates Whether the user also has tool/activitydates:manage.
     * @param string $firstscript The page the entry points at.
     */
    #[DataProvider('locks_user_provider')]
    public function test_highlight_navigation(bool $dates, string $firstscript): void {
        global $PAGE;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('tool/activitydates:manage', $dates ? CAP_ALLOW : CAP_PROHIBIT, $roleid, $context);
        assign_capability('tool/activitydates:managelocks', CAP_ALLOW, $roleid, $context);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $roleid);
        $this->setUser($user);

        // Set the page up as locks.php does.
        $PAGE->set_url(new \moodle_url('/admin/tool/activitydates/locks.php', ['courseid' => $course->id]));
        $PAGE->set_course($course);
        $PAGE->set_context($context);
        $PAGE->set_pagelayout('admin');
        tabs::highlight_navigation((int) $course->id);

        $active = $PAGE->settingsnav->find_active_node();
        $this->assertNotFalse($active, 'No active node in the settings navigation');
        $this->assertSame(get_string('pluginname', 'tool_activitydates'), $active->text);
        $this->assertStringEndsWith('/admin/tool/activitydates/' . $firstscript, $active->action->out_omit_querystring());
    }
}
