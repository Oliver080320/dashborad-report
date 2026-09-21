var activeBtn = null;

// Schedule counts for the open modal's owning caller, keyed 'YYYY-MM-DD' => count.
// Refreshed by fetchScheduleCounts() on modal open; missing key means 0.
var scheduleCounts = {};

function ymd(dateObj) {
    var y = dateObj.getFullYear();
    var m = String(dateObj.getMonth() + 1).padStart(2, '0');
    var d = String(dateObj.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + d;
}

function countForDate(dateObj) {
    return scheduleCounts[ymd(dateObj)] || 0;
}

// Single source of truth for "can't be scheduled" — used by both the flatpickr
// disable config and nextAvailableDate()'s forward search.
// SCHEDULE_MAX <= 0 is the "no cap" sentinel (Leads today): a day is only unavailable for
// being a Sunday, never for load.
function isDateUnavailable(dateObj) {
    return (SCHEDULE_MAX > 0 && countForDate(dateObj) >= SCHEDULE_MAX) || dateObj.getDay() === 0; // Sundays are not a working day
}

function scheduleWarnThreshold() {
    return Math.ceil(SCHEDULE_MAX * SCHEDULE_WARN_RATIO);
}

function fetchScheduleCounts(quoteid, followupId) {
    var fd = new FormData();
    fd.append('quoteid', quoteid);
    fd.append('followup_id', followupId || '');

    return fetch(AJAX_BASE + '/ajax_get_schedule_counts.php', { method: 'POST', headers: { 'X-CSRF-Token': CSRF_TOKEN }, body: fd })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            scheduleCounts = (res.success && res.counts) ? res.counts : {};
            // res.max can legitimately be 0 (Leads' "no cap" sentinel), so check presence,
            // not truthiness, or a 0 response would silently fail to overwrite a stale value.
            if (res.success && res.max !== undefined) SCHEDULE_MAX = res.max;
            if (dateFlatpickr) dateFlatpickr.redraw();
        })
        .catch(function () { scheduleCounts = {}; });
}

// Colours each in-range calendar day by load and stamps an "N/MAX" badge under the day
// number. Out-of-range days (adjacent-month overflow) are skipped. SCHEDULE_MAX <= 0 is the
// "no cap" sentinel (Leads today): every day renders green with a plain count.
function onScheduleDayCreate(dObj, dStr, fp, dayElem) {
    if (dayElem.dateObj < fp.config.minDate || dayElem.dateObj > fp.config.maxDate) return;

    var noCap    = SCHEDULE_MAX <= 0;
    // Sundays get no colour/badge — not a working day regardless of load.
    var isSunday = dayElem.dateObj.getDay() === 0;
    var count    = countForDate(dayElem.dateObj);
    var state    = isSunday ? ''
                 : noCap ? 'tdu-day-safe'
                 : (count >= SCHEDULE_MAX) ? 'tdu-day-full'
                 : (count >= scheduleWarnThreshold()) ? 'tdu-day-warn' : 'tdu-day-safe';

    // Wrap the day number in its own span so colour can be scoped to just the number,
    // not the whole (taller, badge-holding) cell.
    var numSpan = document.createElement('span');
    numSpan.className = 'tdu-day-num' + (state ? ' ' + state : '');
    numSpan.textContent = dayElem.textContent;
    dayElem.textContent = '';
    dayElem.appendChild(numSpan);

    if (isSunday) return;

    var badge = document.createElement('span');
    badge.className = 'tdu-day-count' + (state ? ' ' + state : '');
    badge.textContent = noCap ? (count + ' scheduled') : (count + '/' + SCHEDULE_MAX);
    dayElem.appendChild(badge);
}

var dateFlatpickr = flatpickr('#modal-next-date', {
    // ISO-like shape ajax_log_outcome.php's strtotime() parses reliably; altFormat
    // below is just what the agent sees and doesn't affect submission.
    dateFormat: 'Y-m-d\\TH:i',
    altInput: true,
    altFormat: 'd/m/Y h:i K',
    enableTime: true,
    // Refreshed live to "now" in the modal-outcome change listener below — this is
    // just the initial placeholder before the modal is first opened.
    defaultHour: QUEUE_DEFAULT_HOUR,
    defaultMinute: QUEUE_DEFAULT_MINUTE,
    // Default increment of 5 makes the minute spinner HTML5-invalid whenever the
    // current minute isn't a multiple of 5, which silently aborts form submit once
    // the (now-hidden) spinner can't be focused to show the native error. 1 avoids it.
    minuteIncrement: 1,
    minDate: QUEUE_MIN_DATE,
    // Explicit T23:59 (not bare Y-m-d): flatpickr clamps defaultHour to maxDate's
    // hour, and a bare date parses to midnight — without this the preselected time
    // was always forced to 12:00 AM. Day-selectability is unaffected either way.
    // Initial value only: every open re-sets it via windowMaxFor(), so a Lead gets the
    // shorter window even on the Follow-up page.
    maxDate: QUEUE_MAX_DATE + 'T23:59',
    // Renders inline inside the <dialog>'s own subtree instead of using flatpickr's
    // viewport-relative positioning, which broke against the position:fixed dialog.
    static: true,
    locale: { firstDayOfWeek: 1 }, // weeks start Monday
    plugins: [new confirmDatePlugin({})], // Done button — stops the calendar auto-closing on day click
    onDayCreate: [onScheduleDayCreate],
    onChange: function () { updateDateAvailability(); },
    disable: [isDateUnavailable]
});

// Same fix as minuteIncrement above: if the hour/minute/second spinners ever hold
// an out-of-range value, swallow the browser's 'invalid' event so a hidden/unfocusable
// control can't silently abort the whole submit.
[dateFlatpickr.hourElement, dateFlatpickr.minuteElement, dateFlatpickr.secondElement]
    .filter(Boolean)
    .forEach(function (el) { el.addEventListener('invalid', function (e) { e.preventDefault(); }); });

