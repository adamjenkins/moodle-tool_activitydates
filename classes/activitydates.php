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

    /** @var string[] the table's date fields, which each carry a Fix flag. */
    public const FIELDS = ['timeopen', 'duedate', 'timeclose', 'timelock'];

    /** @var string[] the fields whose Fix flag needs tool/activitydates:manage (timelock needs :managelocks). */
    private const DATEFIELDS = ['timeopen', 'duedate', 'timeclose'];

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
     * Save the page: the single write path for dates and grade locks.
     *
     * Each part is written only with its capability, whatever the submission holds:
     * - with $canmanage: the dates configuration and selection, and for a type with
     *   dates, the table's open, due and close values (never the lock date) and the
     *   hide/reset treatment of unselected rows;
     * - with $canlocks: the lock configuration, the lock selection with each row's
     *   activity-page and course-page note settings, and, unless the lock mode is
     *   none, the table's lock value of each selected, scheduled row with a grade
     *   item (0 clears the lock). With the lock "reset unselected" option, the locks
     *   of the unselected rows are cleared, whatever the lock mode;
     * - the Fix flags of the selected rows, each field with its capability (see
     *   save_fixed()).
     *
     * @param \stdClass $fromform submitted form data.
     * @param int $courseid the course ID.
     * @param array $tabledata rows from get_table_data() for the submitted settings and selection.
     * @param array $values datefields::validate_dates()'s values: cmid => ['timeopen' => int,
     *     'duedate' => ?int, 'timeclose' => int, 'timelock' => ?int].
     * @param int[] $shownotecmids the cmids whose lock note is ticked for the activity page.
     * @param int[] $shownotecoursecmids the cmids whose lock note is ticked for the course page.
     * @param array $fixposted the posted Fix flags: field => [cmid => 1].
     * @param bool $canmanage whether the user has tool/activitydates:manage.
     * @param bool $canlocks whether the user has tool/activitydates:managelocks.
     * @param bool $hasdates whether the type has open and close dates.
     * @return array ['dates' => activities whose dates were written, 'locks' => activities whose locks were
     *   written, or already had the posted lock date].
     */
    public function save(
        \stdClass $fromform,
        int $courseid,
        array $tabledata,
        array $values,
        array $shownotecmids,
        array $shownotecoursecmids,
        array $fixposted,
        bool $canmanage,
        bool $canlocks,
        bool $hasdates
    ): array {
        $context = \context_course::instance($courseid);
        $result = ['dates' => 0, 'locks' => 0];
        // Read before update() rewrites the dates selection.
        $keeplocks = $canmanage && $canlocks ? self::lock_only_cmids($courseid, $tabledata) : [];

        if ($canmanage) {
            [, $settings] = $this->update($fromform, $courseid);
            if ($hasdates) {
                $result['dates'] = $this->apply_dates($tabledata, $settings, $values);
                event\dates_updated::create(['context' => $context])->trigger();
            }
        }

        if ($canlocks) {
            $settings = self::settings_from_form($fromform, $courseid, 0);
            $this->save_lock_config($settings, $courseid, $tabledata, $shownotecmids, $shownotecoursecmids, $keeplocks);

            $lockdates = [];
            // Selected rows whose posted lock date is the current one: nothing to write.
            // The current one is the earliest of the grade items' lock dates, so writing
            // it would move the later ones back.
            $unchanged = 0;
            foreach ($tabledata as $row) {
                if ($row['isheader'] || !$row['hasgradeitem']) {
                    continue;
                }
                $cmid = (int) $row['id'];
                if (isset($keeplocks[$cmid])) {
                    continue;
                }
                if ($row['selected'] === 'checked') {
                    // Lock mode none leaves the existing locks alone.
                    if ($settings->lockmode !== local\schedule::MODE_NONE && $row['scheduled'] && isset($values[$cmid])) {
                        $locktime = (int) ($values[$cmid]['timelock'] ?? 0);
                        if ($locktime === (int) ($row['current']['timelock'] ?? -1)) {
                            $unchanged++;
                        } else {
                            $lockdates[$cmid] = $locktime;
                        }
                    }
                } else if ($settings->lockresetunselected) {
                    $lockdates[$cmid] = 0;
                }
            }
            $result['locks'] = $unchanged;
            if ($lockdates) {
                $result['locks'] += (new locks\manager())->apply_locks($lockdates, $settings->modtype, $courseid, false);
            }
            event\locks_updated::create(['context' => $context])->trigger();
        }

        $selectedcmids = [];
        foreach ($tabledata as $row) {
            if (!$row['isheader'] && $row['selected'] === 'checked') {
                $selectedcmids[] = (int) $row['id'];
            }
        }
        // A lock-only type has no open, due or close date to fix.
        $this->save_fixed($courseid, $selectedcmids, $fixposted, $canmanage && $hasdates, $canlocks);
        return $result;
    }

    /**
     * The unticked rows that are in the lock selection but not in the dates selection.
     *
     * A user with :manage sees the ticks of the dates selection (view.php), so such a
     * row was shown unticked without the user unticking it: the two selections were
     * saved apart (a 2.0 course, or a Save by a user with :managelocks only). Save keeps
     * its lock selection, notes and lock date. Unticking a row the page showed ticked
     * still removes it from both selections.
     *
     * @param int $courseid the course ID.
     * @param array $tabledata rows from get_table_data(), with the posted selection.
     * @return array cmid => true.
     */
    private static function lock_only_cmids(int $courseid, array $tabledata): array {
        global $DB;
        $lockcmids = $DB->get_fieldset_sql(
            "SELECT i.cmid
               FROM {tool_activitydates_lockitem} i
               JOIN {tool_activitydates_lock} l ON l.id = i.lockid
              WHERE l.courseid = :courseid",
            ['courseid' => $courseid]
        );
        if (!$lockcmids) {
            return [];
        }
        $lockcmids = array_fill_keys(array_map('intval', $lockcmids), true);
        $datescmids = $DB->get_fieldset_sql(
            "SELECT c.coursemoduleid
               FROM {tool_activitydates_cmids} c
               JOIN {tool_activitydates} a ON a.id = c.activitydates
              WHERE a.courseid = :courseid",
            ['courseid' => $courseid]
        );
        $datescmids = array_fill_keys(array_map('intval', $datescmids), true);
        $keep = [];
        foreach ($tabledata as $row) {
            $cmid = (int) ($row['id'] ?? 0);
            if (!$row['isheader'] && $row['selected'] !== 'checked' && isset($lockcmids[$cmid]) && !isset($datescmids[$cmid])) {
                $keep[$cmid] = true;
            }
        }
        return $keep;
    }

    /**
     * The saved Fix flags of the given cms.
     *
     * @param int $courseid the course ID.
     * @param int[] $cmids the cms to read.
     * @return array cmid => [field => true], only for cms with a flag.
     */
    public static function load_fixed(int $courseid, array $cmids): array {
        global $DB;
        if (!$cmids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_map('intval', $cmids), SQL_PARAMS_NAMED);
        $records = $DB->get_records_select(
            'tool_activitydates_fixed',
            "courseid = :courseid AND cmid $insql",
            ['courseid' => $courseid] + $params,
            'cmid, field',
            'id, cmid, field'
        );
        $fixed = [];
        foreach ($records as $record) {
            $fixed[(int) $record->cmid][$record->field] = true;
        }
        return $fixed;
    }

    /**
     * Replace the Fix flags of the given (selected, valid) cms with the posted ones,
     * for the fields the user may set: open, due and close with $canmanage, the lock
     * date with $canlocks. Flags of other cms, and of fields the user may not set,
     * are left alone; posted flags of other cms are ignored.
     *
     * @param int $courseid the course ID.
     * @param int[] $validcmids the selected cms of this course and type whose flags are replaced.
     * @param array $posted field => [cmid => 1], read with optional_param_array(..., PARAM_BOOL).
     * @param bool $canmanage whether the open, due and close flags may be set.
     * @param bool $canlocks whether the lock date flag may be set.
     */
    public function save_fixed(int $courseid, array $validcmids, array $posted, bool $canmanage, bool $canlocks): void {
        global $DB;
        $fields = array_merge($canmanage ? self::DATEFIELDS : [], $canlocks ? ['timelock'] : []);
        $validcmids = array_map('intval', $validcmids);
        if (!$fields || !$validcmids) {
            return;
        }
        [$cmsql, $cmparams] = $DB->get_in_or_equal($validcmids, SQL_PARAMS_NAMED, 'cm');
        [$fieldsql, $fieldparams] = $DB->get_in_or_equal($fields, SQL_PARAMS_NAMED, 'field');
        $records = $DB->get_records_select(
            'tool_activitydates_fixed',
            "cmid $cmsql AND field $fieldsql",
            $cmparams + $fieldparams,
            '',
            'id, cmid, field'
        );
        $existing = [];
        foreach ($records as $record) {
            $existing[(int) $record->cmid][$record->field] = (int) $record->id;
        }

        $now = time();
        $transaction = $DB->start_delegated_transaction();
        foreach ($validcmids as $cmid) {
            foreach ($fields as $field) {
                $wanted = !empty($posted[$field][$cmid]);
                $id = $existing[$cmid][$field] ?? null;
                if ($wanted && $id === null) {
                    $DB->insert_record('tool_activitydates_fixed', (object) [
                        'courseid' => $courseid,
                        'cmid' => $cmid,
                        'field' => $field,
                        'timemodified' => $now,
                    ]);
                } else if (!$wanted && $id !== null) {
                    $DB->delete_records('tool_activitydates_fixed', ['id' => $id]);
                }
            }
        }
        $transaction->allow_commit();
    }

    /**
     * Upsert the course's lock configuration and rewrite the lock selection of
     * the table's activities, each with its activity-page and course-page note
     * settings. Lock selections of activities of other types are kept.
     *
     * @param \stdClass $settings settings from settings_from_form().
     * @param int $courseid the course ID.
     * @param array $tabledata rows from get_table_data().
     * @param int[] $shownotecmids the cmids whose lock note is ticked for the activity page.
     * @param int[] $shownotecoursecmids the cmids whose lock note is ticked for the course page.
     * @param array $keeplocks cmid => true: unticked rows whose lock selection is kept (see lock_only_cmids()).
     */
    private function save_lock_config(
        \stdClass $settings,
        int $courseid,
        array $tabledata,
        array $shownotecmids,
        array $shownotecoursecmids,
        array $keeplocks
    ): void {
        global $DB;
        $notes = array_fill_keys(array_map('intval', $shownotecmids), true);
        $coursenotes = array_fill_keys(array_map('intval', $shownotecoursecmids), true);
        $record = (object) [
            'courseid' => $courseid,
            'lockmode' => $settings->lockmode,
            'lockdays' => $settings->lockdays,
            'lockdate' => $settings->lockdate,
            'resetunselected' => $settings->lockresetunselected,
            'timemodified' => time(),
        ];

        $transaction = $DB->start_delegated_transaction();
        $existing = $DB->get_record('tool_activitydates_lock', ['courseid' => $courseid]);
        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('tool_activitydates_lock', $record);
        } else {
            $record->id = $DB->insert_record('tool_activitydates_lock', $record);
        }

        $tablecmids = [];
        $selected = [];
        foreach ($tabledata as $row) {
            if ($row['isheader'] || isset($keeplocks[(int) $row['id']])) {
                continue;
            }
            $tablecmids[] = (int) $row['id'];
            if ($row['selected'] === 'checked') {
                $selected[] = (int) $row['id'];
            }
        }
        if ($tablecmids) {
            [$insql, $params] = $DB->get_in_or_equal($tablecmids, SQL_PARAMS_NAMED);
            $DB->delete_records_select(
                'tool_activitydates_lockitem',
                "lockid = :lockid AND cmid $insql",
                ['lockid' => $record->id] + $params
            );
        }
        foreach ($selected as $cmid) {
            $DB->insert_record('tool_activitydates_lockitem', (object) [
                'lockid' => $record->id,
                'cmid' => $cmid,
                'shownote' => isset($notes[$cmid]) ? 1 : 0,
                'shownotecoursepage' => isset($coursenotes[$cmid]) ? 1 : 0,
            ]);
        }
        $transaction->allow_commit();
    }

    /**
     * Build the settings object from submitted form data, without persisting it.
     *
     * The optional finish-date selector submits 0 when disabled, which disables the
     * cap. Unknown modes fall back to the defaults, and absent due fields (types
     * without a duedate column) get the defaults.
     *
     * The lock fields (lockmode, lockdays, lockdate and lockresetunselected) are
     * read when present. Absent ones (a user without :managelocks gets no lock
     * controls) fall back to the course's saved lock configuration, then to the
     * site defaults. The note options are per row (see save()), not settings.
     *
     * @param \stdClass $fromform submitted form data.
     * @param int $courseid the course ID.
     * @param int $id the tool_activitydates row ID, 0 if none yet.
     * @return \stdClass settings object (tool_activitydates row shape, plus lockid and the lock fields).
     */
    public static function settings_from_form(\stdClass $fromform, int $courseid, int $id): \stdClass {
        global $DB;
        $mode = fn($value, string $default): string =>
            in_array((string) $value, local\schedule::MODES, true) ? (string) $value : $default;
        $lock = $DB->get_record('tool_activitydates_lock', ['courseid' => $courseid]) ?: null;
        $lockdefaults = self::lock_defaults($lock);
        $lockvalue = fn(string $field, string $lockfield) => $fromform->$field ?? $lockdefaults->$lockfield;
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
            'lockid' => (int) ($lock->id ?? 0),
            'lockmode' => $mode($lockvalue('lockmode', 'lockmode'), local\schedule::MODE_NONE),
            'lockdays' => (int) $lockvalue('lockdays', 'lockdays'),
            'lockdate' => (int) $lockvalue('lockdate', 'lockdate'),
            'lockresetunselected' => (int) !empty($lockvalue('lockresetunselected', 'resetunselected')),
        ];
    }

    /**
     * The saved lock configuration, or the site defaults when there is none.
     *
     * @param \stdClass|null $lock the course's tool_activitydates_lock row, or null.
     * @return \stdClass lockmode, lockdays, lockdate and resetunselected.
     */
    private static function lock_defaults(?\stdClass $lock): \stdClass {
        $config = fn(string $name, $default) => get_config('tool_activitydates', $name) !== false
            ? get_config('tool_activitydates', $name) : $default;
        return (object) [
            'lockmode' => (string) ($lock->lockmode ?? $config('lockmode', local\schedule::MODE_NONE)),
            'lockdays' => (int) ($lock->lockdays ?? $config('lockdays', 7)),
            'lockdate' => (int) ($lock->lockdate ?? 0),
            'resetunselected' => (int) ($lock->resetunselected ?? 0),
        ];
    }

    /**
     * The settings a page load shows: the course's saved dates configuration,
     * its saved lock configuration and the site defaults, merged into one
     * settings object for the given type.
     *
     * Timestamps are floored to the minute, the precision the date selectors
     * submit, so a Save straight after page load matches the table's fingerprint.
     * An unset lock date defaults like the close date (the finish date, or two
     * weeks after the start).
     *
     * @param int $courseid the course ID.
     * @param string $modtype the activity type shown.
     * @return \stdClass settings object (as settings_from_form() builds it).
     */
    public static function load_settings(int $courseid, string $modtype): \stdClass {
        global $DB;
        $config = $DB->get_record('tool_activitydates', ['courseid' => $courseid]) ?: null;
        if ($config) {
            $settings = form\activitydates_form::form_defaults($config);
            $settings->finishenabled = (int) !empty($settings->schedulefinish);
            // A disabled finish date keeps its saved value, so the fingerprint ignores it either way.
            $settings->schedulefinish = $settings->finishenabled ? $settings->schedulefinish : (int) $config->schedulefinish;
        } else {
            $settings = form\activitydates_form::new_course_defaults($courseid);
        }
        $settings->modtype = $modtype;

        $lock = $DB->get_record('tool_activitydates_lock', ['courseid' => $courseid]) ?: null;
        $lockdefaults = self::lock_defaults($lock);
        $settings->lockid = (int) ($lock->id ?? 0);
        $settings->lockmode = in_array($lockdefaults->lockmode, local\schedule::MODES, true)
            ? $lockdefaults->lockmode : local\schedule::MODE_NONE;
        $settings->lockdays = $lockdefaults->lockdays;
        $fallbackdate = $settings->finishenabled
            ? (int) $settings->schedulefinish
            : (int) $settings->schedulestart + 14 * DAYSECS;
        $lockdate = $lockdefaults->lockdate ?: $fallbackdate;
        $settings->lockdate = $lockdate - $lockdate % MINSECS;
        $settings->lockresetunselected = $lockdefaults->resetunselected;
        return $settings;
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
     * - current: ['timeopen' => int, 'duedate' => ?int, 'timeclose' => int, 'timelock' => int],
     *   the activity's current values (0 when unset; duedate null when the type has
     *   no duedate column; timelock the earliest grade item locktime);
     * - fixed: field => bool, the saved Fix flags of the cm (every field of FIELDS);
     * - scheduled: whether the cm is selected and in a window;
     * - proposed: the engine's ['timeopen', 'duedate', 'timeclose', 'timelock'], or null;
     *   a fixed field's proposal is its fixed value;
     * - status: '' for scheduled rows, else the lang key of what Save does to
     *   the row (rowstatus_reset wins over rowstatus_hidden: a reset is not
     *   undone by showing the activity again);
     * - duedate: the current due date, null when the type has no duedate column;
     * - locktime: the current lock date (the earliest grade item locktime, 0 if none);
     * - hasgradeitem: whether the cm has a grade item;
     * - shownote, shownotecoursepage: the cm's saved activity-page and course-page
     *   lock note settings, else the site defaults (lockshownote, lockshownotecoursepage).
     *
     * For a lock-only type ($hasdates false) the module's timeopen and timeclose
     * are not read (the columns do not exist): the current open, due and close
     * dates are 0 (due null) and only proposed.timelock is set.
     *
     * @param \stdClass $settings settings object (tool_activitydates row shape, optionally with the lock fields).
     * @param array $selectedcmids the selected cmids.
     * @param bool $hasdates whether the type has open and close dates.
     * @param array|\Closure|null $fixed the fixed fields for the engine, cmid => [field => int]; null uses
     *   the saved Fix flags with the current values (page load and Save). A closure is called with
     *   the data rows' isheader, id, selected, current and fixed keys, before the engine runs, and
     *   returns the fixed fields (Preview: see output\preview_rows::engine_fixed()).
     * @return array list of header/data rows.
     */
    public function get_table_data(
        \stdClass $settings,
        array $selectedcmids,
        bool $hasdates = true,
        array|\Closure|null $fixed = null
    ): array {
        global $DB;
        $modules = self::get_modules($settings);
        $cmids = array_map('intval', array_keys($modules));
        $selected = array_fill_keys(array_map('intval', $selectedcmids), true);
        $hasdue = $hasdates && self::has_duedate($settings->modtype);
        $courseid = (int) $settings->courseid;
        $lockmanager = new locks\manager();
        $savedfixed = self::load_fixed($courseid, $cmids);

        // The current values of every cm, which the saved Fix flags keep.
        $fields = 'id, intro' . ($hasdates ? ', timeopen, timeclose' : '') . ($hasdue ? ', duedate' : '')
            . ($settings->modtype === 'quiz' ? ', grade, decimalpoints' : '');
        $instances = [];
        $current = [];
        foreach ($modules as $cmid => $cm) {
            $instance = $DB->get_record($settings->modtype, ['id' => $cm->instance], $fields);
            $instances[$cmid] = $instance;
            $current[$cmid] = [
                'timeopen' => (int) ($instance->timeopen ?? 0),
                'duedate' => $hasdue ? (int) ($instance->duedate ?? 0) : null,
                'timeclose' => (int) ($instance->timeclose ?? 0),
                'timelock' => $lockmanager->current_locktime($courseid, $cm),
            ];
        }
        if ($fixed instanceof \Closure) {
            $fixed = $fixed(array_map(fn(int $cmid): array => [
                'isheader' => false,
                'id' => $cmid,
                'selected' => isset($selected[$cmid]) ? 'checked' : '',
                'current' => $current[$cmid],
                'fixed' => self::fixed_flags($savedfixed, $cmid),
            ], $cmids));
        } else if ($fixed === null) {
            $fixed = [];
            foreach ($savedfixed as $cmid => $flags) {
                foreach (array_keys($flags) as $field) {
                    if (isset($current[$cmid][$field])) {
                        $fixed[$cmid][$field] = $current[$cmid][$field];
                    }
                }
            }
        }

        $computed = local\schedule::compute(
            $settings,
            $cmids,
            $selected,
            $hasdue,
            \core_date::get_user_timezone_object(),
            $hasdates,
            $fixed
        );

        // A saved lock note setting wins over the site default for selected rows.
        $savednotes = [];
        $lockid = $DB->get_field('tool_activitydates_lock', 'id', ['courseid' => $courseid]);
        if ($lockid) {
            $savednotes = $DB->get_records(
                'tool_activitydates_lockitem',
                ['lockid' => $lockid],
                '',
                'cmid, shownote, shownotecoursepage'
            );
        }
        $config = fn(string $name, $default) => get_config('tool_activitydates', $name) !== false
            ? get_config('tool_activitydates', $name) : $default;
        $defaultnote = !empty($config('lockshownote', 1));
        $defaultcoursenote = !empty($config('lockshownotecoursepage', 0));

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

        $chunksize = max(1, (int) $settings->activitiespersession);
        $rows = [];
        foreach (array_chunk($modules, $chunksize, true) as $chunkkey => $chunk) {
            $window = $windows[$chunkkey];
            $rows[] = ['isheader' => true, 'dates' => $window];
            foreach ($chunk as $cm) {
                $cmid = (int) $cm->id;
                $instance = $instances[$cm->id];
                // A quiz's maximum grade, shown as the quiz shows it.
                $marks = null;
                if ($settings->modtype === 'quiz') {
                    $marks = format_float((float) $instance->grade, (int) $instance->decimalpoints);
                }
                $isselected = isset($selected[$cmid]);
                $proposed = $computed['dates'][$cmid] ?? null;
                if ($proposed !== null) {
                    $status = '';
                } else if ($hasdates && !$isselected && !empty($settings->resetunselected)) {
                    $status = 'rowstatus_reset';
                } else if ($hasdates && !$isselected && !empty($settings->hideunselected)) {
                    $status = 'rowstatus_hidden';
                } else {
                    $status = 'rowstatus_notscheduled';
                }
                $duedate = $current[$cm->id]['duedate'];
                $note = $savednotes[$cm->id] ?? null;
                $rows[] = [
                    'isheader' => false,
                    'cm' => $cm,
                    'id' => $cm->id,
                    'name' => $cm->name,
                    'intro' => strip_tags($instance->intro ?? ''),
                    'selected' => $isselected ? 'checked' : '',
                    'marks' => $marks,
                    'dates' => $window,
                    'scheduled' => $proposed !== null,
                    'proposed' => $proposed,
                    'current' => $current[$cm->id],
                    'fixed' => self::fixed_flags($savedfixed, $cmid),
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
                    'locktime' => $current[$cm->id]['timelock'],
                    'hasgradeitem' => $lockmanager->has_grade_item($courseid, $cm),
                    // Without a saved item, a selected row shows the site defaults (what Save
                    // stores), and an unselected row has no note: its ticks are the defaults
                    // only once it is selected (modform.js).
                    'shownote' => $note ? (bool) $note->shownote : $isselected && $defaultnote,
                    'shownotecoursepage' => $note ? (bool) $note->shownotecoursepage : $isselected && $defaultcoursenote,
                    'notedefaults' => $note || $isselected ? null : [
                        'shownote' => $defaultnote,
                        'shownotecoursepage' => $defaultcoursenote,
                    ],
                ];
            }
        }
        return $rows;
    }

    /**
     * A cm's saved Fix flags, for every field of FIELDS.
     *
     * @param array $savedfixed load_fixed()'s flags, cmid => [field => true].
     * @param int $cmid the course module id.
     * @return array field => bool.
     */
    private static function fixed_flags(array $savedfixed, int $cmid): array {
        return array_combine(
            self::FIELDS,
            array_map(fn($field) => !empty($savedfixed[$cmid][$field]), self::FIELDS)
        );
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
