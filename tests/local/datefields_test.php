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
 * Tests for date-field parsing and row validation.
 *
 * @package    tool_activitydates
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_activitydates\local;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the datefields class.
 */
#[CoversClass(datefields::class)]
final class datefields_test extends \basic_testcase {
    /**
     * The timezone the tests work in.
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
     * Dates-tab inputs for one cm.
     *
     * @param int $cmid the cm id.
     * @param string $open the timeopen input.
     * @param string $due the duedate input.
     * @param string $close the timeclose input.
     * @return array
     */
    private static function row(int $cmid, string $open, string $due, string $close): array {
        return [
            'timeopen' => [$cmid => $open],
            'duedate' => [$cmid => $due],
            'timeclose' => [$cmid => $close],
        ];
    }

    /**
     * A timestamp converts to an input value and back; 0 is the empty string.
     */
    public function test_round_trip(): void {
        $ts = self::ts('2030-01-14 09:30');
        $this->assertSame('2030-01-14T09:30', datefields::to_input($ts, self::tz()));
        $this->assertSame($ts, datefields::from_input('2030-01-14T09:30', self::tz()));
        $this->assertSame('', datefields::to_input(0, self::tz()));
        $this->assertSame(0, datefields::from_input('', self::tz()));
    }

    /**
     * Malformed or impossible values are rejected.
     */
    public function test_from_input_rejects(): void {
        foreach (['2030-02-30T10:00', '2030-01-01 10:00', 'x', '2030-01-01T10:00:00'] as $value) {
            $this->assertNull(datefields::from_input($value, self::tz()), $value);
        }
    }

    /**
     * Dates at or before the Unix epoch are rejected, so they can neither pass as
     * "no date" nor skip the ordering rules.
     */
    public function test_pre_epoch_rejected(): void {
        $utc = new \DateTimeZone('UTC');
        $this->assertNull(datefields::from_input('1969-12-31T23:00', $utc));
        $this->assertNull(datefields::from_input('1970-01-01T00:00', $utc));
        $this->assertSame(60, datefields::from_input('1970-01-01T00:01', $utc));

        [$values, $errors] = datefields::validate_dates(
            self::row(11, '1969-12-31T23:00', '1969-12-31T22:30', '1969-12-31T22:00'),
            [11 => true],
            true,
            false,
            $utc
        );
        $this->assertSame([], $values);
        $this->assertSame([11 => [
            'timeopen' => 'errorinvaliddate',
            'timeclose' => 'errorinvaliddate',
            'duedate' => 'errorinvaliddate',
        ]], $errors);

        [$values, $errors] = datefields::validate_locks(['locktime' => [11 => '1970-01-01T00:00']], [11 => true], $utc);
        $this->assertSame([], $values);
        $this->assertSame([11 => ['locktime' => 'errorinvaliddate']], $errors);
    }

    /**
     * A cleared open date is 0 (not set), with no error, and the close date is kept
     * without an ordering check.
     */
    public function test_cleared_open_skips_ordering(): void {
        [$values, $errors] = datefields::validate_dates(
            self::row(11, '', '', '2030-01-10T09:00'),
            [11 => true],
            true,
            false,
            self::tz()
        );
        $this->assertSame([], $errors);
        $this->assertSame(
            [11 => ['timeopen' => 0, 'duedate' => 0, 'timeclose' => self::ts('2030-01-10 09:00'), 'timelock' => null]],
            $values
        );

        // Every date empty, or every input missing, is a row with no dates set.
        foreach ([self::row(11, '', '', ''), []] as $inputs) {
            [$values, $errors] = datefields::validate_dates($inputs, [11 => true], true, false, self::tz());
            $this->assertSame([], $errors);
            $this->assertSame([11 => ['timeopen' => 0, 'duedate' => 0, 'timeclose' => 0, 'timelock' => null]], $values);
        }

        // An unparseable open date is still an error.
        [$values, $errors] = datefields::validate_dates(self::row(11, 'x', '', ''), [11 => true], true, false, self::tz());
        $this->assertSame([], $values);
        $this->assertSame([11 => ['timeopen' => 'errorinvaliddate']], $errors);

        // A valid open date alone is enough.
        [$values, $errors] = datefields::validate_dates(
            self::row(11, '2030-01-07T09:00', '', ''),
            [11 => true],
            true,
            false,
            self::tz()
        );
        $this->assertSame([], $errors);
        $this->assertSame(
            [11 => ['timeopen' => self::ts('2030-01-07 09:00'), 'duedate' => 0, 'timeclose' => 0, 'timelock' => null]],
            $values
        );
    }

