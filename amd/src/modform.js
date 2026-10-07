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
 * enable each row's Hold and note checkboxes only while the row is selected,
 * drive the select-all and the two note select-all checkboxes, filter the table's
 * rows by activity name, and preview automatically when the activity type is
 * changed. The select-alls act on the rows the filter shows only.
 *
 * @module     tool_activitydates/modform
 * @copyright  2022 Marcus Green
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

    // Enter in the configuration name saves the configuration. Otherwise it would press
    // the form's first submit button, Preview, which discards the table's edits.
    const configName = document.getElementById('id_configname');
    const saveConfigButton = document.getElementById('id_saveconfig');
    if (configName && saveConfigButton) {
        configName.addEventListener('keydown', e => {
            if (e.key === 'Enter') {
                e.preventDefault();
                if (!saveConfigButton.disabled) {
                    saveConfigButton.click();
                }
            }
        });
    }

    /**
     * Whether an element's table row is shown (not hidden by the name filter).
     *
     * @param {HTMLElement} element an element in the table.
     * @returns {boolean}
     */
    const shown = element => {
        const row = element.closest('tr');
        return !row || !row.hidden;
    };

    // The Grade-lock note column's header checkboxes tick or untick the activity-page
    // or the course-page note of every selected row the filter shows (the others are
    // disabled or hidden).
    const noteToggles = [
        ['id_togglenotes', 'shownote_cmids[]'],
        ['id_togglecoursenotes', 'shownotecourse_cmids[]'],
    ].map(([toggleid, name]) => [document.getElementById(toggleid), name]).filter(([toggle]) => toggle);

    /**
     * Tick each note header checkbox only when every enabled, shown note checkbox of
     * its kind is ticked, and there is at least one.
     */
    const syncNoteToggles = () => {
        noteToggles.forEach(([toggle, name]) => {
            const enabled = Array.from(document.querySelectorAll('input[name="' + name + '"]'))
                .filter(checkbox => !checkbox.disabled && shown(checkbox));
            toggle.checked = enabled.length > 0 && enabled.every(checkbox => checkbox.checked);
        });
    };

    noteToggles.forEach(([toggle, name]) => {
        toggle.addEventListener('click', e => {
            document.querySelectorAll('input[name="' + name + '"]').forEach(checkbox => {
                if (!checkbox.disabled && shown(checkbox)) {
                    checkbox.checked = e.target.checked;
                }
            });
        });
        document.querySelectorAll('input[name="' + name + '"]').forEach(checkbox => {
            checkbox.addEventListener('change', syncNoteToggles);
        });
    });
    syncNoteToggles();

    /**
     * Enable a row's Hold and note checkboxes only while the row is selected. Their
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
        syncNoteToggles();
    };

    const selectAllCheckBox = document.getElementById('id_selectall');
    // Guard against a course with no activities of the selected type, where the
    // table (and its select-all checkbox) is not rendered.
    if (!selectAllCheckBox) {
        return;
    }

    // The table checkboxes of the rows the filter shows, and each one's hidden form checkbox.
    const shownRowCheckboxes = () => Array.from(document.querySelectorAll("input[id^='id_cmid_']")).filter(shown);
    const formCheckbox = checkbox => document.getElementById('id_activitygroup_activity_' + checkbox.id.split('_')[2]);

    selectAllCheckBox.addEventListener('click', e => {
        // Only the rows the filter shows; hidden rows keep their ticks.
        shownRowCheckboxes().forEach(checkbox => {
            checkbox.checked = e.target.checked;
            const hidden = formCheckbox(checkbox);
            if (hidden) {
                hidden.checked = e.target.checked;
            }
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
     * Tick the select-all checkbox only when every activity the filter shows is selected.
     */
    function configureSelectAll() {
        const rows = shownRowCheckboxes();
        selectAllCheckBox.checked = rows.length > 0 && rows.every(checkbox => checkbox.checked);
    }

    // Filter the rows by activity name, ignoring case. A session row shows while any of
    // its activities does.
    const filter = document.querySelector('[data-region="tool_activitydates-filter"]');
    const noMatch = document.querySelector('[data-region="tool_activitydates-nomatch"]');
    if (filter) {
        filter.addEventListener('input', () => {
            const text = filter.value.trim().toLowerCase();
            let session = null;
            let sessionShown = false;
            let anyShown = false;
            const closeSession = () => {
                if (session) {
                    session.hidden = !sessionShown;
                }
            };
            document.querySelectorAll('[data-region="tool_activitydates-sessionrow"], ' +
                    '[data-region="tool_activitydates-activityrow"]').forEach(row => {
                if (row.dataset.region === 'tool_activitydates-sessionrow') {
                    closeSession();
                    session = row;
                    sessionShown = false;
                    return;
                }
                row.hidden = text !== '' && !(row.dataset.name || '').toLowerCase().includes(text);
                sessionShown = sessionShown || !row.hidden;
                anyShown = anyShown || !row.hidden;
            });
            closeSession();
            if (noMatch) {
                noMatch.hidden = anyShown;
            }
            configureSelectAll();
            syncNoteToggles();
        });
    }
};
