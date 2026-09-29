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

namespace tool_activitydates\output;

use tool_activitydates\local\datefields;

/**
 * Turns activitydates::get_table_data() rows into the modtable template's rows.
 *
 * @package    tool_activitydates
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class preview_rows {
    /** @var string the inputs show the activities' current values (page load, after Save). */
    public const SOURCE_CURRENT = 'current';

    /** @var string the inputs show the proposals, and the posted values of fixed fields (Preview). */
    public const SOURCE_PREVIEW = 'preview';

    /** @var string the inputs re-show the posted values (a Save with row errors). */
    public const SOURCE_POSTED = 'posted';

    /** @var string[] the date fields, in display order. */
    private const FIELDS = ['timeopen', 'duedate', 'timeclose', 'timelock'];

    /** @var array field => [lang key, component] of each field's label. */
    private const LABELS = [
        'timeopen' => ['open', 'tool_activitydates'],
        'duedate' => ['due', 'tool_activitydates'],
        'timeclose' => ['close', 'tool_activitydates'],
        'timelock' => ['locked', 'core_grades'],
    ];

    /**
     * Whether a row's Fix checkbox for a field is ticked.
     *
     * The posted tick counts where the checkbox was editable (a selected row and a
     * field the user may fix); elsewhere the saved flag does.
     *
     * @param array $row a get_table_data() data row.
     * @param string $field the date field.
     * @param array $fixable field => bool: the fields whose Fix flag the user may set.
     * @param array|null $fixposted the posted flags, field => [cmid => 1], or null for the saved ones.
     * @return bool
     */
    private static function is_fixed(array $row, string $field, array $fixable, ?array $fixposted): bool {
        if ($fixposted !== null && $row['selected'] === 'checked' && !empty($fixable[$field])) {
            return !empty($fixposted[$field][(int) $row['id']]);
        }
        return !empty($row['fixed'][$field]);
    }

    /**
     * The fixed fields for local\schedule::compute() on Preview.
     *
     * A field is fixed when its Fix checkbox is ticked (see is_fixed()). Its value is
     * the posted input value when there is one, else the current value (the input
     * was not editable, so it was not posted). A posted value that does not parse
     * leaves the field unfixed for the calculation; the input keeps the raw string.
     *
     * @param array $tabledata rows from activitydates::get_table_data() (with the saved flags).
     * @param array|null $fixposted the posted flags, field => [cmid => 1], or null for the saved ones.
     * @param array $rowinputs the posted inputs, field => [cmid => string], of the fields the user may edit.
     * @param array $fixable field => bool: the fields whose Fix flag the user may set.
     * @param \DateTimeZone $tz the user's timezone.
     * @return array cmid => [field => int].
     */
    public static function engine_fixed(
        array $tabledata,
        ?array $fixposted,
        array $rowinputs,
        array $fixable,
        \DateTimeZone $tz
    ): array {
        $fixed = [];
        foreach ($tabledata as $row) {
            if ($row['isheader']) {
                continue;
            }
            $cmid = (int) $row['id'];
            foreach (self::FIELDS as $field) {
                $current = $row['current'][$field] ?? null;
                if ($current === null || !self::is_fixed($row, $field, $fixable, $fixposted)) {
                    continue;
                }
                $posted = $rowinputs[$field][$cmid] ?? null;
                if ($posted === null) {
                    $fixed[$cmid][$field] = (int) $current;
                    continue;
                }
                $value = is_string($posted) ? datefields::from_input(trim($posted), $tz, [(int) $current]) : null;
                if ($value !== null) {
                    $fixed[$cmid][$field] = $value;
                }
            }
        }
        return $fixed;
    }

    /**
     * Decorate the table rows for the template.
     *
     * Each data row gets a fields list, one entry per present field, with the
     * input's value, its disabled state, its validation error, and its Fix
     * checkbox. An input is editable on a selected, scheduled row when the user
     * may edit the field ($options['editable']), and, for the lock date, when the
     * activity has a grade item; every other input is disabled and shows the
     * current value. The value of an editable input depends on the source:
     * - SOURCE_CURRENT: the current value;
     * - SOURCE_PREVIEW: a fixed field's posted string (the current value when
     *   nothing was posted), else the proposal;
     * - SOURCE_POSTED: the posted string.
     *
     * A Fix checkbox is editable on a selected row for a field the user may fix
     * ($options['fixable']). Rows also get their grade-lock note ticks (posted
     * ones on selected rows when given, else the saved ones), whether those are
     * editable (selected rows), and their status text.
     *
     * @param array $tabledata rows from activitydates::get_table_data().
     * @param \DateTimeZone $tz the user's timezone.
     * @param array $options
     *   - source string: one of the SOURCE_ constants (default SOURCE_CURRENT);
     *   - fields string[]: the present fields, of timeopen, duedate, timeclose, timelock;
     *   - editable array: field => bool, whether the user may edit the field's input;
     *   - fixable array: field => bool, whether the user may set the field's Fix flag;
     *   - rowinputs array: the posted inputs, field => [cmid => string];
     *   - rowerrors array: datefields::validate_dates()'s errors, cmid => [field => lang key];
     *   - fixposted array|null: the posted Fix flags, field => [cmid => 1], or null for the saved ones;
     *   - notecmids int[]|null: the posted activity-page note ticks, or null for the saved ones;
     *   - coursenotecmids int[]|null: the posted course-page note ticks, or null for the saved ones.
     * @return array the template rows (no cm_info objects).
     */
    public static function dates(array $tabledata, \DateTimeZone $tz, array $options): array {
        $source = $options['source'] ?? self::SOURCE_CURRENT;
        $fields = array_values(array_intersect(self::FIELDS, $options['fields'] ?? self::FIELDS));
        $editable = $options['editable'] ?? [];
        $fixable = $options['fixable'] ?? [];
        $rowinputs = $options['rowinputs'] ?? [];
        $rowerrors = $options['rowerrors'] ?? [];
        $fixposted = $options['fixposted'] ?? null;
        $ticks = fn(?array $cmids): ?array => $cmids === null ? null : array_fill_keys(array_map('intval', $cmids), true);
        $notes = $ticks($options['notecmids'] ?? null);
        $coursenotes = $ticks($options['coursenotecmids'] ?? null);

        $labels = [];
        foreach ($fields as $field) {
            $labels[$field] = get_string(self::LABELS[$field][0], self::LABELS[$field][1]);
        }

        $rows = [];
        foreach ($tabledata as $row) {
            if ($row['isheader']) {
                unset($row['cm'], $row['proposed']);
                $rows[] = $row;
                continue;
            }
            $cmid = (int) $row['id'];
            $selected = $row['selected'] === 'checked';
            $scheduled = $selected && $row['scheduled'];
            $name = (string) $row['name'];

            $entries = [];
            foreach ($fields as $field) {
                $current = datefields::to_input((int) ($row['current'][$field] ?? 0), $tz);
                $enabled = $scheduled && !empty($editable[$field])
                    && ($field !== 'timelock' || !empty($row['hasgradeitem']));
                $fixed = self::is_fixed($row, $field, $fixable, $fixposted);
                $posted = $rowinputs[$field][$cmid] ?? null;
                $posted = is_string($posted) ? $posted : null;

                $value = $current;
                if ($enabled && $source === self::SOURCE_POSTED) {
                    $value = $posted ?? '';
                } else if ($enabled && $source === self::SOURCE_PREVIEW) {
                    if ($fixed) {
                        $value = $posted ?? $current;
                    } else {
                        $value = datefields::to_input((int) ($row['proposed'][$field] ?? 0), $tz);
                    }
                }

                $errorkey = $enabled ? ($rowerrors[$cmid][$field] ?? null) : null;
                $entries[] = [
                    'field' => $field,
                    'cmid' => $cmid,
                    'label' => $labels[$field],
                    'inputlabel' => $labels[$field] . ': ' . $name,
                    'value' => $value,
                    'disabled' => !$enabled,
                    'invalid' => $errorkey !== null,
                    'error' => $errorkey === null ? '' : get_string($errorkey, 'tool_activitydates'),
                    'fixed' => $fixed,
                    // Editable Fix checkboxes follow the row's selection (modform.js).
                    'fixtoggle' => !empty($fixable[$field]),
                    'fixdisabled' => !$selected || empty($fixable[$field]),
                    'fixlabel' => get_string(
                        'fixfield',
                        'tool_activitydates',
                        (object) ['field' => $labels[$field], 'name' => $name]
                    ),
                ];
            }

            $shownote = !empty($row['shownote']);
            $shownotecoursepage = !empty($row['shownotecoursepage']);
            if ($selected && $notes !== null) {
                $shownote = isset($notes[$cmid]);
            }
            if ($selected && $coursenotes !== null) {
                $shownotecoursepage = isset($coursenotes[$cmid]);
            }

            $rows[] = [
                'isheader' => false,
                'id' => $cmid,
                'name' => $name,
                'intro' => $row['intro'],
                'selected' => $row['selected'],
                'questioncount' => $row['questioncount'],
                'editable' => $scheduled,
                'statustext' => $row['status'] === '' ? '' : get_string($row['status'], 'tool_activitydates'),
                'fields' => $entries,
                'shownote' => $shownote,
                'shownotecoursepage' => $shownotecoursepage,
                'notedisabled' => !$selected,
            ];
        }
        return $rows;
    }
}
