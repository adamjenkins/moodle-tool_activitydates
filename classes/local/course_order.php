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

use cm_info;
use course_modinfo;

/**
 * Orders course modules the way they appear on the course page.
 *
 * modinfo lists modules section by section in section-number order. That is
 * not page order once a course uses subsections: a subsection's content lives
 * in a delegated section numbered after every listed section, but the page
 * shows it inline, where the subsection module sits. This walks the listed
 * sections and descends into each delegated section at its module's position.
 *
 * Core's course_modinfo::sort_cm_array() does the same, but only from Moodle
 * 5.0.3 (absent from the v5.0.0-v5.0.2 tags), and this plugin supports 5.0.0.
 *
 * @package    tool_activitydates
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_order {
    /**
     * Every module on the course page, mapped to its position there.
     *
     * @param course_modinfo $modinfo
     * @return array cmid => 0-based position; modules not reachable from a
     *               listed section (e.g. in an orphaned delegated section) are absent
     */
    public static function positions(course_modinfo $modinfo): array {
        $sequences = $modinfo->get_sections();
        $delegatedbycm = $modinfo->get_sections_delegated_by_cm();
        $positions = [];

        $walk = function (int $sectionnum) use (&$walk, &$positions, $sequences, $delegatedbycm): void {
            foreach ($sequences[$sectionnum] ?? [] as $cmid) {
                $cmid = (int) $cmid;
                if (isset($positions[$cmid])) {
                    continue;
                }
                $positions[$cmid] = count($positions);
                if (isset($delegatedbycm[$cmid])) {
                    $walk((int) $delegatedbycm[$cmid]->section);
                }
            }
        };
        foreach ($modinfo->get_section_info_all() as $sectionnum => $section) {
            if (!$section->is_delegated()) {
                $walk((int) $sectionnum);
            }
        }
        return $positions;
    }

    /**
     * Sort course modules into course-page order, keeping their keys.
     *
     * Modules with no page position keep their relative order at the end.
     *
     * @param course_modinfo $modinfo the modinfo of the course the modules belong to
     * @param cm_info[] $cms
     * @return cm_info[] the same modules and keys, in course-page order
     */
    public static function sort(course_modinfo $modinfo, array $cms): array {
        $positions = self::positions($modinfo);
        // PHP 8 sorts are stable, so equal (missing) positions keep their order.
        uasort($cms, fn(cm_info $a, cm_info $b): int =>
            ($positions[(int) $a->id] ?? PHP_INT_MAX) <=> ($positions[(int) $b->id] ?? PHP_INT_MAX));
        return $cms;
    }
}