// Opens the modal for a trigger button: one carrying data-followup-id edits that call, one
// without it logs a fresh call. Shared so "+ Inbound" can't drift from the Log/Done button.
//
// opts.blank forces a fresh call even when the trigger has a saved one, used when Search
// Quote passes the row's own Done button (so submit updates the right row) without inheriting
// its edit meaning. opts.inboundOnly narrows to a dateless inbound call (applyInboundOnly())
// and implies blank.
function openOutcomeModal(btn, opts) {
    var inboundOnly = !!(opts && opts.inboundOnly);
    var blank       = inboundOnly || !!(opts && opts.blank);
    activeBtn = btn;

    // Cap the calendar at this quote's own trip date (never past it), falling back to its
    // queue's scheduling window for rows with no trip date on record.
    // Re-set on every open since dateFlatpickr is a single shared instance.
    var tripDate     = btn.dataset.trip || '';
    var windowMax    = windowMaxFor(btn.dataset.stage || '');
    var effectiveMax = (tripDate && dayBefore(tripDate) < windowMax) ? dayBefore(tripDate) : windowMax;
    dateFlatpickr.set('maxDate', effectiveMax + 'T23:59');

    var editingId = blank ? '' : (btn.dataset.followupId || '');

    document.getElementById('modal-quoteid').value          = btn.dataset.quoteid;
    document.getElementById('modal-followup-id').value      = editingId;
    document.getElementById('modal-quote-no').textContent   = btn.dataset.quoteno;
    setDateRequired(false);
    document.getElementById('modal-notes').value            = blank ? '' : (btn.dataset.notes || '');

    applyStageMode(btn.dataset.stage || '');

    document.getElementById('modal-accepted-warning').style.display = 'none';

    scheduleCounts = {};
    // The exclusion only applies to an edit, which updates the existing reminder in place.
    // A fresh call closes that reminder and writes its own, so nothing is excluded.
    fetchScheduleCounts(btn.dataset.quoteid, editingId);

    applyInboundOnly(inboundOnly);

    var outcomeSel = document.getElementById('modal-outcome');
    if (inboundOnly) {
        // applyInboundOnly() already selected the only outcome this control offers.
    } else if (!blank && btn.dataset.outcome) {
        // Editing a logged outcome — restore outcome, then date + channel.
        // Fire 'change' so the existing handler shows the date row and sets the
        // required flag / default channel, then override with the saved values.
        outcomeSel.value = btn.dataset.outcome;
        outcomeSel.dispatchEvent(new Event('change'));
        if (btn.dataset.date)    dateFlatpickr.setDate(btn.dataset.date, false);
        if (btn.dataset.channel) document.getElementById('modal-channel').value = btn.dataset.channel;
    } else {
        // Fresh log — reset all dependent rows via the change handler.
        outcomeSel.value = '';
        outcomeSel.dispatchEvent(new Event('change'));
    }
    updateDateAvailability();

    document.querySelector('#outcome-modal button[type="submit"]').disabled = false;
    document.getElementById('outcome-modal').showModal();
}

// Adds "+ Inbound" once the first call of the day promotes a row to Done, so a third call
// doesn't need a reload (mirrors what queue_view.php renders server-side). No-op if the row
// already has one; never runs for the read-only viewer (no Log button to reach it from).
function addCallButtonFor(stateBtn) {
    if (IS_READONLY) return;
    var row = stateBtn.closest('tr');
    if (!row || row.querySelector('.addcall-btn')) return;

    var btn = document.createElement('button');
    btn.type      = 'button';
    btn.className = 'addcall-btn';
    btn.title     = 'Record another call the client made on this quote';
    btn.textContent = '+ Inbound';
    btn.style.cssText = 'font-size:0.78rem;padding:2px 8px;cursor:pointer;border:1px solid #aaa;border-radius:3px;margin-left:4px;';
    btn.dataset.quoteid = stateBtn.dataset.quoteid || '';
    btn.dataset.quoteno = stateBtn.dataset.quoteno || '';
    btn.dataset.stage   = stateBtn.dataset.stage   || '';
    btn.dataset.trip    = stateBtn.dataset.trip    || '';
    stateBtn.insertAdjacentElement('afterend', btn);
}

document.querySelectorAll('.log-btn').forEach(function (btn) {
    btn.addEventListener('click', function () { openOutcomeModal(this); });
});

// Delegated, unlike .log-btn above: the "+ Inbound" button can also be created after load, by
// the submit handler promoting a row to its worked state, and a bind-on-load pass would miss it.
document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('.addcall-btn') : null;
    if (btn) openOutcomeModal(btn, { inboundOnly: true });
});

// The only stages the queue can act on: a Lead can be converted or rejected, a Created or
// Requote quote can be followed up or closed. Anything else (Accepted, Delivered, Rejected…)
// sits outside every cohort, so no outcome applies and the server refuses the close anyway.
function stageCanLog(stage) {
    return stage === 'Lead' || stage === 'Created' || stage === 'Requote';
}

// Furthest date a callback can be scheduled on for this quote. Leads get a shorter window than
// Follow-up, and it follows the quote's stage rather than the page, since Search Quote can open
// a Lead from the Follow-up queue. The quote's own trip date can still cut it shorter.
function windowMaxFor(stage) {
    return stage === 'Lead' ? LEAD_MAX_DATE : QUEUE_MAX_DATE;
}

