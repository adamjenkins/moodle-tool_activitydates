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
use tool_activitydates\local\course_order;

/**
 * Computes staggered gradebook lock dates, applies them to native grade
 * items, and persists the course's scheduling configuration and selections.
 */
class manager {
    /**
     * Compute a staggered lock date for each course module, grouping them
     * into sessions of $perssession activities (in the given order).
     *
     * Pure function: performs no reads or writes.
     *
     * @param array $cmids Course module IDs, in the order to be staggered.
     * @param int $schedulestart Timestamp when the first session starts.
     * @param int $sessionlengthdays Length of each session, in days.
     * @param int $perssession Number of activities grouped per session.
     * @return array Map of cmid => lock timestamp.
     */
    public function compute_lockdates(array $cmids, int $schedulestart, int $sessionlengthdays, int $perssession): array {
        $perssession = max(1, $perssession);
        $result = [];
        foreach (array_values($cmids) as $index => $cmid) {
            $session = intdiv($index, $perssession); // 0-based session.
            $result[$cmid] = $schedulestart + ($session + 1) * $sessionlengthdays * DAYSECS;
        }
        return $result;
    }

    /**
     * Apply computed lock dates to native grade items, and optionally clear
     * the locktime on activities of the same modtype that were not selected.
     *
     * @param array $lockdates Map of cmid => lock timestamp, as from compute_lockdates().
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
     * Upsert the course's tool_activitydates_lock configuration row and rebuild its
     * tool_activitydates_lockitem rows from the submitted selections.
     *
     * @param stdClass $formdata Submitted form data: modtype, schedulestart,
     *                           sessionlength, activitiespersession, shownote,
     *                           shownotecoursepage, resetunselected, cmids[],
     *                           shownote_cmids[].
     * @param int $courseid The course ID.
     * @return stdClass The upserted tool_activitydates_lock settings row.
     */
    public function update(stdClass $formdata, int $courseid): stdClass {
        global $DB;

        $settings = $DB->get_record('tool_activitydates_lock', ['courseid' => $courseid]);
        if (!$settings) {
            $settings = new stdClass();
            $settings->courseid = $courseid;
        }

        $settings->modtype = $formdata->modtype;
        $settings->schedulestart = (int) $formdata->schedulestart;
        $settings->sessionlength = (int) $formdata->sessionlength;
        $settings->activitiespersession = (int) $formdata->activitiespersession;
        $settings->shownote = !empty($formdata->shownote) ? 1 : 0;
        $settings->shownotecoursepage = !empty($formdata->shownotecoursepage) ? 1 : 0;
        $settings->resetunselected = !empty($formdata->resetunselected) ? 1 : 0;
        $settings->timemodified = time();

        // Only cmids that actually belong to this course and modtype may be persisted, otherwise
        // an editing teacher in one course could plant an item row for another course's/type's cmid.
        // get_instances_of() is keyed by instance id, not cmid, so read each cm_info's ->id.
        $validcmids = array_map(fn($cm) => (int) $cm->id, get_fast_modinfo($courseid)->get_instances_of($formdata->modtype));

        $cmids = !empty($formdata->cmids) ? array_map('intval', (array) $formdata->cmids) : [];
        $cmids = array_intersect($cmids, $validcmids);

        $shownotecmids = !empty($formdata->shownote_cmids)
            ? array_map('intval', (array) $formdata->shownote_cmids)
            : [];
        $shownotecmids = array_flip(array_intersect($shownotecmids, $validcmids));

        $transaction = $DB->start_delegated_transaction();

        if (!empty($settings->id)) {
            $DB->update_record('tool_activitydates_lock', $settings);
        } else {
            $settings->id = $DB->insert_record('tool_activitydates_lock', $settings);
        }

        $DB->delete_records('tool_activitydates_lockitem', ['lockid' => $settings->id]);

        foreach ($cmids as $cmid) {
            $item = new stdClass();
            $item->lockid = $settings->id;
            $item->cmid = $cmid;
            $item->shownote = array_key_exists($cmid, $shownotecmids) ? 1 : 0;
            $DB->insert_record('tool_activitydates_lockitem', $item);
        }

        $transaction->allow_commit();

        return $settings;
    }

    /**
     * Build the ordered activity table for the settings' modtype, in course-page
     * order (activities in a subsection appear where the subsection sits),
     * describing each activity's current lock state and selection.
     *
     * @param stdClass $settings A tool_activitydates_lock settings row (courseid, modtype, shownote, id if saved).
     * @return array Ordered list of rows: [cmid, name, gradeitemids[], locktime, selected, shownote].
     */
    public function get_table_data(stdClass $settings): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');

        $courseid = $settings->courseid;
        $modtype = $settings->modtype;

        $existingitems = [];
        if (!empty($settings->id)) {
            $records = $DB->get_records('tool_activitydates_lockitem', ['lockid' => $settings->id]);
            foreach ($records as $record) {
                $existingitems[$record->cmid] = $record;
            }
        }

        $modinfo = get_fast_modinfo($courseid);
        $rows = [];
        foreach (course_order::sort($modinfo, $modinfo->get_instances_of($modtype)) as $cm) {
            if ($cm->deletioninprogress) {
                continue;
            }

            $gradeitems = $this->fetch_mod_grade_items($courseid, $cm);
            $gradeitemids = [];
            $locktimes = [];
            if ($gradeitems) {
                foreach ($gradeitems as $gradeitem) {
                    $gradeitemids[] = (int) $gradeitem->id;
                    $itemlocktime = (int) $gradeitem->get_locktime();
                    if ($itemlocktime > 0) {
                        $locktimes[] = $itemlocktime;
                    }
                }
            }
            // Use the earliest scheduled locktime, matching how the student-facing note picks its
            // date; 0 (none) if no grade item has one. Core keeps locktime after cron locks an item,
            // so this can be a past date.
            $locktime = $locktimes ? min($locktimes) : 0;

            $selected = array_key_exists($cm->id, $existingitems);
            $shownote = $selected
                ? (bool) $existingitems[$cm->id]->shownote
                : (bool) ($settings->shownote ?? false);

            $rows[] = [
                'cmid' => $cm->id,
                'name' => $cm->name,
                'gradeitemids' => $gradeitemids,
                'locktime' => $locktime,
                'selected' => $selected,
                'shownote' => $shownote,
            ];
        }

        return $rows;
    }
}
