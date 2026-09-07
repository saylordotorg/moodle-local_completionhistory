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
 * Column manager and filter-form behaviour for the staff ledger pages.
 *
 * The widget markup comes from the local_completionhistory/column_manager
 * template; every part is found through a data-region attribute inside the
 * form, so the Achievement Ledger and the Exam Attempt Log share this module.
 *
 * @module     local_completionhistory/column_manager
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';
import {getString} from 'core/str';

const SELECTORS = {
    manager: '[data-region="lch-column-manager"]',
    checkboxes: '[data-region="column-checkboxes"]',
    checkboxWrapper: '.form-check',
    toggle: '.lch-col-toggle',
    orderList: '[data-region="column-order"]',
    badge: '[data-col]',
    visibleCols: '[data-region="visiblecols"]',
    pills: '[data-region="category-pills"]',
    pill: '.lch-cat-pill',
    search: '[data-region="column-search"]',
    programSelector: '[data-region="program-selector"]',
    programIds: '[data-region="program-ids"]',
    confirmButtons: 'button[data-confirm], input[data-confirm]',
    dateInputs: 'input[data-lch-type="date"]',
    getOnlyInputs: 'input[name="sesskey"], input[name^="_qf__"]',
};

const CLASSES = {
    badge: 'badge badge-light border lch-col-badge',
    dragging: 'lch-dragging',
};

/**
 * Build a draggable badge for a visible column.
 *
 * @param {String} col The column name.
 * @param {String} label The column label.
 * @return {HTMLElement}
 */
const makeBadge = (col, label) => {
    const span = document.createElement('span');
    span.className = CLASSES.badge;
    span.setAttribute('draggable', 'true');
    span.dataset.col = col;
    span.textContent = `↕ ${label}`;
    return span;
};

/**
 * Wire the checkbox grid, the drag list and the hidden visiblecols input.
 *
 * @param {HTMLFormElement} form The filter form.
 * @return {Function} A function that copies the badge order into the hidden input.
 */
const initColumns = (form) => {
    const manager = form.querySelector(SELECTORS.manager);
    if (!manager) {
        return () => undefined;
    }
    const checks = manager.querySelector(SELECTORS.checkboxes);
    const list = manager.querySelector(SELECTORS.orderList);
    const hidden = manager.querySelector(SELECTORS.visibleCols);

    const syncHidden = () => {
        if (!list || !hidden) {
            return;
        }
        hidden.value = Array.from(list.querySelectorAll(SELECTORS.badge))
            .map((el) => el.dataset.col)
            .join(',');
    };

    if (checks && list) {
        checks.addEventListener('change', (e) => {
            const cb = e.target.closest(SELECTORS.toggle);
            if (!cb) {
                return;
            }
            const existing = Array.from(list.querySelectorAll(SELECTORS.badge))
                .find((el) => el.dataset.col === cb.dataset.col);
            if (cb.checked && !existing) {
                list.appendChild(makeBadge(cb.dataset.col, cb.dataset.label));
            } else if (!cb.checked && existing) {
                existing.remove();
            }
            syncHidden();
        });
    }

    if (list) {
        let dragging = null;
        list.addEventListener('dragstart', (e) => {
            dragging = e.target.closest(SELECTORS.badge);
            if (dragging) {
                dragging.classList.add(CLASSES.dragging);
                e.dataTransfer.effectAllowed = 'move';
            }
        });
        list.addEventListener('dragend', () => {
            if (dragging) {
                dragging.classList.remove(CLASSES.dragging);
            }
            dragging = null;
            syncHidden();
        });
        list.addEventListener('dragover', (e) => {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            const target = e.target.closest(SELECTORS.badge);
            if (!dragging || !target || target === dragging) {
                return;
            }
            const rect = target.getBoundingClientRect();
            const after = (e.clientX - rect.left) > rect.width / 2;
            list.insertBefore(dragging, after ? target.nextSibling : target);
        });
        list.addEventListener('drop', (e) => e.preventDefault());
    }

    initCategoryFilter(manager, checks);

    return syncHidden;
};

/**
 * Wire the optional category pills and search box that narrow the checkbox grid.
 *
 * @param {HTMLElement} manager The widget root.
 * @param {HTMLElement|null} checks The checkbox grid.
 */