// Which closing outcomes the modal offers is decided by the quote's own stage, not by which
// queue page this is: Search Quote can open a Lead from Follow-up and a quote from Leads.
// Must run before any restore of a previously logged outcome, since it clears the selection.
function applyStageMode(stage) {
    var isLead     = (stage === 'Lead');
    var isFollowup = (stage === 'Created' || stage === 'Requote');

    document.querySelectorAll('#modal-outcome .oc-lead')
        .forEach(function (o) { o.hidden = !isLead; });
    document.querySelectorAll('#modal-outcome .oc-followup')
        .forEach(function (o) { o.hidden = !isFollowup; });
    // Requote is narrower than the rest of its group: only a Created quote can be requoted.
    var requoteOpt = document.getElementById('outcome-opt-requote');
    if (requoteOpt) requoteOpt.hidden = (stage !== 'Created');

    document.querySelectorAll('#modal-reject-reason .rr-lead')
        .forEach(function (g) { g.hidden = !isLead; });
    document.querySelectorAll('#modal-reject-reason .rr-followup')
        .forEach(function (g) { g.hidden = !isFollowup; });

    // Hiding an <option> does not deselect it. Without this, picking "Reject lead", cancelling,
    // then opening a Created quote would still post lead_rejected from a blank-looking select.
    var outcomeSel = document.getElementById('modal-outcome');
    var chosen     = outcomeSel.selectedOptions[0];
    if (chosen && chosen.hidden) outcomeSel.value = '';
    // Same hazard one level down: hiding an <optgroup> leaves its selected option selected.
    document.getElementById('modal-reject-reason').value = '';

    return stageCanLog(stage);
}

// "+ Inbound" only records that the client rang; everything else that changes what the quote
// owes stays behind the row's edit button. Keeping "+ Inbound" always dateless also means no
// UI path can create two dated calls in one day, so queue_builder.php's next_date COALESCE
// fallback never has more than one open reminder to pick between.
//
// Restoring runs unconditionally first: the outcome select is one shared instance, so an
// inbound-only open would otherwise hide the other outcomes for the rest of the page's life.
function applyInboundOnly(on) {
    var sel       = document.getElementById('modal-outcome');
    var staticEl  = document.getElementById('modal-outcome-static');
    ['next_call', 'no_answer_email', 'interested'].forEach(function (v) {
        var o = sel.querySelector('option[value="' + v + '"]');
        if (o) o.hidden = false;
    });
    sel.style.display      = on ? 'none' : '';
    if (staticEl) staticEl.style.display = on ? '' : 'none';
    if (!on) return;

    sel.querySelectorAll('option').forEach(function (o) {
        if (o.value && o.value !== 'inbound_call') o.hidden = true;
    });
    // Hiding an option does not deselect it, so the pick has to come after the hiding. The
    // <select> itself stays hidden rather than removed, so this value still posts via FormData.
    sel.value = 'inbound_call';
    sel.dispatchEvent(new Event('change'));

    // The change handler shows the date row (a normal inbound call may carry one); this
    // control never schedules anything, so the field is cleared and hidden instead of left
    // optional.
    dateFlatpickr.clear();
    document.getElementById('modal-next-date').value = '';
    setDateRequired(false);
    document.getElementById('modal-date-row').style.display = 'none';
}

// altInput:true hides the real input and validates the visible altInput instead —
// `required` must be set on both.
function setDateRequired(required) {
    document.getElementById('modal-next-date').required = required;
    if (dateFlatpickr.altInput) dateFlatpickr.altInput.required = required;
}

// Cool-down defaults (locked values, days until quote returns to queue)
var COOLDOWN_DAYS = { next_call: 0, no_answer_email: 1, interested: 2 };

function addDays(days) {
    var d = new Date(QUEUE_TODAY + 'T00:00:00');
    d.setDate(d.getDate() + days);
    // Format from local components, not toISOString() — that converts to UTC and
    // can shift the date by a day depending on the viewer's timezone.
    var y   = d.getFullYear();
    var m   = String(d.getMonth() + 1).padStart(2, '0');
    var day = String(d.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + day + 'T09:00';
}

// One day before an arbitrary 'Y-m-d' date string (a quote's trip date), for the
// per-quote calendar cap below — same local-component formatting as addDays()
// above, for the same reason (avoid a UTC-conversion day shift).
function dayBefore(dateStr) {
    var d = new Date(dateStr + 'T00:00:00');
    d.setDate(d.getDate() - 1);
    var y   = d.getFullYear();
    var m   = String(d.getMonth() + 1).padStart(2, '0');
    var day = String(d.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + day;
}

// setDate() silently drops a date that fails isDateUnavailable() instead of erroring,
// so an auto-suggested cooldown date landing on a full/Sunday day left the field
// blank. Walks forward, preserving time-of-day, to the next selectable day.
function nextAvailableDate(startStr) {
    var time    = startStr.slice(10); // 'THH:mm'
    var d       = new Date(startStr);
    var maxDate = dateFlatpickr.config.maxDate;
    for (var i = 0; i < 100 && d <= maxDate; i++) {
        if (!isDateUnavailable(d)) {
            var y   = d.getFullYear();
            var m   = String(d.getMonth() + 1).padStart(2, '0');
            var day = String(d.getDate()).padStart(2, '0');
            return y + '-' + m + '-' + day + time;
        }
        d.setDate(d.getDate() + 1);
    }
    return startStr; // no selectable day found in range — fall back to the naive default
}

// Live preview of the daily schedule cap (MAX_SCHEDULED_PER_DAY_PER_CALLER) for the
// currently selected date, so the agent sees remaining slots before trying to save.
function updateDateAvailability() {
    var span      = document.getElementById('modal-date-availability');
    var dateRow   = document.getElementById('modal-date-row');
    var dateInput = document.getElementById('modal-next-date');

    if (dateRow.style.display === 'none' || !dateInput.value) {
        span.textContent = '';
        return;
    }

    var fd = new FormData();
    fd.append('quoteid', document.getElementById('modal-quoteid').value);
    fd.append('followup_id', document.getElementById('modal-followup-id').value);
    fd.append('date', dateInput.value);

    fetch(AJAX_BASE + '/ajax_check_schedule_availability.php', { method: 'POST', headers: { 'X-CSRF-Token': CSRF_TOKEN }, body: fd })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res.success) { span.textContent = ''; return; }
            // res.max <= 0 is the "no cap" sentinel (Leads today).
            var text = (res.max > 0) ? (res.count + '/' + res.max + ' scheduled') : (res.count + ' scheduled');
            if (res.full && res.next_available_date) {
                text += ' — next available: ' + res.next_available_date;
            }
            span.textContent = text;
            span.style.color = res.full ? '#c0392b' : '#666';
        })
        .catch(function () { span.textContent = ''; });
}

