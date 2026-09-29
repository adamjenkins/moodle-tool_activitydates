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
    /**
     * A get_table_data()-shaped table: a header, a scheduled row and an unselected row.
     *
     * @param int $open the proposed open date of the scheduled row.
     * @return array
     */
    private function table(int $open): array {
        $row = [
            'isheader' => false,
            'cm' => new \stdClass(),
            'name' => 'Quiz',
            'intro' => '',
            'questioncount' => 1,
            'timeopen' => 0,
            'duedate' => null,
            'timeclose' => 0,
        ];
        return [
            ['isheader' => true, 'dates' => null],
            ['id' => 11, 'selected' => 'checked', 'scheduled' => true, 'status' => '',
                'proposed' => ['timeopen' => $open, 'duedate' => null, 'timeclose' => $open + DAYSECS]] + $row,
            ['id' => 12, 'selected' => '', 'scheduled' => false, 'status' => 'rowstatus_notscheduled',
                'proposed' => null] + $row,
        ];
    }

    public function test_proposals_rendered_as_input_values(): void {
        $tz = new \DateTimeZone('Europe/London');
        $open = (new \DateTimeImmutable('2030-01-07 09:00', $tz))->getTimestamp();
        $rows = preview_rows::dates($this->table($open), null, [], $tz);

        $this->assertCount(3, $rows);
        $this->assertArrayNotHasKey('cm', $rows[1]);
        $this->assertArrayNotHasKey('proposed', $rows[1]);
        $this->assertTrue($rows[1]['editable']);
        $this->assertSame('2030-01-07T09:00', $rows[1]['timeopenvalue']);
        $this->assertSame('2030-01-08T09:00', $rows[1]['timeclosevalue']);
        $this->assertSame('', $rows[1]['duedatevalue']);
        $this->assertFalse($rows[1]['timeopeninvalid']);

        $this->assertFalse($rows[2]['editable']);
        $this->assertSame('', $rows[2]['timeopenvalue']);
        $this->assertSame(get_string('rowstatus_notscheduled', 'tool_activitydates'), $rows[2]['statustext']);
    }

    public function test_posted_values_and_errors_kept(): void {
        $tz = new \DateTimeZone('Europe/London');
        $open = (new \DateTimeImmutable('2030-01-07 09:00', $tz))->getTimestamp();
        $inputs = [
            'timeopen' => [11 => '2030-02-30T10:00', 12 => '2030-01-01T10:00'],
            'duedate' => [],
            'timeclose' => [11 => ''],
        ];
        $errors = [11 => ['timeopen' => 'errorinvaliddate']];
        $rows = preview_rows::dates($this->table($open), $inputs, $errors, $tz);

        // The teacher's own (invalid) value is re-rendered, not the proposal.
        $this->assertSame('2030-02-30T10:00', $rows[1]['timeopenvalue']);
        $this->assertSame('', $rows[1]['timeclosevalue']);
        $this->assertTrue($rows[1]['timeopeninvalid']);
        $this->assertSame(get_string('errorinvaliddate', 'tool_activitydates'), $rows[1]['timeopenerror']);
        $this->assertFalse($rows[1]['timecloseinvalid']);
        // A posted value for a non-editable row is not echoed.
        $this->assertSame('', $rows[2]['timeopenvalue']);
    }
}