    /**
     * With the open date cleared, a due date is checked only against the close date.
     */
    public function test_cleared_open_due_rules(): void {
        $allowed = [11 => true];
        $close = '2030-01-10T09:00';

        // Any due date up to close is valid, however early.
        foreach (['2001-01-01T00:00', '2030-01-10T09:00'] as $due) {
            [$values, $errors] = datefields::validate_dates(self::row(11, '', $due, $close), $allowed, true, false, self::tz());
            $this->assertSame([], $errors, $due);
            $this->assertSame([11 => [
                'timeopen' => 0,
                'duedate' => datefields::from_input($due, self::tz()),
                'timeclose' => self::ts('2030-01-10 09:00'),
                'timelock' => null,
            ]], $values, $due);
        }

        // Due after close is not.
        [$values, $errors] = datefields::validate_dates(
            self::row(11, '', '2030-01-10T09:01', $close),
            $allowed,
            true,
            false,
            self::tz()
        );
        $this->assertSame([], $values);
        $this->assertSame([11 => ['duedate' => 'errordueafterclose']], $errors);

        // With close cleared too, a due date alone is valid.
        [$values, $errors] = datefields::validate_dates(
            self::row(11, '', '2030-01-10T09:01', ''),
            $allowed,
            true,
            false,
            self::tz()
        );
        $this->assertSame([], $errors);
        $this->assertSame(self::ts('2030-01-10 09:01'), $values[11]['duedate']);
    }

    /**
     * The close date must be strictly after the open date.
     */
    public function test_close_before_open(): void {
        $inputs = [
            'timeopen' => [11 => '2030-01-07T09:00', 12 => '2030-01-07T09:00', 13 => '2030-01-07T09:00'],
            'timeclose' => [11 => '2030-01-07T09:00', 12 => '2030-01-06T09:00', 13 => '2030-01-07T09:01'],
        ];
        [$values, $errors] = datefields::validate_dates($inputs, [11 => true, 12 => true, 13 => true], false, false, self::tz());

        $this->assertSame([
            11 => ['timeclose' => 'errorclosebeforeopen'],
            12 => ['timeclose' => 'errorclosebeforeopen'],
        ], $errors);
        $this->assertSame([13], array_keys($values));
        $this->assertSame(self::ts('2030-01-07 09:01'), $values[13]['timeclose']);
        $this->assertNull($values[13]['duedate']);

        [, $errors] = datefields::validate_dates(
            self::row(11, '2030-01-07T09:00', '', 'nonsense'),
            [11 => true],
            false,
            false,
            self::tz()
        );
        $this->assertSame([11 => ['timeclose' => 'errorinvaliddate']], $errors);
    }

