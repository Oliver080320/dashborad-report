# TDU Sales Prioritisation

A daily prioritisation tool for the TDU sales team. It replaces the manual morning
Google Sheet that agents used to decide who to call, with three automatically
ranked queues that read live data from the CRM, apply the team's cohort rules, and
remember what has already been done across days. Two more queues sit either side of
those three: one works enquiries that have not been quoted yet, the other chases
payment deadlines on quotes that have already been accepted. A sixth piece of work,
chasing the client details a confirmed booking is blocked on, is not a queue at all
but a section of its own page.

**Stack:** PHP on cPanel, MySQL (reads directly from the CRM database).

---

## Why it exists

Every TDU sales agent starts the day needing to decide which quotes and which
organisations to contact. Previously that decision lived in a shared Google Sheet
that someone had to rebuild and re-rank by hand each morning. It was slow, easy to
get wrong, and had no memory: if an agent called a client yesterday and agreed to
ring back next week, nothing stopped the same client showing up at the top again
today.

This tool automates that decision. It pulls the relevant quotes and organisations
from the CRM, sorts them into the three queues below according to fixed business
rules, and records each call outcome so the same contact is not surfaced again until
it is due. The result is that an agent opens one page and sees a ready-made,
ordered list of who to call today, with the most urgent work first.

---

## How it works

1. **Read** -- cohort queries pull active quotes and organisations from the CRM
   (`vtiger_quotes`, `tdu_organisation`, and related tables).
2. **Rank** -- each item is classified into a queue and a sub-priority, then capped
   to a daily budget per region owner so the list stays workable.
3. **Freeze** -- the day's queue is computed once on first load and stored, so
   reloading does not reshuffle the list mid-day.
4. **Remember** -- when an agent logs a call outcome, it is written back to the CRM's
   own follow-up tables (plus a small extension table for structured outcome data),
   which sets the cool-down before that contact reappears.

The system both **filters and remembers**: it reuses the CRM's existing tracking
tables as the source of truth for what has been done, rather than inventing a
parallel record.

---

## Ownership: one owner per region, not per caller

