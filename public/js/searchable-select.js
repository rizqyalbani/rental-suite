/**
 * Turns a plain <select data-searchable-select> into a type-to-filter combobox
 * — the Chart of Accounts list is long enough (30+ entries) that scrolling a
 * native dropdown to find one account is real friction. Progressive
 * enhancement, not a replacement: the original <select> stays in the DOM
 * (visually hidden, not display:none, so its own `required` validation still
 * fires) and remains the actual value the form submits — every existing
 * @change listener, edit-modal populate function, and tour pre-fill script
 * that reads/writes `select.value` keeps working unchanged, as long as a
 * `change` event is dispatched after a programmatic `.value =` assignment
 * (native user interaction fires this on its own; code that sets the value
 * directly must dispatch it itself — see the call sites in expenses/
 * cash-movements/accounts/*.blade.php and the accounting tour partials).
 */
(function () {
    function options(select) {
        // Every literal <option> is a real, pickable choice — including a
        // blank-value one, since its meaning varies by select ("— Select —"
        // as a required-field prompt vs. General Ledger's "All accounts" as a
        // genuine "show everything" filter the user must be able to pick
        // again after choosing a specific account).
        return Array.prototype.slice.call(select.options);
    }

    function currentLabel(select) {
        var opt = select.options[select.selectedIndex];
        return opt ? opt.textContent : '';
    }

    function makeSearchable(select) {
        if (!select || select.dataset.searchableBound) return;
        select.dataset.searchableBound = '1';

        // Captured before sr-only is added below, so the visible input
        // inherits whatever sizing the page gave this select (a compact
        // filter bar's "form-select text-sm" vs. a full-width form's
        // "form-select w-full") instead of a one-size-fits-all default.
        var originalClass = select.className;

        var wrapper = document.createElement('div');
        wrapper.className = 'relative';
        select.parentNode.insertBefore(wrapper, select);
        wrapper.appendChild(select);

        // sr-only (not display:none) keeps the select focusable enough for
        // native `required` validation to still find and report on it.
        select.classList.add('sr-only');
        select.setAttribute('tabindex', '-1');

        var input = document.createElement('input');
        input.type = 'text';
        input.autocomplete = 'off';
        input.placeholder = select.dataset.searchablePlaceholder || 'Search accounts...';
        input.className = originalClass || 'form-select w-full';
        wrapper.appendChild(input);

        var dropdown = document.createElement('div');
        dropdown.className = 'absolute z-20 mt-1 w-full max-h-56 overflow-y-auto rounded-md border border-gray-200 bg-white shadow-lg hidden';
        wrapper.appendChild(dropdown);

        var filtered = [];
        var highlighted = -1;

        function render(text) {
            var needle = (text || '').toLowerCase();
            filtered = options(select).filter(function (o) {
                return o.textContent.toLowerCase().indexOf(needle) !== -1;
            });
            dropdown.innerHTML = '';
            if (!filtered.length) {
                var empty = document.createElement('div');
                empty.className = 'px-3 py-2 text-sm text-gray-400';
                empty.textContent = 'No matching accounts.';
                dropdown.appendChild(empty);
            } else {
                filtered.forEach(function (o, idx) {
                    var item = document.createElement('div');
                    item.className = 'px-3 py-2 text-sm cursor-pointer hover:bg-indigo-50'
                        + (idx === highlighted ? ' bg-indigo-50' : '');
                    item.textContent = o.textContent;
                    // mousedown (not click) fires before the input's blur,
                    // so the dropdown is still open and this handler runs
                    // before blur's close() would otherwise wipe it out.
                    item.addEventListener('mousedown', function (e) {
                        e.preventDefault();
                        selectOption(o);
                    });
                    dropdown.appendChild(item);
                });
            }
            dropdown.classList.remove('hidden');
        }

        function selectOption(option) {
            select.value = option.value;
            input.value = option.textContent;
            dropdown.classList.add('hidden');
            highlighted = -1;
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function close() {
            dropdown.classList.add('hidden');
            // Revert to the real selection's label — covers a user who
            // typed to filter, then clicked away without picking anything.
            input.value = currentLabel(select);
            highlighted = -1;
        }

        input.value = currentLabel(select);
        // Clicking in clears the current label immediately (rather than
        // leaving it sitting there to be deleted by hand) so the full list
        // shows right away and typing starts a fresh search. blur's close()
        // below restores the real selection's label if nothing gets picked.
        input.addEventListener('focus', function () { input.value = ''; render(''); });
        input.addEventListener('input', function () { highlighted = -1; render(input.value); });
        input.addEventListener('keydown', function (e) {
            if (dropdown.classList.contains('hidden')) {
                if (e.key === 'ArrowDown' || e.key === 'ArrowUp') render(input.value);
                return;
            }
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                highlighted = Math.min(highlighted + 1, filtered.length - 1);
                render(input.value);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                highlighted = Math.max(highlighted - 1, 0);
                render(input.value);
            } else if (e.key === 'Enter') {
                if (highlighted >= 0 && filtered[highlighted]) {
                    e.preventDefault();
                    selectOption(filtered[highlighted]);
                }
            } else if (e.key === 'Escape') {
                close();
            }
        });
        // Delayed so a dropdown item's mousedown (which needs the dropdown
        // still open) resolves before blur's close() hides it.
        input.addEventListener('blur', function () { setTimeout(close, 100); });

        // External code that sets select.value directly (edit-modal
        // populate functions, tour pre-fill scripts) must dispatch a
        // `change` event afterward for this label to catch up — same as a
        // real user selection does automatically.
        select.addEventListener('change', function () { input.value = currentLabel(select); });
    }

    function bindAll(root) {
        (root || document).querySelectorAll('[data-searchable-select]').forEach(makeSearchable);
    }

    document.addEventListener('DOMContentLoaded', function () { bindAll(document); });
    window.rentalBindSearchableSelects = bindAll;
})();
