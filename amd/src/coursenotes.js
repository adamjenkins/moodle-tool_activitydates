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
 * Moves the server-rendered grade-lock notes into their activity cards on
 * the course page, and puts a note back if the course editor re-renders
 * its activity.
 *
 * @module     tool_activitydates/coursenotes
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Put each note at the bottom of its activity's card, if it is not already there.
 *
 * The bottom of the card, not next to the activity information: with
 * completion on, that sits in the narrow completion column.
 *
 * @param {HTMLElement[]} notes The note elements, each with a data-cmid.
 */
const place = notes => {
    notes.forEach(note => {
        const card = document.querySelector(
            `[data-for="cmitem"][data-id="${parseInt(note.dataset.cmid, 10)}"] [data-region="activity-card"]`
        );
        // No card: not on this page, or a format without the standard activity
        // markup. The note stays where it is (the hidden container at first).
        if (card && note.parentElement !== card) {
            card.append(note);
        }
    });
};

export const init = () => {
    const container = document.querySelector('[data-region="tool_activitydates-coursenotes"]');
    if (!container) {
        return;
    }
    const notes = Array.from(container.querySelectorAll('[data-cmid]'));
    place(notes);

    // In editing mode the course editor replaces an activity's whole element
    // after an edit, taking the note with it; put it into the new card.
    const observer = new MutationObserver(() => {
        if (notes.some(note => !note.isConnected)) {
            place(notes.filter(note => !note.isConnected));
        }
    });
    observer.observe(document.body, {childList: true, subtree: true});
};
