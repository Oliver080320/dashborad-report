# Sales Queue — Data Analysis Report

**Source:** `dev2yourbestwayh_v5` (vtiger CRM) export, 7 CSV files in `data/`
**Data window:** Sep 2024 → 10 Sep 2026
**Prepared:** 14 Sep 2026

---

## 1. Headline findings

| # | Finding |
|---|---|
| 1 | **Win rate is ~9.8%** — 978 of 9,935 live quotes reach a post-sale stage. Roughly 8 in 10 quotes end as Rejected or Auto Rejected. |
| 2 | **Auto Rejected is 21% of all quotes** (2,070). These are system-expired, not human-declined — a fifth of the pipeline dies without a decision. |
| 3 | **Win rate has flatlined at 8–11%** for 20 straight months. Volume moves; conversion does not. |
| 4 | **Agent win rates range 1.6% → 20.5%** at comparable volume. Karthik (20.1% on 329) and Mayur (20.5% on 254) convert roughly **2× the company average**; Dhiraj carries 30% of all volume at 9.0%. |
| 5 | **Queue work is 71% carryover.** The same quote reappears a median of **7 times** (max 54) across 57 days — callers are re-touching a stuck set, not working fresh leads. |
| 6 | **10.3M received across 868 quotes** (1,302 payment lines). Roughly flat month-to-month, no growth trend. |
| 7 | **Revenue cannot be tied to most quotes.** `vtiger_quotes.total` is populated on only **327 of 9,935 rows (3.3%)** — see §6. |

---

## 2. What's in the data

| File | Rows | What it is |
|---|---:|---|
| `vtiger_quotes.csv` | 9,973 | One row per quote. 71 columns. The spine of the dataset. |
| `vtiger_quotescf.csv` | 9,973 | Custom fields, 1:1 with quotes. Columns are opaque codes (`cf_1020`…) — unusable without a field map. |
| `vtiger_quotes_followup.csv` | 25,901 | Call logs and scheduled follow-ups. |
| `vtiger_quote_stage_track.csv` | 221,070 | Full audit trail of every change to a quote. |
| `vtiger_payment_history.csv` | 2,339 | Payment lines. |
| `vtiger_users.csv` | 77 | CRM users. |
| `daily_runs.csv` | 11,822 | The queue system's own log — who was served which quote, which day. |

38 quotes are soft-deleted (`deleted=1`) and excluded throughout. **All analysis below uses 9,935 live quotes.**

---

## 3. The funnel

| Outcome | Quotes | Share |
|---|---:|---:|
| Rejected | 6,028 | 60.7% |
| Auto Rejected | 2,070 | 20.8% |
| **Won / post-sale** | **978** | **9.8%** |
| Open / in progress | 681 | 6.9% |
| Rejected after confirmation | 178 | 1.8% |

"Won / post-sale" groups everything past the sale: Accepted, On Ground, Accounts-Reconciliation, QA stages, Completed, Payment Received.

**The 178 rejected-after-confirmation quotes are the expensive ones** — the sale was made, then lost. That is 15% of everything that ever got confirmed. Worth a separate root-cause review; the reason codes live in `tdu_quote_closure_feedback`, which was not in this export.

---

## 4. Volume and conversion over time

| Month | Quotes | Won | Win % |
|---|---:|---:|---:|
| 2025-04 | 407 | 44 | 10.8 |
| 2025-05 | 414 | 41 | 9.9 |
| 2025-06 | 411 | 37 | 9.0 |
| 2025-07 | 548 | 54 | 9.9 |
| 2025-08 | 615 | 68 | 11.1 |
| 2025-09 | 662 | 48 | 7.3 |
| 2025-10 | 447 | 67 | **15.0** |
| 2025-11 | 552 | 50 | 9.1 |
| 2025-12 | 403 | 40 | 9.9 |
| 2026-01 | 466 | 41 | 8.8 |
| 2026-02 | 370 | 31 | 8.4 |
| 2026-03 | 393 | 35 | 8.9 |
| 2026-04 | 423 | 36 | 8.5 |
| 2026-05 | 356 | 40 | 11.2 |
| 2026-06 | 411 | 49 | 11.9 |
| 2026-07 | 439 | 34 | 7.7 |
| 2026-08 | 362 | 19 | **5.2** |

Two things stand out:

- **Sep–Nov 2025 was the volume peak** (662, 447, 552) and Oct 2025 the best conversion month on record at 15.0%. Nothing since has come close.
- **Aug 2026 is the worst month in the dataset** — 362 quotes at 5.2%. Sep 2026 is partial (6 quotes) and should be ignored.

