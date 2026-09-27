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
 * Hook listener callbacks for the tool_activitydates plugin.
 *
 * @package    tool_activitydates
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_activitydates\locks;

use tool_activitydates\locks\local\locknote;

/**
 * Hook listeners that add the student-facing grade-lock note to activity
 * pages and, where the course has opted in, to the course page.
 */
class hook_callbacks {
    /**
     * Add a note near the activity dates telling students when the
     * activity's grade will lock, or was locked.
     *
     * Triggered on every page, so it must cheaply bail out unless the
     * current page is an activity ({@see \moodle_page::$cm}) that has been
     * explicitly configured (via {@see \tool_activitydates\locks\manager}) to
     * show the note.
     *
     * @param \core\hook\output\before_standard_top_of_body_html_generation $hook
     */
    public static function add_activity_lock_note(
        \core\hook\output\before_standard_top_of_body_html_generation $hook
    ): void {
        global $PAGE, $OUTPUT;

        // NOTE: $PAGE->cm is a magic property (moodle_page::magic_get_cm()) and
        // moodle_page defines __get() but not __isset(), so isset()/empty() checks
        // directly on $PAGE->cm always treat it as unset. Read it into a local
        // variable first so the emptiness check actually reflects its value.
        $cm = $PAGE->cm;
        if (empty($cm)) {
            return;
        }
        $state = locknote::for_cm($cm);
        if (!$state) {
            return;
        }

        $html = $OUTPUT->render_from_template('tool_activitydates/locknote', [
            'islocked' => $state['islocked'],
            'date' => userdate($state['time']),
        ]);
        // The add_header_extras() method on moodle_page only exists in Moodle 5.2
        // and up (MDL-87931); this plugin also supports 5.0 and 5.1. Where it exists
        // it is preferred: this hook fires before standard_top_of_body_html() runs,
        // which is itself rendered before the activity header, so the note lands
        // in the header-extras region next to the activity's dates. On 5.0/5.1
        // fall back to the hook's own output, which renders the same note at the
        // top of the body instead.
        self::place_activity_note($hook, $html, method_exists($PAGE, 'add_header_extras'));
    }

    /**
     * Put the activity-page note in the header extras (Moodle 5.2+) or, failing
     * that, in the hook's own top-of-body output (Moodle 5.0/5.1).
     *
     * Split out so both branches can be tested on any Moodle version.
     *
     * @param \core\hook\output\before_standard_top_of_body_html_generation $hook The hook.
     * @param string $html The rendered note.
     * @param bool $useheaderextras Whether moodle_page::add_header_extras() is available.
     */
    public static function place_activity_note(
        \core\hook\output\before_standard_top_of_body_html_generation $hook,
        string $html,
        bool $useheaderextras
    ): void {
        global $PAGE;
        if ($useheaderextras) {
            $PAGE->add_header_extras($html);
        } else {
            $hook->add_html($html);
        }
    }

    /**
     * On a course page, add each switched-on lock note to its activity, when
     * the course has the "also show notes on the course page" option on.
     *
     * No core hook lets a plugin add to an activity on the course page, so
     * the notes are rendered here into a hidden container and the
     * tool_activitydates/coursenotes module moves each into its activity card.
     *
     * @param \core\hook\output\before_footer_html_generation $hook
     */
    public static function add_course_page_lock_notes(
        \core\hook\output\before_footer_html_generation $hook
    ): void {
        global $PAGE, $OUTPUT;

        // The course page and single-section pages only, told apart by URL: other
        // course pages (backup, reports, grade import/export, profile) set the very
        // same 'course-view-<format>' page type but list no activities.
        if (empty($PAGE->course->id) || !$PAGE->has_set_url()) {
            return;
        }
        $iscoursepage = false;
        foreach (['/course/view.php', '/course/section.php'] as $script) {
            $iscoursepage = $iscoursepage || $PAGE->url->compare(new \moodle_url($script), URL_MATCH_BASE);
        }
        if (!$iscoursepage) {
            return;
        }
        $notes = locknote::course_page_notes((int) $PAGE->course->id);
        if (!$notes) {
            return;
        }

        $context = [];
        foreach ($notes as $cmid => $state) {
            $context[] = ['cmid' => $cmid, 'islocked' => $state['islocked'], 'date' => userdate($state['time'])];
        }
        $hook->add_html($OUTPUT->render_from_template('tool_activitydates/coursenotes', ['notes' => $context]));
        $PAGE->requires->js_call_amd('tool_activitydates/coursenotes', 'init');
    }
}
