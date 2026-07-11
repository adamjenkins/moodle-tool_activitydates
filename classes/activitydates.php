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
 * Scheduling/manager core for the tool_activitydates plugin.
 *
 * @package    tool_activitydates
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_activitydates;

/**
 * Manages course-level activity date scheduling configuration.
 */
class activitydates {
    /**
     * Upsert the course's activity dates configuration and its selections.
     *
     * @param \stdClass $fromform submitted form data.
     * @param int $courseid the course ID.
     * @return array [$selections, $settings] where $selections is a list of
     *     selected coursemoduleids and $settings is the saved config record.
     */
    public function update(\stdClass $fromform, int $courseid): array {
        global $DB;
        $existing = $DB->get_record('tool_activitydates', ['courseid' => $courseid]);
        $data = (object) [
            'courseid' => $courseid,
            'modtype' => $fromform->modtype,
            'schedulestart' => $fromform->schedulestart,
            'schedulefinish' => $fromform->schedulefinish,
            'sessionlength' => $fromform->sessionlength,
            'activitiespersession' => $fromform->activitiespersession,
            'stayavailable' => (int) !empty($fromform->stayavailable),
            'hideunselected' => (int) !empty($fromform->hideunselected),
            'resetunselected' => (int) !empty($fromform->resetunselected),
            'timemodified' => time(),
        ];
        if ($existing) {
            $data->id = $existing->id;
            $DB->update_record('tool_activitydates', $data);
        } else {
            $data->id = $DB->insert_record('tool_activitydates', $data);
        }
        // Call once only: driprelease's bug is calling this twice, which is harmless
        // but wasteful and a source of confusion when tracing selection changes.
        $this->manage_selections($fromform, $data->id);
        $selections = array_values($DB->get_records(
            'tool_activitydates_cmids',
            ['activitydates' => $data->id],
            'coursemoduleid ASC',
            'coursemoduleid'
        ));
        return [$selections, $data];
    }

    /**
     * Diff the form's activitygroup checkboxes against the current selection
     * table, inserting newly-checked cmids and deleting newly-unchecked ones.
     *
     * @param \stdClass $fromform submitted form data with an activitygroup array.
     * @param int $activitydatesid the tool_activitydates config row ID.
     * @return int the number of currently-checked coursemoduleids.
     */
    public function manage_selections(\stdClass $fromform, int $activitydatesid): int {
        global $DB;
        $existing = $DB->get_records_menu(
            'tool_activitydates_cmids',
            ['activitydates' => $activitydatesid],
            '',
            'coursemoduleid, id'
        );
        $checked = [];
        foreach ((array) ($fromform->activitygroup ?? []) as $key => $value) {
            if (empty($value)) {
                continue;
            }
            if (preg_match('/^activity_(\d+)$/', $key, $matches)) {
                $checked[(int) $matches[1]] = true;
            }
        }
        foreach ($checked as $cmid => $unused) {
            if (!array_key_exists($cmid, $existing)) {
                $DB->insert_record(
                    'tool_activitydates_cmids',
                    (object) ['activitydates' => $activitydatesid, 'coursemoduleid' => $cmid]
                );
            }
        }
        foreach ($existing as $cmid => $id) {
            if (!array_key_exists($cmid, $checked)) {
                $DB->delete_records('tool_activitydates_cmids', ['id' => $id]);
            }
        }
        return count($checked);
    }

