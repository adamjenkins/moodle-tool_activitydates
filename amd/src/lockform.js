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
 * Select-all / toggle-all-notes helpers for the activity table, and an
 * automatic Preview when the activity type is changed.
 *
 * @module     tool_activitydates/lockform
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

export const init = () => {

    const selectAllCheckbox = document.getElementById('id_selectall');
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('click', e => {
            document.querySelectorAll("[id^='id_cmid_']").forEach(checkbox => {
                checkbox.checked = e.target.checked;
            });
        });
    }

    const toggleNotesCheckbox = document.getElementById('id_togglenotes');
    if (toggleNotesCheckbox) {
        toggleNotesCheckbox.addEventListener('click', e => {
            document.querySelectorAll("[id^='id_shownote_']").forEach(checkbox => {
                checkbox.checked = e.target.checked;
            });
        });
    }

    // Preview when the activity type changes, so the table and schedule fields
    // are recalculated for the newly chosen type. The Preview submit button is
    // the no-JS fallback this mirrors. The form adds the group with appendName
    // off, so the elements keep their own ids (id_modtype, id_preview).
    const modtypeSelect = document.getElementById('id_modtype');
    const previewButton = document.getElementById('id_preview');
    if (modtypeSelect && previewButton) {
        modtypeSelect.addEventListener('change', () => {
            previewButton.click();
        });
    }
};
