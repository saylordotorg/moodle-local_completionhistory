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
 * Expands a ledger row to show the learner's per-attempt exam history.
 *
 * The Attempts column renders a .lch-expand-attempts button carrying
 * data-userid, data-courseid and data-rowid. The first click fetches the
 * attempt_history fragment from ajax_get_attempts.php into a new row under the
 * current one; later clicks toggle that row.
 *
 * @module     local_completionhistory/attempt_details
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Config from 'core/config';
import Notification from 'core/notification';
import {getString} from 'core/str';

const SELECTORS = {
    button: '.lch-expand-attempts',
    row: 'tr',
};

const CLASSES = {
    detailsRow: 'lch-attempt-details-row',
};

const ENDPOINT = '/local/completionhistory/ajax_get_attempts.php';

/**
 * Fetch the attempt history fragment for one user and course.
 *
 * @param {String} userid The user id.
 * @param {String} courseid The course id.
 * @return {Promise<String>} The HTML fragment.
 */
const fetchHistory = async(userid, courseid) => {
    const url = new URL(Config.wwwroot + ENDPOINT);
    url.searchParams.set('userid', userid);
    url.searchParams.set('courseid', courseid);
    url.searchParams.set('sesskey', Config.sesskey);

    const response = await fetch(url, {credentials: 'same-origin'});
    if (!response.ok) {
        throw new Error(`${response.status} ${response.statusText}`);
    }
    return response.text();
};

/**
 * Create the details row under a ledger row and fill it.
 *
 * @param {HTMLTableRowElement} row The ledger row.
 * @param {HTMLElement} button The expand button.
 * @return {Promise<void>}
 */
const expand = async(row, button) => {
    const detailsRow = document.createElement('tr');
    detailsRow.className = CLASSES.detailsRow;
    detailsRow.dataset.rowid = button.dataset.rowid || '';

    const cell = document.createElement('td');
    cell.colSpan = row.children.length;
    cell.textContent = await getString('attempt_details_loading', 'local_completionhistory');
    detailsRow.appendChild(cell);
    row.after(detailsRow);

    try {
        cell.innerHTML = await fetchHistory(button.dataset.userid, button.dataset.courseid);
    } catch (error) {
        cell.textContent = await getString('attempt_details_error', 'local_completionhistory');
        Notification.exception(error);
    }
};

/**
 * Handle a click anywhere in the document, acting on expand buttons only.
 *
 * @param {MouseEvent} e The click event.
 */
const onClick = (e) => {
    const button = e.target.closest(SELECTORS.button);
    if (!button) {
        return;
    }
    e.preventDefault();

    const row = button.closest(SELECTORS.row);
    if (!row) {
        return;
    }

    const existing = row.nextElementSibling;
    if (existing && existing.classList.contains(CLASSES.detailsRow)) {
        existing.hidden = !existing.hidden;
        button.setAttribute('aria-expanded', existing.hidden ? 'false' : 'true');
        return;
    }

    button.setAttribute('aria-expanded', 'true');
    expand(row, button);
};

/**
 * Initialise the expand buttons on the page.
 */
export const init = () => {
    if (document.body.dataset.lchAttemptDetails === '1') {
        return;
    }
    document.body.dataset.lchAttemptDetails = '1';
    document.addEventListener('click', onClick);
};
