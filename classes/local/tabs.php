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

namespace tool_activitydates\local;

use core\output\tabobject;
use core\output\tabtree;
use moodle_url;

/**
 * The "Activity dates | Grade locks" tab row and the page each user starts on.
 *
 * @package    tool_activitydates
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tabs {
    /**
     * The tabs the current user may open, in display order.
     *
     * @param int $courseid The course ID.
     * @return array tab id => [moodle_url, lang string key]
     */
    private static function available(int $courseid): array {
        $context = \context_course::instance($courseid);
        $tabs = [];
        if (has_capability('tool/activitydates:manage', $context)) {
            $tabs['dates'] = [new moodle_url('/admin/tool/activitydates/view.php', ['courseid' => $courseid]), 'tabdates'];
        }
        if (has_capability('tool/activitydates:managelocks', $context)) {
            $tabs['locks'] = [new moodle_url('/admin/tool/activitydates/locks.php', ['courseid' => $courseid]), 'tablocks'];
        }
        return $tabs;
    }

    /**
     * Render the tab row, or nothing if the user can open only one tab.
     *
     * @param int $courseid The course ID.
     * @param string $current The current tab: 'dates' or 'locks'.
     * @return string HTML.
     */
    public static function render(int $courseid, string $current): string {
        global $OUTPUT;
        $available = self::available($courseid);
        if (count($available) < 2) {
            return '';
        }
        $tabs = [];
        foreach ($available as $id => [$url, $key]) {
            $tabs[] = new tabobject($id, $url, get_string($key, 'tool_activitydates'));
        }
        return $OUTPUT->render(new tabtree($tabs, $current));
    }

    /**
     * The first page the current user may open, for the navigation entry.
     *
     * @param int $courseid The course ID.
     * @return moodle_url|null Null if the user may open neither page.
     */
    public static function first_url(int $courseid): ?moodle_url {
        $available = self::available($courseid);
        return $available ? reset($available)[0] : null;
    }

    /**
     * Mark the course-administration "Activity dates" entry as the active node.
     *
     * The entry points at first_url() (see lib.php), which is locks.php for a user
     * who holds only managelocks, so match that rather than a fixed view.php.
     *
     * @param int $courseid The course ID.
     */
    public static function highlight_navigation(int $courseid): void {
        $url = self::first_url($courseid);
        if ($url) {
            \navigation_node::override_active_url($url);
        }
    }
}
