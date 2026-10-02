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

/**
 * Named configurations of the Activity dates page, saved per course.
 *
 * A configuration is a JSON snapshot of what the page posts: the settings, the
 * ticked activities, the table's date inputs, the Hold ticks and the note ticks.
 * Saving and loading write nothing to the activities or the gradebook. Only the
 * parts the user may edit are saved, and loading keeps only the parts the user
 * may edit, for the activities the course still has.
 *
 * @package    tool_activitydates
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class saved_configs {
    /** @var string the table. */
    const TABLE = 'tool_activitydates_saved';

    /** @var int the snapshot format. */
    const VERSION = 1;

    /** @var string[] the settings saved with tool/activitydates:manage. */
    const DATESETTINGS = [
        'schedulestart', 'schedulefinish', 'sessionlength', 'activitiespersession',
        'closemode', 'closedays', 'closedate', 'duemode', 'duedays', 'duedate',
        'hideunselected', 'resetunselected',
    ];

    /** @var string[] the settings saved with tool/activitydates:managelocks. */
    const LOCKSETTINGS = ['lockmode', 'lockdays', 'lockdate', 'lockresetunselected'];

    /** @var string[] the settings that are mode names, not numbers. */
    const MODESETTINGS = ['closemode', 'duemode', 'lockmode'];

    /** @var string[] the table's date fields. */
    const FIELDS = ['timeopen', 'duedate', 'timeclose', 'timelock'];

    /** @var string[] the table's date fields edited with tool/activitydates:manage (timelock needs :managelocks). */
    const DATEFIELDS = ['timeopen', 'duedate', 'timeclose'];

    /** @var int the longest table input kept: a datetime-local value is 16 characters. */
    const MAXINPUT = 32;

    /**
     * The course's configurations, by name.
     *
     * @param int $courseid the course id.
     * @param bool $withdata whether to include each one's snapshot (data).
     * @return \stdClass[] id, name and timemodified (and data), keyed by id.
     */
    public static function list(int $courseid, bool $withdata = false): array {
        global $DB;
        $fields = 'id, name, timemodified' . ($withdata ? ', data' : '');
        return $DB->get_records(self::TABLE, ['courseid' => $courseid], 'name ASC, id ASC', $fields);
    }

    /**
     * One configuration of the course.
     *
     * @param int $courseid the course id.
     * @param int $id the configuration id, which must belong to the course.
     * @return \stdClass the record.
     * @throws \dml_missing_record_exception when there is no such configuration in the course.
     */
    public static function get(int $courseid, int $id): \stdClass {
        global $DB;
        return $DB->get_record(self::TABLE, ['id' => $id, 'courseid' => $courseid], '*', MUST_EXIST);
    }

    /**
     * Clean a configuration name: plain text, trimmed, at most 255 characters.
     *
     * @param string $name the name as typed.
     * @return string the name, '' when nothing is left.
     */
    public static function clean_name(string $name): string {
        return trim(\core_text::substr(trim(clean_param($name, PARAM_TEXT)), 0, 255));
    }

    /**
     * Save a snapshot under a name, replacing the course's configuration of that name.
     *
     * A replace keeps the parts of the existing configuration that the saving user may
     * not edit (see merge()), so a user with one of the two capabilities never wipes
     * what a user with the other saved.
     *
     * @param int $courseid the course id.
     * @param string $name the name, already cleaned (see clean_name()).
     * @param array $snapshot from snapshot().
     * @param bool $canmanage whether the user has tool/activitydates:manage.
     * @param bool $canlocks whether the user has tool/activitydates:managelocks.
     * @return bool true when a configuration of that name was replaced.
     */
    public static function save(
        int $courseid,
        string $name,
        array $snapshot,
        bool $canmanage = true,
        bool $canlocks = true
    ): bool {
        global $DB;
        $now = time();
        $existing = $DB->get_record(self::TABLE, ['courseid' => $courseid, 'name' => $name]);
        if ($existing) {
            $merged = self::merge(self::decode($existing), $snapshot, $canmanage, $canlocks);
            $DB->update_record(self::TABLE, (object) [
                'id' => $existing->id,
                'data' => json_encode($merged),
                'timemodified' => $now,
            ]);
            return true;
        }
        $data = json_encode($snapshot);
        $DB->insert_record(self::TABLE, (object) [
            'courseid' => $courseid,
            'name' => $name,
            'data' => $data,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        return false;
    }

    /**
     * A replacing snapshot, with the existing configuration's parts the saver may not edit.
     *
     * Without :manage the existing date settings, open/due/close inputs and Hold ticks and
     * the ticked activities are kept (a user without :manage does not change the dates
     * selection on Save either); without :managelocks the existing lock settings, Locked
     * inputs and Hold ticks and the note ticks are kept.
     *
     * @param array $existing the existing configuration, from decode().
     * @param array $snapshot the saver's snapshot, from snapshot().
     * @param bool $canmanage whether the saver has tool/activitydates:manage.
     * @param bool $canlocks whether the saver has tool/activitydates:managelocks.
     * @return array the snapshot to store.
     */
    public static function merge(array $existing, array $snapshot, bool $canmanage, bool $canlocks): array {
        $keep = array_merge($canmanage ? [] : self::DATESETTINGS, $canlocks ? [] : self::LOCKSETTINGS);
        foreach ($keep as $name) {
            if (array_key_exists($name, $existing['settings'])) {
                $snapshot['settings'][$name] = $existing['settings'][$name];
            }
        }
        $keepfields = array_merge($canmanage ? [] : self::DATEFIELDS, $canlocks ? [] : ['timelock']);
        foreach ($keepfields as $field) {
            foreach (['rows', 'hold'] as $part) {
                unset($snapshot[$part][$field]);
                if (!empty($existing[$part][$field]) && is_array($existing[$part][$field])) {
                    $snapshot[$part][$field] = $existing[$part][$field];
                }
            }
        }
        if (!$canmanage) {
            $snapshot['selected'] = $existing['selected'];
        }
        if (!$canlocks) {
            $snapshot['notes'] = $existing['notes'];
            $snapshot['coursenotes'] = $existing['coursenotes'];
        }
        return $snapshot;
    }

    /**
     * Whether a user may delete a configuration: they must be able to edit every part it
     * holds (the date part needs :manage, the lock part :managelocks).
     *
     * @param \stdClass $record a configuration record.
     * @param bool $canmanage whether the user has tool/activitydates:manage.
     * @param bool $canlocks whether the user has tool/activitydates:managelocks.
     * @return bool
     */
    public static function can_delete(\stdClass $record, bool $canmanage, bool $canlocks): bool {
        $snapshot = self::decode($record);
        $holds = fn(array $settings, array $fields): bool =>
            (bool) array_intersect_key($snapshot['settings'], array_flip($settings))
            || (bool) array_filter(array_intersect_key($snapshot['rows'], array_flip($fields)))
            || (bool) array_filter(array_intersect_key($snapshot['hold'], array_flip($fields)));
        $hasdates = $holds(self::DATESETTINGS, self::DATEFIELDS);
        $haslocks = $holds(self::LOCKSETTINGS, ['timelock']) || $snapshot['notes'] || $snapshot['coursenotes'];
        return (!$hasdates || $canmanage) && (!$haslocks || $canlocks);
    }

    /**
     * Delete one configuration of the course.
     *
     * @param int $courseid the course id.
     * @param int $id the configuration id, which must belong to the course.
     * @return \stdClass the deleted record.
     * @throws \dml_missing_record_exception when there is no such configuration in the course.
     */
    public static function delete(int $courseid, int $id): \stdClass {
        global $DB;
        $record = self::get($courseid, $id);
        $DB->delete_records(self::TABLE, ['id' => $record->id]);
        return $record;
    }

    /**
     * The snapshot of what the page posted, limited to what the user may edit.
     *
     * The table parts are passed already limited to the fields the user may set (view.php
     * reads only those); here they are limited to the ticked activities as well.
     *
     * @param \stdClass $settings settings from activitydates::settings_from_form().
     * @param int[] $selected the ticked cmids.
     * @param array $rows the table's inputs, field => [cmid => string].
     * @param array $hold the Hold ticks, field => [cmid => 1].
     * @param int[] $notes the cmids whose activity-page note is ticked.
     * @param int[] $coursenotes the cmids whose course-page note is ticked.
     * @param bool $canmanage whether the user has tool/activitydates:manage.
     * @param bool $canlocks whether the user has tool/activitydates:managelocks.
     * @return array the snapshot.
     */
    public static function snapshot(
        \stdClass $settings,
        array $selected,
        array $rows,
        array $hold,
        array $notes,
        array $coursenotes,
        bool $canmanage,
        bool $canlocks
    ): array {
        $saved = [];
        $names = array_merge($canmanage ? self::DATESETTINGS : [], $canlocks ? self::LOCKSETTINGS : []);
        foreach ($names as $name) {
            $saved[$name] = $settings->$name ?? null;
        }
        if ($canmanage && empty($settings->finishenabled)) {
            $saved['schedulefinish'] = 0;
        }

        // Table inputs and Hold ticks only for the ticked activities (the only rows whose
        // inputs are editable), and no input longer than a date value can be.
        $ticked = array_fill_keys(array_map('intval', $selected), true);
        $cleanrows = [];
        $cleanhold = [];
        foreach (self::FIELDS as $field) {
            foreach ($rows[$field] ?? [] as $cmid => $value) {
                if (isset($ticked[(int) $cmid]) && is_string($value) && \core_text::strlen($value) <= self::MAXINPUT) {
                    $cleanrows[$field][(int) $cmid] = $value;
                }
            }
            foreach ($hold[$field] ?? [] as $cmid => $holdtick) {
                if (isset($ticked[(int) $cmid]) && !empty($holdtick)) {
                    $cleanhold[$field][(int) $cmid] = 1;
                }
            }
        }
        return [
            'version' => self::VERSION,
            'modtype' => (string) $settings->modtype,
            'settings' => $saved,
            'selected' => array_values(array_map('intval', $selected)),
            'rows' => $cleanrows,
            'hold' => $cleanhold,
            'notes' => $canlocks ? array_values(array_map('intval', $notes)) : [],
            'coursenotes' => $canlocks ? array_values(array_map('intval', $coursenotes)) : [],
        ];
    }

    /**
     * Decode a configuration's snapshot.
     *
     * @param \stdClass $record a configuration record.
     * @return array the snapshot, with every part present (empty when missing).
     */
    public static function decode(\stdClass $record): array {
        $data = json_decode((string) $record->data, true);
        $data = is_array($data) ? $data : [];
        return [
            'modtype' => is_string($data['modtype'] ?? null) ? $data['modtype'] : '',
            'settings' => is_array($data['settings'] ?? null) ? $data['settings'] : [],
            'selected' => is_array($data['selected'] ?? null) ? $data['selected'] : [],
            'rows' => is_array($data['rows'] ?? null) ? $data['rows'] : [],
            'hold' => is_array($data['hold'] ?? null) ? $data['hold'] : [],
            'notes' => is_array($data['notes'] ?? null) ? $data['notes'] : [],
            'coursenotes' => is_array($data['coursenotes'] ?? null) ? $data['coursenotes'] : [],
        ];
    }

    /**
     * The form data a snapshot's settings give, for activitydates::settings_from_form().
     *
     * The settings the user may not edit keep the course's current values.
     *
     * @param array $snapshot from decode().
     * @param \stdClass $current the page's current settings (activitydates::load_settings()).
     * @param bool $canmanage whether the user has tool/activitydates:manage.
     * @param bool $canlocks whether the user has tool/activitydates:managelocks.
     * @return \stdClass form data with modtype and every setting.
     */
    public static function form_data(array $snapshot, \stdClass $current, bool $canmanage, bool $canlocks): \stdClass {
        $data = (object) ['modtype' => $snapshot['modtype']];
        foreach ([[self::DATESETTINGS, $canmanage], [self::LOCKSETTINGS, $canlocks]] as [$names, $allowed]) {
            foreach ($names as $name) {
                if ($allowed && array_key_exists($name, $snapshot['settings']) && is_scalar($snapshot['settings'][$name])) {
                    $value = $snapshot['settings'][$name];
                    $data->$name = in_array($name, self::MODESETTINGS, true) ? (string) $value : (int) $value;
                } else {
                    $data->$name = $current->$name ?? null;
                }
            }
        }
        if (!$canmanage && empty($current->finishenabled)) {
            $data->schedulefinish = 0;
        }
        return $data;
    }

    /**
     * The table parts of a snapshot, limited to the course's current activities and
     * to what the user may edit.
     *
     * @param array $snapshot from decode().
     * @param int[] $validcmids the cms of the type the course has now.
     * @param array $editable field => bool: the inputs the user may edit.
     * @param array $fixable field => bool: the Hold ticks the user may set.
     * @param bool $canlocks whether the user has tool/activitydates:managelocks.
     * @return array selected, rows, hold, notes and coursenotes as view.php reads them from a post,
     *     and dropped: how many activities of the snapshot the course no longer has.
     */
    public static function table_parts(array $snapshot, array $validcmids, array $editable, array $fixable, bool $canlocks): array {
        $valid = array_fill_keys(array_map('intval', $validcmids), true);
        $seen = [];
        $ids = function (array $list) use ($valid, &$seen): array {
            $kept = [];
            foreach ($list as $cmid) {
                if (!is_scalar($cmid)) {
                    continue;
                }
                $cmid = (int) $cmid;
                $seen[$cmid] = true;
                if (isset($valid[$cmid])) {
                    $kept[$cmid] = $cmid;
                }
            }
            return array_values($kept);
        };

        $rows = [];
        $hold = [];
        foreach (self::FIELDS as $field) {
            $rows[$field] = [];
            $hold[$field] = [];
            $fieldrows = is_array($snapshot['rows'][$field] ?? null) ? $snapshot['rows'][$field] : [];
            foreach ($ids(array_keys($fieldrows)) as $cmid) {
                if (!empty($editable[$field]) && is_string($fieldrows[$cmid])) {
                    $rows[$field][$cmid] = clean_param($fieldrows[$cmid], PARAM_RAW_TRIMMED);
                }
            }
            $fieldhold = is_array($snapshot['hold'][$field] ?? null) ? $snapshot['hold'][$field] : [];
            foreach ($ids(array_keys($fieldhold)) as $cmid) {
                if (!empty($fixable[$field]) && !empty($fieldhold[$cmid])) {
                    $hold[$field][$cmid] = 1;
                }
            }
        }
        $selected = $ids($snapshot['selected']);
        $notes = $ids($snapshot['notes']);
        $coursenotes = $ids($snapshot['coursenotes']);

        // The selection in course order, as selected_from_form() gives it.
        $order = array_flip(array_map('intval', $validcmids));
        usort($selected, fn(int $a, int $b): int => $order[$a] <=> $order[$b]);

        return [
            'selected' => $selected,
            'rows' => $rows,
            'hold' => $hold,
            'notes' => $canlocks ? $notes : [],
            'coursenotes' => $canlocks ? $coursenotes : [],
            'dropped' => count(array_diff_key($seen, $valid)),
        ];
    }
}
