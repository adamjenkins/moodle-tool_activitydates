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
 * Tests for the session date engine.
 *
 * @package    tool_activitydates
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_activitydates\local;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the schedule class.
 */
#[CoversClass(schedule::class)]
final class schedule_test extends \basic_testcase {
    /**
     * The timezone the tests schedule in.
     *
     * @return \DateTimeZone
     */
    private static function tz(): \DateTimeZone {
        return new \DateTimeZone('Europe/London');
    }

    /**
     * Timestamp of a London wall-clock time.
     *
     * @param string $local date and time, e.g. '2030-01-14 09:00'.
     * @return int
     */
    private static function ts(string $local): int {
        return (new \DateTimeImmutable($local, self::tz()))->getTimestamp();
    }

    /**
     * A settings object with test defaults.
     *
     * @param array $over values that replace the defaults.
     * @return \stdClass
     */
    private static function settings(array $over = []): \stdClass {
        return (object) array_merge([
            'id' => 1,
            'courseid' => 2,
            'modtype' => 'quiz',
            'schedulestart' => self::ts('2030-01-07 09:00'),
            'finishenabled' => 0,
            'schedulefinish' => 0,
            'sessionlength' => 7,
            'activitiespersession' => 2,
            'closemode' => schedule::MODE_SESSION,
            'closedays' => 3,
            'closedate' => 0,
            'duemode' => schedule::MODE_NONE,
            'duedays' => 2,
            'duedate' => 0,
            'hideunselected' => 0,
            'resetunselected' => 0,
        ], $over);
    }

    /**
     * Every cm of [11, 12, 13, 14] selected.
     *
     * @return array
     */
    private static function allselected(): array {
        return [11 => true, 12 => true, 13 => true, 14 => true];
    }

    /**
     * In session mode an activity closes when the next session starts.
     */
    public function test_session_mode_close_is_next_session_start(): void {
        $result = schedule::compute(self::settings(), [11, 12, 13, 14], self::allselected(), false, self::tz());

        $this->assertEquals([
            0 => ['sessionnumber' => 1, 'start' => self::ts('2030-01-07 09:00'), 'end' => self::ts('2030-01-14 09:00')],
            1 => ['sessionnumber' => 2, 'start' => self::ts('2030-01-14 09:00'), 'end' => self::ts('2030-01-21 09:00')],
        ], $result['windows']);
        $this->assertSame(self::ts('2030-01-07 09:00'), $result['dates'][11]['timeopen']);
        $this->assertSame(self::ts('2030-01-14 09:00'), $result['dates'][11]['timeclose']);
        $this->assertSame(self::ts('2030-01-14 09:00'), $result['dates'][13]['timeopen']);
        $this->assertSame(self::ts('2030-01-21 09:00'), $result['dates'][13]['timeclose']);
        $this->assertNull($result['dates'][11]['duedate']);
    }

    /**
     * In days mode an activity closes a number of days after it opens.
     */
    public function test_days_mode(): void {
        $settings = self::settings(['closemode' => schedule::MODE_DAYS, 'closedays' => 3]);
        $result = schedule::compute($settings, [11, 12, 13, 14], self::allselected(), false, self::tz());

        $this->assertSame(self::ts('2030-01-10 09:00'), $result['dates'][11]['timeclose']);
        $this->assertSame(self::ts('2030-01-17 09:00'), $result['dates'][13]['timeclose']);
    }

    /**
     * Date mode gives every activity the same close date; none mode gives none.
     */
    public function test_date_mode_and_none_mode(): void {
        $closedate = self::ts('2030-03-01 17:00');
        $settings = self::settings(['closemode' => schedule::MODE_DATE, 'closedate' => $closedate]);
        $result = schedule::compute($settings, [11, 12, 13, 14], self::allselected(), false, self::tz());
        foreach ([11, 12, 13, 14] as $cmid) {
            $this->assertSame($closedate, $result['dates'][$cmid]['timeclose']);
        }

        $settings = self::settings(['closemode' => schedule::MODE_NONE, 'closedate' => $closedate]);
        $result = schedule::compute($settings, [11, 12, 13, 14], self::allselected(), false, self::tz());
        foreach ([11, 12, 13, 14] as $cmid) {
            $this->assertSame(0, $result['dates'][$cmid]['timeclose']);
            $this->assertNotNull($result['dates'][$cmid]['timeopen']);
        }
    }

