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
 * Mark the editable preview table stale when the settings it was built from
 * change, and show inline hints for invalid row dates.
 *
 * The stale bar and the disabled Save buttons are a convenience only: the
 * server refuses a stale table by its fingerprint, and validates every row
 * itself. Hints never block submission.
 *
 * @module     tool_activitydates/previewtable
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getStrings} from 'core/str';
import Notification from 'core/notification';

/** @var {string[]} The error string keys the hints use. */
const ERRORKEYS = [
    'erroropenrequired',
    'errorclosebeforeopen',
    'errorduebeforeopen',
    'errordueafterclose',
    'errorlockrequired',
];

/** @var {string[]} The ids of the Save buttons disabled while the table is stale. */
const SAVEBUTTONIDS = ['id_submitbutton', 'id_submitbutton2'];

/**
 * Initialise the stale bar and inline hints for one form's preview table.
 *
 * @param {string} formid the id of the moodleform the table's inputs submit with.
 * @param {string[]} watchednames name prefixes of the settings controls the table depends on.
 */
export const init = (formid, watchednames) => {
    const wrapper = document.querySelector(
        '[data-region="tool_activitydates-previewtable"][data-formid="' + formid + '"]');
    const form = document.getElementById(formid);
    if (!wrapper || !form) {
        return;
    }
    const stalebar = wrapper.querySelector('[data-region="tool_activitydates-stale"]');

    /**
     * The watched controls: the form's own elements plus any element outside
     * it that submits with it, de-duplicated.
     *
     * @return {HTMLElement[]}
     */
    const controls = () => {
        const all = new Set(Array.from(form.elements));
        document.querySelectorAll('[form="' + formid + '"]').forEach(element => all.add(element));
        return Array.from(all).filter(element => element.name &&
            watchednames.some(prefix => element.name.startsWith(prefix)));
    };

    /**
     * Snapshot the watched controls' values.
     *
     * Keys are the name plus the index among same-name controls, so a hidden
     * input and the checkbox that share its name stay distinct.
     *
     * @return {Map<string, string|boolean>}
     */
    const snapshot = () => {
        const counts = {};
        const values = new Map();
        controls().forEach(element => {
            const index = counts[element.name] || 0;
            counts[element.name] = index + 1;
            const checkable = element.type === 'checkbox' || element.type === 'radio';
            values.set(element.name + '#' + index, checkable ? element.checked : element.value);
        });
        return values;
    };

    const initial = snapshot();

    /**
     * Whether the live snapshot differs from the one taken at load.
     *
     * @return {boolean}
     */
    const changed = () => {
        const live = snapshot();
        if (live.size !== initial.size) {
            return true;
        }
        for (const [key, value] of live) {
            if (!initial.has(key) || initial.get(key) !== value) {
                return true;
            }
        }
        return false;
    };

    /**
     * Show or hide the stale bar and disable or enable the Save buttons.
     */
    const compare = () => {
        const stale = changed();
        if (stalebar) {
            stalebar.hidden = !stale;
        }
        SAVEBUTTONIDS.forEach(id => {
            const button = document.getElementById(id);
            if (button) {
                button.disabled = stale;
            }
        });
    };

    // Deferred so that other handlers (modform's checkbox mirroring) run first.
    ['change', 'input', 'click'].forEach(type => {
        document.addEventListener(type, () => {
            setTimeout(compare, 0);
        });
    });

    const stringsPromise = getStrings(ERRORKEYS.map(key => ({key, component: 'tool_activitydates'})))
        .then(texts => {
            const strings = {};
            ERRORKEYS.forEach((key, i) => {
                strings[key] = texts[i];
            });
            return strings;
        });

    /**
     * Find one row's date input.
     *
     * @param {string} cmid the course module id.
     * @param {string} field the date field.
     * @return {HTMLInputElement|null}
     */
    const input = (cmid, field) => wrapper.querySelector(
        'input[data-cmid="' + cmid + '"][data-field="' + field + '"]');

    /**
     * Mark one input valid or invalid, with its hint text.
     *
     * @param {Object} strings the error texts by key.
     * @param {string} cmid the course module id.
     * @param {string} field the date field.
     * @param {string|null} errorkey the error string key, or null when valid.
     */
    const mark = (strings, cmid, field, errorkey) => {
        const element = input(cmid, field);
        if (!element) {
            return;
        }
        const hint = wrapper.querySelector('[data-region="tool_activitydates-hint"]' +
            '[data-cmid="' + cmid + '"][data-field="' + field + '"]');
        element.classList.toggle('is-invalid', errorkey !== null);
        if (hint) {
            hint.textContent = errorkey === null ? '' : strings[errorkey];
        }
    };

    /**
     * Validate one row with the same rules as the server (datefields).
     *
     * Values are datetime-local strings in the user's timezone, so they order
     * lexicographically. An incomplete browser value reads as empty.
     *
     * @param {Object} strings the error texts by key.
     * @param {string} cmid the course module id.
     */
    const validateRow = (strings, cmid) => {
        const value = field => {
            const element = input(cmid, field);
            return element ? element.value : null;
        };

        const lock = value('locktime');
        if (lock !== null) {
            mark(strings, cmid, 'locktime', lock === '' ? 'errorlockrequired' : null);
            return;
        }

        const open = value('timeopen');
        const close = value('timeclose');
        const due = value('duedate');
        mark(strings, cmid, 'timeopen', open === '' ? 'erroropenrequired' : null);
        mark(strings, cmid, 'timeclose', close && open && close <= open ? 'errorclosebeforeopen' : null);
        if (due !== null) {
            let dueerror = null;
            if (due && open && due <= open) {
                dueerror = 'errorduebeforeopen';
            } else if (due && close && due > close) {
                dueerror = 'errordueafterclose';
            }
            mark(strings, cmid, 'duedate', dueerror);
        }
    };

    ['input', 'change'].forEach(type => {
        wrapper.addEventListener(type, e => {
            const target = e.target;
            if (!(target instanceof HTMLInputElement) || !target.dataset.field || !target.dataset.cmid) {
                return;
            }
            stringsPromise.then(strings => validateRow(strings, target.dataset.cmid))
                .catch(Notification.exception);
        });
    });
};
