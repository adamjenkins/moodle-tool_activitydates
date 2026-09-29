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
 * Date-field conversion and row validation for the editable date tables.
 *
 * Converts between timestamps and datetime-local input values in the user's
 * timezone, and validates the submitted per-activity rows. No database and
 * no globals.
 *
 * @package    tool_activitydates
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class datefields {
    /** @var string the datetime-local input value format. */
    public const INPUTFORMAT = 'Y-m-d\TH:i';

    /**
     * Convert a timestamp to an input value.
     *
     * @param int $timestamp the instant, or 0 for no date.
     * @param \DateTimeZone $tz the user's timezone.
     * @return string '' for 0; else YYYY-MM-DDTHH:MM in $tz.
     */
    public static function to_input(int $timestamp, \DateTimeZone $tz): string {
        if ($timestamp === 0) {
            return '';
        }
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone($tz)->format(self::INPUTFORMAT);
    }

    /**
     * Parse an input value strictly.
     *
     * The value has no offset, so a wall-clock time in the hour a DST fall-back repeats
     * names two instants, and PHP picks the second. A known timestamp (the row's current
     * or proposed date) that shows as the value wins, so a date the table showed is saved
     * unchanged.
     *
     * @param string $value the submitted value.
     * @param \DateTimeZone $tz the user's timezone.
     * @param int[] $known timestamps the input may have shown.
     * @return int|null 0 for ''; null for a malformed or impossible date, or one at or before
     *   the Unix epoch (0 means "no date", and the ordering rules skip values that are not positive);
     *   else the timestamp.
     */
    public static function from_input(string $value, \DateTimeZone $tz, array $known = []): ?int {
        if ($value === '') {
            return 0;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!' . self::INPUTFORMAT, $value, $tz);
        // The round trip rejects overflowing dates (30 February) and wall-clock times skipped by DST.
        if ($date === false || $date->format(self::INPUTFORMAT) !== $value) {
            return null;
        }
        foreach ($known as $candidate) {
            if (is_int($candidate) && $candidate > 0 && self::to_input($candidate, $tz) === $value) {
                return $candidate;
            }
        }
        $timestamp = $date->getTimestamp();
        return $timestamp > 0 ? $timestamp : null;
    }

    /**
     * The timestamps each row's inputs may have shown: the current and the proposed dates.
     *
     * @param array $tabledata rows from activitydates::get_table_data().
     * @return array cmid => [field => int[]], for validate_dates().
     */
    public static function known_values(array $tabledata): array {
        $known = [];
        foreach ($tabledata as $row) {
            if (!empty($row['isheader'])) {
                continue;
            }
            foreach ([$row['current'] ?? [], $row['proposed'] ?? []] as $dates) {
                foreach ($dates as $field => $timestamp) {
                    if (is_int($timestamp) && $timestamp > 0) {
                        $known[(int) $row['id']][$field][] = $timestamp;
                    }
                }
            }
        }
        return $known;
    }

    /**
     * Read one submitted value.
     *
     * @param array $inputs the inputs, keyed by field then cmid.
     * @param string $field the field name.
     * @param int $cmid the cm id.
     * @param \DateTimeZone $tz the user's timezone.
     * @param array $known cmid => [field => int[]], see from_input().
     * @return int|null as from_input(); a missing input is ''.
     */
    private static function read(array $inputs, string $field, int $cmid, \DateTimeZone $tz, array $known): ?int {
        $value = $inputs[$field][$cmid] ?? '';
        if (!is_string($value)) {
            return null;
        }
        return self::from_input(trim($value), $tz, $known[$cmid][$field] ?? []);
    }

    /**
     * Validate the table rows.
     *
     * @param array $inputs ['timeopen' => [cmid => string], 'duedate' => [...], 'timeclose' => [...],
     *   'timelock' => [...]].
     * @param array $allowed cmid => true: selected and scheduled cms of this course and type.
     * @param bool $hasdue whether the type has a duedate column.
     * @param bool $haslocks whether the lock dates are edited (and so parsed).
     * @param \DateTimeZone $tz the user's timezone.
     * @param array $known cmid => [field => int[]]: the timestamps each input may have shown (see from_input()
     *   and known_values()).
     * @return array [values, errors]: values[cmid] = ['timeopen' => int, 'duedate' => ?int, 'timeclose' => int,
     *   'timelock' => ?int]; errors[cmid][field] = lang string key. Cms not in $allowed are ignored. Every date
     *   is optional: an empty or missing value is 0 (not set, or for the lock date, cleared), and the ordering
     *   rules (close after open; due after open and not after close) apply only between dates that are set.
     *   The lock date has no ordering rule, and is null when $haslocks is false.
     */
    public static function validate_dates(
        array $inputs,
        array $allowed,
        bool $hasdue,
        bool $haslocks,
        \DateTimeZone $tz,
        array $known = []
    ): array {
        $values = [];
        $errors = [];
        foreach (array_keys($allowed) as $cmid) {
            $cmid = (int) $cmid;
            $rowerrors = [];

            $open = self::read($inputs, 'timeopen', $cmid, $tz, $known);
            if ($open === null) {
                $rowerrors['timeopen'] = 'errorinvaliddate';
            }

            $close = self::read($inputs, 'timeclose', $cmid, $tz, $known);
            if ($close === null) {
                $rowerrors['timeclose'] = 'errorinvaliddate';
            } else if ($close > 0 && $open > 0 && $close <= $open) {
                $rowerrors['timeclose'] = 'errorclosebeforeopen';
            }

            $due = null;
            if ($hasdue) {
                $due = self::read($inputs, 'duedate', $cmid, $tz, $known);
                if ($due === null) {
                    $rowerrors['duedate'] = 'errorinvaliddate';
                } else if ($due > 0 && $open > 0 && $due <= $open) {
                    $rowerrors['duedate'] = 'errorduebeforeopen';
                } else if ($due > 0 && $close > 0 && $due > $close) {
                    $rowerrors['duedate'] = 'errordueafterclose';
                }
            }

            $lock = null;
            if ($haslocks) {
                $lock = self::read($inputs, 'timelock', $cmid, $tz, $known);
                if ($lock === null) {
                    $rowerrors['timelock'] = 'errorinvaliddate';
                }
            }

            if ($rowerrors) {
                $errors[$cmid] = $rowerrors;
            } else {
                $values[$cmid] = ['timeopen' => $open, 'duedate' => $due, 'timeclose' => $close, 'timelock' => $lock];
            }
        }
        return [$values, $errors];
    }
}