document.getElementById('modal-outcome').addEventListener('change', function () {
    var outcome      = this.value;
    var dateRow      = document.getElementById('modal-date-row');
    var dateInput    = document.getElementById('modal-next-date');
    var dateLabel    = document.getElementById('modal-date-label');
    var channelRow   = document.getElementById('modal-channel').closest('.form-row');
    var notesRow     = document.getElementById('modal-notes-row');
    var rejectRow    = document.getElementById('modal-reject-row');
    var rejectOther  = document.getElementById('modal-reject-other-row');
    var rejectReason = document.getElementById('modal-reject-reason');
    var notesArea    = document.getElementById('modal-notes');
    var acceptWarn   = document.getElementById('modal-accepted-warning');

    var isTerminal = (outcome === 'rejected' || outcome === 'accepted' ||
                      outcome === 'lead_converted' || outcome === 'lead_rejected');

    // Refresh the time picker's default hour/minute to right now BEFORE clear() runs —
    // clear() is what actually pushes defaultHour/defaultMinute into the visible time
    // spinner (flatpickr's internal setHours-from-default step lives inside clear()
    // itself when enableTime is on), so setting it afterwards was always one step too
    // late and left the spinner showing whatever time was current the last time this
    // listener ran (or page-load time, on the very first run).
    var _now = new Date();
    dateFlatpickr.set('defaultHour', _now.getHours());
    dateFlatpickr.set('defaultMinute', _now.getMinutes());

    // Reset fields hidden for terminal outcomes
    dateRow.style.display  = 'none';
    setDateRequired(false);
    dateFlatpickr.clear();

    // Notes: hidden for terminal
    notesRow.style.display = isTerminal ? 'none' : '';
    notesArea.required     = (!isTerminal && outcome !== '');

    // Rejection rows
    var needsReason = (outcome === 'rejected' || outcome === 'lead_rejected');
    rejectRow.style.display      = needsReason ? '' : 'none';
    rejectReason.required        = needsReason;
    rejectOther.style.display    = 'none';
    document.getElementById('modal-reject-other').required = false;

    if (outcome === 'accepted') {
        var qid = parseInt(document.getElementById('modal-quoteid').value, 10);
        acceptWarn.style.display = (ACCEPTED_BLOCKED.indexOf(qid) !== -1) ? '' : 'none';
    } else {
        acceptWarn.style.display = 'none';
    }

    if (outcome === '') {
        channelRow.style.display = '';
        document.getElementById('modal-channel').value = 'phone';
    } else if (outcome === 'inbound_call') {
        // The only outcome that may skip a date: left blank, the quote keeps whatever it
        // already owed.
        channelRow.style.display = '';
        dateRow.style.display    = '';
        setDateRequired(false);
        dateLabel.textContent    = 'Call back on (optional)';
        document.getElementById('modal-channel').value = 'phone';
    } else if (outcome === 'next_call' || outcome === 'requote') {
        channelRow.style.display = '';
        dateRow.style.display    = '';
        setDateRequired(true);
        dateLabel.textContent    = 'Call back on';
        document.getElementById('modal-channel').value = 'phone';
    } else if (outcome === 'no_answer_email') {
        channelRow.style.display = '';
        dateRow.style.display    = '';
        dateLabel.textContent    = 'Back in queue on';
        dateFlatpickr.setDate(nextAvailableDate(addDays(COOLDOWN_DAYS['no_answer_email'] || 1)), false);
        document.getElementById('modal-channel').value = 'email';
    } else if (outcome === 'interested') {
        channelRow.style.display = '';
        dateRow.style.display    = '';
        dateLabel.textContent    = 'Back in queue on';
        dateFlatpickr.setDate(nextAvailableDate(addDays(COOLDOWN_DAYS['interested'] || 2)), false);
        document.getElementById('modal-channel').value = 'phone';
    } else if (outcome === 'rejected' || outcome === 'lead_rejected') {
        channelRow.style.display = '';
        document.getElementById('modal-channel').value = 'phone';
    } else if (outcome === 'accepted' || outcome === 'lead_converted') {
        channelRow.style.display = 'none';
    }

    updateDateAvailability();
});

document.getElementById('modal-reject-reason').addEventListener('change', function () {
    var otherRow   = document.getElementById('modal-reject-other-row');
    var otherInput = document.getElementById('modal-reject-other');
    var isOther    = (this.value === 'other');
    otherRow.style.display   = isOther ? '' : 'none';
    otherInput.required      = isOther;
    if (!isOther) otherInput.value = '';
});

