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
 * itself. Hints never block submission. Every row is checked when the page
 * loads, so proposals that break a row rule are flagged straight after
 * Preview, and Enter in a table date does not submit the form (it would
 * press Preview and discard the table's edits). Disabled dates (fields the
 * user cannot change) are not checked, and the Fix checkboxes are not watched:
 * fixing a date does not make the table stale.
 *
 * @module     tool_activitydates/previewtable
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getStrings} from 'core/str';
import Notification from 'core/notification';

/** @var {string[]} The error string keys the hints use. */
const ERRORKEYS = [
    'errorclosebeforeopen',
    'errorduebeforeopen',
    'errordueafterclose',
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
    // The bar sits inside an always-present live region; its text is added only
    // when the table goes stale, so screen readers announce the change.
    const staletext = stalebar ? stalebar.textContent.trim() : '';
    if (stalebar) {
        stalebar.textContent = '';
    }
    let isstale = false;

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
        if (stalebar && stale !== isstale) {
            stalebar.textContent = stale ? staletext : '';
            stalebar.hidden = !stale;
        }
        isstale = stale;
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
     * Find one row's editable date input.
     *
     * @param {string} cmid the course module id.
     * @param {string} field the date field.
     * @return {HTMLInputElement|null} null when the row has no such input, or it is disabled.
     */
    const input = (cmid, field) => wrapper.querySelector(
        'input[data-cmid="' + cmid + '"][data-field="' + field + '"]:not([disabled])');

    /**
     * Inputs the server marked invalid for a reason the rules below cannot see
     * (an impossible date), with the server's hint text. Kept until the input
     * itself is edited.
     *
     * @type {Map<HTMLInputElement, string>}
     */
    const serveronly = new Map();

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
        let text = errorkey === null ? '' : strings[errorkey];
        if (errorkey === null && serveronly.has(element)) {
            text = serveronly.get(element);
        }
        const invalid = text !== '';
        element.classList.toggle('is-invalid', invalid);
        if (invalid) {
            element.setAttribute('aria-invalid', 'true');
        } else {
            element.removeAttribute('aria-invalid');
        }
        if (hint) {
            hint.textContent = text;
            // The hint is not the input's sibling, so Bootstrap does not show it by itself.
            hint.classList.toggle('d-block', invalid);
        }
    };

    /**
     * Check one row with the same rules as the server (datefields).
     *
     * Values are datetime-local strings in the user's timezone, so they order
     * lexicographically. An incomplete browser value reads as empty. Every
     * date is optional: the ordering rules apply only between set values.
     *
     * @param {string} cmid the course module id.
     * @return {Object} field => error string key, or null when valid, for each field the row has.
     */
    const rowErrors = cmid => {
        const value = field => {
            const element = input(cmid, field);
            return element ? element.value : null;
        };

        const open = value('timeopen');
        const close = value('timeclose');
        const due = value('duedate');
        const errors = {};
        // The lock date has no ordering rule.
        if (value('timelock') !== null) {
            errors.timelock = null;
        }
        if (open !== null) {
            errors.timeopen = null;
        }
        if (close !== null) {
            errors.timeclose = close && open && close <= open ? 'errorclosebeforeopen' : null;
        }
        if (due !== null) {
            let dueerror = null;
            if (due && open && due <= open) {
                dueerror = 'errorduebeforeopen';
            } else if (due && close && due > close) {
                dueerror = 'errordueafterclose';
            }
            errors.duedate = dueerror;
        }
        return errors;
    };

    /**
     * Show one row's hints.
     *
     * @param {Object} strings the error texts by key.
     * @param {string} cmid the course module id.
     */
    const validateRow = (strings, cmid) => {
        Object.entries(rowErrors(cmid)).forEach(([field, errorkey]) => mark(strings, cmid, field, errorkey));
    };

    const cmids = [...new Set(Array.from(wrapper.querySelectorAll('input[data-field][data-cmid]:not([disabled])'))
        .map(element => element.dataset.cmid))];

    // Keep the server's hints that the rules above would clear.
    cmids.forEach(cmid => {
        Object.entries(rowErrors(cmid)).forEach(([field, errorkey]) => {
            const element = input(cmid, field);
            const hint = wrapper.querySelector('[data-region="tool_activitydates-hint"]' +
                '[data-cmid="' + cmid + '"][data-field="' + field + '"]');
            if (errorkey === null && element && element.classList.contains('is-invalid') && hint) {
                serveronly.set(element, hint.textContent.trim());
            }
        });
    });

    // Flag proposals that break a row rule as soon as the table is shown.
    stringsPromise.then(strings => cmids.forEach(cmid => validateRow(strings, cmid)))
        .catch(Notification.exception);

    ['input', 'change'].forEach(type => {
        wrapper.addEventListener(type, e => {
            const target = e.target;
            if (!(target instanceof HTMLInputElement) || !target.dataset.field || !target.dataset.cmid) {
                return;
            }
            serveronly.delete(target);
            stringsPromise.then(strings => validateRow(strings, target.dataset.cmid))
                .catch(Notification.exception);
        });
    });

    // Enter in a date would submit the form with its first button, Preview,
    // which recalculates the table and drops every edit.
    wrapper.addEventListener('keydown', e => {
        const target = e.target;
        if (e.key === 'Enter' && target instanceof HTMLInputElement && target.dataset.field) {
            e.preventDefault();
        }
    });
};
