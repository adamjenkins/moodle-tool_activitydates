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
 * Timezone-aware session date engine.
 *
 * Pure date arithmetic: no database and no globals. It turns the schedule
 * settings into session windows and proposed per-activity dates.
 *
 * @package    tool_activitydates
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class schedule {
    /** @var string a number of days after the open date. */
    public const MODE_DAYS = 'days';

    /** @var string when the next session starts. */
    public const MODE_SESSION = 'session';

    /** @var string one common date. */
    public const MODE_DATE = 'date';

    /** @var string no date. */
    public const MODE_NONE = 'none';

    /** @var string[] every mode. */
    public const MODES = [self::MODE_DAYS, self::MODE_SESSION, self::MODE_DATE, self::MODE_NONE];

    /**
     * Add whole calendar days in $tz, keeping local wall-clock time across DST.
     *
     * @param int $timestamp the starting instant.
     * @param int $days the number of days to add (may be negative).
     * @param \DateTimeZone $tz the timezone whose wall-clock time is kept.
     * @return int
     */
    public static function add_days(int $timestamp, int $days, \DateTimeZone $tz): int {
        return (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone($tz)
            ->modify(($days >= 0 ? '+' : '') . $days . ' days')
            ->getTimestamp();
    }

    /**
     * Compute the session windows and the proposed dates of every activity.
     *
     * All cms are chunked by activitiespersession. A chunk gets a window only if
     * it contains a selected cm, and only such chunks advance the session index.
     * A session ends when the next one starts. With the finish date enabled, a
     * window starting after it is null and its cms are not scheduled.
     *
     * @param \stdClass $settings settings object (tool_activitydates row shape).
     * @param int[] $cmids every cm of the type, in course order.
     * @param array $selected cmid => true for selected cms.
     * @param bool $hasdue whether the type has a duedate column.
     * @param \DateTimeZone $tz the user's timezone.
     * @return array{windows: array<int, ?array{sessionnumber:int,start:int,end:int}>,
     *               dates: array<int, ?array{timeopen:int,duedate:?int,timeclose:int}>}
     *   windows is keyed by chunk index (0-based, chunks of activitiespersession over ALL
     *   $cmids); dates is keyed by cmid, null = not scheduled.
     * @throws \coding_exception on an unknown close or due mode.
     */
    public static function compute(\stdClass $settings, array $cmids, array $selected, bool $hasdue, \DateTimeZone $tz): array {
        foreach (['closemode', 'duemode'] as $field) {
            if (!in_array($settings->$field, self::MODES, true)) {
                throw new \coding_exception("Unknown {$field}: " . $settings->$field);
            }
        }

        $chunksize = max(1, (int) $settings->activitiespersession);
        $sessionlength = (int) $settings->sessionlength;
        $windows = [];
        $dates = [];
        $windowindex = 0;
        foreach (array_chunk($cmids, $chunksize) as $chunkindex => $chunk) {
            $window = null;
            $hasselected = false;
            foreach ($chunk as $cmid) {
                if (!empty($selected[$cmid])) {
                    $hasselected = true;
                    break;
                }
            }
            if ($hasselected) {
                $start = self::add_days((int) $settings->schedulestart, $windowindex * $sessionlength, $tz);
                $end = self::add_days($start, $sessionlength, $tz);
                $windowindex++;
                if (empty($settings->finishenabled) || $start <= (int) $settings->schedulefinish) {
                    $window = ['sessionnumber' => $windowindex, 'start' => $start, 'end' => $end];
                }
            }
            $windows[$chunkindex] = $window;

            foreach ($chunk as $cmid) {
                if ($window === null || empty($selected[$cmid])) {
                    $dates[$cmid] = null;
                    continue;
                }
                $timeopen = $window['start'];
                $duedate = null;
                if ($hasdue) {
                    $duedate = self::by_mode(
                        $settings->duemode,
                        $timeopen,
                        (int) $settings->duedays,
                        (int) $settings->duedate,
                        $window['end'],
                        $tz
                    );
                }
                $dates[$cmid] = [
                    'timeopen' => $timeopen,
                    'duedate' => $duedate,
                    'timeclose' => self::by_mode(
                        $settings->closemode,
                        $timeopen,
                        (int) $settings->closedays,
                        (int) $settings->closedate,
                        $window['end'],
                        $tz
                    ),
                ];
            }
        }
        return ['windows' => $windows, 'dates' => $dates];
    }

    /**
     * One date by a mode.
     *
     * @param string $mode one of MODES.
     * @param int $timeopen the activity's open date.
     * @param int $days the day offset for days mode.
     * @param int $date the common date for date mode.
     * @param int $sessionend the end of the activity's session.
     * @param \DateTimeZone $tz the user's timezone.
     * @return int 0 for none.
     */
    private static function by_mode(string $mode, int $timeopen, int $days, int $date, int $sessionend, \DateTimeZone $tz): int {
        return match ($mode) {
            self::MODE_DAYS => self::add_days($timeopen, $days, $tz),
            self::MODE_SESSION => $sessionend,
            self::MODE_DATE => $date,
            self::MODE_NONE => 0,
        };
    }
}
