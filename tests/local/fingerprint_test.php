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
 * Tests for the stale-preview settings fingerprint.
 *
 * @package    tool_activitydates
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_activitydates\local;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the fingerprint class.
 */
#[CoversClass(fingerprint::class)]
final class fingerprint_test extends \basic_testcase {
    /** @var int a minute-aligned start time (2030-01-14 09:00:00 UTC). */
    private const START = 1894611600;

    /**
     * A settings object in the tool_activitydates row shape.
     *
     * @param array $overrides field => value.
     * @return \stdClass
     */
    private static function settings(array $overrides = []): \stdClass {
        return (object) array_merge([
            'id' => 5,
            'courseid' => 2,
            'modtype' => 'quiz',
            'schedulestart' => self::START,
            'finishenabled' => 0,
            'schedulefinish' => 0,
            'sessionlength' => 7,
            'activitiespersession' => 1,
            'closemode' => schedule::MODE_SESSION,
            'closedays' => 0,
            'closedate' => 0,
            'duemode' => schedule::MODE_NONE,
            'duedays' => 0,
            'duedate' => 0,
            'hideunselected' => 0,
            'resetunselected' => 0,
        ], $overrides);
    }

    /**
     * The order, duplication and type of the selected cmids do not matter.
     */
    public function test_selection_order_irrelevant(): void {
        $s = self::settings();
        $base = fingerprint::dates($s, [3, 1, 2], true);
        $this->assertSame($base, fingerprint::dates($s, [1, 2, 3], true));
        $this->assertSame($base, fingerprint::dates($s, ['2', '3', '1', '3'], true));
        $this->assertSame(40, strlen($base));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $base);
    }

    /**
     * Seconds in any timestamp do not change the hash.
     */
    public function test_seconds_ignored(): void {
        $s = self::settings();
        $late = self::settings(['schedulestart' => self::START + 59]);
        $this->assertSame(fingerprint::dates($s, [1], false), fingerprint::dates($late, [1], false));

        $finish = self::settings(['finishenabled' => 1, 'schedulefinish' => self::START + 86400 * 30]);
        $finishlate = self::settings(['finishenabled' => 1, 'schedulefinish' => self::START + 86400 * 30 + 42]);
        $this->assertSame(fingerprint::dates($finish, [1], false), fingerprint::dates($finishlate, [1], false));

        $close = self::settings(['closemode' => schedule::MODE_DATE, 'closedate' => self::START + 3600]);
        $closelate = self::settings(['closemode' => schedule::MODE_DATE, 'closedate' => self::START + 3600 + 1]);
        $this->assertSame(fingerprint::dates($close, [1], false), fingerprint::dates($closelate, [1], false));

        $due = self::settings(['duemode' => schedule::MODE_DATE, 'duedate' => self::START + 1800]);
        $duelate = self::settings(['duemode' => schedule::MODE_DATE, 'duedate' => self::START + 1800 + 30]);
        $this->assertSame(fingerprint::dates($due, [1], true), fingerprint::dates($duelate, [1], true));

        $lock = self::settings(['lockmode' => schedule::MODE_DATE, 'lockdate' => self::START + 900]);
        $locklate = self::settings(['lockmode' => schedule::MODE_DATE, 'lockdate' => self::START + 900 + 59]);
        $this->assertSame(fingerprint::dates($lock, [1], false, true), fingerprint::dates($locklate, [1], false, true));

        // A whole minute is a change.
        $nextminute = self::settings(['schedulestart' => self::START + 60]);
        $this->assertNotSame(fingerprint::dates($s, [1], false), fingerprint::dates($nextminute, [1], false));
    }

    /**
     * Every included input changes the hash.
     */
    public function test_each_included_input_changes_hash(): void {
        $base = self::settings(['duemode' => schedule::MODE_SESSION]);
        $basehash = fingerprint::dates($base, [1, 2], true);
        $changes = [
            'modtype' => 'assign',
            'schedulestart' => self::START + 86400,
            'sessionlength' => 14,
            'activitiespersession' => 2,
            'closemode' => schedule::MODE_NONE,
            'duemode' => schedule::MODE_NONE,
        ];
        foreach ($changes as $field => $value) {
            $changed = clone $base;
            $changed->$field = $value;
            $this->assertNotSame($basehash, fingerprint::dates($changed, [1, 2], true), $field);
        }
        $this->assertNotSame($basehash, fingerprint::dates($base, [1, 3], true), 'selection');
        $this->assertNotSame($basehash, fingerprint::dates($base, [1], true), 'selection size');

        // The enabled finish date counts.
        $finish = self::settings(['finishenabled' => 1, 'schedulefinish' => self::START + 86400 * 30]);
        $finish2 = self::settings(['finishenabled' => 1, 'schedulefinish' => self::START + 86400 * 31]);
        $this->assertNotSame(fingerprint::dates($finish, [1], false), fingerprint::dates($finish2, [1], false));
        $this->assertNotSame(fingerprint::dates(self::settings(), [1], false), fingerprint::dates($finish, [1], false));
    }

    /**
     * A disabled finish date is ignored, whatever it holds.
     */
    public function test_disabled_finish_ignored(): void {
        $off = self::settings(['finishenabled' => 0, 'schedulefinish' => 0]);
        $offwithdate = self::settings(['finishenabled' => 0, 'schedulefinish' => self::START + 86400 * 30]);
        $this->assertSame(fingerprint::dates($off, [1], false), fingerprint::dates($offwithdate, [1], false));
    }

    /**
     * The day and date fields count only in their own mode.
     */
    public function test_mode_specific_fields(): void {
        foreach (['close', 'due'] as $prefix) {
            $mode = $prefix . 'mode';
            $days = $prefix . 'days';
            $date = $prefix . 'date';

            $session = self::settings([$mode => schedule::MODE_SESSION, $days => 3]);
            $session2 = self::settings([$mode => schedule::MODE_SESSION, $days => 5, $date => self::START]);
            $this->assertSame(fingerprint::dates($session, [1], true), fingerprint::dates($session2, [1], true), $prefix);

            $d3 = self::settings([$mode => schedule::MODE_DAYS, $days => 3]);
            $d5 = self::settings([$mode => schedule::MODE_DAYS, $days => 5]);
            $this->assertNotSame(fingerprint::dates($d3, [1], true), fingerprint::dates($d5, [1], true), $prefix);
            $d5withdate = self::settings([$mode => schedule::MODE_DAYS, $days => 5, $date => self::START]);
            $this->assertSame(fingerprint::dates($d5, [1], true), fingerprint::dates($d5withdate, [1], true), $prefix);

            $dt1 = self::settings([$mode => schedule::MODE_DATE, $date => self::START + 86400]);
            $dt2 = self::settings([$mode => schedule::MODE_DATE, $date => self::START + 2 * 86400]);
            $this->assertNotSame(fingerprint::dates($dt1, [1], true), fingerprint::dates($dt2, [1], true), $prefix);
            $dt2withdays = self::settings([$mode => schedule::MODE_DATE, $date => self::START + 2 * 86400, $days => 9]);
            $this->assertSame(fingerprint::dates($dt2, [1], true), fingerprint::dates($dt2withdays, [1], true), $prefix);
        }
    }

    /**
     * Without a duedate column the due fields are ignored.
     */
    public function test_due_ignored_without_column(): void {
        $none = self::settings(['duemode' => schedule::MODE_NONE]);
        $days = self::settings(['duemode' => schedule::MODE_DAYS, 'duedays' => 4]);
        $this->assertSame(fingerprint::dates($none, [1], false), fingerprint::dates($days, [1], false));
        $this->assertNotSame(fingerprint::dates($none, [1], true), fingerprint::dates($days, [1], true));
        $this->assertNotSame(fingerprint::dates($none, [1], false), fingerprint::dates($none, [1], true));
    }

    /**
     * Inputs that change no proposed date are excluded.
     */
    public function test_excluded_inputs(): void {
        $base = fingerprint::dates(self::settings(), [1], true);
        $this->assertSame($base, fingerprint::dates(self::settings(['hideunselected' => 1]), [1], true));
        $this->assertSame($base, fingerprint::dates(self::settings(['resetunselected' => 1]), [1], true));
        $this->assertSame($base, fingerprint::dates(self::settings(['id' => 99, 'courseid' => 77]), [1], true));
    }

    /**
     * The lock mode and its days or date change the hash only when the table edits lock dates.
     */
    public function test_lock_fields_only_with_locks(): void {
        $base = self::settings(['lockmode' => schedule::MODE_NONE, 'lockdays' => 7, 'lockdate' => 0]);
        $changes = [
            'lockmode' => ['lockmode' => schedule::MODE_SESSION],
            'lockdays' => ['lockmode' => schedule::MODE_DAYS, 'lockdays' => 3],
            'lockdate' => ['lockmode' => schedule::MODE_DATE, 'lockdate' => self::START + 3600],
        ];
        foreach ($changes as $label => $overrides) {
            $changed = self::settings(array_merge((array) $base, $overrides));
            $this->assertSame(fingerprint::dates($base, [1], true), fingerprint::dates($changed, [1], true), $label);
            $this->assertNotSame(
                fingerprint::dates($base, [1], true, true),
                fingerprint::dates($changed, [1], true, true),
                $label
            );
        }

        // As for close and due, the days count only in days mode and the date only in date mode.
        $days = self::settings(['lockmode' => schedule::MODE_DAYS, 'lockdays' => 3, 'lockdate' => 0]);
        $dayswithdate = self::settings(['lockmode' => schedule::MODE_DAYS, 'lockdays' => 3, 'lockdate' => self::START]);
        $this->assertSame(fingerprint::dates($days, [1], false, true), fingerprint::dates($dayswithdate, [1], false, true));
        $days5 = self::settings(['lockmode' => schedule::MODE_DAYS, 'lockdays' => 5]);
        $this->assertNotSame(fingerprint::dates($days, [1], false, true), fingerprint::dates($days5, [1], false, true));

        // Settings without lock fields hash like lock mode none.
        $this->assertSame(fingerprint::dates(self::settings(), [1], false), fingerprint::dates($base, [1], false));
    }
}
