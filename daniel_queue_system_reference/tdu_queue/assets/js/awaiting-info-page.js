// Awaiting Information queue, standalone page. Adapted from assets/js/awaiting-info.js
// (the Log modal) and payment-deadline.js (the history panel, the section-toggle
// accordion): this page loads neither queue.js nor awaiting-info.js, since both assume
// globals and DOM elements (queue.js's delegated .hist-btn handler, .section-toggle
// markup, refreshHistPanelIfPresent) that don't exist here. Own compact copy, same
// reasoning fit_viewer_live_book.php's JS gives for not loading by-person.js.
//
// No "Pull to me": this page has no owned/pool split, so there is nothing to pull.
(function () {
    'use strict';

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // Own copy for the same reason payment-deadline.js's is: ajax_get_history.php returns
    // every call ever logged on the quote, not just this queue's own outcome codes.
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

    function histLabel(map, code) { return map[code] || code; }

    function buildHistTable(history) {
        if (!history || !history.length) {
            return '<em class="ai-hist-none">No calls logged against this quote yet.</em>';
        }
        var rows = history.map(function (h) {
            return '<tr>'
                + '<td>' + (h.call_date   ? esc(h.call_date)   : '&mdash;') + '</td>'
                + '<td>' + (h.notes       ? esc(h.notes)       : '<em style="color:#bbb">no notes</em>') + '</td>'
                + '<td>' + (h.created_by  ? esc(h.created_by)  : '&mdash;') + '</td>'
                + '<td>' + (h.outcome     ? esc(histLabel(HIST_OUTCOME_LABELS, h.outcome)) : '&mdash;') + '</td>'
                + '<td>' + (h.channel     ? esc(histLabel(HIST_CHANNEL_LABELS, h.channel)) : '&mdash;') + '</td>'
                + '</tr>';
        }).join('');
        return '<table class="ai-hist-table"><thead><tr>'
            + '<th>Called on</th><th>Description</th><th>Agent</th>'
            + '<th>Outcome</th><th>Channel</th>'
            + '</tr></thead><tbody>' + rows + '</tbody></table>';
    }

    function loadHistory(quoteid, cell) {
        cell.innerHTML = '<em class="ai-hist-none">Loading…</em>';
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
                cell.innerHTML = '<em class="ai-hist-none">Could not load history.</em>';
                return;
            }
            cell.innerHTML = buildHistTable(res.history);
            cell.setAttribute('data-loaded', '1');
        })
        .catch(function () {
            cell.innerHTML = '<em class="ai-hist-none">Could not load history.</em>';
        });
    }

    // ── History panel toggle ────────────────────────────────────────────────
    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest ? ev.target.closest('.ai-hist-btn') : null;
        if (!btn) return;

        var quoteid = btn.getAttribute('data-quoteid');
        var row     = document.getElementById('ai-hist-' + quoteid);
        if (!row) return;
        var cell = row.querySelector('.ai-hist-cell');

        if (row.style.display !== 'none') {
            row.style.display = 'none';
            btn.textContent   = 'History';
            return;
        }
        row.style.display = '';
        btn.textContent   = 'Hide history';

        if (cell.getAttribute('data-loaded') === '1') return;
        loadHistory(quoteid, cell);
    });

    // ── Unrouted section collapse ───────────────────────────────────────────
    document.addEventListener('click', function (ev) {
        var h = ev.target.closest ? ev.target.closest('.ai-section-toggle') : null;
        if (!h) return;
        var section = h.closest('.ai-section');
        if (section) section.classList.toggle('collapsed');
    });

    // ── Log outcome modal ───────────────────────────────────────────────────
    var modal = document.getElementById('ai-outcome-modal');
    if (!modal) return; // read-only viewer: no Log buttons, modal not needed

    var form       = document.getElementById('ai-outcome-form');
    var outcomeSel = document.getElementById('ai-modal-outcome');
    var noteBlock  = document.getElementById('ai-modal-note-block');
    var noteBody   = document.getElementById('ai-modal-note-body');
    var missingBox = document.getElementById('ai-modal-missing');
    var activeBtn  = null;

    function applyOutcome() {
        var wants = (outcomeSel.value === 'info_received');
        noteBlock.hidden  = !wants;
        noteBody.required = wants;
        if (!wants) noteBody.value = '';
    }
    outcomeSel.addEventListener('change', applyOutcome);

    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest ? ev.target.closest('.ai-log-btn') : null;
        if (!btn) return;
        activeBtn = btn;

        form.reset();
        document.getElementById('ai-modal-quoteid').value = btn.dataset.quoteid;
        document.getElementById('ai-modal-quote-no').textContent = btn.dataset.quoteno || '';

        missingBox.textContent = '';
        var lines = [];
        try { lines = JSON.parse(btn.dataset.missing || '[]') || []; } catch (err) { lines = []; }
        if (lines.length) {
            var head = document.createElement('p');
            head.className = 'ai-modal-missing-head';
            head.textContent = 'Still outstanding:';
            missingBox.appendChild(head);
            var ul = document.createElement('ul');
            lines.forEach(function (l) {
                var li = document.createElement('li');
                li.textContent = l; // textContent, never innerHTML: another team's copy
                ul.appendChild(li);
            });
            missingBox.appendChild(ul);
        }

        applyOutcome();
        form.querySelector('button[type="submit"]').disabled = false;
        modal.showModal();
    });

    document.getElementById('ai-modal-cancel').addEventListener('click', function () {
        modal.close();
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var saveBtn = this.querySelector('button[type="submit"]');
        saveBtn.disabled = true;

        fetch(AJAX_BASE + '/ajax_log_awaiting_info.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': CSRF_TOKEN },
            body: new FormData(this)
        })
            .then(function (r) { return r.json().then(function (body) { return { ok: r.ok, body: body }; }); })
            .then(function (res) {
                if (!res.ok || !res.body.success) {
                    saveBtn.disabled = false;
                    alert(res.body.message || 'Could not save outcome.');
                    return;
                }
                modal.close();

                if (activeBtn) {
                    var row   = activeBtn.closest('tr');
                    var cells = row.querySelectorAll('td');
                    var callsCell = cells[cells.length - 3];
                    var lastCell  = cells[cells.length - 2];
                    var alreadyCalledToday = !!(lastCell && lastCell.querySelector('.ai-called-today'));
                    if (callsCell) {
                        callsCell.textContent = (parseInt(callsCell.textContent, 10) || 0) + 1;
                    }
                    if (lastCell) {
                        lastCell.className = '';
                        lastCell.innerHTML = '';
                        lastCell.appendChild(document.createTextNode(formatToday() + ' '));
                        var badge = document.createElement('span');
                        badge.className   = 'ai-called-today';
                        badge.textContent = 'today';
                        lastCell.appendChild(badge);
                        var by = res.body.created_by || '';
                        if (by) {
                            lastCell.appendChild(document.createElement('br'));
                            var byEl = document.createElement('span');
                            byEl.className   = 'ai-last-by';
                            byEl.textContent = 'by ' + by;
                            lastCell.appendChild(byEl);
                        }
                    }
                    row.classList.add('ai-row--called');

                    // Refresh an already-open history panel in place, same as the fetch this
                    // page's own toggle handler uses — no queue.js dependency needed.
                    var histRow = document.getElementById('ai-hist-' + activeBtn.dataset.quoteid);
                    if (histRow && histRow.style.display !== 'none') {
                        loadHistory(activeBtn.dataset.quoteid, histRow.querySelector('.ai-hist-cell'));
                    }

                    relayoutAwaitingTable(row.closest('table'), alreadyCalledToday);
                }
                activeBtn = null;
            })
            .catch(function () {
                saveBtn.disabled = false;
                alert('Network error, please try again.');
            });
    });

    // Redoes group_awaiting_by_org()'s layout after a call is logged, same rule and same
    // duplication trade-off documented on awaiting-info.js's own copy: keep the two in
    // step if the server-side grouping ever changes.
    function relayoutAwaitingTable(table) {
        if (!table) return;
        var body = table.tBodies[0] || table;

        var entries = [];
        Array.prototype.forEach.call(body.children, function (tr) {
            if (!tr.hasAttribute || !tr.hasAttribute('data-org')) return;
            var next = tr.nextElementSibling;
            entries.push({
                row:    tr,
                panel:  (next && next.classList.contains('ai-hist-row')) ? next : null,
                called: tr.classList.contains('ai-row--called'),
                org:    tr.getAttribute('data-org'),
                trip:   tr.getAttribute('data-trip') || ''
            });
        });
        if (!entries.length) return;

        var anchor = Object.create(null);
        entries.forEach(function (e) {
            if (anchor[e.org] === undefined || e.trip < anchor[e.org]) anchor[e.org] = e.trip;
        });

        function layout(list) {
            var byOrg = Object.create(null);
            var order = [];
            list.forEach(function (e) {
                if (!byOrg[e.org]) { byOrg[e.org] = []; order.push(e.org); }
                byOrg[e.org].push(e);
            });
            order.sort(function (a, b) {
                return anchor[a] < anchor[b] ? -1 : (anchor[a] > anchor[b] ? 1 : 0);
            });
            var out = [];
            order.forEach(function (k) {
                byOrg[k].sort(function (a, b) {
                    return a.trip < b.trip ? -1 : (a.trip > b.trip ? 1 : 0);
                });
                out = out.concat(byOrg[k]);
            });
            return out;
        }

        var laid = layout(entries.filter(function (e) { return !e.called; }))
             .concat(layout(entries.filter(function (e) { return  e.called; })));

        var prevOrg = null, prevCalled = null;
        laid.forEach(function (e) {
            e.row.classList.toggle('org-row', e.org !== prevOrg || e.called !== prevCalled);
            body.appendChild(e.row);
            if (e.panel) body.appendChild(e.panel);
            prevOrg    = e.org;
            prevCalled = e.called;
        });
    }

    function formatToday() {
        var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                      'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        var d = new Date();
        return String(d.getDate()).padStart(2, '0') + ' ' + months[d.getMonth()] + ' ' + d.getFullYear();
    }
})();
