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
 * Mirror the visible activity checkboxes into the hidden form checkboxes and
 * drive the select-all toggle.
 *
 * @module     tool_activitydates/modform
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

export const init = () => {

    const selectAllCheckBox = document.getElementById('id_selectall');
    // Guard against a course with no activities of the selected type, where the
    // table (and its select-all checkbox) is not rendered.
    if (!selectAllCheckBox) {
        return;
    }

    selectAllCheckBox.addEventListener('click', e => {
        // Hidden form checkboxes.
        document.querySelectorAll("[id^='id_activitygroup_activity_']").forEach(checkbox => {
            checkbox.checked = e.target.checked ? true : false;
        });
        // Visible table checkboxes.
        document.querySelectorAll("[id^='id_cmid_']").forEach(checkbox => {
            checkbox.checked = e.target.checked ? true : false;
        });
    });

    const cmids = document.querySelectorAll('input[id^="id_cmid_"]');
    cmids.forEach(function(e) {
        e.addEventListener('click', cmidClick);
    });
    configureSelectAll();

    /**
     * Mirror a visible checkbox's state into its hidden form checkbox.
     *
     * @param {Event} e the click event on a visible checkbox.
     */
    function cmidClick(e) {
        const id = e.currentTarget.id.split('_')[2];
        const checkboxid = 'id_activitygroup_activity_' + id;
        const checkbox = document.getElementById(checkboxid);
        checkbox.checked = e.currentTarget.checked;
        configureSelectAll();
    }

    /**
     * Tick the select-all checkbox only when every activity is selected.
     */
    function configureSelectAll() {
        let allchecked = true;
        document.querySelectorAll("[id^='id_activitygroup_activity_']").forEach(checkbox => {
            if (checkbox.checked === false) {
                allchecked = false;
            }
        });
        selectAllCheckBox.checked = allchecked;
    }
};
