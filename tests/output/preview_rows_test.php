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
 * Tests for the modtable row decorator.
 *
 * @package    tool_activitydates
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_activitydates\output;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for preview_rows.
 */
#[CoversClass(preview_rows::class)]
final class preview_rows_test extends \advanced_testcase {
    /** @var string[] every field. */
    private const ALL = ['timeopen', 'duedate', 'timeclose', 'timelock'];

    /**
     * A timestamp in Europe/London.
     *
     * @param string $date the wall-clock date and time.
     * @return int
     */
    private function ts(string $date): int {
        return (new \DateTimeImmutable($date, new \DateTimeZone('Europe/London')))->getTimestamp();
    }

    /**
     * A get_table_data()-shaped table: a header, then
     * 11 selected and scheduled, 12 unselected, 13 selected but not scheduled.
     *
     * @return array
     */
    private function table(): array {
        $row = [
            'isheader' => false,
            'cm' => new \stdClass(),
            'intro' => '',
            'questioncount' => 1,
            'hasgradeitem' => true,
            'shownote' => false,
            'shownotecoursepage' => false,
            'fixed' => array_fill_keys(self::ALL, false),
        ];
        $current = [
            'timeopen' => $this->ts('2029-12-01 09:00'),
            'duedate' => 0,
            'timeclose' => 0,
            'timelock' => $this->ts('2029-12-20 09:00'),
        ];
        return [
            ['isheader' => true, 'dates' => null],
            ['id' => 11, 'name' => 'Quiz A', 'selected' => 'checked', 'scheduled' => true, 'status' => '',
                'current' => $current,
                'proposed' => [
                    'timeopen' => $this->ts('2030-01-07 09:00'),
                    'duedate' => 0,
                    'timeclose' => $this->ts('2030-01-08 09:00'),
                    'timelock' => $this->ts('2030-01-09 09:00'),
                ]] + $row,
            ['id' => 12, 'name' => 'Quiz B', 'selected' => '', 'scheduled' => false, 'status' => 'rowstatus_notscheduled',
                'current' => $current, 'proposed' => null] + $row,
            ['id' => 13, 'name' => 'Quiz C', 'selected' => 'checked', 'scheduled' => false,
                'status' => 'rowstatus_notscheduled', 'current' => $current, 'proposed' => null] + $row,
        ];
    }

    /**
     * The options of a user with both capabilities, lock mode in use.
     *
     * @param array $options options to override.
     * @return array
     */
    private function options(array $options = []): array {
        return $options + [
            'fields' => ['timeopen', 'timeclose', 'timelock'],
            'editable' => array_fill_keys(self::ALL, true),
            'fixable' => array_fill_keys(self::ALL, true),
        ];
    }

    /**
     * One row's field entries, keyed by field.
     *
     * @param array $row a template row.
     * @return array
     */
    private function fields(array $row): array {
        return array_column($row['fields'], null, 'field');
    }

    public function test_current_values_on_load(): void {
        $tz = new \DateTimeZone('Europe/London');
        $rows = preview_rows::dates($this->table(), $tz, $this->options());

        $this->assertCount(4, $rows);
        $this->assertArrayNotHasKey('cm', $rows[1]);
        $this->assertArrayNotHasKey('proposed', $rows[1]);
        $this->assertTrue($rows[1]['editable']);
        $fields = $this->fields($rows[1]);
        $this->assertSame(['timeopen', 'timeclose', 'timelock'], array_keys($fields));
        // The current values, not the proposals; an unset date is empty.
        $this->assertSame('2029-12-01T09:00', $fields['timeopen']['value']);
        $this->assertSame('', $fields['timeclose']['value']);
        $this->assertSame('2029-12-20T09:00', $fields['timelock']['value']);
        $this->assertFalse($fields['timeopen']['disabled']);
        $this->assertFalse($fields['timeopen']['fixdisabled']);
        $this->assertSame(get_string('open', 'tool_activitydates') . ': Quiz A', $fields['timeopen']['inputlabel']);
        $this->assertSame(
            get_string('fixfield', 'tool_activitydates', (object) ['field' => get_string('open', 'tool_activitydates'),
                'name' => 'Quiz A']),
            $fields['timeopen']['fixlabel']
        );

        // Unselected, and selected but not scheduled: disabled, showing the current value.
        foreach ([2, 3] as $index) {
            $fields = $this->fields($rows[$index]);
            $this->assertFalse($rows[$index]['editable']);
            $this->assertTrue($fields['timeopen']['disabled']);
            $this->assertSame('2029-12-01T09:00', $fields['timeopen']['value']);
            $this->assertSame(get_string('rowstatus_notscheduled', 'tool_activitydates'), $rows[$index]['statustext']);
        }
        // Fix follows the selection, not the schedule.
        $this->assertTrue($this->fields($rows[2])['timeopen']['fixdisabled']);
        $this->assertFalse($this->fields($rows[3])['timeopen']['fixdisabled']);
    }