Every quote's ownership follows a fixed geographic region rather than being shared
out across a two-person calling team. There are five queue regions -- **West,
North, South, Gujarat, East** -- and the CRM's own eight India regions collapse
into them through a lookup table (its three Mumbai sub-regions all fold into
West). Each region has **exactly one owner**, enforced by the database schema
itself (the owner table's primary key is the region), though one person can own
more than one region. Whichever of the two CRM ownership fields the write lands in
-- "Internal Sales Agent" or "External Sales Agent" -- is decided by that owner's
own CRM account type, never guessed from the quote.

A region's owner works FIT and Groups & MICE quotes together as one merged
Follow-up cohort against a single daily budget (60% of daily capacity, the same
share the old India-caller config used), rather than as two separately filtered
team configs. An owner covering more than one region gets one blended budget,
split back across their regions by how much live work each one actually has.

Two people, not tied to any region, can view every region and log outcomes on any
quote: this is the **supervisor** role. One of them may additionally be granted
the ability to personally claim any Follow-up quote and work it as their own,
independent of its region -- a narrow, explicitly-granted capability, not
something every supervisor gets by default.

Payment Deadline and Awaiting Information sit outside the region model entirely.
Both are **access-gated, not region-owned**: a fixed pair sees one merged list
across every region with no per-quote owner to resolve, supervisors get the same
list read-only, and admins get full access. There is no "unclaimed pool" or
"pull to me" on either of those two pages any more -- every row is already visible
to everyone who has access.

---

## The three queues

| Queue | Who is in it | Default daily share |
|---|---|---|
| Follow-up | Active quotes in Created or Requote stage with a future trip date | 60% |
| Engagement | Organisations whose last quote was 3 to 12 months ago (the "warm dormant" cohort) | 10% |
| Outreach | Organisations cold for more than 12 months, or that have never quoted | 30% |

Only **Follow-up** is built; Engagement and Outreach remain not started (see
Build status below), so today the 60% share is what actually governs daily
capacity in practice.

Within Follow-up, items fill a limited number of daily slots in cascade order of
sub-priority (scheduled callbacks first, then recent quotes, then upcoming trip
dates, then a "Further out" tier that backfills any remaining slots with older
quotes that didn't qualify for anything else). All of an organisation's
qualifying quotes are grouped together so an agent resolves them in a single call.

### Inside its own page: Awaiting Information

A dedicated page (not a queue, and not embedded inside Follow-up) lists quotes
that are already confirmed but whose booking cannot proceed, because the client
has not supplied something the booking agents need: a traveller contact number,
guest names, flight details, which hotels cover which nights, dietary
requirements.

A caller rings the agency, gets what is missing, and writes it as a note on the
quote. The booking system's own extraction agent reads that note on its next
pass, the reason clears, and the row disappears on its own. Membership is a live
read of the table that agent's pipeline writes, so unlike every other page in this
system it has no frozen daily snapshot, no slot budget and no carry-over: a row
leaves the moment its reasons clear, without anyone working it.

Rows are ordered by trip date and grouped by organisation so one call clears an
agency's whole list, with rows already called today dropping to the bottom. The
page is reached by the post-sale pair, supervisors (read-only) and admins -- see
"Ownership" above.

### Before those three: the Leads queue

A **Lead** is an enquiry that has not been quoted yet. It sits at the `Lead`
stage in the CRM, one step before `Created`, so it is not yet in the Follow-up
cohort at all. The Leads queue works those, so an enquiry that never turned into
a quote is chased rather than quietly written off as a lost sale.

It reuses the Follow-up queue's whole shape: the same SP1-4 cascade, the same
organisation grouping, the same frozen daily snapshot, the same call outcomes,
and the same region ownership. Two things differ. There is **no slot budget**,
so every qualifying Lead surfaces every day rather than filling a capped number
of slots. And its closing outcomes are its own: a Lead is converted (moving it to
`Created`, where the Follow-up queue picks it up on the next pull) or rejected
with a reason list written for enquiries that were never priced. Callbacks are
capped at 7 days out rather than 21, because a fresh enquiry goes cold quickly.

A region owner switches into it from a button on their Follow-up queue; a
supervisor with no personal claim queue of their own falls back to the merged
view for Leads, since there is no personal Leads queue.

**One outcome does not work yet, and it is not this code's doing.** The CRM's own
quote update handler refuses to move a quote off the `Lead` stage to anything but
`Created`, so rejecting a Lead is silently reverted by the CRM. This code detects
that the stage did not change and reports an error rather than recording a
rejection that did not happen, so nothing is corrupted, but callers cannot use
**Reject lead** until that handler is widened to allow `Lead` to `Rejected`.
Converting a Lead is unaffected.

### After them: the Payment Deadline queue

The three queues above all work the **pre-sale** cohort and share the daily
capacity split. A separate **Payment Deadline** queue works the **post-sale**
one: quotes already `Accepted` whose payment cancellation deadline is near or
has passed, and whose trip hasn't happened yet.

It deliberately behaves differently: no share of the daily capacity, no slot
cap, no frozen daily snapshot, and no carry-over. Every qualifying quote is
shown, grouped by urgency (Escalate / Overdue / Due soon / Paid, waiting on
stage), and a quote leaves only when its CRM stage moves on. See "Ownership"
above for who can reach it.

---

## Regions and access, by role

| Role | Reaches | Notes |
|---|---|---|
| Region owner | Follow-up + Leads for their own region(s) | One merged FIT + Groups & MICE cohort, one budget |
| Supervisor | Follow-up + Leads for every region (read/write); own region's default view if they also own one | Personal-claim capability is a separate, explicitly granted flag, not automatic |
| Post-sale pair | Payment Deadline + Awaiting Information | One merged list across all regions; no Follow-up/Leads access |
| Admin | Everything | Merged view of every page, full write access |

Asia and Field reps remain separate, not-yet-built configurations, unaffected by
this change (see Build status).

---

## Build status

| Scope | Status |
|---|---|
| Follow-up + Leads, region-owned | Built, writes live, pending production switch |
| Payment Deadline + Awaiting Information, access-gated | Built, writes live, pending production switch |
| Engagement + Outreach | Not started |
| Asia config | Not started |
| Field reps | Not started |

**"Pending production switch" refers to the database target, not deployment: the
code itself has been running in production since 2026-06-19.** For this change
specifically, that switch is not yet safe to make: **migration 010 (below) has not
been confirmed applied on the production schema.** Deploying this code before it
runs is not a partial-functionality gap like earlier migrations -- there is no
legacy fallback left to fall back to (see Setup), so every page and endpoint would
fatal-error for every user from the first load. Development and testing for this
change used a local copy of the `dev2yourbestwayh_v9` staging snapshot plus real
staging; production's exact schema state has not been independently confirmed.

Alongside the above, an **admin-only monitoring dashboard** (read-only, no
writes) is reachable via a "Monitoring" button on the queue page for `admin`
users, or standalone at `tdu_queue/monitoring_system/monitoring.php` -- not yet
wired into the CRM's own header menu. It includes a manager-only "By person" tab:
a per-caller drill-down covering summary counts, a live book with travel-month
and upcoming-follow-ups charts, a calls-by-hour chart, and an individual quote
timeline. Full chart-by-chart detail lives in the main development repository,
not in this deploy-ready one.

**Known gap, not blocking:** six of the monitoring dashboard's charts (activity,
coverage and backlog trends) still read the old per-caller snapshot table, which
this change no longer writes to, so those specific charts render empty rather
than wrong once the region model is live. The rest of the dashboard is
unaffected.

---

## Key files

```
tdu_queue/
  queue.php                              -- router (reads $_SESSION['user_name']; region owner,
                                            supervisor, post-sale or admin; ?view=leads,
                                            ?view=payment_deadline, ?view=awaiting_info)
  config.php                             -- DB connection (shared dbconn.php), region config
                                            (QUEUE_REGIONS, REGION_MAP, REGION_OWNERS), access
                                            scopes, internal/external ownership-column helpers,
                                            whole-system and per-feature maintenance-mode gates
  cron_daily_runs.php                    -- daily cron: builds and freezes each region's Follow-up
                                            and Leads snapshot, corrects CRM ownership continuously,
                                            builds the personal-claim queue
  queues/
    queue_builder.php                    -- shared SP1-SP4 classification, region cohort/budget/
                                            split, continuous ownership correction, snapshot
                                            read/write, schedule-cap helpers, quote-owner resolution,
                                            and the Payment Deadline cohort query
    region_queue.php                     -- Follow-up queue for a region owner, a supervisor's
                                            merged view, or a supervisor's personal-claim queue
    region_leads.php                     -- Leads queue, same three views as above
    all_callers.php                      -- merged region view (admin)
    lead_builder.php                     -- Lead cohort query and SP1-4 classifier (region-scoped,
                                            no slot budget)
    payment_deadline.php                 -- Payment Deadline queue (access-gated, all regions)
    awaiting_info.php                    -- Awaiting Information page (access-gated, all regions)
    awaiting_info_builder.php            -- Awaiting Information cohort (live read, no snapshot),
                                            reason grouping and per-organisation ordering
  monitoring_system/
    monitoring.php                       -- admin-only monitoring dashboard router
    monitoring_builder.php               -- one query/aggregation function per chart
    ajax_person_*.php                    -- "By person" tab endpoints (summary, quotes, companies,
                                            breakdown, live book, calls by hour)
    ajax_quote_timeline.php              -- merged per-quote event timeline (any quote, any owner)
    ajax_export_accepted.php             -- CSV export behind the "Accepted this month" card
    ajax_export_next_3_months.php        -- CSV export of the upcoming-trips cohort
  views/
    queue_view.php                       -- Follow-up queue HTML/JS (admins get a "Monitoring" button)
    monitoring_view.php                  -- monitoring dashboard HTML/PHP view
    payment_deadline_view.php            -- Payment Deadline page (urgency accordions, outcome modal)
    awaiting_info_view.php               -- Awaiting Information page
  assets/
    css/queue.css                        -- queue page styles
    css/monitoring.css                   -- monitoring dashboard styles
    css/payment-deadline.css             -- Payment Deadline page styles
    css/awaiting-info-page.css           -- Awaiting Information page styles
    js/queue.js                          -- queue page JS (outcome modal, history, claim, quote search)
    js/payment-deadline.js               -- Payment Deadline JS (modal, calendar, history, accordions)
    js/awaiting-info-page.js             -- Awaiting Information JS (outcome modal)
    js/monitoring/                       -- monitoring dashboard JS (core, team-activity, workload,
                                            accounts, quote-pipeline, by-person)
    vendor/chart.umd.min.js              -- vendored Chart.js, no CDN dependency
    vendor/flatpickr/                    -- vendored Flatpickr calendar (MIT), used by both outcome modals
  ajax_log_outcome.php                   -- Follow-up outcome logging (calls, terminal stage changes, requote)
  ajax_log_payment_outcome.php           -- Payment Deadline outcome logging (payment_no_answer/_next_call)
  ajax_log_awaiting_info.php             -- Awaiting Information outcome logging (info_* outcomes,
                                            writes the note the extraction agent reads back)
  ajax_claim_quote.php                   -- personally claim a Follow-up quote (single quote only,
                                            never changes the quote's actual region)
  ajax_check_schedule_availability.php   -- live daily schedule cap preview for the outcome modal
  ajax_get_schedule_counts.php           -- bulk schedule-cap counts for the outcome modal's calendar
  ajax_get_history.php                   -- call history per quote (used by both queues)
  ajax_set_priority.php                  -- inline priority edit
  ajax_search_quote.php                  -- quote lookup by number (Search Quote modal), with/without "TDU"
  migrations/
    001_phase1_tables.sql                -- extension tables and daily_runs
    002_outcome_column.sql               -- adds outcome column to vtiger_quotes_followup
    003_vtiger_groups_country.sql        -- country column on vtiger_groups (dynamic India region list)
    004_tdu_callers.sql                  -- tdu_callers table (retired by 010; kept for its own history)
    005_indexes.sql                      -- performance indexes on vtiger_quotes_followup
    006_quote_closure_feedback.sql       -- structured rejection reasons (Rejected outcome)
    007_tdu_queue_access_view.sql        -- access-scope table (supervisor / post_sale)
    008_lead_closure_feedback.sql        -- structured rejection reasons for Leads (own list, own table)
    009_schedule_followup_link.sql       -- links a call to the reminder it created in the same write,
                                            so a later dateless call can be told apart from the one
                                            holding the day's live callback date
    010_region_model.sql                 -- region tables (map, owners, daily snapshot) and the
                                            personal-claim access flag; see Setup below, this one
                                            cannot be deferred
    schema_migrations.sql                -- tracking table for which migrations are applied where

user_manual.php                          -- caller-facing user guide (session-gated, no separate login)
```

The Payment Deadline queue needs no migration of its own: it reuses
`vtiger_quotes_followup` / `tdu_quotes_followup_ext`, with its outcome codes
namespaced (`payment_*`) so its call history can't be confused with the
Follow-up queue's. Awaiting Information needs none either, for the same reason
(`info_*` codes), and adds no table of its own.

It does, however, read one table this repository does not own:
`oskar_pause_reasons`, written by the booking system's own extraction pipeline in
the same database. That table is what decides which quotes appear on the page and
when they leave it. If it is absent or the read fails, the page renders empty and
the failure is written to the PHP error log -- nothing else in this system is
affected, so the code is safe to deploy on a schema where that pipeline is not yet
running.

---

## Maintenance mode

`config.php` can take the whole system offline in one place: a `MAINTENANCE_MODE`
constant at the top of the file, checked before the database connection so it
still works even if the DB itself is unreachable. Set it to `true` and redeploy
to block every page and AJAX endpoint; set it back to `false` to bring the
system back. AJAX requests get a JSON 503; page loads get a plain "System Under
Maintenance" message, rendered inside the dashboard or as a standalone page
depending on how the queue was reached.

Two narrower switches sit below it, independent of the whole-system one:
`MONITORING_MAINTENANCE_MODE` (`config.php`) gates only the monitoring dashboard
and its AJAX endpoints, and `USER_MANUAL_MAINTENANCE_MODE` (defined locally
inside `user_manual.php`, which has no other dependency on `config.php`) gates
only the user manual page. Both currently reflect real, still-open work rather
than being switched on by accident -- check what each one actually covers before
flipping it back to `false`.

---

## Setup

1. Run the migrations in `tdu_queue/migrations/` in order, 001 through 010, on
   the target schema. `schema_migrations.sql` creates a tracking table for which
   migrations have been applied where -- run it too, adjusting its backfill
   INSERT to match what's actually applied on that specific database.

   **Migration 010 cannot be deferred, and its failure mode is the worst of any
   migration this project has shipped.** This deploy removes every fallback
   controller the previous ownership model had (there is no per-caller queue
   left to route to if a region lookup comes back empty). `config.php` treats an
   empty region configuration as a fatal, system-wide error, so deploying this
   code before 010 has run means every page and every AJAX endpoint fails from
   the first request, for every user, with no degraded mode. Apply it, and
   confirm the seed data (region owners, supervisors, the post-sale pair) matches
   real CRM accounts on that schema, before this code goes live there.

   Migration 009 also cannot be deferred. Outcome logging writes its column on
   **every** outcome, not only the inbound ones, so if the code is deployed while
   the column is missing, the insert fails and no outcome can be saved at all, in
   either queue, from the first click. Apply it before or together with the
   code, never after. It only adds a nullable column, so it is safe to apply
   ahead of the deploy: the running code simply ignores it until then.

   Migration 007 must be applied before deploying the code, or the supervisor and
   post-sale roles resolve to nobody and those accounts get a "No queue
   assigned" message instead of their real page. Its seed data (who is a
   supervisor, who is post-sale, who can personally claim a quote) should be
   checked against the real CRM accounts on that schema before relying on it.

   Migration 008 must also be applied before anyone uses **Reject lead**, and its
   failure mode is worse than 007's. Rejecting a Lead changes the CRM stage
   first and writes the reason second, and those two steps are not one
   transaction. If the table is missing, the Lead is already rejected by the
   time the reason write fails, so the closure is recorded in the CRM with no
   reason attached anywhere. Nothing else in the Leads queue depends on it: the
   queue itself, the call outcomes and converting a Lead all work without it.