document.querySelector('#outcome-modal form').addEventListener('submit', function (e) {
    e.preventDefault();

    // Neither Lead exit can be undone once the stage moves, so confirm first. Wording mirrors
    // the CRM's own Convert to Quote prompt so the two feel like the same action.
    var chosen = document.getElementById('modal-outcome').value;
    if (chosen === 'lead_converted' &&
        !confirm('Convert this Lead into a quote? It will move to the Created stage. This cannot be undone.')) {
        return;
    }
    if (chosen === 'lead_rejected' &&
        !confirm('Reject this Lead? It will move to the Rejected stage. This cannot be undone.')) {
        return;
    }

    var saveBtn = this.querySelector('button[type="submit"]');
    saveBtn.disabled = true;

    fetch(AJAX_BASE + '/ajax_log_outcome.php', { method: 'POST', headers: { 'X-CSRF-Token': CSRF_TOKEN }, body: new FormData(this) })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res.success) {
                document.getElementById('outcome-modal').close();
                var outcome    = document.getElementById('modal-outcome').value;
                var isTerminal = (res.terminal === true);

                if (activeBtn) {
                    var row = activeBtn.closest('tr');

                    // The server inserts a new call_info row unless this POST carried an
                    // existing followup_id (then it's an UPDATE in place) or the outcome is
                    // terminal (no vtiger_quotes_followup write at all). Independent of isDone
                    // below: a dateless "+ Inbound" call with nothing else owed still logs a row.
                    var wasEditingCall = !!document.getElementById('modal-followup-id').value;
                    if (!isTerminal && !wasEditingCall) {
                        var callsCell = row.querySelector('.calls-cell');
                        if (callsCell) callsCell.textContent = (parseInt(callsCell.textContent, 10) || 0) + 1;
                    }

                    // State lives on the row's own Log/Done button, which is not necessarily the
                    // button that was clicked. "+ Inbound" opens the modal but never holds state.
                    var stateBtn = row.querySelector('.log-btn') || activeBtn;
                    var wasDone  = !!stateBtn.dataset.followupId;
                    // A call with no date leaves the row actionable, matching the next reload.
                    // An earlier call today that did promise one still counts. A terminal outcome
                    // resolves the row on its own regardless, since it wrote no reminder at all.
                    var isDone   = wasDone || isTerminal || (res.has_commitment === true);
                    // Only a row crossing into "done" moves the Called counters; a second call on
                    // an already-done row would otherwise push the count past the row total.
                    var isNewLog = !wasDone && isDone;

                    if (isTerminal) {
                        var tColors = { rejected: '#c0392b', accepted: '#27ae60',
                                        lead_rejected: '#c0392b', lead_converted: '#27ae60' };
                        var tLabels = { rejected: 'Rejected', accepted: 'Accepted',
                                        lead_rejected: 'Rejected', lead_converted: 'Converted' };
                        row.style.opacity          = '0.45';
                        row.style.background       = '#f9f9f9';
                        // Badge goes on the row's state button, not whichever button opened the
                        // modal (the two happen to coincide today, only Log/Done can close a
                        // quote), but this stays explicit rather than relying on that.
                        stateBtn.innerHTML         = '&#10003; ' + (tLabels[outcome] || outcome);
                        stateBtn.style.color       = tColors[outcome] || '#555';
                        stateBtn.style.borderColor = tColors[outcome] || '#555';
                        stateBtn.style.background  = '#fff';
                        stateBtn.disabled          = true;
                        // The quote is closed, so another call can no longer be logged against
                        // it. Leaving the button would offer an action the server now refuses.
                        var deadAddBtn = row.querySelector('.addcall-btn');
                        if (deadAddBtn) deadAddBtn.remove();
                    } else if (isDone) {
                        // Edit target follows ownership of the live promise, not recency,
                        // matching the server's reload anchor. Earned by one of three: no target
                        // yet, this call now owns a schedule row, or it IS the current target. A
                        // dateless extra call is none of those, so it's left alone.
                        var isEditTarget = !wasDone
                            || !!res.schedule_followup_id
                            || String(res.followup_id) === String(stateBtn.dataset.followupId);
                        if (isEditTarget) {
                            stateBtn.dataset.followupId = res.followup_id;
                            stateBtn.dataset.notes      = res.notes || '';
                            stateBtn.dataset.outcome    = outcome;
                            stateBtn.dataset.date       = document.getElementById('modal-next-date').value;
                            stateBtn.dataset.channel    = document.getElementById('modal-channel').value;
                        }
                        row.style.opacity    = '0.45';
                        row.style.background = '#f9f9f9';
                        stateBtn.innerHTML         = '&#10003; Done <span style="font-size:0.7rem;text-decoration:underline;margin-left:4px;">edit</span>';
                        stateBtn.style.color       = '#27ae60';
                        stateBtn.style.borderColor = '#27ae60';
                        stateBtn.style.background  = '#fff';
                        addCallButtonFor(stateBtn);
                        if (outcome === 'interested') {
                            var prioritySel = row.querySelector('.priority-sel');
                            if (prioritySel) prioritySel.value = 'high';
                        }
                    }
                    // else: no date and nothing else owed, so the row stays untouched: the next
                    // click logs another call rather than editing this one, matching the reload.

                    refreshHistPanelIfPresent(row, document.getElementById('modal-quoteid').value);

                    if (isNewLog) {
                        var gc = document.getElementById('global-called-count');
                        var gb = document.getElementById('global-called-bar');
                        if (gc) {
                            var gp = gc.textContent.split('/');
                            var gw = parseInt(gp[0]) + 1, gt = parseInt(gp[1]);
                            gc.textContent = gw + '/' + gt;
                            if (gb) gb.style.width = Math.round(gw / gt * 100) + '%';
                        }
                        // Off the row, not activeBtn: the terminal branch above can remove
                        // activeBtn from the row, leaving a detached node with no ancestors to walk.
                        var section = row.closest('.queue-section');
                        if (section) {
                            var sc = section.querySelector('.section-called-count');
                            var sb = section.querySelector('.section-called-bar');
                            if (sc) {
                                var sp = sc.textContent.split('/');
                                var sw = parseInt(sp[0]) + 1, st = parseInt(sp[1]);
                                sc.textContent = sw + '/' + st;
                                if (sb) sb.style.width = Math.round(sw / st * 100) + '%';
                            }
                        }
                    }
                }
            } else {
                alert('Error: ' + (res.message || 'Unknown error'));
                saveBtn.disabled = false;
            }
        })
        .catch(function () {
            alert('Network error — please try again.');
            saveBtn.disabled = false;
        });
});

document.getElementById('modal-cancel').addEventListener('click', function () {
    document.getElementById('outcome-modal').close();
});

