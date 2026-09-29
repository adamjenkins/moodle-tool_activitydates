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
 * Fingerprint of the date-affecting settings, for stale preview detection.
 *
 * The page renders the fingerprint of the settings its date table was built
 * from; Save refuses to write when the submitted settings hash differently.
 * The hash is a plain sha1 with no secret: it detects staleness only and is
 * not a security control (row validation is).
 *
 * Inputs are normalised so that a Save straight after page load matches:
 * timestamps are floored to the minute, fields that do not apply in the
 * current mode count as 0, and the selection is sorted and de-duplicated.
 *
 * @package    tool_activitydates
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class fingerprint {
    /**
     * Fingerprint of the dates-tab inputs.
     *
     * Covers modtype, schedulestart, the finish date (when enabled),
     * sessionlength, activitiespersession, the close mode and its days or date,
     * the due mode and its days or date (when the type has a duedate column),
     * and the selected cmids. hideunselected and resetunselected are excluded.
     *
     * @param \stdClass $settings settings object (tool_activitydates row shape).
     * @param array $selectedcmids the selected cm ids, in any order.
     * @param bool $hasdue whether the type has a duedate column.
     * @return string 40-character sha1 hex digest.
     */
    public static function dates(\stdClass $settings, array $selectedcmids, bool $hasdue): string {
        $finishenabled = !empty($settings->finishenabled);
        $close = self::mode_fields($settings, 'close');
        $due = $hasdue ? self::mode_fields($settings, 'due') : ['', 0, 0];
        return self::hash([
            'dates',
            (string) ($settings->modtype ?? ''),
            self::minute($settings->schedulestart ?? 0),
            (int) $finishenabled,
            $finishenabled ? self::minute($settings->schedulefinish ?? 0) : 0,
            (int) ($settings->sessionlength ?? 0),
            (int) ($settings->activitiespersession ?? 0),
            ...$close,
            ...$due,
            self::selection($selectedcmids),
        ]);
    }

    /**
     * Fingerprint of the locks-tab inputs.
     *
     * Covers modtype, schedulestart, sessionlength, activitiespersession and
     * the selected cmids. The note options are excluded.
     *
     * @param \stdClass $settings settings object (modtype, schedulestart, sessionlength, activitiespersession).
     * @param array $selectedcmids the selected cm ids, in any order.
     * @return string 40-character sha1 hex digest.
     */
    public static function locks(\stdClass $settings, array $selectedcmids): string {
        return self::hash([
            'locks',
            (string) ($settings->modtype ?? ''),
            self::minute($settings->schedulestart ?? 0),
            (int) ($settings->sessionlength ?? 0),
            (int) ($settings->activitiespersession ?? 0),
            self::selection($selectedcmids),
        ]);
    }

    /**
     * The mode, days and date of the close or due setting.
     *
     * The days count only in days mode and the date only in date mode.
     *
     * @param \stdClass $settings settings object.
     * @param string $prefix 'close' or 'due'.
     * @return array [mode, days, date]
     */
    private static function mode_fields(\stdClass $settings, string $prefix): array {
        $mode = (string) ($settings->{$prefix . 'mode'} ?? '');
        $days = $mode === schedule::MODE_DAYS ? (int) ($settings->{$prefix . 'days'} ?? 0) : 0;
        $date = $mode === schedule::MODE_DATE ? self::minute($settings->{$prefix . 'date'} ?? 0) : 0;
        return [$mode, $days, $date];
    }

    /**
     * A timestamp floored to the minute.
     *
     * @param mixed $timestamp the timestamp.
     * @return int
     */
    private static function minute($timestamp): int {
        $timestamp = (int) $timestamp;
        return $timestamp - $timestamp % 60;
    }

    /**
     * The selection as sorted, unique ints.
     *
     * @param array $cmids cm ids.
     * @return int[]
     */
    private static function selection(array $cmids): array {
        $ints = array_values(array_unique(array_map('intval', $cmids)));
        sort($ints);
        return $ints;
    }

    /**
     * The sha1 of the canonical JSON of an ordered list.
     *
     * @param array $values the canonical values.
     * @return string
     */
    private static function hash(array $values): string {
        return sha1(json_encode($values));
    }
}
