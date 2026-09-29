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
     * A session starting exactly at the finish date is still scheduled; one a minute later is not.
     */
    public function test_finish_cap_boundary(): void {
        $settings = self::settings(['finishenabled' => 1, 'schedulefinish' => self::ts('2030-01-14 09:00')]);
        $result = schedule::compute($settings, [11, 12, 13, 14], self::allselected(), false, self::tz());
        $this->assertNotNull($result['windows'][1]);
        $this->assertSame(self::ts('2030-01-14 09:00'), $result['dates'][13]['timeopen']);

        $settings = self::settings(['finishenabled' => 1, 'schedulefinish' => self::ts('2030-01-14 08:59')]);
        $result = schedule::compute($settings, [11, 12, 13, 14], self::allselected(), false, self::tz());
        $this->assertNull($result['windows'][1]);
        $this->assertNull($result['dates'][13]);
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

        // New York leaves DST on 3 November 2030; London (the tests' zone), Perth (PHPUnit's
        // default) and UTC do not change between these dates, so only New York's rules give 25 hours.
        $newyork = new \DateTimeZone('America/New_York');
        $from = (new \DateTimeImmutable('2030-10-30 09:00', $newyork))->getTimestamp();
        $to = schedule::add_days($from, 7, $newyork);
        $this->assertSame('2030-11-06 09:00', (new \DateTimeImmutable('@' . $to))->setTimezone($newyork)->format('Y-m-d H:i'));
        $this->assertSame(7 * 86400 + 3600, $to - $from);
    }

    /**
     * The lock date follows the lock mode, with days counted from the open date.
     */
    public function test_lock_modes(): void {
        $settings = self::settings(['lockmode' => schedule::MODE_DAYS, 'lockdays' => 10]);
        $result = schedule::compute($settings, [11, 12, 13, 14], self::allselected(), false, self::tz());
        $this->assertSame(self::ts('2030-01-17 09:00'), $result['dates'][11]['timelock']);
        $this->assertSame(self::ts('2030-01-24 09:00'), $result['dates'][13]['timelock']);

        $settings = self::settings(['lockmode' => schedule::MODE_SESSION]);
        $result = schedule::compute($settings, [11, 12, 13, 14], self::allselected(), false, self::tz());
        $this->assertSame(self::ts('2030-01-14 09:00'), $result['dates'][12]['timelock']);
        $this->assertSame(self::ts('2030-01-21 09:00'), $result['dates'][14]['timelock']);

        $lockdate = self::ts('2030-04-01 23:59');
        $settings = self::settings(['lockmode' => schedule::MODE_DATE, 'lockdate' => $lockdate]);
        $result = schedule::compute($settings, [11, 12, 13, 14], self::allselected(), false, self::tz());
        foreach ([11, 12, 13, 14] as $cmid) {
            $this->assertSame($lockdate, $result['dates'][$cmid]['timelock']);
        }

        $settings = self::settings(['lockmode' => schedule::MODE_NONE, 'lockdays' => 10, 'lockdate' => $lockdate]);
        $result = schedule::compute($settings, [11, 12, 13, 14], self::allselected(), false, self::tz());
        foreach ([11, 12, 13, 14] as $cmid) {
            $this->assertNull($result['dates'][$cmid]['timelock']);
            $this->assertNotNull($result['dates'][$cmid]['timeclose']);
        }

        // Unselected and unscheduled cms have no dates at all, lock included.
        $settings = self::settings(['lockmode' => schedule::MODE_SESSION]);
        $result = schedule::compute($settings, [11, 12, 13, 14], [13 => true], false, self::tz());
        $this->assertNull($result['dates'][11]);
        $this->assertSame(self::ts('2030-01-14 09:00'), $result['dates'][13]['timelock']);
    }

    /**
     * Without lock fields, or with lock mode none, there is no lock date.
     */
    public function test_lock_none_is_null(): void {
        $result = schedule::compute(self::settings(), [11, 12, 13, 14], self::allselected(), true, self::tz());
        foreach ([11, 12, 13, 14] as $cmid) {
            $this->assertArrayHasKey('timelock', $result['dates'][$cmid]);
            $this->assertNull($result['dates'][$cmid]['timelock']);
        }

        $settings = self::settings([
            'lockmode' => schedule::MODE_NONE,
            'lockdays' => 3,
            'lockdate' => self::ts('2030-02-01 09:00'),
        ]);
        $result = schedule::compute($settings, [11, 12, 13, 14], self::allselected(), true, self::tz());
        foreach ([11, 12, 13, 14] as $cmid) {
            $this->assertNull($result['dates'][$cmid]['timelock']);
        }
    }

    /**
     * A lock-only type gets only a lock date, and its close and due modes are not checked.
     */
    public function test_lock_only_type(): void {
        $settings = self::settings([
            'closemode' => 'weekly',
            'duemode' => 'weekly',
            'lockmode' => schedule::MODE_DAYS,
            'lockdays' => 3,
        ]);
        $result = schedule::compute($settings, [11, 12, 13, 14], [11 => true, 13 => true], true, self::tz(), false);

        $this->assertSame(self::ts('2030-01-07 09:00'), $result['windows'][0]['start']);
        foreach ([11 => '2030-01-10 09:00', 13 => '2030-01-17 09:00'] as $cmid => $lock) {
            $this->assertSame(
                ['timeopen' => null, 'duedate' => null, 'timeclose' => null, 'timelock' => self::ts($lock)],
                $result['dates'][$cmid]
            );
        }
        $this->assertNull($result['dates'][12]);
        $this->assertNull($result['dates'][14]);

        $settings = self::settings(['lockmode' => schedule::MODE_NONE]);
        $result = schedule::compute($settings, [11, 12, 13, 14], [11 => true], false, self::tz(), false);
        $this->assertSame(
            ['timeopen' => null, 'duedate' => null, 'timeclose' => null, 'timelock' => null],
            $result['dates'][11]
        );
    }

    /**
     * An unknown lock mode is a coding error, with or without open and close dates.
     */
    public function test_unknown_lock_mode_throws(): void {
        foreach ([true, false] as $hasdates) {
            try {
                schedule::compute(
                    self::settings(['lockmode' => 'weekly']),
                    [11, 12, 13, 14],
                    self::allselected(),
                    false,
                    self::tz(),
                    $hasdates
                );
                $this->fail('No exception for an unknown lock mode, hasdates ' . (int) $hasdates);
            } catch (\coding_exception $e) {
                $this->assertStringContainsString('lockmode', $e->getMessage());
            }
        }
    }

    /**
     * A fixed field keeps its given value, and "after X days" counts from a fixed,
     * set open date; the settings still move every unfixed date.
     */
    public function test_fixed_fields_kept(): void {
        $settings = self::settings([
            'closemode' => schedule::MODE_DAYS,
            'closedays' => 3,
            'duemode' => schedule::MODE_DAYS,
            'duedays' => 2,
            'lockmode' => schedule::MODE_DAYS,
            'lockdays' => 10,
        ]);
        $fixedopen = self::ts('2030-01-08 12:00');
        $fixedclose = self::ts('2030-03-01 10:00');
        $fixed = [
            11 => ['timeopen' => $fixedopen],
            13 => ['timeclose' => $fixedclose],
            // A fixed, cleared open counts from the session start.
            14 => ['timeopen' => 0],
        ];
        $result = schedule::compute($settings, [11, 12, 13, 14], self::allselected(), true, self::tz(), true, $fixed);

        $this->assertSame([
            'timeopen' => $fixedopen,
            'duedate' => self::ts('2030-01-10 12:00'),
            'timeclose' => self::ts('2030-01-11 12:00'),
            'timelock' => self::ts('2030-01-18 12:00'),
        ], $result['dates'][11]);
        // Unfixed: counted from the session start.
        $this->assertSame(self::ts('2030-01-07 09:00'), $result['dates'][12]['timeopen']);
        $this->assertSame(self::ts('2030-01-10 09:00'), $result['dates'][12]['timeclose']);
        $this->assertSame(self::ts('2030-01-14 09:00'), $result['dates'][13]['timeopen']);
        $this->assertSame($fixedclose, $result['dates'][13]['timeclose']);
        $this->assertSame(self::ts('2030-01-16 09:00'), $result['dates'][13]['duedate']);
        $this->assertSame(0, $result['dates'][14]['timeopen']);
        $this->assertSame(self::ts('2030-01-17 09:00'), $result['dates'][14]['timeclose']);

        // The settings change: the unfixed dates move, the fixed ones stay. Every
        // activity keeps its session slot.
        $settings->sessionlength = 14;
        $result = schedule::compute($settings, [11, 12, 13, 14], self::allselected(), true, self::tz(), true, $fixed);
        $this->assertSame(self::ts('2030-01-21 09:00'), $result['windows'][1]['start']);
        $this->assertSame($fixedopen, $result['dates'][11]['timeopen']);
        $this->assertSame(self::ts('2030-01-21 09:00'), $result['dates'][13]['timeopen']);
        $this->assertSame($fixedclose, $result['dates'][13]['timeclose']);

        // Session mode still closes at the session end, even after a fixed open.
        $settings = self::settings(['lockmode' => schedule::MODE_SESSION]);
        $result = schedule::compute($settings, [11, 12, 13, 14], self::allselected(), false, self::tz(), true, $fixed);
        $this->assertSame($fixedopen, $result['dates'][11]['timeopen']);
        $this->assertSame(self::ts('2030-01-14 09:00'), $result['dates'][11]['timeclose']);
        $this->assertSame(self::ts('2030-01-14 09:00'), $result['dates'][11]['timelock']);

        // A fixed field that does not apply stays null, and an unscheduled cm stays unscheduled.
        $fixed = [11 => ['duedate' => $fixedclose, 'timelock' => $fixedclose], 12 => ['timeopen' => $fixedopen]];
        $result = schedule::compute(self::settings(), [11, 12, 13, 14], [11 => true], false, self::tz(), true, $fixed);
        $this->assertNull($result['dates'][11]['duedate']);
        $this->assertNull($result['dates'][11]['timelock']);
        $this->assertNull($result['dates'][12]);

        // A lock-only type keeps a fixed lock date.
        $settings = self::settings(['lockmode' => schedule::MODE_DAYS, 'lockdays' => 3]);
        $fixed = [11 => ['timelock' => $fixedclose, 'timeopen' => $fixedopen]];
        $result = schedule::compute($settings, [11, 12, 13, 14], [11 => true], false, self::tz(), false, $fixed);
        $this->assertSame(
            ['timeopen' => null, 'duedate' => null, 'timeclose' => null, 'timelock' => $fixedclose],
            $result['dates'][11]
        );
    }

    /**
     * An unknown close or due mode is a coding error.
     */
    public function test_unknown_mode_throws(): void {
        $this->expectException(\coding_exception::class);
        schedule::compute(self::settings(['closemode' => 'weekly']), [11, 12, 13, 14], self::allselected(), false, self::tz());
    }
}