2. `config.php` connects via the existing dashboard's shared `dbconn.php`
   (`require_once __DIR__ . '/../../dbconn.php'`) -- the same connection object the
   rest of the sales dashboard uses. That file lives outside this repository, in the
   cPanel `public_html/` tree; no separate `.env` or credentials file is needed
   inside `tdu_queue/`. Which schema it points to (staging vs production) is
   controlled entirely by `dbconn.php`, not by anything in this repo.
3. Deploy `tdu_queue/` and `user_manual.php` to the cPanel public directory
   alongside the existing sales dashboard. The queue is reached through the
   dashboard's own router (`quote.php?opt=sales-queue`) via a menu link added to
   `header.php` -- both `quote.php` and `header.php` live outside this repo and are
   edited directly on the server.

---

## Usage

Users open the queue from the **Quote** dropdown in the sales dashboard
(`quote.php?opt=sales-queue`), or via the standalone URL
`/tdu_sales_prioritisation/tdu_queue/queue.php`. The page reads the logged-in
user (`$_SESSION['user_name']`) and routes to the right view for their role:

- A **region owner** sees their own region's (or regions', merged) prioritised
  Follow-up queue for the day. Each row is one quote to call. Use **Log** to
  record an outcome and **History** to see past contact. Call outcomes (next
  call, no answer, interested, inbound call) schedule the quote's return to the
  queue; Rejected and Accepted close it out and change its stage in the CRM.
  Requote also changes the CRM stage, but then schedules a return date like a
  call outcome, so the quote stays in play. Inbound call is the one outcome
  whose date is optional: left blank it records the call without changing what
  the quote owes. A row already worked today shows a small **+ Inbound** button
  for logging a second same-day call that way, while correcting an existing
  call, its date included, stays behind **Edit** on the row's own state button.