// History panel — event delegation so it works for all .hist-btn buttons
document.addEventListener('click', function (e) {
    if (!e.target.classList.contains('hist-btn')) return;
    var btn    = e.target;
    var row    = btn.closest('tr');
    var nextTr = row.nextElementSibling;

    // Toggle if panel already exists
    if (nextTr && nextTr.classList.contains('hist-panel')) {
        nextTr.style.display = nextTr.style.display === 'none' ? '' : 'none';
        return;
    }

    btn.textContent = '...';
    fetch(AJAX_BASE + '/ajax_get_history.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': CSRF_TOKEN },
        body: new URLSearchParams({ quoteid: btn.dataset.quoteid })
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        btn.textContent = 'History';
        if (!res.success) { alert('Error: ' + res.message); return; }
        var cols  = row.querySelectorAll('td').length;
        var panel = document.createElement('tr');
        panel.className = 'hist-panel';
        panel.innerHTML = histPanelCellHtml(cols, res.history, histHidesNextCall(row));
        row.after(panel);
    })
    .catch(function () { btn.textContent = 'History'; alert('Network error'); });
});

// The Awaiting Info section drops the Next call column: its outcomes never schedule one.
// Derived from the row rather than passed in, so every caller (awaiting-info.js included)
// needs no new argument, and anything outside the section keeps the column.
function histHidesNextCall(row) {
    return !!(row && row.closest && row.closest('.awaiting-info'));
}

// Builds the <td> markup for a history panel row. Shared by the initial
// History-button fetch and the post-outcome-save refresh below, so both
// stay in sync with the same rendering.
function histPanelCellHtml(cols, history, hideNextCall) {
    return '<td colspan="' + cols + '" style="padding:4px 10px 10px 30px;background:#fafcff">'
        + buildHistTable(history, hideNextCall) + '</td>';
}

// After a new outcome is logged, any already-open (or previously loaded and
// hidden) history panel for that row is stale — it was fetched before this
// call was written. Re-fetch and replace its content so it reflects the new
// call without requiring a page refresh.
function refreshHistPanelIfPresent(row, quoteid) {
    var histPanel = row.nextElementSibling;
    if (!histPanel || !histPanel.classList.contains('hist-panel')) return;

    fetch(AJAX_BASE + '/ajax_get_history.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': CSRF_TOKEN },
        body: new URLSearchParams({ quoteid: quoteid })
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (!res.success) return;
        var cols = row.querySelectorAll('td').length;
        histPanel.innerHTML = histPanelCellHtml(cols, res.history, histHidesNextCall(row));
    });
}

document.addEventListener('change', function (e) {
    if (!e.target.classList.contains('priority-sel')) return;
    var sel = e.target;
    var fd  = new FormData();
    fd.append('quoteid',  sel.dataset.quoteid);
    fd.append('priority', sel.value);
    fetch(AJAX_BASE + '/ajax_set_priority.php', { method: 'POST', headers: { 'X-CSRF-Token': CSRF_TOKEN }, body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (!d.success) alert('Could not save priority'); });
});


// "Pull to me" — a supervisor with no region of their own reaching for a quote from the
// merged view. Writes only the CRM owner column, never assigned_to_region, so it never
// changes which region the quote belongs to.
document.addEventListener('click', function (e) {
    if (!e.target.classList.contains('claim-btn')) return;
    var btn = e.target;
    var fd  = new FormData();
    fd.append('quoteid', btn.dataset.quoteid);
    fd.append('claim_as', btn.dataset.claimAs);
    btn.disabled = true;
    fetch(AJAX_BASE + '/ajax_claim_quote.php', { method: 'POST', headers: { 'X-CSRF-Token': CSRF_TOKEN }, body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.success) { location.reload(); }
            else { alert('Could not claim this quote'); btn.disabled = false; }
        })
        .catch(function () { alert('Network error'); btn.disabled = false; });
});

// ── Inbound call search ──────────────────────────────────────────────────────
var currentInboundQuote = null;

document.getElementById('manual-open-btn').addEventListener('click', function () {
    window.open(MANUAL_URL, '_blank');
});

if (VIEWER_IS_ADMIN) {
    document.getElementById('monitoring-open-btn').addEventListener('click', function () {
        window.open(AJAX_BASE + '/monitoring_system/monitoring.php', '_blank');
    });
}

document.getElementById('inbound-open-btn').addEventListener('click', function () {
    document.getElementById('inbound-quote-no').value      = '';
    document.getElementById('inbound-result').style.display    = 'none';
    document.getElementById('inbound-not-found').style.display = 'none';
    currentInboundQuote = null;
    document.getElementById('inbound-search-modal').showModal();
    document.getElementById('inbound-quote-no').focus();
});

document.getElementById('inbound-cancel').addEventListener('click', function () {
    document.getElementById('inbound-search-modal').close();
});

document.getElementById('inbound-quote-no').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); document.getElementById('inbound-search-btn').click(); }
});

