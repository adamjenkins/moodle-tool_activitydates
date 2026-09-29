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
    /** @var string[] the editable date fields, in column order. */
    private const FIELDS = ['timeopen', 'duedate', 'timeclose'];

    /**
     * Decorate the dates-tab table rows for the template.
     *
     * Selected, scheduled rows are editable: each field gets a
     * {field}value input value (the teacher's own posted strings when
     * $rowinputs is given, else the engine's proposal), plus a {field}error
     * message and {field}invalid flag from $rowerrors. Other rows get their
     * status text instead.
     *
     * @param array $tabledata rows from activitydates::get_table_data().
     * @param array|null $rowinputs posted inputs ['timeopen' => [cmid => string], ...] to re-render, or null.
     * @param array $rowerrors datefields::validate_dates()'s errors: cmid => [field => lang key].
     * @param \DateTimeZone $tz the user's timezone.
     * @return array the template rows (no cm_info objects).
     */
    public static function dates(array $tabledata, ?array $rowinputs, array $rowerrors, \DateTimeZone $tz): array {
        $rows = [];
        foreach ($tabledata as $row) {
            $proposed = $row['proposed'] ?? null;
            unset($row['cm'], $row['proposed']);
            if ($row['isheader']) {
                $rows[] = $row;
                continue;
            }
            $cmid = (int) $row['id'];
            $row['editable'] = $row['scheduled'] && $row['selected'] === 'checked';
            $row['statustext'] = $row['status'] === '' ? '' : get_string($row['status'], 'tool_activitydates');
            foreach (self::FIELDS as $field) {
                $value = '';
                if ($row['editable']) {
                    if ($rowinputs !== null) {
                        $posted = $rowinputs[$field][$cmid] ?? '';
                        $value = is_string($posted) ? $posted : '';
                    } else {
                        $value = datefields::to_input((int) ($proposed[$field] ?? 0), $tz);
                    }
                }
                $errorkey = $rowerrors[$cmid][$field] ?? null;
                $row[$field . 'value'] = $value;
                $row[$field . 'invalid'] = $errorkey !== null;
                $row[$field . 'error'] = $errorkey === null ? '' : get_string($errorkey, 'tool_activitydates');
            }
            $rows[] = $row;
        }
        return $rows;
    }
}
