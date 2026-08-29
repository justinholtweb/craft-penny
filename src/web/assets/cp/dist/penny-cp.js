/*
 * Penny — control panel.
 *
 * Two jobs: the target repeater on the invite editor, and the invite's secondary actions.
 *
 * The secondary actions are posted from here rather than rendered as forms because the details
 * pane is *inside* Craft's page form. A nested <form> is invalid HTML: the parser drops the inner
 * tag and keeps its children, so a second action input ends up in the page form and Save runs
 * whichever action came last — which is how a Save button comes to delete things.
 */
(function() {
    var $ = window.jQuery;

    // --- secondary actions ------------------------------------------------

    function wireActions() {
        var container = document.querySelector('.penny-actions[data-invite-id]');

        if (!container) {
            return;
        }

        var inviteId = container.dataset.inviteId;

        container.querySelectorAll('.penny-action').forEach(function(button) {
            button.addEventListener('click', function() {
                var confirmMessage = button.dataset.confirm;

                if (confirmMessage && !window.confirm(confirmMessage)) {
                    return;
                }

                button.classList.add('loading');

                Craft.sendActionRequest('POST', button.dataset.action, {
                    data: { inviteId: inviteId }
                })
                    .then(function(response) {
                        // These actions all redirect, and the redirect is the point — a re-issued
                        // link has a "copy it now" screen waiting on the other side of it.
                        window.location.href = (response.data && response.data.redirect) ||
                            (response.request && response.request.responseURL) ||
                            window.location.href;
                    })
                    .catch(function(error) {
                        button.classList.remove('loading');
                        Craft.cp.displayError(
                            (error.response && error.response.data && error.response.data.message) ||
                            Craft.t('penny', 'Something went wrong.')
                        );
                    });
            });
        });
    }

    // --- the target repeater ----------------------------------------------

    function rowIndex(row) {
        return row.dataset.index;
    }

    function collectSelected(row) {
        return Array.prototype.map.call(
            row.querySelectorAll('input[name*="[layoutElementUids]"]:checked'),
            function(input) { return input.value; }
        );
    }

    /*
     * Read a row by input *name*, not by class.
     *
     * Craft's form macros decide for themselves whether a `class` lands on the field wrapper or on
     * the control, and a selector that guesses wrong returns an empty string rather than an error —
     * which is how "everything on this element" silently became "none of them" on every re-render.
     * The name attribute is the one thing this code and the server already have to agree on.
     */
    function readRow(row) {
        function value(suffix) {
            var el = row.querySelector('[name$="[' + suffix + ']"]');
            return el ? el.value : '';
        }

        /*
         * Craft's element select renders *two* inputs for the same thing: an empty placeholder
         * named `…[elementId]`, so that clearing the field posts something, and one named
         * `…[elementId][]` inside each chip carrying the actual id. Reading the first is how the
         * row came to think nothing had been chosen a moment after something was.
         */
        function chosenElementId() {
            var chip = row.querySelector('[name$="[elementId][]"]');
            return chip && chip.value ? chip.value : value('elementId');
        }

        return {
            index: rowIndex(row),
            kind: value('kind'),
            elementType: value('elementType'),
            sectionId: value('sectionId'),
            entryTypeId: value('entryTypeId'),
            elementId: chosenElementId(),
            scopeMode: value('scopeMode'),
            siteId: document.getElementById('penny-targets').dataset.siteId,
            selected: collectSelected(row)
        };
    }

    /**
     * Rebuilds part of a row from the server.
     *
     * `part` is either the whole body — when the kind or element type changes, because the picker
     * itself has to change — or just the scope half. Rebuilding the whole body when only the chosen
     * element changed would tear out the element select while Craft is still animating the new chip
     * into it, and the chip is left stranded on the page.
     */
    function refreshRow(row, part) {
        part = part || 'scope';

        /*
         * Let Craft finish first.
         *
         * Choosing an element kicks off an animation that flies a copy of the chip from the modal
         * into the field and then removes it. Rebuilding part of the row while that is in flight
         * strands the copy in the middle of the page — and reading the row that early gets the
         * chip's id before it has been written.
         */
        window.clearTimeout(row.pennyRefreshTimer);
        row.pennyRefreshTimer = window.setTimeout(function() { doRefresh(row, part); }, 350);
    }

    function doRefresh(row, part) {

        var selector = part === 'body' ? '.penny-target-body' : '.penny-target-scope';
        var container = row.querySelector(selector);
        var action = part === 'body' ? 'penny/invites/target-body' : 'penny/invites/target-scope';

        if (!container) {
            return;
        }

        container.classList.add('loading');

        Craft.sendActionRequest('POST', action, { data: readRow(row) })
            .then(function(response) {
                container.innerHTML = response.data.html;

                // Element selects, date pickers and the rest wire themselves up from these; without
                // them the markup is inert and nothing can be picked.
                if (response.data.headHtml) { Craft.appendHeadHtml(response.data.headHtml); }
                if (response.data.bodyHtml) { Craft.appendBodyHtml(response.data.bodyHtml); }

                Craft.initUiElements($(container));
                wireRow(row);
            })
            .catch(function() {
                Craft.cp.displayError(Craft.t('penny', 'Could not load that.'));
            })
            .finally(function() {
                container.classList.remove('loading');
            });
    }

    function wireRow(row) {
        // Kind and element type change what the picker *is*, so the whole body is rebuilt.
        row.querySelectorAll('[name$="[kind]"], [name$="[elementType]"]')
            .forEach(function(select) {
                if (select.dataset.pennyWired === '1') {
                    return;
                }

                select.dataset.pennyWired = '1';
                select.addEventListener('change', function() { refreshRow(row, 'body'); });
            });

        // A section change only changes which entry types and fields are on offer.
        row.querySelectorAll('[name$="[sectionId]"], [name$="[entryTypeId]"]')
            .forEach(function(select) {
                if (select.dataset.pennyWired === '1') {
                    return;
                }

                select.dataset.pennyWired = '1';
                select.addEventListener('change', function() { refreshRow(row, 'body'); });
            });

        // The scope checkboxes only need showing and hiding — the value that matters is the select,
        // and the checkboxes are ignored server-side when it says "everything".
        var scopeMode = row.querySelector('[name$="[scopeMode]"]');
        var scopeFields = row.querySelector('.penny-scope-fields');

        if (scopeMode && scopeFields && scopeMode.dataset.pennyWired !== '1') {
            scopeMode.dataset.pennyWired = '1';
            scopeMode.addEventListener('change', function() {
                scopeFields.classList.toggle('hidden', scopeMode.value === 'all');

                if (scopeMode.value === 'some' && !scopeFields.querySelector('input')) {
                    refreshRow(row, 'scope');
                }
            });
        }

        var removeButton = row.querySelector('.penny-remove-target');

        if (removeButton && removeButton.dataset.pennyWired !== '1') {
            removeButton.dataset.pennyWired = '1';
            removeButton.addEventListener('click', function() {
                var container = document.getElementById('penny-targets');

                if (container.querySelectorAll('.penny-target').length <= 1) {
                    Craft.cp.displayError(Craft.t('penny', 'An invite needs at least one target.'));
                    return;
                }

                row.remove();
            });
        }

        // An element being picked or cleared changes which fields exist, so the scope half has to
        // be rebuilt.
        //
        // Watched rather than listened for: Craft's element select does not fire a DOM `change` —
        // it triggers its own Garnish events on a JavaScript object, and the hidden inputs it
        // writes fire nothing at all. The one thing that is always true is that the chip list gains
        // or loses a child, which an observer can see without reaching into Craft's internals.
        var chipList = row.querySelector('.elementselect .elements');

        if (chipList && chipList.dataset.pennyWired !== '1' && typeof MutationObserver === 'function') {
            chipList.dataset.pennyWired = '1';

            new MutationObserver(function() {
                refreshRow(row, 'scope');
            }).observe(chipList, { childList: true });
        }
    }

    function wireRepeater() {
        var container = document.getElementById('penny-targets');

        if (!container) {
            return;
        }

        container.querySelectorAll('.penny-target').forEach(wireRow);

        var addButton = document.getElementById('penny-add-target');

        if (!addButton || addButton.disabled) {
            return;
        }

        addButton.addEventListener('click', function() {
            var rows = container.querySelectorAll('.penny-target');
            var last = rows[rows.length - 1];
            var nextIndex = rows.length;
            var clone = last.cloneNode(true);

            clone.dataset.index = nextIndex;

            // Re-key every input to the new row, and drop the row id so the clone is saved as a new
            // target rather than overwriting the one it was copied from.
            clone.querySelectorAll('[name]').forEach(function(input) {
                input.name = input.name.replace(/^targets\[\d+\]/, 'targets[' + nextIndex + ']');

                if (/\[id\]$/.test(input.name)) {
                    input.remove();
                }
            });

            clone.querySelectorAll('[data-penny-wired]').forEach(function(el) {
                delete el.dataset.pennyWired;
            });

            container.appendChild(clone);
            refreshRow(clone, 'body');
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        wireActions();
        wireRepeater();
    });
})();
