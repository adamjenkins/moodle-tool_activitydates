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

namespace tool_activitydates\locks\local;

use cm_info;

/**
 * Works out the student-facing grade-lock note for activities, for both the
 * activity page and the course page, so the two always agree.
 *
 * A note state is ['islocked' => bool, 'time' => int]: the lock date once any
 * of the activity's grade items is locked, otherwise its earliest scheduled
 * lock date.
 *
 * @package    tool_activitydates
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class locknote {
    /**
     * The note state for one activity's grade items.
     *
     * @param array $gradeitems The activity's grade_item objects.
     * @return array|null Note state, or null if nothing is locked or scheduled.
     */
    public static function lock_state(array $gradeitems): ?array {
        $locktime = 0;
        $lockedat = 0;
        foreach ($gradeitems as $gradeitem) {
            if ($gradeitem->is_locked()) {
                $lockedat = $lockedat ? min($lockedat, (int) $gradeitem->locked) : (int) $gradeitem->locked;
            }
            $itemlocktime = (int) $gradeitem->get_locktime();
            if ($itemlocktime > 0) {
                $locktime = $locktime ? min($locktime, $itemlocktime) : $itemlocktime;
            }
        }
        if (!$lockedat && !$locktime) {
            return null;
        }
        return ['islocked' => (bool) $lockedat, 'time' => $lockedat ?: $locktime];
    }

    /**
     * The note state for an activity page, if the activity's note is switched on.
     *
     * Nothing for an activity the current user cannot open: core's restricted-activity
     * page sets such an activity as the page's cm, and the course page shows it no
     * note either (see course_page_notes()).
     *
     * @param cm_info $cm The activity, from the current user's modinfo.
     * @return array|null Note state, or null if there is no note to show.
     */
    public static function for_cm(cm_info $cm): ?array {
        global $CFG, $DB;

        if (!$cm->uservisible || $cm->deletioninprogress) {
            return null;
        }

        // Course-scoped lookup: the item must belong to a tool_activitydates
        // configuration row for THIS course, so a note row can never affect
        // an activity in another course (defense in depth).
        $sql = "SELECT i.id
                  FROM {tool_activitydates_lockitem} i
                  JOIN {tool_activitydates_lock} t ON t.id = i.lockid
                 WHERE i.cmid = :cmid AND i.shownote = 1 AND t.courseid = :courseid";
        if (!$DB->record_exists_sql($sql, ['cmid' => $cm->id, 'courseid' => $cm->course])) {
            return null;
        }

        require_once($CFG->libdir . '/gradelib.php');
        $gradeitems = \grade_item::fetch_all([
            'courseid' => $cm->course,
            'itemtype' => 'mod',
            'itemmodule' => $cm->modname,
            'iteminstance' => $cm->instance,
        ]);
        return $gradeitems ? self::lock_state($gradeitems) : null;
    }

    /**
     * The note states to show on a course page, for the current user.
     *
     * Only activities whose note is switched on, also for the course page, and
     * which the current user sees listed on the course page are included, so
     * nothing about a hidden or stealth activity reaches the page.
     *
     * @param int $courseid The course ID.
     * @return array cmid => note state.
     */
    public static function course_page_notes(int $courseid): array {
        global $CFG, $DB;

        // Course-scoped, as in for_cm(): only items of this course's configuration row.
        $notecmids = $DB->get_fieldset_sql(
            "SELECT i.cmid
               FROM {tool_activitydates_lockitem} i
               JOIN {tool_activitydates_lock} t ON t.id = i.lockid
              WHERE t.courseid = :courseid AND i.shownote = 1 AND i.shownotecoursepage = 1",
            ['courseid' => $courseid]
        );
        if (!$notecmids) {
            return [];
        }

        // The visible activities with a note, as cmid => grade items (filled below).
        $notecmids = array_flip(array_map('intval', $notecmids));
        $itemsbycm = [];
        $cmidbyinstance = [];
        foreach (get_fast_modinfo($courseid)->get_cms() as $cm) {
            // The uservisible flag alone admits stealth activities, which are available
            // but not listed on the course page; their note would leak into the page.
            if (
                isset($notecmids[(int) $cm->id]) && $cm->uservisible
                && $cm->is_visible_on_course_page() && !$cm->deletioninprogress
            ) {
                $itemsbycm[(int) $cm->id] = [];
                $cmidbyinstance[$cm->modname][(int) $cm->instance] = (int) $cm->id;
            }
        }
        if (!$itemsbycm) {
            return [];
        }

        // One query for every activity grade item in the course.
        require_once($CFG->libdir . '/gradelib.php');
        foreach (\grade_item::fetch_all(['courseid' => $courseid, 'itemtype' => 'mod']) ?: [] as $gradeitem) {
            $cmid = $cmidbyinstance[$gradeitem->itemmodule][(int) $gradeitem->iteminstance] ?? null;
            if ($cmid) {
                $itemsbycm[$cmid][] = $gradeitem;
            }
        }

        $notes = [];
        foreach ($itemsbycm as $cmid => $gradeitems) {
            $state = self::lock_state($gradeitems);
            if ($state) {
                $notes[$cmid] = $state;
            }
        }
        return $notes;
    }

    /**
     * Whether this plugin shows a lock note for an activity.
     *
     * Public contract: tool_timelocker 0.1.1 calls this to stay quiet where
     * activitydates already shows a note, so students see one note, not two.
     *
     * @param int $cmid The course module ID.
     * @param bool $coursepage True for the course page, false for the activity page.
     * @return bool
     */
    public static function shows_note(int $cmid, bool $coursepage): bool {
        global $DB;
        $sql = "SELECT 1
                  FROM {tool_activitydates_lockitem} i
                  JOIN {tool_activitydates_lock} l ON l.id = i.lockid
                 WHERE i.cmid = :cmid AND i.shownote = 1";
        if ($coursepage) {
            $sql .= " AND i.shownotecoursepage = 1";
        }
        return $DB->record_exists_sql($sql, ['cmid' => $cmid]);
    }
}
