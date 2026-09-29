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
    /** @var string strftime format producing a valid <time> datetime attribute value. */
    private const DATETIMEATTRFORMAT = '%Y-%m-%dT%H:%M';

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
        // Persist exactly what a preview of the same submission computes.
        $data = self::settings_from_form($fromform, $courseid, $existing ? (int) $existing->id : 0);
        $data->timemodified = time();
        if ($existing) {
            $DB->update_record('tool_activitydates', $data);
        } else {
            unset($data->id);
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
     * Build the settings object from submitted form data, without persisting it.
     *
     * The optional finish-date selector submits 0 when disabled, which disables the
     * cap. Unknown modes fall back to the defaults, and absent due fields (types
     * without a duedate column) get the defaults.
     *
     * @param \stdClass $fromform submitted form data.
     * @param int $courseid the course ID.
     * @param int $id the tool_activitydates row ID, 0 if none yet.
     * @return \stdClass settings object (tool_activitydates row shape).
     */
    public static function settings_from_form(\stdClass $fromform, int $courseid, int $id): \stdClass {
        $mode = fn($value, string $default): string =>
            in_array((string) $value, local\schedule::MODES, true) ? (string) $value : $default;
        return (object) [
            'id' => $id,
            'courseid' => $courseid,
            'modtype' => (string) ($fromform->modtype ?? ''),
            'schedulestart' => (int) ($fromform->schedulestart ?? 0),
            'finishenabled' => (int) !empty($fromform->schedulefinish),
            'schedulefinish' => (int) ($fromform->schedulefinish ?? 0),
            'sessionlength' => (int) ($fromform->sessionlength ?? 0),
            'activitiespersession' => (int) ($fromform->activitiespersession ?? 0),
            'closemode' => $mode($fromform->closemode ?? '', local\schedule::MODE_SESSION),
            'closedays' => (int) ($fromform->closedays ?? 7),
            'closedate' => (int) ($fromform->closedate ?? 0),
            'duemode' => $mode($fromform->duemode ?? '', local\schedule::MODE_NONE),
            'duedays' => (int) ($fromform->duedays ?? 7),
            'duedate' => (int) ($fromform->duedate ?? 0),
            'hideunselected' => (int) !empty($fromform->hideunselected),
            'resetunselected' => (int) !empty($fromform->resetunselected),
        ];
    }

    /**
     * The cmids ticked in the form's activitygroup checkboxes, restricted to the
     * valid cmids (the course's cms of the configured type).
     *
     * @param \stdClass $fromform submitted form data with an activitygroup array.
     * @param array $validcmids the cmids that may be selected.
     * @return int[] selected cmids, in $validcmids order.
     */
    public static function selected_from_form(\stdClass $fromform, array $validcmids): array {
        $checked = self::checked_cmids($fromform);
        $selected = [];
        foreach ($validcmids as $cmid) {
            if (isset($checked[(int) $cmid])) {
                $selected[] = (int) $cmid;
            }
        }
        return $selected;
    }

    /**
     * The saved selection of a settings row, restricted to the valid cmids the
     * same way selected_from_form() restricts a submitted one, so the table and
     * fingerprint built on page load match what a Save of that page posts back.
     * Leftover rows (a deleted cm, a cm of another type) are skipped.
     *
     * @param int $activitydatesid tool_activitydates id, 0 when nothing is saved.
     * @param array $validcmids the cmids that may be selected.
     * @return int[] selected cmids, in $validcmids order.
     */
    public static function saved_selection(int $activitydatesid, array $validcmids): array {
        global $DB;
        if (!$activitydatesid) {
            return [];
        }
        $saved = $DB->get_records_menu(
            'tool_activitydates_cmids',
            ['activitydates' => $activitydatesid],
            '',
            'coursemoduleid, coursemoduleid AS cmid'
        );
        $selected = [];
        foreach ($validcmids as $cmid) {
            if (isset($saved[(int) $cmid])) {
                $selected[] = (int) $cmid;
            }
        }
        return $selected;
    }

    /**
     * Parse the ticked activitygroup[activity_<cmid>] checkboxes.
     *
     * @param \stdClass $fromform submitted form data with an activitygroup array.
     * @return array cmid => true.
     */
    private static function checked_cmids(\stdClass $fromform): array {
        $checked = [];
        foreach ((array) ($fromform->activitygroup ?? []) as $key => $value) {
            if (empty($value)) {
                continue;
            }
            if (preg_match('/^activity_(\d+)$/', $key, $matches)) {
                $checked[(int) $matches[1]] = true;
            }
        }
        return $checked;
    }

    /**
     * Whether a module type has a duedate column (quiz from Moodle 5.3).
     *
     * @param string $modtype the module name, which is also its table name.
     * @return bool
     */
    public static function has_duedate(string $modtype): bool {
        global $DB;
        return isset($DB->get_columns($modtype)['duedate']);
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
        $checked = self::checked_cmids($fromform);
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
     * The windows and proposed dates come from local\schedule, in the user's
     * timezone. A chunk's window is null when it has no selected cm (the window
     * index only advances for chunks with a selection) or, with the finish date
     * enabled, when it would start after the finish date.
     *
     * Data rows carry the current dates and, beside them:
     * - scheduled: whether the cm is selected and in a window;
     * - proposed: the engine's ['timeopen', 'duedate', 'timeclose', 'timelock'], or null;
     * - status: '' for scheduled rows, else the lang key of what Save does to
     *   the row (rowstatus_reset wins over rowstatus_hidden: a reset is not
     *   undone by showing the activity again);
     * - duedate: the current due date, null when the type has no duedate column.
     *
     * @param \stdClass $settings settings object (tool_activitydates row shape).
     * @param array $selectedcmids the selected cmids.
     * @return array list of header/data rows.
     */
    public function get_table_data(\stdClass $settings, array $selectedcmids): array {
        global $DB;
        $modules = self::get_modules($settings);
        $cmids = array_map('intval', array_keys($modules));
        $selected = array_fill_keys(array_map('intval', $selectedcmids), true);
        $hasdue = self::has_duedate($settings->modtype);
        $computed = local\schedule::compute(
            $settings,
            $cmids,
            $selected,
            $hasdue,
            \core_date::get_user_timezone_object()
        );

        // Add display strings so the template needs no date logic of its own.
        $dateformat = get_string('dateformat', 'tool_activitydates');
        $windows = $computed['windows'];
        foreach ($windows as $chunkkey => $window) {
            if ($window === null) {
                continue;
            }
            $windows[$chunkkey]['startformatted'] = userdate($window['start'], $dateformat);
            $windows[$chunkkey]['startattr'] = userdate($window['start'], self::DATETIMEATTRFORMAT, 99, false, false);
            $windows[$chunkkey]['endformatted'] = userdate($window['end'], $dateformat);
            $windows[$chunkkey]['endattr'] = userdate($window['end'], self::DATETIMEATTRFORMAT, 99, false, false);
        }

        $fields = 'id, timeopen, timeclose, intro' . ($hasdue ? ', duedate' : '');
        $chunksize = max(1, (int) $settings->activitiespersession);
        $rows = [];
        foreach (array_chunk($modules, $chunksize, true) as $chunkkey => $chunk) {
            $window = $windows[$chunkkey];
            $rows[] = ['isheader' => true, 'dates' => $window];
            foreach ($chunk as $cm) {
                $instance = $DB->get_record($settings->modtype, ['id' => $cm->instance], $fields);
                $questioncount = null;
                if ($settings->modtype === 'quiz') {
                    $questioncount = $DB->count_records('quiz_slots', ['quizid' => $cm->instance]);
                }
                $isselected = isset($selected[(int) $cm->id]);
                $proposed = $computed['dates'][(int) $cm->id] ?? null;
                if ($proposed !== null) {
                    $status = '';
                } else if (!$isselected && !empty($settings->resetunselected)) {
                    $status = 'rowstatus_reset';
                } else if (!$isselected && !empty($settings->hideunselected)) {
                    $status = 'rowstatus_hidden';
                } else {
                    $status = 'rowstatus_notscheduled';
                }
                $duedate = $hasdue ? (int) ($instance->duedate ?? 0) : null;
                $rows[] = [
                    'isheader' => false,
                    'cm' => $cm,
                    'id' => $cm->id,
                    'name' => $cm->name,
                    'intro' => strip_tags($instance->intro ?? ''),
                    'selected' => $isselected ? 'checked' : '',
                    'questioncount' => $questioncount,
                    'dates' => $window,
                    'scheduled' => $proposed !== null,
                    'proposed' => $proposed,
                    'status' => $status,
                    'timeopen' => $instance->timeopen ?? 0,
                    'timeopenformatted' => empty($instance->timeopen)
                        ? '' : userdate($instance->timeopen, $dateformat),
                    'timeopenattr' => empty($instance->timeopen)
                        ? '' : userdate($instance->timeopen, self::DATETIMEATTRFORMAT, 99, false, false),
                    'duedate' => $duedate,
                    'duedateformatted' => empty($duedate) ? '' : userdate($duedate, $dateformat),
                    'duedateattr' => empty($duedate)
                        ? '' : userdate($duedate, self::DATETIMEATTRFORMAT, 99, false, false),
                    'timeclose' => $instance->timeclose ?? 0,
                    'timecloseformatted' => empty($instance->timeclose)
                        ? '' : userdate($instance->timeclose, $dateformat),
                    'timecloseattr' => empty($instance->timeclose)
                        ? '' : userdate($instance->timeclose, self::DATETIMEATTRFORMAT, 99, false, false),
                ];
            }
        }
        return $rows;
    }

    /**
     * Get the course modules of the configured module type, in course-page
     * order (activities in a subsection appear where the subsection sits).
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
        return local\course_order::sort($modinfo, $modules);
    }

    /**
     * Write the table's dates for every selected, scheduled row that has a
     * validated value, and refresh each activity's calendar events. Unselected
     * rows get the hide/reset treatment.
     *
     * Values are only ever written to rows of this table that are selected and
     * scheduled: a posted value for any other cm (unselected, past the finish
     * cap, or another course's) is ignored.
     *
     * @param array $tabledata rows from get_table_data().
     * @param \stdClass $settings settings object (tool_activitydates row shape).
     * @param array $values datefields::validate_dates()'s values: cmid =>
     *     ['timeopen' => int, 'duedate' => ?int, 'timeclose' => int].
     * @return int the number of activities updated.
     */
    public function apply_dates(array $tabledata, \stdClass $settings, array $values): int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');

        $modtype = $settings->modtype;
        $columns = $DB->get_columns($modtype);
        $updatecount = 0;
        foreach ($tabledata as $row) {
            if ($row['isheader']) {
                continue;
            }
            if ($row['selected'] !== 'checked') {
                $this->process_unselected($row, $settings);
                continue;
            }
            // Never write a row that is not scheduled (e.g. past the finish date), or
            // one without a validated value.
            if (!$row['scheduled'] || !isset($values[(int) $row['id']])) {
                continue;
            }
            $value = $values[(int) $row['id']];
            $cm = $row['cm'];
            $instance = (object) [
                'id' => $cm->instance,
                'timeopen' => (int) $value['timeopen'],
                'timeclose' => (int) $value['timeclose'],
            ];
            if (isset($columns['duedate'])) {
                $instance->duedate = (int) ($value['duedate'] ?? 0);
            }
            if (isset($columns['timemodified'])) {
                $instance->timemodified = time();
            }
            $DB->update_record($modtype, $instance);
            set_coursemodule_visible($cm->id, true, true);
            // Recreate the open/close calendar events. Pass the instance ID (not an
            // object) so the callback re-reads the freshly updated record.
            component_callback(
                'mod_' . $modtype,
                'refresh_events',
                [$settings->courseid, $cm->instance, $cm]
            );
            \core\event\course_module_updated::create_from_cm($cm)->trigger();
            $updatecount++;
        }
        rebuild_course_cache($settings->courseid, true);
        return $updatecount;
    }

    /**
     * Handle an unselected row: visibility per hideunselected, and an
     * optional date reset (which deletes its calendar events) per
     * resetunselected.
     *
     * @param array $row a get_table_data() data row.
     * @param \stdClass $settings settings object (tool_activitydates row shape).
     */
    public function process_unselected(array $row, \stdClass $settings): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');

        $cm = $row['cm'];
        if (!empty($settings->hideunselected)) {
            set_coursemodule_visible($cm->id, false, false);
            \core\event\course_module_updated::create_from_cm($cm)->trigger();
        } else {
            set_coursemodule_visible($cm->id, true, true);
        }
        if (!empty($settings->resetunselected)) {
            $reset = (object) ['id' => $cm->instance, 'timeopen' => 0, 'timeclose' => 0];
            if (self::has_duedate($settings->modtype)) {
                $reset->duedate = 0;
            }
            $DB->update_record($settings->modtype, $reset);
            // With all times zero the callback deletes the calendar events.
            component_callback(
                'mod_' . $settings->modtype,
                'refresh_events',
                [$settings->courseid, $cm->instance, $cm]
            );
            \core\event\course_module_updated::create_from_cm($cm)->trigger();
        }
    }
}