const initCategoryFilter = (manager, checks) => {
    const search = manager.querySelector(SELECTORS.search);
    const pills = manager.querySelector(SELECTORS.pills);
    if (!checks || (!search && !pills)) {
        return;
    }
    let activeCat = '';

    const applyFilter = () => {
        const q = search ? search.value.trim().toLowerCase() : '';
        checks.querySelectorAll(SELECTORS.checkboxWrapper).forEach((wrap) => {
            const cb = wrap.querySelector(SELECTORS.toggle);
            if (!cb) {
                return;
            }
            const label = (cb.dataset.label || '').toLowerCase();
            const col = (cb.dataset.col || '').toLowerCase();
            const cat = wrap.dataset.category || '';
            const textHit = q === '' || label.includes(q) || col.includes(q);
            const catHit = activeCat === '' || cat === activeCat;
            wrap.hidden = !(textHit && catHit);
        });
    };

    if (search) {
        search.addEventListener('input', applyFilter);
    }

    if (pills) {
        pills.addEventListener('click', (e) => {
            const btn = e.target.closest(SELECTORS.pill);
            if (!btn) {
                return;
            }
            const cat = btn.dataset.cat || '';
            // Clicking the active category clears it, which lights the "All" pill again.
            activeCat = (activeCat === cat) ? '' : cat;
            pills.querySelectorAll(SELECTORS.pill).forEach((b) => {
                const isActive = (b.dataset.cat || '') === activeCat;
                b.classList.toggle('btn-secondary', isActive);
                b.classList.toggle('btn-outline-secondary', !isActive);
                b.classList.toggle('active', isActive);
                b.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });
            applyFilter();
        });
    }
};

/**
 * Wire the optional program multi-select to its hidden comma-separated input.
 *
 * @param {HTMLFormElement} form The filter form.
 * @return {Function} A function that copies the selection into the hidden input.
 */
const initProgramSelector = (form) => {
    const selector = form.querySelector(SELECTORS.programSelector);
    const hidden = form.querySelector(SELECTORS.programIds);
    if (!selector || !hidden) {
        return () => undefined;
    }
    const saved = (hidden.value || '').split(',').filter(Boolean);
    if (saved.length) {
        Array.from(selector.options).forEach((o) => {
            if (saved.includes(o.value)) {
                o.selected = true;
            }
        });
    }
    return () => {
        hidden.value = Array.from(selector.selectedOptions).map((o) => o.value).join(',');
    };
};

/**
 * Ask before a destructive layout button submits.
 *
 * The button carries its question in data-confirm; the form is submitted with
 * that button as the submitter once the user agrees, so its formmethod is kept.
 *
 * @param {HTMLFormElement} form The filter form.
 */
const initConfirmButtons = (form) => {
    form.querySelectorAll(SELECTORS.confirmButtons).forEach((button) => {
        button.addEventListener('click', (e) => {
            if (button.dataset.confirmed === '1') {
                delete button.dataset.confirmed;
                return;
            }
            e.preventDefault();
            Notification.saveCancelPromise(
                getString('confirm'),
                button.dataset.confirm,
                getString('continue'),
                {triggerElement: button}
            ).then(() => {
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit(button);
                } else {
                    button.dataset.confirmed = '1';
                    button.click();
                }
                return;
            }).catch(() => {
                // The user cancelled; nothing to do.
            });
        });
    });
};

/**
 * Upgrade the YYYY-MM-DD text filters to native date inputs.
 *
 * The form defines them as text so that the value format, and therefore every
 * existing URL, is unchanged; a date input reads and writes the same format.
 *
 * @param {HTMLFormElement} form The filter form.
 */
const initDateInputs = (form) => {
    form.querySelectorAll(SELECTORS.dateInputs).forEach((input) => {
        const value = input.value;
        input.type = 'date';
        if (input.type === 'date') {
            input.value = value;
            input.removeAttribute('placeholder');
        }
    });
};

/**
 * Keep a search (GET) submission to the filter parameters only.
 *
 * The layout buttons post, so they keep the session key and the submission
 * marker. A search is a bookmarkable URL, so the entries that only make sense
 * for one session are dropped from it, along with the button name and the
 * multi-select whose value the hidden program-ids input already carries.
 *
 * @param {HTMLFormElement} form The filter form.
 * @param {Function} syncColumns Copies the badge order into the hidden visiblecols input.
 * @param {Function} syncPrograms Copies the program selection into its hidden input.
 */
const initSubmit = (form, syncColumns, syncPrograms) => {
    let submitter = null;

    form.addEventListener('submit', (e) => {
        syncColumns();
        syncPrograms();
        submitter = e.submitter || null;
    });

    form.addEventListener('formdata', (e) => {
        const posting = !submitter || submitter.getAttribute('formmethod') === 'post';
        if (posting) {
            return;
        }
        form.querySelectorAll(SELECTORS.getOnlyInputs).forEach((input) => e.formData.delete(input.name));
        if (submitter.name) {
            e.formData.delete(submitter.name);
        }
        const selector = form.querySelector(SELECTORS.programSelector);
        if (selector && selector.name) {
            // The select itself (name[]) and the forced-submission hidden input
            // that Moodle adds beside every multi-select (name).
            e.formData.delete(selector.name);
            e.formData.delete(selector.name.replace(/\[\]$/, ''));
        }
    });
};

/**
 * Initialise the column manager and filter-form behaviour.
 *
 * @param {String} formId The id attribute of the filter form.
 */
export const init = (formId) => {
    const form = document.getElementById(formId);
    if (!form) {
        return;
    }
    const syncColumns = initColumns(form);
    const syncPrograms = initProgramSelector(form);
    initConfirmButtons(form);
    initDateInputs(form);
    initSubmit(form, syncColumns, syncPrograms);
};