- Each region owner can have at most 10 calls scheduled for the same future
  date. The outcome modal's date field is a colour-coded calendar
  (green/yellow/red per day) and suggests the next open date if the one chosen
  is already full.
- The day's queue is frozen on first load -- reloads show the same slots, with
  worked rows greyed out.
- A **supervisor** lands on their own region's view if they also own one,
  otherwise on their merged, all-region view; a link toggles between the two.
  In the merged view every row shows a region badge. A supervisor with the
  personal-claim capability sees a **Pull to me** option on rows there and in
  Search Quote, letting them take a quote to work directly regardless of its
  region.
- The **Leads** button switches to the Leads queue (`?view=leads`), the
  enquiries that have not been quoted yet, and the same button there switches
  back. The two queues are independent: each freezes its own daily snapshot, so
  opening one does not affect the other. A Lead's callback calendar stops at 7
  days rather than 21, and its closing outcomes are **Convert to quote** and
  **Reject lead**, the latter with its own reason list. Both follow the quote's
  own stage, not the page, so a Lead found through Search Quote from the
  Follow-up queue still gets Lead options.
- The **Search Quote** button finds any quote by number, on or off today's
  queue.
- The **post-sale pair** and admins reach **Payment deadlines**
  (`?view=payment_deadline`) and **Awaiting Information** (`?view=awaiting_info`)
  from links on the Follow-up page (or, for the post-sale pair with no
  Follow-up page of their own, as their default landing page). Payment
  Deadline has two outcomes only (No answer, Next call), both requiring a note;
  callback dates must fall before the trip date and can't be a Sunday.
  Awaiting Information's three outcomes are No answer, Contacted (information
  still to come) and Information received; the last one writes what the caller
  was given as a note on the quote, which is what clears the row. Supervisors
  get both pages read-only.

---

## Documentation

Full documentation (queue rules, database design, delivery roadmap, and locked
constants) lives in the main development repository, not in this deploy-ready one.