document.getElementById('inbound-search-btn').addEventListener('click', function () {
    var quoteNo = document.getElementById('inbound-quote-no').value.trim();
    if (!quoteNo) return;

    var btn = this;
    btn.disabled    = true;
    btn.textContent = '…';

    var fd = new FormData();
    fd.append('quote_no', quoteNo);

    fetch(AJAX_BASE + '/ajax_search_quote.php', { method: 'POST', headers: { 'X-CSRF-Token': CSRF_TOKEN }, body: fd })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            btn.disabled    = false;
            btn.textContent = 'Search';

            var resultDiv   = document.getElementById('inbound-result');
            var notFoundDiv = document.getElementById('inbound-not-found');

            if (!res.success) {
                resultDiv.style.display    = 'none';
                notFoundDiv.textContent    = res.message || 'Quote not found';
                notFoundDiv.style.display  = '';
                currentInboundQuote        = null;
                return;
            }

            notFoundDiv.style.display = 'none';
            currentInboundQuote       = res.quote;

            var q = res.quote;
            // Personal claim: a supervisor who owns no region reaching for a quote that
            // is not already theirs.
            var canClaim = CAN_PERSONAL_CLAIM && q.assigned_to_sales_agent !== MY_FULLNAME;
            // Same display-only Lead marker the queue table and outcome modal use: appended
            // when the stage is 'Lead', never part of the actual quote_no.
            var qnoDisplay = q.quote_no + (q.quotestage === 'Lead' ? 'L' : '');
            // Same CRM link the table rows carry, so a quote found here can be opened without
            // having to be in today's queue first.
            var crmUrl = '/quote.php?opt=summary&sales=true&quotetype='
                + encodeURIComponent(CRM_QUOTETYPE) + '&quoteid=' + encodeURIComponent(q.quoteid);

            // Panel actions depend on whether the quote is on today's page and already called. A
            // terminal row, or one rendered for the read-only viewer, carries no data-quoteid so
            // never matches.
            var qidSel    = String(parseInt(q.quoteid, 10) || 0);
            var rowLogBtn = document.querySelector('.log-btn[data-quoteid="' + qidSel + '"]');
            var rowWorked = !!(rowLogBtn && rowLogBtn.dataset.followupId);

            // Same padding/font across all three so they align; primary is told apart by
            // fill/weight, not size (matching borders left three buttons with no clear primary).
            var btnGhost   = 'padding:4px 10px;font-size:0.85rem;cursor:pointer;background:#fff;color:#334155;border:1px solid #aaa;border-radius:3px';
            var btnPrimary = 'padding:4px 14px;font-size:0.85rem;cursor:pointer;background:#334155;color:#fff;border:1px solid #334155;border-radius:3px;font-weight:600';
            resultDiv.innerHTML =
                '<table style="width:100%;border-collapse:collapse">'
                + '<tr><td style="color:#666;padding:3px 0;width:80px">Quote</td><td>'
                  // Bold via font-weight, not a nested <strong>: the dashboard's global
                  // stylesheet reaches bare elements here and was indenting this value.
                  + '<a href="' + crmUrl + '" target="_blank"'
                  + ' style="padding:0;margin:0;text-indent:0;font-weight:bold">'
                  + esc(qnoDisplay) + '</a></td></tr>'
                + '<tr><td style="color:#666;padding:3px 0">Org</td><td>'     + esc(q.organization_name  || '—') + '</td></tr>'
                + '<tr><td style="color:#666;padding:3px 0">Stage</td><td>'   + esc(q.quotestage         || '—') + '</td></tr>'
                + '<tr><td style="color:#666;padding:3px 0">Contact</td><td>' + esc(q.contactname        || '—') + '</td></tr>'
                + '<tr><td style="color:#666;padding:3px 0">Mobile</td><td>'  + esc(q.contactmobile      || '—') + '</td></tr>'
                + '<tr><td style="color:#666;padding:3px 0">Owner</td><td>'   + esc(q.assigned_to_sales_agent || '—') + '</td></tr>'
                // Only shown when the page can see the row: a quote in another caller's queue
                // isn't on this page, so saying nothing is honest; claiming "not worked" would
                // often be wrong.
                + (rowWorked
                    ? '<tr><td style="color:#666;padding:3px 0">Today</td>'
                      + '<td style="color:#27ae60">&#10003; Already worked</td></tr>'
                    : '')
                + '</table>'
                + '<div id="inbound-hist-panel" style="display:none;margin-top:8px"></div>'
                // space-between with no button wrapper: with three buttons the middle one fills
                // the gap between History and the pair; with two it falls back to the ordinary
                // dialog row.
                + '<div style="margin-top:12px;display:flex;align-items:center;gap:8px;'
                + 'justify-content:space-between;flex-wrap:wrap">'
                + '<button type="button" id="inbound-hist-btn" style="' + btnGhost + '">History</button>'
                + (canClaim
                    ? '<button type="button" class="claim-btn" data-quoteid="' + q.quoteid + '" data-claim-as="' + esc(SESSION_USER) + '"'
                      + ' style="' + btnGhost + '">Pull this quote to me</button>'
                    : '')
                // No outcome can be logged outside the three actionable stages. Says why rather
                // than just vanishing, or a missing button reads as a bug.
                + (IS_READONLY ? '' : (!stageCanLog(q.quotestage)
                    ? '<span style="font-size:0.8rem;color:#666;text-align:right">Stage ' + esc(q.quotestage || '—')
                      + ':<br>no outcome can be logged from the queue</span>'
                    // Two buttons once the row has a call: correcting overwrites the existing
                    // call, logging adds another (same dateless narrowing as the row's own
                    // "+ Inbound"). "Edit" doesn't name which call it opens, since the caller
                    // only needs to know the row was already worked.
                    : (rowWorked
                        ? '<button type="button" id="inbound-correct-btn" style="' + btnGhost + '">Edit</button>'
                          + '<button type="button" id="inbound-log-btn" style="' + btnPrimary + '">Log inbound call</button>'
                        : '<button type="button" id="inbound-log-btn" style="' + btnPrimary + '">Log outcome</button>')))
                + '</div>';
            resultDiv.style.display = '';

            // Always a fresh call, never an edit: the quote may not even be on today's page, and
            // inserting is the one outcome that can't destroy anything.
            var inboundLogBtn = document.getElementById('inbound-log-btn');
            if (inboundLogBtn) inboundLogBtn.addEventListener('click', function () {
                document.getElementById('inbound-search-modal').close();

                // Drives the modal off the row's own button so submit updates that row, forced
                // blank so it inserts rather than inheriting the button's edit meaning. On an
                // already-worked row this narrows to the same dateless inbound as "+ Inbound";
                // on an untouched row it's a normal first call.
                if (rowLogBtn) {
                    openOutcomeModal(rowLogBtn, { blank: true, inboundOnly: rowWorked });
                    return;
                }

                // Quote not in today's queue — open the outcome modal manually.
                // activeBtn stays null so no table row update is attempted.
                activeBtn = null;
                document.getElementById('modal-quoteid').value          = q.quoteid;
                document.getElementById('modal-followup-id').value      = '';
                document.getElementById('modal-quote-no').textContent   = qnoDisplay;
                document.getElementById('modal-next-date').value        = '';
                document.getElementById('modal-notes').value            = '';

                // The calendar is a single shared instance: without these it keeps the trip-date
                // cap and per-day counts of whichever row was opened last, and a Lead and a
                // quote have different daily limits.
                dateFlatpickr.set('maxDate', windowMaxFor(q.quotestage || '') + 'T23:59');
                scheduleCounts = {};
                fetchScheduleCounts(q.quoteid, '');
                applyStageMode(q.quotestage || '');
                // Builds the modal itself instead of going through openOutcomeModal(), so it
                // must also undo any narrowing left by a previous "+ Inbound" open.
                applyInboundOnly(false);

                var outcomeSel = document.getElementById('modal-outcome');
                outcomeSel.value = '';
                outcomeSel.dispatchEvent(new Event('change'));
                document.querySelector('#outcome-modal button[type="submit"]').disabled = false;
                document.getElementById('outcome-modal').showModal();
            });

            // Rendered only when the row holds a logged call, so this is exactly the edit the
            // row's own "Done edit" performs, reached from here instead.
            var inboundCorrectBtn = document.getElementById('inbound-correct-btn');
            if (inboundCorrectBtn) inboundCorrectBtn.addEventListener('click', function () {
                document.getElementById('inbound-search-modal').close();
                openOutcomeModal(rowLogBtn);
            });

            document.getElementById('inbound-hist-btn').addEventListener('click', function () {
                var btn   = this;
                var panel = document.getElementById('inbound-hist-panel');
                if (panel.dataset.loaded) {
                    panel.style.display = panel.style.display === 'none' ? '' : 'none';
                    btn.textContent = panel.style.display === 'none' ? 'History' : 'Hide history';
                    return;
                }
                btn.textContent = '…';
                fetch(AJAX_BASE + '/ajax_get_history.php', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': CSRF_TOKEN },
                    body: new URLSearchParams({ quoteid: q.quoteid })
                })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res.success) { btn.textContent = 'History'; alert('Error: ' + res.message); return; }
                    panel.innerHTML      = buildHistTable(res.history);
                    panel.dataset.loaded = '1';
                    panel.style.display  = '';
                    btn.textContent      = 'Hide history';
                })
                .catch(function () { btn.textContent = 'History'; alert('Network error'); });
            });
        })
        .catch(function () {
            btn.disabled    = false;
            btn.textContent = 'Search';
            alert('Network error — please try again.');
        });
});

