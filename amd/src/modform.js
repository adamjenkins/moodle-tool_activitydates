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
 * Mirror the visible activity checkboxes into the hidden form checkboxes,
 * enable each row's Fix and note checkboxes only while the row is selected,
 * drive the select-all and the two note select-all checkboxes, and preview
 * automatically when the activity type is changed.
 *
 * @module     tool_activitydates/modform
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

export const init = () => {

    // Preview when the activity type changes, so the table and the schedule
    // fields refresh for the newly chosen type. The Preview submit button is the
    // no-JS fallback this mirrors. The form adds the group with appendName off,
    // so the elements keep their own ids (id_modtype, id_preview).
    const modtypeSelect = document.getElementById('id_modtype');
    const previewButton = document.getElementById('id_preview');
    if (modtypeSelect && previewButton) {
        modtypeSelect.addEventListener('change', () => {
            previewButton.click();
        });
    }

    // The Grade-lock note column's header checkboxes tick or untick the activity-page
    // or the course-page note of every selected row (the others are disabled).
    [
        ['id_togglenotes', 'shownote_cmids[]'],
        ['id_togglecoursenotes', 'shownotecourse_cmids[]'],
    ].forEach(([toggleid, name]) => {
        const toggle = document.getElementById(toggleid);
        if (!toggle) {
            return;
        }
        toggle.addEventListener('click', e => {
            document.querySelectorAll('input[name="' + name + '"]').forEach(checkbox => {
                if (!checkbox.disabled) {
                    checkbox.checked = e.target.checked;
                }
            });
        });
    });

    /**
     * Enable a row's Fix and note checkboxes only while the row is selected. Their
     * values are saved for selected rows only, and a disabled checkbox is not posted.
     * A note checkbox of a row without a saved note takes the site default the first
     * time the row is selected.
     *
     * @param {string} cmid the course module id.
     * @param {boolean} selected whether the row is selected.
     */
    const setRowControls = (cmid, selected) => {
        document.querySelectorAll('[data-rowcontrol="' + cmid + '"]').forEach(control => {
            control.disabled = !selected;
            if (selected && control.dataset.defaultchecked !== undefined) {
                control.checked = control.dataset.defaultchecked === '1';
                delete control.dataset.defaultchecked;
            }
        });
    };

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
            setRowControls(checkbox.id.split('_')[2], checkbox.checked);
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
        setRowControls(id, e.currentTarget.checked);
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