Sep 2024 shows 54.4%, but on only 90 quotes at the very start of the data — an artifact of backfilled records, not real performance.

---

## 5. Agent performance

Quotes created, agents with 50+ quotes. Names normalised for case (the CRM stores `HarshP` and `harshp` as separate strings).

| Agent | Quotes | Won | Win % |
|---|---:|---:|---:|
| pankajb | 59 | 27 | **45.8** |
| mayur | 254 | 52 | **20.5** |
| karthik | 329 | 66 | **20.1** |
| hemant | 483 | 63 | 13.0 |
| rachana | 289 | 36 | 12.5 |
| maharshi | 827 | 83 | 10.0 |
| snehasawant | 164 | 16 | 9.8 |
| **dhiraj** | **3,031** | 274 | 9.0 |
| rohitmokal | 149 | 13 | 8.7 |
| priyanka | 286 | 23 | 8.0 |
| harshp | 727 | 56 | 7.7 |
| sidhikka | 593 | 39 | 6.6 |
| kunjan2 | 152 | 10 | 6.6 |
| abhisheks | 496 | 32 | 6.5 |
| anindita | 208 | 13 | 6.2 |
| pratik | 122 | 7 | 5.7 |
| pradeep | 163 | 9 | 5.5 |
| rakeshm | 561 | 24 | 4.3 |
| komal | 294 | 7 | 2.4 |
| abhisheksharma | 61 | 1 | 1.6 |

**Read this carefully before acting on it.** `created_by` records who *entered* the quote, which is not always who *sold* it — Dhiraj's 3,031 quotes (30% of everything) looks more like a data-entry or intake role than a sales book. Pankaj's 45.8% on 59 quotes is a small, probably hand-picked sample.

The comparison that does hold up: **Karthik and Mayur, at 250–330 quotes each, convert at roughly double Rakesh, Komal, and Sidhikka at similar or higher volume.** That gap is large enough, and the samples big enough, to be worth understanding.

### Call activity (from follow-up logs)

| Agent | Log entries | Distinct quotes touched | Entries per quote |
|---|---:|---:|---:|
| Karthik Suriyan | 3,611 | — | — |
| Rachana Hingorani | 3,155 | — | — |
| Prasanta Dawn | 2,472 | 540 | 4.6 |
| Hemant Dongrani | 2,422 | 637 | 3.8 |
| Sidhikka Lotlikar | 1,947 | 495 | 3.9 |
| Mayur P | 1,866 | 1,281 | **1.5** |
| Harsh Purswani | 1,444 | 544 | 2.7 |
| Dhiraj Salvi | 668 | 547 | **1.2** |

**Mayur and Dhiraj close with far fewer touches per quote than Prasanta or Sidhikka** (1.2–1.5 vs 3.9–4.6). Combined with Mayur's 20.5% win rate, that is the most interesting signal in this dataset: either he qualifies out early, or he closes on the first call. Worth listening to his calls.

---

## 6. Revenue

| Metric | Value |
|---|---|
| Total received | **10,296,073** |
| Quotes with payment | 868 |
| Payment lines | 1,302 (of 2,339 rows) |
| Median payment | 3,362 |
| Largest payment | 150,400 |

Monthly received is choppy and trendless — Oct 2025 (904k) and Dec 2025 (925k) are the peaks; Apr 2026 (240k) and Aug 2026 (256k) the troughs.

**Currency is not labelled in the export.** `currency_id` is `1` on every row with no lookup table. Confirm with the dev team before quoting these numbers to anyone.

**The bigger problem:** `vtiger_quotes.total` and `total_new` are populated on only **327 of 9,935 quotes**. You cannot compute average deal size, pipeline value, or revenue-per-agent from this export. Payments join to quotes by `quoteid`, so *realised* revenue per quote is recoverable — but quoted value is not.

---

## 7. The queue system (daily_runs)

57 days of queue history, 25 Jun → 31 Aug 2026. Four callers.

| Caller | Queue rows | Distinct quotes | Days active |
|---|---:|---:|---:|
| karthik | 4,546 | 279 | 57 |
| HarshP | 2,814 | 420 | 57 |
| hemant | 2,461 | 515 | 57 |
| ArunP | 2,001 | 139 | 57 |

**71.4% of all queue rows are `carryover`** — quotes rolled forward from a previous day rather than newly assigned. The median quote appears in the queue **7 times**; one appeared **54 times**.