// ── Collapsible sections — click a section heading to show/hide its table.
document.addEventListener('click', function (e) {
    var h = e.target.closest('.section-toggle');
    if (!h) return;
    var section = h.closest('.queue-section');
    if (section) section.classList.toggle('collapsed');
});

// Escape dynamic values before inserting into innerHTML. notes/created_by are
// free-text/user-controlled, so they must be HTML-encoded to prevent stored XSS.
function esc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

if (VIEWER_IS_ADMIN) {
    (function () {
        var url = new URL(window.location.href);
        if (url.searchParams.has('user')) {
            url.searchParams.delete('user');
            window.history.replaceState({}, '', url.toString());
        }
    }());
}

// Each label is the modal's own <option> text, worded as a record rather than as the
// action about to be taken. The payment codes carry a prefix because a quote's history
// mixes all three queues and bare "No answer" lines would not say which was chased.
// Duplicated in payment-deadline.js, which cannot load this file: keep the two in step.
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

// Falls back to the raw code, never a blank: an outcome added later stays visible rather
// than reading as if nothing was recorded.
function histLabel(map, code) {
    return map[code] || code;
}

// hideNextCall drops the last column entirely, header and cells together. Absent means
// keep it, so every existing caller behaves exactly as before.
function buildHistTable(history, hideNextCall) {
    if (!history.length) {
        return '<em style="color:#999;font-size:0.8rem">No history yet.</em>';
    }
    var rows = history.map(function (h) {
        return '<tr>'
            + '<td style="padding:3px 8px">' + (h.call_date   ? esc(h.call_date)   : '&mdash;') + '</td>'
            + '<td style="padding:3px 8px">' + (h.notes       ? esc(h.notes)       : '<em style="color:#bbb">no notes</em>') + '</td>'
            + '<td style="padding:3px 8px">' + (h.created_by  ? esc(h.created_by)  : '&mdash;') + '</td>'
            + '<td style="padding:3px 8px">' + (h.outcome     ? esc(histLabel(HIST_OUTCOME_LABELS, h.outcome)) : '&mdash;') + '</td>'
            + '<td style="padding:3px 8px">' + (h.channel     ? esc(histLabel(HIST_CHANNEL_LABELS, h.channel)) : '&mdash;') + '</td>'
            + (hideNextCall ? ''
                : '<td style="padding:3px 8px">' + (h.follow_date ? esc(h.follow_date) : '&mdash;') + '</td>')
            + '</tr>';
    }).join('');
    return '<table style="width:100%;font-size:0.8rem;border-collapse:collapse;margin:6px 0">'
        + '<tr style="background:#eef2ff">'
        + '<th style="text-align:left;padding:4px 8px">Called on</th>'
        + '<th style="text-align:left;padding:4px 8px">Description</th>'
        + '<th style="text-align:left;padding:4px 8px">Agent</th>'
        + '<th style="text-align:left;padding:4px 8px">Outcome</th>'
        + '<th style="text-align:left;padding:4px 8px">Channel</th>'
        + (hideNextCall ? '' : '<th style="text-align:left;padding:4px 8px">Next call</th>')
        + '</tr>' + rows + '</table>';
}
