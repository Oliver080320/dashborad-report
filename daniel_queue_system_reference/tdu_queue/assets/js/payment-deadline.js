// Payment Deadline queue. Handles the collapsible call-history panel, the collapsible
// urgency sections, and the Log outcome modal, against ajax_log_payment_outcome.php.
//
// CSRF_TOKEN and AJAX_BASE are defined in an inline <script> by payment_deadline_view.php.
(function () {
    'use strict';

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // Own copy for the same reason buildHistTable below is one: this page cannot load
    // queue.js. Kept identical to the map there. The full list, not just the two payment
    // codes, because ajax_get_history.php returns every call ever logged on the quote.
    var HIST_OUTCOME_LABELS = {
        next_call:         'Next call scheduled',
        no_answer_email:   'No answer, email sent',
        interested:        'Interested',
        inbound_call:      'Inbound call',
        requote:           'Requote',
        accepted:          'Accepted',
        rejected:          'Rejected',
        lead_converted:    'Converted to quote',
        lead_rejected:     'Lead rejected',
        payment_no_answer: 'Payment: no answer',
        payment_next_call: 'Payment: next call scheduled',
        info_no_answer:    'No answer',
        info_contacted:    'Contacted, information still to come',
        info_received:     'Information received'
    };

    var HIST_CHANNEL_LABELS = { phone: 'Phone', email: 'Email', whatsapp: 'WhatsApp', other: 'Other' };

    function histLabel(map, code) {
        return map[code] || code;
    }

    // Own compact copy rather than importing from queue.js: that file expects a set of
    // globals this page never defines, and the two pages are deliberately independent
    // (same separation monitoring keeps from the queue).
    function buildHistTable(history) {
        if (!history || !history.length) {
            return '<em class="pd-hist-none">No calls logged against this quote yet.</em>';
        }
        var rows = history.map(function (h) {
            return '<tr>'
                + '<td>' + (h.call_date   ? esc(h.call_date)   : '&mdash;') + '</td>'
                + '<td>' + (h.notes       ? esc(h.notes)       : '<em style="color:#bbb">no notes</em>') + '</td>'
                + '<td>' + (h.created_by  ? esc(h.created_by)  : '&mdash;') + '</td>'
                + '<td>' + (h.outcome     ? esc(histLabel(HIST_OUTCOME_LABELS, h.outcome)) : '&mdash;') + '</td>'
                + '<td>' + (h.channel     ? esc(histLabel(HIST_CHANNEL_LABELS, h.channel)) : '&mdash;') + '</td>'
                + '<td>' + (h.follow_date ? esc(h.follow_date) : '&mdash;') + '</td>'
                + '</tr>';
        }).join('');
        return '<table class="pd-hist-table"><thead><tr>'
            + '<th>Called on</th><th>Description</th><th>Agent</th>'
            + '<th>Outcome</th><th>Channel</th><th>Next call</th>'
            + '</tr></thead><tbody>' + rows + '</tbody></table>';
    }

    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest ? ev.target.closest('.pd-hist-btn') : null;
        if (!btn) return;

        var quoteid = btn.getAttribute('data-quoteid');
        var row     = document.getElementById('pd-hist-' + quoteid);
        if (!row) return;
        var cell = row.querySelector('.pd-hist-cell');

        // Toggle closed if already open.
        if (row.style.display !== 'none') {
            row.style.display = 'none';
            btn.textContent   = 'History';
            return;
        }

        row.style.display = '';
        btn.textContent   = 'Hide history';

        // Fetch once, then reuse — history is read-only on this page, so there is
        // nothing that can make it stale while it sits open.
        if (cell.getAttribute('data-loaded') === '1') return;
        cell.innerHTML = '<em class="pd-hist-none">Loading…</em>';

        fetch(AJAX_BASE + '/ajax_get_history.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': CSRF_TOKEN
            },
            body: 'quoteid=' + encodeURIComponent(quoteid)
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res || !res.success) {
                cell.innerHTML = '<em class="pd-hist-none">Could not load history.</em>';
                return;
            }
            cell.innerHTML = buildHistTable(res.history);
            cell.setAttribute('data-loaded', '1');
        })
        .catch(function () {
            cell.innerHTML = '<em class="pd-hist-none">Could not load history.</em>';
        });
    });

    // ── Collapsible urgency sections ────────────────────────────────────────
    // Same accordion pattern as queue.js's .section-toggle handler, own copy since this
    // page doesn't load queue.js.
    document.addEventListener('click', function (ev) {
        var h = ev.target.closest ? ev.target.closest('.pd-section-toggle') : null;
        if (!h) return;
        var section = h.closest('.pd-section');
        if (section) section.classList.toggle('collapsed');
    });

    // ── Log outcome modal ───────────────────────────────────────────────────
    var modal        = document.getElementById('pd-outcome-modal');
    var form         = document.getElementById('pd-outcome-form');
    var outcomeSel    = document.getElementById('pd-modal-outcome');
    var dateRow       = document.getElementById('pd-modal-date-row');
    var followupInput = document.getElementById('pd-modal-followup-id');
    var noAnswerHint  = document.getElementById('pd-modal-noanswer-hint');
    var notesArea     = document.getElementById('pd-modal-notes');

    // Same Flatpickr-with-time widget as the Follow-up queue's #modal-next-date
    // (queue.js's dateFlatpickr) — date + time in one field. No day-load colouring here
    // (this queue has no daily cap, unlike Follow-up's MAX_SCHEDULED_PER_DAY_PER_CALLER),
    // so no onDayCreate badges, just the picker plus the Sunday block below.
    var pdDateFlatpickr = flatpickr('#pd-modal-next-call-date', {
        dateFormat: 'Y-m-d\\TH:i',
        altInput: true,
        altFormat: 'd/m/Y h:i K',
        enableTime: true,
        minuteIncrement: 1,
        minDate: PD_MIN_CALL_DATE,
        static: true,
        locale: { firstDayOfWeek: 1 },
        // The team doesn't work Sundays. Server-side rejected too
        // (ajax_log_payment_outcome.php), this just stops the caller picking one.
        disable: [function (date) { return date.getDay() === 0; }],
        plugins: [new confirmDatePlugin({})]
    });

    // altInput:true hides the real input and validates the visible altInput instead —
    // required must be set on both, same pattern as queue.js's setDateRequired().
    function setPdDateRequired(required) {
        document.getElementById('pd-modal-next-call-date').required = required;
        if (pdDateFlatpickr.altInput) pdDateFlatpickr.altInput.required = required;
    }

    // Only 'payment_next_call' carries a date — 'payment_no_answer' snoozes to tomorrow
    // automatically server-side, no input needed (hence the hint instead of a field).
    // Refresh defaultHour/defaultMinute to "now" BEFORE clear() — clear() is what
    // actually pushes them into the visible time spinner (enableTime's own behaviour),
    // so the caller sees "now" already dialled in the moment they pick a date, and can
    // freely edit it from there. Same fix/reasoning as queue.js's modal-outcome handler.
    function syncDateRow() {
        var needsDate = (outcomeSel.value === 'payment_next_call');
        dateRow.style.display = needsDate ? '' : 'none';

        var now = new Date();
        pdDateFlatpickr.set('defaultHour', now.getHours());
        pdDateFlatpickr.set('defaultMinute', now.getMinutes());
        setPdDateRequired(needsDate);
        pdDateFlatpickr.clear();

        noAnswerHint.style.display = (outcomeSel.value === 'payment_no_answer') ? '' : 'none';
    }

    // Notes are required (server-enforced too, see ajax_log_payment_outcome.php) — this
    // gives 'No answer' a starting point so the caller isn't stuck typing the same thing
    // every time, while staying fully editable. Only fills an EMPTY field, so it never
    // overwrites a real note already there (editing a past call, or the caller's own
    // typing) — and only auto-clears itself again if it's still exactly what we put
    // there, so an edited note is never silently wiped by switching outcomes.
    var NO_ANSWER_SUGGESTED_NOTE = 'Called, no answer.';
    var noAnswerAutofilled = false;

    function syncNotesSuggestion() {
        if (outcomeSel.value === 'payment_no_answer') {
            if (notesArea.value.trim() === '') {
                notesArea.value = NO_ANSWER_SUGGESTED_NOTE;
                noAnswerAutofilled = true;
            }
        } else if (noAnswerAutofilled && notesArea.value === NO_ANSWER_SUGGESTED_NOTE) {
            notesArea.value = '';
            noAnswerAutofilled = false;
        }
    }

    notesArea.addEventListener('input', function () {
        if (notesArea.value !== NO_ANSWER_SUGGESTED_NOTE) noAnswerAutofilled = false;
    });

    // Calendar can't offer a date on/after the trip — the server already rejects that
    // (ajax_log_payment_outcome.php), this just stops the caller from picking one in the
    // first place. One day before the trip date. Format from local components, not
    // toISOString() — that converts to UTC and can shift the date by a day depending on
    // the viewer's timezone (same fix already applied in queue.js's dayBefore()).
    function tripDateMax(tripDateStr) {
        if (!tripDateStr) return '';
        var d = new Date(tripDateStr + 'T00:00:00');
        d.setDate(d.getDate() - 1);
        var y   = d.getFullYear();
        var m   = String(d.getMonth() + 1).padStart(2, '0');
        var day = String(d.getDate()).padStart(2, '0');
        return y + '-' + m + '-' + day;
    }

    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest ? ev.target.closest('.pd-log-btn') : null;
        if (!btn) return;

        form.reset();
        noAnswerAutofilled = false;
        followupInput.value = btn.getAttribute('data-followup-id') || '';
        document.getElementById('pd-modal-quoteid').value  = btn.getAttribute('data-quoteid');
        document.getElementById('pd-modal-quote-no').textContent = btn.getAttribute('data-quoteno') || '';
        // Cap the calendar at this quote's own trip date, same as queue.js's per-row
        // maxDate re-set — a single shared Flatpickr instance, reconfigured on every open.
        pdDateFlatpickr.set('maxDate', tripDateMax(btn.getAttribute('data-tripdate')) + 'T23:59');

        // Editing an already-pending row — prefill from its last logged call so
        // correcting it updates that call in place rather than logging a new one.
        // Channel/notes set BEFORE the outcome dispatch below (same order queue.js
        // uses) so syncNotesSuggestion() sees the real prior note first and only fills
        // the suggested text for a genuinely blank one.
        var prevOutcome = btn.getAttribute('data-outcome');
        document.getElementById('pd-modal-channel').value = btn.getAttribute('data-channel') || 'phone';
        notesArea.value = btn.getAttribute('data-notes') || '';
        outcomeSel.value = prevOutcome || '';
        // Fires the same 'change' handling a manual outcome switch would (shows/hides
        // the date row, resets+re-defaults the time-of-day, fills the note suggestion).
        outcomeSel.dispatchEvent(new Event('change'));
        // Restore the real prior date last, overriding syncDateRow()'s clear() above —
        // only a date string is given, so Flatpickr fills the time from the
        // defaultHour/defaultMinute ("now") that dispatch just set, same as queue.js.
        if (prevOutcome && btn.getAttribute('data-date')) {
            pdDateFlatpickr.setDate(btn.getAttribute('data-date'), false);
        }

        modal.querySelector('button[type="submit"]').disabled = false;
        modal.showModal();
    });

    document.getElementById('pd-modal-cancel').addEventListener('click', function () {
        modal.close();
    });

    outcomeSel.addEventListener('change', function () {
        syncDateRow();
        syncNotesSuggestion();
    });

    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var saveBtn = form.querySelector('button[type="submit"]');
        saveBtn.disabled = true;

        fetch(AJAX_BASE + '/ajax_log_payment_outcome.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': CSRF_TOKEN },
            body: new FormData(form)
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res.success) {
                modal.close();
                // Reload — the row's escalate/pending badges and call count all depend
                // on server-side computation there is no lightweight way to patch client-side.
                location.reload();
            } else {
                alert(res.message || 'Could not save outcome.');
                saveBtn.disabled = false;
            }
        })
        .catch(function () {
            alert('Network error.');
            saveBtn.disabled = false;
        });
    });
})();