    public function test_preview_keeps_fixed_fields(): void {
        $tz = new \DateTimeZone('Europe/London');
        $table = $this->table();
        // A saved flag on the unselected row.
        $table[2]['fixed']['timeclose'] = true;
        $rows = preview_rows::dates($table, $tz, $this->options([
            'source' => preview_rows::SOURCE_PREVIEW,
            'rowinputs' => [
                'timeopen' => [11 => '2029-06-01T10:00', 12 => '2029-06-01T10:00'],
                'timeclose' => [11 => '2030-02-01T10:00'],
            ],
            'fixposted' => ['timeclose' => [11 => 1, 12 => 1], 'timelock' => [11 => 1]],
        ]));

        $fields = $this->fields($rows[1]);
        // Not fixed: the proposal, whatever was posted.
        $this->assertSame('2030-01-07T09:00', $fields['timeopen']['value']);
        $this->assertFalse($fields['timeopen']['fixed']);
        // Fixed: the posted value.
        $this->assertSame('2030-02-01T10:00', $fields['timeclose']['value']);
        $this->assertTrue($fields['timeclose']['fixed']);
        // Fixed, nothing posted (the input was disabled): the current value.
        $this->assertSame('2029-12-20T09:00', $fields['timelock']['value']);
        $this->assertTrue($fields['timelock']['fixed']);

        // The unselected row keeps its saved flag, and its current value.
        $fields = $this->fields($rows[2]);
        $this->assertTrue($fields['timeclose']['fixed']);
        $this->assertFalse($fields['timeopen']['fixed']);
        $this->assertSame('2029-12-01T09:00', $fields['timeopen']['value']);
    }

    public function test_posted_values_and_errors_kept(): void {
        $tz = new \DateTimeZone('Europe/London');
        $rows = preview_rows::dates($this->table(), $tz, $this->options([
            'source' => preview_rows::SOURCE_POSTED,
            'rowinputs' => [
                'timeopen' => [11 => '2030-02-30T10:00', 12 => '2030-01-01T10:00'],
                'timeclose' => [11 => ''],
            ],
            'rowerrors' => [11 => ['timeopen' => 'errorinvaliddate'], 12 => ['timeopen' => 'errorinvaliddate']],
            'fixposted' => [],
        ]));

        // The teacher's own (invalid) value is re-rendered, not the proposal.
        $fields = $this->fields($rows[1]);
        $this->assertSame('2030-02-30T10:00', $fields['timeopen']['value']);
        $this->assertTrue($fields['timeopen']['invalid']);
        $this->assertSame(get_string('errorinvaliddate', 'tool_activitydates'), $fields['timeopen']['error']);
        $this->assertSame('', $fields['timeclose']['value']);
        $this->assertFalse($fields['timeclose']['invalid']);
        // A posted value or error for a disabled row is not echoed.
        $fields = $this->fields($rows[2]);
        $this->assertSame('2029-12-01T09:00', $fields['timeopen']['value']);
        $this->assertFalse($fields['timeopen']['invalid']);
    }

    public function test_disabled_by_capability_and_lock(): void {
        $tz = new \DateTimeZone('Europe/London');
        $table = $this->table();

        // Locks only, lock mode in use: open and close disabled, their Fix too.
        $datesoff = ['timeopen' => false, 'duedate' => false, 'timeclose' => false, 'timelock' => true];
        $rows = preview_rows::dates($table, $tz, $this->options([
            'source' => preview_rows::SOURCE_PREVIEW,
            'editable' => $datesoff,
            'fixable' => $datesoff,
            'fixposted' => [],
        ]));
        $fields = $this->fields($rows[1]);
        $this->assertTrue($fields['timeopen']['disabled']);
        $this->assertSame('2029-12-01T09:00', $fields['timeopen']['value']);
        $this->assertTrue($fields['timeopen']['fixdisabled']);
        $this->assertFalse($fields['timeopen']['fixtoggle']);
        $this->assertFalse($fields['timelock']['disabled']);
        $this->assertSame('2030-01-09T09:00', $fields['timelock']['value']);
        $this->assertTrue($fields['timelock']['fixtoggle']);

        // Lock mode none: Locked is disabled with its current value, but can still be fixed.
        $rows = preview_rows::dates($table, $tz, $this->options([
            'source' => preview_rows::SOURCE_PREVIEW,
            'editable' => ['timelock' => false] + array_fill_keys(self::ALL, true),
            'fixposted' => [],
        ]));
        $fields = $this->fields($rows[1]);
        $this->assertTrue($fields['timelock']['disabled']);
        $this->assertSame('2029-12-20T09:00', $fields['timelock']['value']);
        $this->assertFalse($fields['timelock']['fixdisabled']);

        // No grade item: never a lock input.
        $table[1]['hasgradeitem'] = false;
        $fields = $this->fields(preview_rows::dates($table, $tz, $this->options())[1]);
        $this->assertTrue($fields['timelock']['disabled']);

        // Only the present fields, in display order.
        $rows = preview_rows::dates($table, $tz, $this->options(['fields' => ['timelock', 'duedate', 'timeopen']]));
        $this->assertSame(['timeopen', 'duedate', 'timelock'], array_column($rows[1]['fields'], 'field'));
    }