    /**
     * The due date follows core quiz's rules: after open, not after close.
     */
    public function test_due_boundaries(): void {
        $allowed = [11 => true];
        $open = '2030-01-07T09:00';

        // Due == close is valid.
        [$values, $errors] = datefields::validate_dates(
            self::row(11, $open, '2030-01-10T09:00', '2030-01-10T09:00'),
            $allowed,
            true,
            false,
            self::tz()
        );
        $this->assertSame([], $errors);
        $this->assertSame(self::ts('2030-01-10 09:00'), $values[11]['duedate']);

        // Due == open is not.
        [$values, $errors] = datefields::validate_dates(
            self::row(11, $open, $open, '2030-01-10T09:00'),
            $allowed,
            true,
            false,
            self::tz()
        );
        $this->assertSame([11 => ['duedate' => 'errorduebeforeopen']], $errors);
        $this->assertSame([], $values);

        // Due after close is not.
        [$values, $errors] = datefields::validate_dates(
            self::row(11, $open, '2030-01-10T09:01', '2030-01-10T09:00'),
            $allowed,
            true,
            false,
            self::tz()
        );
        $this->assertSame([11 => ['duedate' => 'errordueafterclose']], $errors);
        $this->assertSame([], $values);

        // With no close date only the open rule applies.
        [$values, $errors] = datefields::validate_dates(
            self::row(11, $open, '2031-06-01T09:00', ''),
            $allowed,
            true,
            false,
            self::tz()
        );
        $this->assertSame([], $errors);
        $this->assertSame(['timeopen' => self::ts('2030-01-07 09:00'), 'duedate' => self::ts('2031-06-01 09:00'),
            'timeclose' => 0, 'timelock' => null], $values[11]);
        [, $errors] = datefields::validate_dates(self::row(11, $open, '2030-01-06T09:00', ''), $allowed, true, false, self::tz());
        $this->assertSame([11 => ['duedate' => 'errorduebeforeopen']], $errors);

        // An unparseable due date.
        [, $errors] = datefields::validate_dates(self::row(11, $open, 'x', ''), $allowed, true, false, self::tz());
        $this->assertSame([11 => ['duedate' => 'errorinvaliddate']], $errors);
    }

    /**
     * Without a duedate column the due input is ignored and the value is null.
     */
    public function test_due_ignored_without_column(): void {
        [$values, $errors] = datefields::validate_dates(
            self::row(11, '2030-01-07T09:00', 'garbage', '2030-01-10T09:00'),
            [11 => true],
            false,
            false,
            self::tz()
        );
        $this->assertSame([], $errors);
        $this->assertSame([11 => [
            'timeopen' => self::ts('2030-01-07 09:00'),
            'duedate' => null,
            'timeclose' => self::ts('2030-01-10 09:00'),
            'timelock' => null,
        ]], $values);
    }

    /**
     * Inputs for cms that are not allowed are ignored, valid or not.
     */
    public function test_unallowed_cmids_ignored(): void {
        $inputs = [
            'timeopen' => [11 => '2030-01-07T09:00', 999 => '2030-01-07T09:00', 998 => ''],
            'duedate' => [999 => ''],
            'timeclose' => [11 => '', 999 => '', 998 => 'x'],
        ];
        [$values, $errors] = datefields::validate_dates($inputs, [11 => true], true, false, self::tz());
        $this->assertSame([11], array_keys($values));
        $this->assertSame([], $errors);

        [$values, $errors] = datefields::validate_locks(
            ['locktime' => [11 => '2030-01-07T09:00', 999 => '2030-01-07T09:00', 998 => '']],
            [11 => true],
            self::tz()
        );
        $this->assertSame([11], array_keys($values));
        $this->assertSame([], $errors);
    }

    /**
     * A cleared or missing lock date is 0, which clears the lock; a date in the past is allowed.
     */
    public function test_cleared_lock_is_zero(): void {
        $inputs = ['locktime' => [11 => '', 12 => 'x', 13 => '2001-01-01T00:00', 14 => '2030-01-07T09:00']];
        [$values, $errors] = datefields::validate_locks(
            $inputs,
            [11 => true, 12 => true, 13 => true, 14 => true, 15 => true],
            self::tz()
        );

        $this->assertSame([12 => ['locktime' => 'errorinvaliddate']], $errors);
        $this->assertSame([
            11 => ['locktime' => 0],
            13 => ['locktime' => self::ts('2001-01-01 00:00')],
            14 => ['locktime' => self::ts('2030-01-07 09:00')],
            15 => ['locktime' => 0],
        ], $values);
    }

