/*
 * The hosted invite page.
 *
 * Small on purpose: the fields on this page are real control panel fields and bring their own
 * behaviour. All that is left is the "save and finish later" button, which posts the same form to
 * a different action without leaving the page.
 */
(function() {
    /*
     * Hide anything another plugin puts on this page.
     *
     * This is a control panel request — it has to be, for the fields to work — so every plugin that
     * injects a widget, a banner or a nag into control panel pages injects it here too. The
     * recipient is a client, and "102 schema changes may need a permissions review" is not for
     * them.
     *
     * Most of those arrive from JavaScript rather than from the server, so a single sweep at load
     * misses them and an observer is needed. The observer is deliberately temporary: it stops at
     * the first interaction, or after a few seconds, because Craft's own modals, element pickers
     * and upload progress bars are also appended to `<body>` — and every one of those appears in
     * response to something the recipient did, which is always after this has stopped watching.
     */
    function hideForeignChrome() {
        Array.prototype.forEach.call(document.body.children, function(child) {
            if (child.classList.contains('penny-shell')) {
                return;
            }

            var tag = child.tagName;

            if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'NOSCRIPT' || tag === 'TEMPLATE' || tag === 'LINK') {
                return;
            }

            child.style.setProperty('display', 'none', 'important');
        });
    }

    function watchForForeignChrome() {
        hideForeignChrome();

        if (typeof MutationObserver !== 'function') {
            return;
        }

        var observer = new MutationObserver(hideForeignChrome);
        observer.observe(document.body, { childList: true });

        function stop() {
            observer.disconnect();
            document.removeEventListener('pointerdown', stop, true);
            document.removeEventListener('keydown', stop, true);
        }

        document.addEventListener('pointerdown', stop, true);
        document.addEventListener('keydown', stop, true);
        window.setTimeout(stop, 5000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', watchForForeignChrome);
    } else {
        watchForForeignChrome();
    }

    var form = document.getElementById('penny-form');
    var saveButton = document.getElementById('penny-save-progress');

    if (!form || !saveButton) {
        return;
    }

    function flash(message) {
        var el = document.createElement('div');
        el.className = 'penny-flash';
        el.textContent = message;
        document.body.appendChild(el);
        window.setTimeout(function() { el.remove(); }, 4000);
    }

    saveButton.addEventListener('click', function() {
        var data = new FormData(form);

        // Same form, different destination. Posting the real FormData rather than a hand-built
        // payload is what keeps file uploads and Matrix's nested naming working.
        data.set('action', 'penny/hosted/save-progress');

        saveButton.disabled = true;
        saveButton.dataset.label = saveButton.textContent;
        saveButton.textContent = saveButton.dataset.savingLabel || 'Saving…';

        fetch(window.location.href, {
            method: 'POST',
            body: data,
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        })
            .then(function(response) { return response.json().catch(function() { return {}; }); })
            .then(function(json) {
                flash(json.message || 'Saved.');
            })
            .catch(function() {
                flash('Could not save just now. Your answers are still on this page.');
            })
            .finally(function() {
                saveButton.disabled = false;
                saveButton.textContent = saveButton.dataset.label;
            });
    });
})();
