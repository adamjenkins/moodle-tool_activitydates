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
 * Gradebook lock scheduling manager for the tool_activitydates plugin.
 *
 * @package    tool_activitydates
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_activitydates\locks;

use stdClass;

/**
 * Reads and writes the lock dates of native grade items.
 */
class manager {
    /**
     * Apply lock dates to native grade items, and optionally clear
     * the locktime on activities of the same modtype that were not selected.
     *
     * @param array $lockdates Map of cmid => lock timestamp; 0 clears the lock.
     * @param string $modtype The course module type being scheduled.
     * @param int $courseid The course ID.
     * @param bool $resetunselected If true, clear locktime on unselected activities of $modtype.
     * @return int The number of activities whose grade item(s) were changed.
     */
    public function apply_locks(array $lockdates, string $modtype, int $courseid, bool $resetunselected): int {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $changed = 0;

        foreach ($lockdates as $cmid => $locktime) {
            $cm = get_coursemodule_from_id('', $cmid, $courseid, false, MUST_EXIST);
            $gradeitems = $this->fetch_mod_grade_items($courseid, $cm);
            if (!$gradeitems) {
                continue;
            }
            foreach ($gradeitems as $gradeitem) {
                $gradeitem->set_locktime((int) $locktime);
            }
            $changed++;
        }

        if ($resetunselected) {
            $modinfo = get_fast_modinfo($courseid);
            foreach ($modinfo->get_instances_of($modtype) as $cm) {
                if ($cm->deletioninprogress || array_key_exists($cm->id, $lockdates)) {
                    continue;
                }
                $gradeitems = $this->fetch_mod_grade_items($courseid, $cm);
                if (!$gradeitems) {
                    continue;
                }
                foreach ($gradeitems as $gradeitem) {
                    $gradeitem->set_locktime(0);
                }
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * Fetch the itemtype='mod' grade item(s) for a course module.
     *
     * @param int $courseid The course ID.
     * @param \cm_info|stdClass $cm The course module record (must have modname and instance).
     * @return array Grade items, as returned by \grade_item::fetch_all(), or an empty array if none.
     */
    private function fetch_mod_grade_items(int $courseid, $cm): array {
        $gradeitems = \grade_item::fetch_all([
            'courseid' => $courseid,
            'itemtype' => 'mod',
            'itemmodule' => $cm->modname,
            'iteminstance' => $cm->instance,
        ]);
        return $gradeitems ?: [];
    }

    /**
     * The current lock date of a course module: the earliest locktime of its
     * grade items, matching how the student-facing note picks its date.
     *
     * Core keeps locktime after cron locks an item, so this can be a past date.
     *
     * @param int $courseid The course ID.
     * @param \cm_info $cm The course module.
     * @return int The earliest locktime, or 0 if no grade item has one (or there is no grade item).
     */
    public function current_locktime(int $courseid, \cm_info $cm): int {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $locktimes = [];
        foreach ($this->fetch_mod_grade_items($courseid, $cm) as $gradeitem) {
            $locktime = (int) $gradeitem->get_locktime();
            if ($locktime > 0) {
                $locktimes[] = $locktime;
            }
        }
        return $locktimes ? min($locktimes) : 0;
    }

    /**
     * Whether a course module has an itemtype='mod' grade item.
     *
     * @param int $courseid The course ID.
     * @param \cm_info $cm The course module.
     * @return bool
     */
    public function has_grade_item(int $courseid, \cm_info $cm): bool {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        return (bool) $this->fetch_mod_grade_items($courseid, $cm);
    }
}