    /**
     * Due dates follow the due mode, but only for types with a due date.
     */
    public function test_due_modes_with_hasdue(): void {
        $settings = self::settings(['duemode' => schedule::MODE_DAYS, 'duedays' => 2]);
        $result = schedule::compute($settings, [11, 12, 13, 14], self::allselected(), true, self::tz());
        $this->assertSame(self::ts('2030-01-09 09:00'), $result['dates'][11]['duedate']);
        $this->assertSame(self::ts('2030-01-16 09:00'), $result['dates'][13]['duedate']);

        $settings = self::settings(['duemode' => schedule::MODE_SESSION]);
        $result = schedule::compute($settings, [11, 12, 13, 14], self::allselected(), true, self::tz());
        $this->assertSame(self::ts('2030-01-14 09:00'), $result['dates'][11]['duedate']);

        $duedate = self::ts('2030-02-01 12:00');
        $settings = self::settings(['duemode' => schedule::MODE_DATE, 'duedate' => $duedate]);
        $result = schedule::compute($settings, [11, 12, 13, 14], self::allselected(), true, self::tz());
        $this->assertSame($duedate, $result['dates'][14]['duedate']);

        $settings = self::settings(['duemode' => schedule::MODE_NONE]);
        $result = schedule::compute($settings, [11, 12, 13, 14], self::allselected(), true, self::tz());
        $this->assertSame(0, $result['dates'][11]['duedate']);

        foreach ([schedule::MODE_DAYS, schedule::MODE_SESSION, schedule::MODE_DATE, schedule::MODE_NONE] as $mode) {
            $settings = self::settings(['duemode' => $mode, 'duedate' => $duedate]);
            $result = schedule::compute($settings, [11, 12, 13, 14], self::allselected(), false, self::tz());
            foreach ([11, 12, 13, 14] as $cmid) {
                $this->assertNull($result['dates'][$cmid]['duedate'], "duemode {$mode} without a due date column");
            }
        }
    }

    /**
     * A chunk with no selected cm has no window and does not use up a session.
     */
    public function test_unselected_chunk_does_not_advance_window(): void {
        $result = schedule::compute(self::settings(), [11, 12, 13, 14], [13 => true], false, self::tz());

        $this->assertNull($result['windows'][0]);
        $this->assertSame(1, $result['windows'][1]['sessionnumber']);
        $this->assertSame(self::ts('2030-01-07 09:00'), $result['windows'][1]['start']);
        $this->assertSame(self::ts('2030-01-07 09:00'), $result['dates'][13]['timeopen']);
        $this->assertNull($result['dates'][11]);
        $this->assertNull($result['dates'][12]);
        $this->assertNull($result['dates'][14]);
    }

    /**
     * With the finish date enabled, sessions starting after it are not scheduled.
     */
    public function test_finish_cap(): void {
        $settings = self::settings(['finishenabled' => 1, 'schedulefinish' => self::ts('2030-01-10 09:00')]);
        $result = schedule::compute($settings, [11, 12, 13, 14], self::allselected(), false, self::tz());
        $this->assertNotNull($result['windows'][0]);
        $this->assertNull($result['windows'][1]);
        $this->assertNotNull($result['dates'][11]);
        $this->assertNotNull($result['dates'][12]);
        $this->assertNull($result['dates'][13]);
        $this->assertNull($result['dates'][14]);

        $settings = self::settings(['finishenabled' => 0, 'schedulefinish' => self::ts('2030-01-10 09:00')]);
        $result = schedule::compute($settings, [11, 12, 13, 14], self::allselected(), false, self::tz());
        $this->assertNotNull($result['windows'][1]);
        $this->assertSame(self::ts('2030-01-14 09:00'), $result['dates'][13]['timeopen']);
        $this->assertSame(self::ts('2030-01-14 09:00'), $result['dates'][14]['timeopen']);
    }

    /**
     * Adding days keeps the local wall-clock time across a DST change.
     */
    public function test_add_days_across_dst(): void {
        $tz = self::tz();
        $from = self::ts('2030-10-20 09:00');
        $to = schedule::add_days($from, 7, $tz);

        $this->assertSame('2030-10-27 09:00', (new \DateTimeImmutable('@' . $to))->setTimezone($tz)->format('Y-m-d H:i'));
        $this->assertSame(7 * 86400 + 3600, $to - $from);
    }

    /**
     * Adding days keeps the wall-clock time of the timezone given, not the server's.
     */
    public function test_add_days_user_timezone(): void {
        $perth = new \DateTimeZone('Australia/Perth');
        $from = self::ts('2030-10-20 09:00');
        $to = schedule::add_days($from, 7, $perth);

        $this->assertSame('2030-10-20 16:00', (new \DateTimeImmutable('@' . $from))->setTimezone($perth)->format('Y-m-d H:i'));
        $this->assertSame('2030-10-27 16:00', (new \DateTimeImmutable('@' . $to))->setTimezone($perth)->format('Y-m-d H:i'));
        $this->assertSame(7 * 86400, $to - $from);
    }

    /**
     * An unknown close or due mode is a coding error.
     */
    public function test_unknown_mode_throws(): void {
        $this->expectException(\coding_exception::class);
        schedule::compute(self::settings(['closemode' => 'weekly']), [11, 12, 13, 14], self::allselected(), false, self::tz());
    }
}