    /**
     * Build the table data for rendering/applying: one header row per
     * session chunk followed by one data row per course module in it.
     *
     * Two-pass design: pass 1 computes every chunk's window (or null if the
     * chunk has no selected cm) before pass 2 emits rows, so the window
     * index only advances for chunks that actually contain a selection.
     * This fixes tool_driprelease's single-pass quirk, where a header row
     * reused the *previous* data row's `selected` flag, so a session whose
     * first activity happened to be unselected inherited a stale window.
     *
     * @param \stdClass $settings the course's activitydates config record.
     * @return array list of header/data rows, see class docblock for shape.
     */
    public function get_table_data(\stdClass $settings): array {
        global $DB;
        $modules = self::get_modules($settings);
        $chunksize = max(1, (int) $settings->activitiespersession);
        $chunks = array_chunk($modules, $chunksize, true);

        $selectedcmids = $DB->get_records_menu(
            'tool_activitydates_cmids',
            ['activitydates' => $settings->id],
            '',
            'coursemoduleid, coursemoduleid'
        );

        // Pass 1: compute each chunk's window without emitting any rows yet.
        $windowindex = 0;
        $windows = [];
        foreach ($chunks as $chunkkey => $chunk) {
            $haschecked = false;
            foreach ($chunk as $cm) {
                if (array_key_exists($cm->id, $selectedcmids)) {
                    $haschecked = true;
                    break;
                }
            }
            if ($haschecked) {
                $windows[$chunkkey] = $this->calculate_dates($settings, $windowindex);
                $windowindex++;
            } else {
                $windows[$chunkkey] = null;
            }
        }

        // Pass 2: emit header + data rows using the precomputed windows.
        $rows = [];
        foreach ($chunks as $chunkkey => $chunk) {
            $window = $windows[$chunkkey];
            $rows[] = ['isheader' => true, 'dates' => $window];
            foreach ($chunk as $cm) {
                $instance = $DB->get_record(
                    $settings->modtype,
                    ['id' => $cm->instance],
                    'id, timeopen, timeclose, intro'
                );
                $questioncount = null;
                if ($settings->modtype === 'quiz') {
                    $questioncount = $DB->count_records('quiz_slots', ['quizid' => $cm->instance]);
                }
                $rows[] = [
                    'isheader' => false,
                    'cm' => $cm,
                    'id' => $cm->id,
                    'name' => $cm->name,
                    'intro' => $instance->intro ?? '',
                    'selected' => array_key_exists($cm->id, $selectedcmids) ? 'checked' : '',
                    'questioncount' => $questioncount,
                    'dates' => $window,
                    'timeopen' => $instance->timeopen ?? 0,
                    'timeclose' => $instance->timeclose ?? 0,
                ];
            }
        }
        return $rows;
    }

    /**
     * Calculate the open/close window for a given session index.
     *
     * Session-window date math ported from tool_driprelease's
     * calculate_availability() by Marcus Green
     * (https://github.com/marcusgreen/moodle-tool_driprelease, GPL-3.0-or-later).
     *
     * @param \stdClass $settings the course's activitydates config record.
     * @param int $sessionindex zero-based session index.
     * @return array ['sessionnumber' => int, 'start' => int, 'end' => int]
     */
    public function calculate_dates(\stdClass $settings, int $sessionindex): array {
        $start = strtotime('+' . ($sessionindex * $settings->sessionlength) . ' day', $settings->schedulestart);
        $endday = strtotime(
            '+' . ((($sessionindex * $settings->sessionlength) - 1) + $settings->sessionlength) . ' day',
            $settings->schedulestart
        );
        $end = strtotime(date('Y-m-d', $endday) . ' ' . date('H:i:s', $settings->schedulefinish));
        return [
            'sessionnumber' => $sessionindex + 1,
            'start' => $start,
            'end' => $end,
        ];
    }

    /**
     * Get the course modules of the configured module type, in course order.
     *
     * @param \stdClass $settings the course's activitydates config record.
     * @return array cm_info objects keyed by coursemoduleid.
     */
    public static function get_modules(\stdClass $settings): array {
        $modinfo = get_fast_modinfo($settings->courseid);
        $modules = [];
        foreach ($modinfo->cms as $cm) {
            if ($cm->modname !== $settings->modtype || $cm->deletioninprogress) {
                continue;
            }
            $modules[$cm->id] = $cm;
        }
        return $modules;
    }
}
