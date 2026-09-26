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
        global $DB, $PAGE;
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
}