    public function test_note_ticks(): void {
        $tz = new \DateTimeZone('Europe/London');
        $table = $this->table();
        $table[1]['shownote'] = true;
        $table[2]['shownotecoursepage'] = true;

        // Saved ticks; the unselected row's are disabled.
        $rows = preview_rows::dates($table, $tz, $this->options());
        $this->assertSame([true, false, false], array_column(array_slice($rows, 1), 'shownote'));
        $this->assertSame([false, true, false], array_column(array_slice($rows, 1), 'shownotecoursepage'));
        $this->assertSame([false, true, false], array_column(array_slice($rows, 1), 'notedisabled'));

        // Posted ticks win on selected rows; the unselected row keeps its saved ones.
        $rows = preview_rows::dates($table, $tz, $this->options(['notecmids' => [13, 12], 'coursenotecmids' => [11]]));
        $this->assertSame([false, false, true], array_column(array_slice($rows, 1), 'shownote'));
        $this->assertSame([true, true, false], array_column(array_slice($rows, 1), 'shownotecoursepage'));
    }

    /**
     * An unselected row without a saved note item carries the site defaults for the
     * page to tick when the row is selected; other rows carry none.
     */
    public function test_note_defaults(): void {
        $tz = new \DateTimeZone('Europe/London');
        $table = $this->table();
        $table[2]['notedefaults'] = ['shownote' => true, 'shownotecoursepage' => false];
        $table[3]['notedefaults'] = ['shownote' => true, 'shownotecoursepage' => true];

        $rows = array_slice(preview_rows::dates($table, $tz, $this->options()), 1);

        $this->assertSame([false, true, false], array_column($rows, 'hasnotedefault'));
        $this->assertTrue($rows[1]['defaultnote']);
        $this->assertFalse($rows[1]['defaultcoursenote']);
        // The unselected row itself shows no note.
        $this->assertFalse($rows[1]['shownote']);
    }

    public function test_engine_fixed(): void {
        $tz = new \DateTimeZone('Europe/London');
        $table = $this->table();
        $table[1]['fixed']['timelock'] = true;
        $table[2]['fixed']['timeclose'] = true;
        $table[3]['fixed']['timeopen'] = true;
        $fixable = ['timeopen' => true, 'duedate' => true, 'timeclose' => true, 'timelock' => false];

        $fixed = preview_rows::engine_fixed(
            $table,
            [
                'timeopen' => [11 => 1],
                'timeclose' => [11 => 1, 12 => 1],
                'duedate' => [11 => 1],
                // Not fixable by this user: the saved flags count.
                'timelock' => [13 => 1],
            ],
            ['timeopen' => [11 => '2030-03-01T08:00'], 'duedate' => [11 => 'garbage']],
            $fixable,
            $tz
        );
        $this->assertEquals([
            11 => [
                // Posted.
                'timeopen' => $this->ts('2030-03-01 08:00'),
                // Ticked, nothing posted: current.
                'timeclose' => 0,
                // Not fixable, saved flag: current.
                'timelock' => $this->ts('2029-12-20 09:00'),
                // The due date's value does not parse: unfixed for the calculation.
            ],
            // Unselected: the saved flag, not the posted tick.
            12 => ['timeclose' => 0],
            // Selected and fixable, not ticked: the saved flag is dropped.
        ], $fixed);

        // Saved flags only (fixposted null).
        $fixed = preview_rows::engine_fixed($table, null, [], $fixable, $tz);
        $this->assertEquals([
            11 => ['timelock' => $this->ts('2029-12-20 09:00')],
            12 => ['timeclose' => 0],
            13 => ['timeopen' => $this->ts('2029-12-01 09:00')],
        ], $fixed);
    }
}