Where those 1,267 queued quotes ended up:

| Stage | Count |
|---|---:|
| Rejected | 622 |
| Created (still open) | 504 |
| Accepted | 55 |
| Lead | 31 |
| Auto Rejected | 21 |

**Only 55 of 1,267 queued quotes (4.3%) reached Accepted, and 504 are still sitting in Created.** Karthik shows the most extreme pattern: 4,546 queue rows across just 279 quotes — **16 touches per quote on average.**

This is the clearest operational problem in the data. The queue is recycling a stuck backlog. A cap on re-queues, or a forced disposition after N touches, would free a large amount of caller time.

---

## 8. Segments

| Country | Quotes | Won | Win % |
|---|---:|---:|---:|
| Australia | 7,422 | 740 | 10.0 |
| New Zealand | 2,438 | 238 | 9.8 |

No meaningful difference. Australia is 75% of the book.

| Quote type (follow-ups) | Entries |
|---|---:|
| group | 21,486 |
| fit | 4,301 |
| fit-dashboard | 114 |

Group business dominates follow-up effort 5:1.

---

## 9. Data quality issues

Ranked by how much they limit analysis.

| # | Issue | Impact |
|---|---|---|
| 1 | `total` / `total_new` null on **96.7%** of quotes | **Blocks all deal-size and pipeline-value analysis.** Biggest single gap. |
| 2 | `followup.outcome` null on **92%** of entries (23,829 of 25,901) | Cannot measure what calls achieved. The column exists but is not being filled. |
| 3 | `vtiger_quotescf` columns are raw codes (`cf_1020`…) | 51 columns of potentially useful data, unusable without a field-name map. |
| 4 | Agent names stored inconsistently | `HarshP`/`harshp`, `Karthik`/`karthik`, `KUNJAN2`/`Kunjan`. Splits agents in half unless normalised. Also: `quotes.created_by` uses usernames, `followup.created_by` uses full names — no clean join. |
| 5 | `created_by` null on 349 quotes and 1,663 follow-ups | ~3.5% of quotes and 6.4% of calls unattributable. |
| 6 | `payment_method` is `12.0` or null on every row | No lookup table. Payment-method analysis impossible. |
| 7 | `balance_amount` 100% null; `region_id` always 0; `quote_type`/`tour_type` populated on 1 row | Dead columns. Ignore. |
| 8 | Stage-name typo variants | `Rejected After Confrmation` (157 rows) vs `Rejected After Confirmation` (20). Must be merged manually. |

---

## 10. Security note

**`vtiger_users.csv` contains a `user_password` column.** Whether those are hashes or plaintext, that file should not sit in a shared folder, be emailed, or be committed to version control. Delete the column before sharing this dataset with anyone.

---

## 11. What to ask for next

To answer the questions this export cannot:

1. **`tdu_quote_closure_feedback` and `tdu_lead_closure_feedback`** — the *why* behind 6,028 rejections. Without these, rejection analysis stops at "they said no."
2. **A `vtiger_quotescf` field-name map** — unlocks 51 existing columns at zero collection cost.
3. **Confirmation on quote value** — is `total` genuinely empty in the source, or did it get dropped in the export? If the former, ask where quoted value actually lives.
4. **Currency confirmation** for the payment figures.
5. **`tdu_callers`** — the official caller-to-queue mapping, so agent analysis uses real roles instead of inferred ones.

---

## 12. Recommended actions

**Operational**
- Cap queue re-touches. 71% carryover and a 54-touch maximum means caller time is going to quotes that are not moving. Force a disposition after ~5 touches.
- Investigate the 2,070 Auto Rejected quotes. A fifth of the pipeline expiring without a human decision is either a real loss or a broken timer — find out which.
- Review the 178 rejected-after-confirmation quotes individually. These cost the most.

**Sales**
- Shadow Mayur and Karthik. Both convert at ~20% on real volume, and Mayur does it with 1.5 touches per quote against a team average near 4.
- Look at Komal (2.4% on 294) and Rakesh (4.3% on 561) — 855 quotes at a third of the company win rate.

**Data**
- Make `followup.outcome` mandatory in the UI. 92% blank is the difference between measuring activity and measuring results.
- Normalise usernames to lowercase at write time, and use a single ID for agent attribution across tables.

---

*All figures computed from the CSVs in `data/`. Soft-deleted quotes excluded. Sep 2026 is a partial month and omitted from trend reads.*