    /**
     * The table's lock date is parsed only with $haslocks: empty is 0 (clear), a past date is allowed,
     * garbage is an error, and it has no ordering rule against the other dates.
     */
    public function test_timelock_parsed_only_with_locks(): void {
        $inputs = self::row(11, '2030-01-07T09:00', '', '2030-01-10T09:00');
        $inputs['timelock'] = [11 => '2001-01-01T00:00'];

        [$values, $errors] = datefields::validate_dates($inputs, [11 => true], false, false, self::tz());
        $this->assertSame([], $errors);
        $this->assertNull($values[11]['timelock']);

        [$values, $errors] = datefields::validate_dates($inputs, [11 => true], false, true, self::tz());
        $this->assertSame([], $errors);
        $this->assertSame(self::ts('2001-01-01 00:00'), $values[11]['timelock']);

        $inputs['timelock'] = [11 => ''];
        [$values] = datefields::validate_dates($inputs, [11 => true, 12 => true], false, true, self::tz());
        $this->assertSame(0, $values[11]['timelock']);
        $this->assertSame(0, $values[12]['timelock']);

        $inputs['timelock'] = [11 => 'x'];
        [$values, $errors] = datefields::validate_dates($inputs, [11 => true], false, true, self::tz());
        $this->assertSame([], $values);
        $this->assertSame([11 => ['timelock' => 'errorinvaliddate']], $errors);
    }

    /**
     * A wall-clock time in the repeated hour of a DST fall-back is ambiguous: it parses
     * to a known value (the row's current or proposed date) that shows as it, so a
     * value shown in the first occurrence is saved unchanged.
     */
    public function test_repeated_hour_keeps_known_value(): void {
        // 01:30 BST and 01:30 GMT on the Europe/London fall-back night.
        $bst = self::ts('2026-10-25 00:30 UTC');
        $gmt = $bst + HOURSECS;
        $this->assertSame('2026-10-25T01:30', datefields::to_input($bst, self::tz()));
        $this->assertSame('2026-10-25T01:30', datefields::to_input($gmt, self::tz()));

        $this->assertSame($bst, datefields::from_input('2026-10-25T01:30', self::tz(), [$bst]));
        $this->assertSame($gmt, datefields::from_input('2026-10-25T01:30', self::tz(), [$gmt]));
        // A known value that does not show as the input is not used.
        $this->assertSame($gmt, datefields::from_input('2026-10-25T01:30', self::tz(), [$bst - MINSECS]));
        // A known value keeps its seconds.
        $this->assertSame($bst + 30, datefields::from_input('2026-10-25T01:30', self::tz(), [$bst + 30]));

        $inputs = self::row(11, '2026-10-25T01:30', '', '2026-10-26T09:00');
        $inputs['timelock'] = [11 => '2026-10-25T01:30'];
        [$values, $errors] = datefields::validate_dates(
            $inputs,
            [11 => true],
            false,
            true,
            self::tz(),
            [11 => ['timeopen' => [$bst], 'timelock' => [0, $bst]]]
        );
        $this->assertSame([], $errors);
        $this->assertSame($bst, $values[11]['timeopen']);
        $this->assertSame($bst, $values[11]['timelock']);
    }

    /**
     * Values are read in the given (user) timezone.
     */
    public function test_user_timezone(): void {
        $perth = new \DateTimeZone('Australia/Perth');
        $london = datefields::from_input('2030-01-01T09:00', self::tz());
        $inperth = datefields::from_input('2030-01-01T09:00', $perth);

        $this->assertNotSame($london, $inperth);
        $this->assertSame(8 * HOURSECS, $london - $inperth);
        $this->assertSame('2030-01-01T01:00', datefields::to_input($inperth, self::tz()));
        $this->assertSame('2030-01-01T09:00', datefields::to_input($inperth, $perth));

        [$values] = datefields::validate_dates(self::row(11, '2030-01-01T09:00', '', ''), [11 => true], false, false, $perth);
        $this->assertSame($inperth, $values[11]['timeopen']);
    }
}
