# Sales dashboard & dataset findings report

## 1. Overview

**Review copy for Oliver • 21 September 2026 • not shared or published.**

Reviewed Suyash’s local Streamlit dashboard, its calculation code and all **7 supplied CRM CSV exports (281,155 rows)**. No source data or dashboard code was changed. The files are exports from `dev2yourbestwayh_v5`, per the handover; there was no live-system refresh. File hashes identify the exact version reviewed.

| file | rows | columns |
| --- | --- | --- |
| daily_runs.csv | 11822 | 8 |
| vtiger_payment_history.csv | 2339 | 19 |
| vtiger_quotes.csv | 9973 | 71 |
| vtiger_quotescf.csv | 9973 | 51 |
| vtiger_quotes_followup.csv | 25901 | 15 |
| vtiger_quote_stage_track.csv | 221070 | 5 |
| vtiger_users.csv | 77 | 15 |

Quote creation spans **16 September 2024–10 September 2026**. Quote analysis uses **9,935 non-deleted quotes**, excluding **38 deleted**; data-quality checks use all **9,973 quotes** unless stated. Stage logs span 2 October 2024–11 September 2026; queue runs cover 25 June–31 August 2026; payment records were added 8 November 2024–20 August 2026. These are record ranges, not known extraction timestamps. There is no shared verified snapshot cutoff.

Dashboard baseline: **Treat as today = 18 August 2026**, all four callers, Per person; trends cover **5–18 August**, with Sundays hidden. By person uses **1–18 August**. Pipeline defaults to **Created / Requote**. The dashboard’s CSV download was saved: **791 quotes**, trip dates **1 August–31 October 2026**, all non-deleted stages. It is a trip-window export, not the full dataset or a sales ledger. The full CSV inputs and derived quote export—not screen readings—drive this report.

**Definitions:** “accepted” means current accepted-family stage, not revenue or unique acceptance events. Resolved win rate = accepted ÷ (accepted + manually rejected family), excluding Auto Rejected and unresolved quotes. “First acceptance” means first *observed* Accepted audit event; audit history is incomplete. Country is destination. FIT/Groups is a quote-number suffix proxy, not a product catalogue. Latest queue assignee is an ownership proxy. Payment source (`initial`/`final`) is not lead source.

All six dashboard tabs were opened and captured. Every panel’s calculation was reviewed, with computed tables exported. Hourly panels inherit the dashboard’s Melbourne-to-India timezone assumption; the CSVs contain no timezone metadata to independently establish it.

Evidence: [source_inventory.csv](evidence/source_inventory.csv), [date_ranges.csv](evidence/date_ranges.csv), [quote_analysis_export.csv](evidence/quote_analysis_export.csv), [dashboard_next_three_months_export.csv](evidence/dashboard_next_three_months_export.csv), and the UI-downloaded file `ui_next_three_months_export.csv`.

## 2. Key findings

- **Quote demand weakened in August:** 439 quotes in July → 362 in August, **−77 (−17.5%)**. Australia accounts for **−80**, offset by **+3** New Zealand quotes. FIT contributes **−57**, Groups **−20**. These are two different decompositions of the same decline; do not add them together.
- **The apparent conversion improvement is not established:** August’s resolved win rate is **31.75% (20/63)** versus July’s **15.22% (35/230)**. Only **63/362 (17.4%)** of August quotes are in those resolved categories, versus **230/439 (52.4%)** in July. Recent cohorts have less time to resolve.
- **Revenue cannot be assessed reliably:** **9,646/9,973 quote totals are NULL (96.72%)**; 299 of the remaining 327 are zero. Only **28 quotes** have positive totals, all in October 2024. Do not call quote counts or payment-history rows revenue.
- **Agent rankings are incomplete:** **7,198/9,973 quotes (72.17%)** have no proxy owner. Even assigned quotes lack authoritative ownership; **52** queue-owned quotes have a different latest owner from the assignment available on 18 August.
- **Logging and date coverage distort trend interpretation:** **9,626/11,698 calls (82.29%)** lack an outcome. All **9,074 calls before June 2026** lack outcomes; missingness falls to **125/1,415 (8.8%)** in July. From 21–31 August, the four callers logged **1 call** while the queue recorded **2,944 rows**.
- **Record integrity needs reconciliation:** **9,154/221,070 stage-log rows (4.14%)** refer to missing quote IDs. Another **7,846 stage rows** are members of **3,000 identical-payload groups** (4,846 rows beyond the first). These are candidate duplicate emissions, not confirmed duplicates.

The evidence supports a quote-demand decline and several specific data/control weaknesses. It does not establish revenue movement, causal sales drivers, or a fair best/worst-agent verdict.

## 3. Sales trends

**August’s decline is concentrated in Australia.** Australian quotes fell **300 → 220 (−26.7%)**; New Zealand increased **139 → 142 (+2.2%)**. FIT fell **327 → 270 (−17.4%)** and Groups **112 → 92 (−17.9%)**. Creation accounts `abhisheks` and `Dhiraj` account for **−37 and −31**, together **68/77 (88.3%)** of the net decline. Creator is a recorded system actor, not an established sales owner or cause.

| month | quotes | accepted | resolved | resolved_win_pct |
| --- | --- | --- | --- | --- |
| 2026-01 | 466 | 41 | 374 | 10.96 |
| 2026-02 | 370 | 31 | 274 | 11.31 |
| 2026-03 | 393 | 35 | 312 | 11.22 |
| 2026-04 | 423 | 36 | 327 | 11.01 |
| 2026-05 | 356 | 40 | 291 | 13.75 |
| 2026-06 | 411 | 52 | 297 | 17.51 |
| 2026-07 | 439 | 35 | 230 | 15.22 |
| 2026-08 | 362 | 20 | 63 | 31.75 |
| 2026-09 | 6 | 2 | 2 | 100.0 |

**Observed acceptance activity also fell:** first-observed acceptance events decreased **62 in July → 36 in August (−41.9%)**. This is a different time basis from quote-created cohorts. **179 current accepted-family quotes have no matching Accepted audit event**, and **164 quotes with an observed acceptance are no longer in the accepted family**. Neither the audit series nor current-stage counts alone are a complete historical sales ledger.

**Seasonality is suggestive, not established.** The largest complete quote-created month is **September 2025 (662)**; the smallest complete month is **November 2024 (157)**. August 2026 is **253 quotes below August 2025 (615 → 362, −41.1%)**. Fewer than two full annual cycles are available, the first/last months are partial, and logging coverage changes. Do not infer a recurring seasonal pattern from the peak alone. The open trip horizon is concentrated in **November–December 2026: 302/643 future-trip open quotes (47.0%)**, which is useful for workload planning, not proof of seasonal sales.

**September is not a full-month comparison:** just **6 non-deleted quotes** are present through 10 September; quote volume after that is not supplied. Across the creation-date span, **55 days have zero non-deleted quote records**, including **36 Sundays** and **19 other days**. Zero records can reflect inactivity or export gaps. The largest daily spike is **330 quotes on 8 October 2024**: **327 share exactly 17:04:46**, have missing creation actors, and account for every populated quote total. This strongly suggests a batch/import boundary; the origin is unconfirmed. Treat this spike separately from organic demand. The complete affected extract and an example appear under I22 in section 8.

**Call trends cannot explain the sales decline as a cause.** Team calls drop from **66 on 17 August** to **43 on 18 August**, **9 on 19 August** and **1 on 20 August**. There are **7 queue-run days with zero team calls during 21–31 August**, and just **1 logged team call in that entire interval**. Queue rows rise **230 on 18 August → 413 on 31 August (+79.6%)**. A broken/incomplete export and changes in logging behavior remain competing explanations.

Evidence: [monthly_quote_cohorts.csv](evidence/monthly_quote_cohorts.csv), [first_acceptance_month.csv](evidence/first_acceptance_month.csv), [jul_aug_driver_country.csv](evidence/jul_aug_driver_country.csv), [jul_aug_driver_segment.csv](evidence/jul_aug_driver_segment.csv), [jul_aug_driver_created_by.csv](evidence/jul_aug_driver_created_by.csv), [daily_quote_volume.csv](evidence/daily_quote_volume.csv), [zero_quote_days.csv](evidence/zero_quote_days.csv), [calls_queue_daily.csv](evidence/calls_queue_daily.csv), [accepted_without_audit.csv](evidence/accepted_without_audit.csv), [acceptance_reversals.csv](evidence/acceptance_reversals.csv).

## 4. Top and bottom performers

**Destination:** Australia leads accepted-quote volume (**745**) versus New Zealand (**238**), mainly alongside a much larger quote base (**7,422 vs 2,438**). The current accepted share of all quotes is similar (**10.04% vs 9.76%**). Resolved win rates are **14.15% vs 12.49%**; this excludes Auto Rejected and cannot establish destination profitability. **75 unknown destinations** have no accepted quotes, but missing location is not a meaningful bottom-performing geography.

| country | quotes | accepted | rejected | auto_rejected | resolved_win_pct |
| --- | --- | --- | --- | --- | --- |
| Australia | 7422 | 745 | 4521 | 1732 | 14.15 |
| New Zealand | 2438 | 238 | 1667 | 280 | 12.49 |
| Unknown | 75 | 0 | 17 | 58 | 0.0 |

**Product and vendor: unavailable.** Product ID is missing on **9,973/9,973 quotes**; there are **0 exported product or vendor dimension tables**, and no field dictionary for the opaque custom fields. Tour type and quote type are populated on only **1 record each**, quote category on **2**. No valid top/bottom product or vendor ranking can be produced. **Lead source is also unmapped across the 9,973 quotes**; payment `source` has **859 initial**, **178 final**, and **1,302 NULL** values and must not be substituted.

**Available segment proxy:** FIT has **714 accepted / 6,825 quotes (10.46%)**; Groups has **269 / 3,110 (8.65%)**. Among manually resolved quotes the ordering reverses (**13.42% FIT vs 14.41% Groups**) because the denominators exclude different mixes of Auto Rejected and unresolved quotes. Report both denominators; do not label Groups a weaker product.

**Agents—activity only, 1–18 August:**

| login | calls | quotes |
| --- | --- | --- |
| ArunP | 159 | 80 |
| HarshP | 185 | 140 |
| hemant | 204 | 176 |
| karthik | 4 | 4 |

Hemant logs the most calls (**204**); Karthik the fewest (**4**). The dashboard attributes **14 accepted quotes** to Hemant, **6** to Harsh, **4** to Karthik and **1** to Arun. Karthik’s displayed conversion is **50% from only 8 resolutions**, versus Hemant’s **11.76% from 119**. Latest-owner approximation, different workloads and missing outcomes prevent a fair sales-performance ranking.

**Accounts are customers/organisations, not vendors.** The largest displayed account is internal **#91445**, with **252 included quotes (25 accepted, 225 rejected, 2 open)**; its inclusion in Account insights differs from exclusion from Accepted this month. Excluding that internal account, **#526083** leads the displayed volume list (**190 included quotes, 38 accepted**). Account **#53082** has **31 rejected / 31 manually resolved (100%)**, plus **7 open**—a focused follow-up candidate, not proof of why it loses.

Evidence: [destinations.csv](evidence/destinations.csv), [fit_group_proxy.csv](evidence/fit_group_proxy.csv), [schema_unsupported_dimensions.csv](evidence/schema_unsupported_dimensions.csv), [team_call_rank.csv](evidence/team_call_rank.csv), [panel_person_summary.csv](evidence/panel_person_summary.csv), [panel_accounts_volume.csv](evidence/panel_accounts_volume.csv), [panel_accounts_rejection.csv](evidence/panel_accounts_rejection.csv), [payment_source_profile.csv](evidence/payment_source_profile.csv).

## 5. Data gaps

**Missingness is concentrated, so a single completeness rate is misleading.**

- **Amounts:** 9,646 NULL quote totals; all **327 populated totals are from October 2024**. Every other creation month has **100% missing totals**. Only 28 totals are positive.
- **Outcomes:** 9,626 missing among 11,698 calls; **9,074/9,074 before June 2026**, **362/595 in June (60.8%)**, **125/1,415 in July (8.8%)**, and **62/587 in August (10.6%)**. This looks like a recording/schema change; the cause is unverified. Agent concentrations are attached.
- **Ownership:** 7,198 missing proxy owners, including **4,360 Rejected**, **1,840 Auto Rejected**, **126 Created**, and **2 Requote**. The two open-stage counts total **128/645 (19.8%)** of the open book. All 9,973 quotes lack exported authoritative owner data, even when a proxy exists.
- **Trips:** **86 zero-date sentinels (`0000-00-00`)**, concentrated in **63 Auto Rejected and 23 Rejected quotes**. There are **0 missing trips in the current Created/Requote book**, so this gap does not reduce that default horizon.
- **Location:** **72 empty countries + 3 N/A = 75**. Origin region cannot be recovered from `region_id`: **9,646 NULL + 327 zero**, with no region lookup. Destination and customer origin must remain separate.
- **Payments:** **1,037/2,339 (44.3%)** processing dates missing; **2,339/2,339** cleared dates and balances missing. Amount columns use disjoint populations: **1,037 total_amount rows** and **1,302 trams_received_amount rows**. Their business meanings and currencies are not established, so summing them as a single sales measure would be unsafe.
- **History and source coverage:** queue history is only **57 run dates**, 25 June–31 August; quote-created history spans 25 calendar months. The **179 accepted-family quotes without an Accepted audit event** show that audit coverage does not reconstruct all historical sales.
- **Unavailable panels:** **4** placeholders—Channel mix, Rejection reasons, quote-origin map, and By-person Region/Priority. Missing dependencies include `vtiger_quotes_info`, `tdu_organisation`, `tdu_quote_closure_feedback`, `tdu_quotes_followup_ext`, and `vtiger_groups`. Organisation names cannot be resolved; product, vendor and acquisition-source cuts remain unavailable.

Proof: full field-level completeness, date coverage, missingness by month/stage/agent, unsupported-dimension schema and row-level examples are included in the evidence pack. Empty/NULL values and invalid zero dates are counted separately; they are not silently converted to zero.

## 6. Data issues

Counts below are affected rows unless stated. They overlap and must not be summed. Confirmed observations are separated from candidate errors; large parties and repeated event payloads require business validation. Every item has a full extract and example in section 8.

**I01 — Queue runs continue on days without team call logs (High).** 9/57 run dates (15.79%). A missing log is not proof of no work. Call KPIs cannot be used as a productivity verdict after the logging break.

**I02 — Quote total is NULL (High).** 9,646/9,973 records (96.72%). Revenue and monetary product rankings are not supportable. Currency ID 1 has no exported currency-name mapping.

**I03 — Trip date is missing or unparseable (High).** 86/9,973 records (0.86%). These quotes disappear from trip-horizon and future-trip cohorts.

**I04 — Dashboard proxy owner cannot be assigned (High).** 7,198/9,973 records (72.17%). Owner comparisons are incomplete; all assigned owners are approximations rather than authoritative sales ownership.

**I05 — Country is blank or N/A (High).** 75/9,973 records (0.75%). These records cannot be assigned to Australia or New Zealand and are grouped as Unknown by the dashboard. Country is destination, not customer origin.

**I07 — Trip date precedes quote creation date (Medium).** 140/9,973 records (1.40%). Historical imports or date errors could explain these records; not automatically invalid sales.

**orphan — Unmatched quote references in vtiger_quote_stage_track (High).** 9,154/221,070 records (4.14%). Inner joins silently drop these child records.

**I08 — Repeated identical stage-log payloads with different IDs (High).** 7,846/221,070 records (3.55%). Candidate duplicate emissions; event counts can inflate. Separate IDs are not proof of distinct business transitions.

**I09 — Repeated structured follow-up payloads (Medium).** 129/25,901 records (0.50%). Candidate duplicates, not proven duplicate conversations: free-text description and contact fields are intentionally excluded.

**I10 — Two spellings of rejection after confirmation (Medium).** 20/9,973 records (0.20%). Suyash normalizes both spellings for stage groups, but timeline reversal detection searches only Confrmation.

**I11 — Call entries have no outcome (High).** 9,626/11,698 records (82.29%). Contact-rate denominator excludes these calls, while call volume includes them.

**I12 — Call timestamp precedes quote creation (Medium).** 286/11,698 records (2.44%). This can distort first-response time; imports or backdated logs need checking.

**I13 — Historical dashboard basket includes later-created quotes (High).** 36/1,629 records (2.21%). Treat as today changes dates but does not reconstruct the historical snapshot; live_basket applies no creation-date cutoff.

**I14 — Latest queue owner differs from owner available on 18 August (High).** 52/1,218 records (4.27%). Historical accepted/lifecycle credit uses latest queue owner, including assignments after the selected day.

**I15 — Open Created/Requote quotes have trips before last quote-record date (Medium).** 51/645 records (7.91%). Stale pipeline candidates as of 10 September; not proof the travel did not occur.

**I16 — Passenger count exceeds empirical 99th percentile (Low).** 92/9,973 records (0.92%). Review candidates above 200 pax; large groups are not inherently errors.

**I18 — Call records are dated after the review date (Medium).** 1/11,698 records (0.01%). These are future-dated call_info entries as of 21 September 2026, not completed historical calls.

**I19 — Payment processing date is missing or invalid (High).** 1,037/2,339 records (44.34%). Payment-date trends are incomplete; added_on is a record timestamp, not necessarily cash receipt date.

**I20 — Populated no_pax differs from adults + children + infants (Medium).** 1,433/6,575 records (21.79%). Two passenger measures disagree; no_pax may use a different definition. Dashboard uses adults + children + infants.

**I21 — Country uses inconsistent casing (Low).** 2/9,973 records (0.02%). Raw groupings split Australia into two labels; dashboard title-case normalization already combines them.

**I22 — Quote creation timestamps cluster in one second (High).** 327/9,973 records (3.28%). 327 records share one second, have missing creation actors, and are exactly the only quotes with populated totals. This strongly suggests a batch/import boundary, not an organic demand spike; import provenance is unconfirmed.

**I23 — Current accepted-family quotes lack an Accepted audit event (High).** 179/983 records (18.21%). Current state and event history cannot be fully reconciled; the first-observed acceptance series is incomplete.

**Duplicate interpretation:** no duplicated primary keys or exact full-row duplicates were found in the 7 tables, no duplicate quote numbers were found, and no repeated queue date/user/type/item keys were found. Stage-payload repetition affects **7,846 rows in 3,000 groups**; keeping one per payload would remove **4,846 rows**, but that is a sensitivity count, not a recommended source edit. The follow-up candidate check covers **129 rows** and deliberately excludes free text; these are not proven duplicates.

**Additional definition risks:** the largest passenger record is **2,550 pax (TDU25537G)**; **92** quotes exceed the empirical **200-pax 99th percentile**. A separate `no_pax` measure disagrees with adults + children + infants on **1,433/6,575 populated records (21.79%)**. Confirm measure definitions before labeling either value wrong. A **single future call**, auto_id **24081**, has calltime **28 September 2026** despite created_at **28 May 2026**; it is future-dated relative to this 21 September review.

**Historical KPI comparability:** the 18 August live basket contains **36 later-created quotes**. Owner look-ahead affects **52 of 1,218 quotes with queue ownership available by that date**. Current stages and unchecked schedules also feed historical views. The dashboard’s Data notes disclose snapshot mixing, but the date filter does not create a historical snapshot. Do not compare those displays as point-in-time pipeline balances.

**Confidence:** counts and examples are reproducible from the supplied exports. Root causes, whether repeat emissions are duplicates, and whether old trips/large parties are errors remain unconfirmed. No corrections were applied.

## 7. Observations & recommendations

**Before using this dashboard for performance decisions:**

1. **Reconcile logging coverage and outcome definitions.** The post-20-August collapse and 9,626 missing outcomes make inactivity and contact-rate judgments unreliable. Obtain a fresh follow-up export and identify when structured outcomes became required. Treat “unknown outcome” as its own category. `next_call` currently counts as reached; confirm that it represents contact rather than merely scheduling.
2. **Separate snapshot metrics from historical metrics.** Label current stages/owners explicitly; reconstruct historical state only with complete event and ownership history. Validate the 36 future-created basket records and 52 changed owners against effective dates. Use creation-date guards where appropriate; a date guard alone does not reconstruct prior stages.
3. **Establish revenue and dimension coverage.** Obtain invoice/payment semantics, currency mapping, product line items, vendor IDs, acquisition source and their dictionaries. Until then, use “quote count” and “accepted quote count,” with the 9,646 missing totals disclosed. Review the 1,037 missing payment dates and two amount populations before cash reporting.
4. **Investigate the August demand decline.** Start with Australia (−80 quotes), then creation accounts abhisheks (−37) and Dhiraj (−31). Check intake volume, routing and export completeness; these are measured contributions, not established causes. Compare equally mature quote cohorts before claiming improved conversion.
5. **Review workload concentration and stale pipeline.** Karthik has 1,142 surfaced quote-days but 26 worked in 5–18 August, while logging just 4 calls in 1–18 August. Confirm assignment and logging before coaching or reallocating. Review 51 open quotes with past trips individually. Use the November–December 302-quote travel cluster for planning, subject to snapshot limitations.
6. **Reconcile integrity failures without deleting evidence.** Check 9,154 unmatched stage rows against export scope. Investigate the 3,000 repeated stage-payload groups and 129 follow-up candidates. Define canonical stage and country dictionaries, preserve event IDs and retain the raw exports. Confirm the 327-record creation batch before using October 2024 as a baseline.
7. **Validate temporal and passenger exceptions.** Review 140 trips before quote creation, 286 calls before quote creation, the 1 future-dated call, 1,433 passenger-count disagreements and 92 large-party candidates. Confirm import/timezone/measure definitions before correction.
8. **Make denominator and cohort rules consistent.** Separate event counts from distinct quotes, accepted-current-state from first acceptance, and customer accounts from internal #91445. Restore the 4 unavailable panels only after their source tables are supplied. Add stable checks for key uniqueness, parent coverage, mandatory-field validity, date boundaries and documented outcome completeness.

These are proposed follow-ups for your review. No messages, source edits, closures, publication or sharing have been performed.

## 8. Proof

Each issue below includes an example and an extract of **every affected row** under the stated rule. The report’s source inspector contains the same reviewed evidence. IDs allow records to be traced back to the unchanged CSVs; direct contact information and free-text call descriptions are omitted.

The evidence folder also contains all 6 dashboard-tab screenshots (plus scrolled captures), the UI-downloaded CSV, full source hashes, date/field profiles, all panel calculation exports, and the panel-by-panel review. `analyze.py` and `analysis.ipynb` reproduce the calculations. `SALES_FINDINGS_REPORT.md` is a readable companion to this report. Raw source files remain in the original data folder.

**Validation:** every file was re-hashed after review; quote/custom-field joins retain one row per quote; primary keys and duplicate quote numbers were checked; driver contributions reconcile to the −77 July/August change. The UI export is checked against the independently exported metric function output. Screenshots corroborate layout and scope; findings are calculated from files, not inferred from chart pixels.

### Issue examples and full extracts

**I01_queue_without_team_calls — 9 affected.** Example: date: 2026-06-27T00:00:00.000; team_calls: 0; queue_rows: 302.

Recommendation: Re-export follow-ups and reconcile logging coverage with source owners.

Full proof: [I01_queue_without_team_calls.csv](evidence/I01_queue_without_team_calls.csv)

**I02_missing_amount — 9,646 affected.** Example: quoteid: 9; quote_no: TDU00004G; created_at: 2024-09-16 22:06:03; total: NULL.

Recommendation: Obtain populated quote/invoice line totals, currency definitions and revenue recognition rules.

Full proof: [I02_missing_amount.csv](evidence/I02_missing_amount.csv)

**I03_missing_trip — 86 affected.** Example: quoteid: 1777358; quote_no: TDU17961G; stage: Rejected; created_at: 2024-10-08T17:04:46.000; trip: None; country: Australia; pax: 1; owner: None.

Recommendation: Confirm when a trip date is mandatory; backfill from authoritative itinerary data.

Full proof: [I03_missing_trip.csv](evidence/I03_missing_trip.csv)

**I04_missing_owner — 7,198 affected.** Example: quoteid: 9; quote_no: TDU00004G; stage: On Ground; created_at: 2024-09-16T22:06:03.000; trip: 2024-09-22T00:00:00.000; country: Australia; pax: 1; owner: None.

Recommendation: Export vtiger_quotes_info with effective ownership history.

Full proof: [I04_missing_owner.csv](evidence/I04_missing_owner.csv)

**I05_country_unusable — 75 affected.** Example: quoteid: 2028409; quote_no: TDU20586G; created_at: 2024-10-08 17:04:46; country: .

Recommendation: Validate destination capture and obtain origin-region lookup tables.

Full proof: [I05_country_unusable.csv](evidence/I05_country_unusable.csv)

**I07_trip_before_creation — 140 affected.** Example: quoteid: 1397762; quote_no: TDU12967G; stage: Completed (Accounts); created_at: 2024-10-08T17:04:46.000; trip: 2024-06-21T00:00:00.000; country: Australia; pax: 1; owner: None.

Recommendation: Verify examples against source itineraries and import history.

Full proof: [I07_trip_before_creation.csv](evidence/I07_trip_before_creation.csv)

**orphan_vtiger_quote_stage_track — 9,154 affected.** Example: auto_id: 2; quoteid: 2347186; stage: Create Project; created_at: 2024-10-02 10:07:04.

Recommendation: Reconcile export scope and parent IDs before combining tables.

Full proof: [orphan_vtiger_quote_stage_track.csv](evidence/orphan_vtiger_quote_stage_track.csv)

**I08_stage_duplicate_candidates — 7,846 affected.** Example: auto_id: 923; quoteid: 2347636; stage: Add Product: Hotel Stay - Rydges Rotorua or Simila; created_at: 2024-12-02 20:21:36.

Recommendation: Review repeated payloads and define an event idempotency key. Preserve raw logs.

Full proof: [I08_stage_duplicate_candidates.csv](evidence/I08_stage_duplicate_candidates.csv)

**I09_followup_duplicate_candidates — 129 affected.** Example: auto_id: 4097; quoteid: 2350313; created_at: 2025-04-07 21:29:32; calltime: 2025-04-07 16:59:00.

Recommendation: Inspect candidate records in CRM before deduplication.

Full proof: [I09_followup_duplicate_candidates.csv](evidence/I09_followup_duplicate_candidates.csv)

**I10_stage_spelling — 20 affected.** Example: quoteid: 36; quote_no: TDU00016G; stage: Rejected After Confirmation QA pending; created_at: 2024-09-17T20:20:32.000; trip: 2025-03-12T00:00:00.000; country: New Zealand; pax: 150; owner: None.

Recommendation: Use a canonical stage dictionary in all metrics and timeline flags.

Full proof: [I10_stage_spelling.csv](evidence/I10_stage_spelling.csv)

**I11_call_outcomes_missing — 9,626 affected.** Example: auto_id: 218; quoteid: 2245698; calltime: 2024-11-11T16:13:00.000.

Recommendation: Measure outcome completeness by agent/time and define a separate unknown-outcome category.

Full proof: [I11_call_outcomes_missing.csv](evidence/I11_call_outcomes_missing.csv)

**I12_call_before_creation — 286 affected.** Example: auto_id: 257; quoteid: 2350193; created_at: 2024-11-12T21:22:14.000; calltime: 2024-11-12T15:52:00.000.

Recommendation: Reconcile source timezone and imported quote/call timestamps.

Full proof: [I12_call_before_creation.csv](evidence/I12_call_before_creation.csv)

**I13_future_quotes_in_historical_basket — 36 affected.** Example: quoteid: 2357967; quote_no: TDU27511G; stage: Created; created_at: 2026-08-19T05:53:31.000; trip: 2026-10-27T00:00:00.000; country: Australia; pax: 200; owner: ArunP.

Recommendation: Label panels as export snapshot or reconstruct as-of states before historical comparisons.

Full proof: [I13_future_quotes_in_historical_basket.csv](evidence/I13_future_quotes_in_historical_basket.csv)

**I14_owner_lookahead — 52 affected.** Example: quoteid: 2356727; quote_no: TDU35990; stage: Created; created_at: 2026-05-27T22:33:03.000; trip: 2026-11-23T00:00:00.000; country: Australia; pax: 2; owner: hemant.

Recommendation: Use time-valid ownership and separate activity actor from sales owner.

Full proof: [I14_owner_lookahead.csv](evidence/I14_owner_lookahead.csv)

**I15_open_past_trips — 51 affected.** Example: quoteid: 2353578; quote_no: TDU26439G; stage: Created; created_at: 2025-10-16T13:23:28.000; trip: 2026-09-01T00:00:00.000; country: Australia; pax: 14; owner: None.

Recommendation: Review disposition and trip rescheduling; do not automatically close records.

Full proof: [I15_open_past_trips.csv](evidence/I15_open_past_trips.csv)

**I16_large_party_review — 92 affected.** Example: quoteid: 2347404; quote_no: TDU24927G; stage: Auto Rejected; created_at: 2024-11-02T16:08:01.000; trip: 2025-02-06T00:00:00.000; country: Australia; pax: 250; owner: None.

Recommendation: Compare passenger manifest and FIT/Groups classification for the largest cases.

Full proof: [I16_large_party_review.csv](evidence/I16_large_party_review.csv)

**I18_future_call_dates — 1 affected.** Example: auto_id: 24081; quoteid: 2356685; created_at: 2026-05-28T18:47:48.000; calltime: 2026-09-28T14:17:00.000.

Recommendation: Check whether scheduled contact was recorded as a completed call.

Full proof: [I18_future_call_dates.csv](evidence/I18_future_call_dates.csv)

**I19_payment_dates — 1,037 affected.** Example: auto_id: 1; quoteid: 2261017; process_date: NULL.

Recommendation: Confirm the payment lifecycle and canonical receipt date before cash-flow reporting.

Full proof: [I19_payment_dates.csv](evidence/I19_payment_dates.csv)

**I20_pax_disagreement — 1,433 affected.** Example: quoteid: 9; quote_no: TDU00004G; no_pax: 18; calculated_pax: 1.

Recommendation: Confirm whether infants/free-of-charge passengers belong in each measure before standardizing.

Full proof: [I20_pax_disagreement.csv](evidence/I20_pax_disagreement.csv)

**I21_country_case — 2 affected.** Example: quoteid: 2350802; quote_no: TDU31527; country: AUSTRALIA.

Recommendation: Standardize the country dictionary upstream.

Full proof: [I21_country_case.csv](evidence/I21_country_case.csv)

**I22_creation_batch — 327 affected.** Example: quoteid: 1333228; quote_no: TDU12569G; created_at: 2024-10-08 17:04:46; total: 0.00000000.

Recommendation: Confirm migration history and preserve original creation timestamps before using October 2024 as a trend baseline.

Full proof: [I22_creation_batch.csv](evidence/I22_creation_batch.csv)

**I23_acceptance_audit_gap — 179 affected.** Example: quoteid: 9; quote_no: TDU00004G; stage: On Ground; created_at: 2024-09-16T22:06:03.000; trip: 2024-09-22T00:00:00.000; country: Australia; pax: 1; owner: None.

Recommendation: Obtain full audit history or an authoritative acceptance timestamp before measuring historical sales.

Full proof: [I23_acceptance_audit_gap.csv](evidence/I23_acceptance_audit_gap.csv)

### Dashboard panel review

| panel | finding | evidence_query |
| --- | --- | --- |
| Header KPIs | 17 August: 66 calls, 96/242 worked (39.7%), 214 carryover rows; 18 August: 43 calls, 44/193 worked (22.8%), 135 current carryover quotes. Backlog units/populations differ. | panel_worked |
| Calls logged | 5–18 August: Hemant 164, Arun 142, Harsh 133, Karthik 0. Sundays hidden in charts; the exported functions retain all dates. | call_rank_14d |
| Queue worked | Hemant 234/368 (63.6%), Harsh 183/355 (51.5%), Arun 84/489 (17.2%), Karthik 26/1142 (2.3%). Worked can be any caller outcome or a stage change, not necessarily the assigned caller doing the work. | worked_rank_14d |
| Contact rate | Arun displays 109/109 (100%) recognized outcomes, but 142 calls exist; 33 are outside that denominator. Do not interpret this as all calls connected. | contact_rank_14d |
| Outcome mix | 5–18 August has 403 recognized outcomes across 439 calls. Inspect missing/other outcomes alongside the plotted distribution. | panel_outcomes |
| Channel mix | 1 of 4 unavailable panels; channel extension table absent. | schema_unsupported_dimensions |
| Calls by hour | 90-day in-window raw peak: 343 calls in the 17:00 IST hour, versus 341 at 12:00. Chart divides by per-agent active days, so raw totals and displayed averages differ. Melbourne source timezone is assumed. | hour_total_90d |
| Accepted this month | 1–18 August attributes 25 accepted quotes to the four callers (14/6/4/1); other/unassigned quotes are separate. Excludes internal account #91445. | dashboard_accepted_month_export |
| Quote lifecycle | Per-owner stage events: 25 accepted, 194 rejected, 6 requote. Event grain can differ from distinct quote grain. | panel_lifecycle |
| Win rate | Business-wide daily Accepted/(Accepted+Rejected) events use current accepted-family filtering. Not a mature created-cohort conversion rate. | panel_win_rate |
| Cycle time | Harsh 9.5 days/6 events; Hemant 14.2/14; Karthik 38.8/4; Arun 73/1. Small denominators and current-owner assignment limit comparisons. | panel_cycle_time |
| Backlog and age | 135 current carryover quotes at 18 August, oldest 50 days; snapshot stages and next-call dates affect inclusion/age. | panel_carryover |
| Coverage | 18 August: 193/517 (37.3%). Denominator is current open future-trip quotes ever in queue, not all quotes eligible on that historical date. | panel_coverage |
| Quotes owned now | Hemant 173, Harsh 170, Karthik 100, Arun 74 = 517 proxy-owned open quotes; 128 of 645 open quotes are outside these owners. | panel_owned |
| Capacity versus demand | 18 August modeled demand 170 versus assumed 336 slots; this is a configuration scenario, not observed staffing capacity. Future schedules and snapshot cohorts can suppress demand. | panel_capacity |
| Queue composition | 18 August follow-up buckets: 146 carryover of 193 rows (75.6%); 37 other queue rows lie outside these buckets. | panel_queue_composition |
| Unresponsive organisations | 1 displayed account #12535579, 3 contact days and 0 reached (100% unanswered). Limited to outcomes and minimum-contact-day threshold. | panel_unresponsive |
| Account insights | Internal account #91445 ranks first with 252 included quotes. #53082 has 31/31 manually resolved rejected; organisation names unavailable. | panel_accounts_volume |
| Rejection reasons | 1 of 4 unavailable panels; no closure-reason export. Cannot explain 6,205 manually rejected-family active quotes. | quote_stages |
| Live basket | 1,629 quotes includes 983 accepted-family and 645 Created/Requote plus 1 Requote After Confirmation. Includes completed/old-trip states, so “live” is not an open-sales-only count. | quote_stages |
| FIT/Groups composition | Default 645 open quotes: 396 FIT, 200 Groups <=40 pax, 49 Groups >40. Suffix-based segment, not product. | panel_default_composition |
| Destination | Default open book: Australia 392 vs New Zealand 253. Current overall quote-base split is different. | panel_default_destination |
| Travel horizon | 643 future-trip open quotes; November 172 and December 130 total 302 (47.0%). Date filter excludes trips on/before 18 August. | panel_default_horizon |
| Passenger counts | Default open book: 396 FIT average 4.0 pax; 249 Groups average 40.8 pax. Underlying no_pax conflicts with derived pax on 1,433 populated records. | I20_pax_disagreement |
| Origin map | 1 of 4 unavailable panels; country cannot substitute for customer origin. | schema_unsupported_dimensions |
| Next 3 months export | 791 non-deleted quotes with trip dates 1 August–31 October; includes rejected stages and past days within August. | dashboard_next_three_months_export |
| By-person summary | 1–18 August: Hemant 129 created-in-range under current ownership, Harsh 96, Arun 52, Karthik 23; these are not creation-actor counts. | panel_person_summary |
| By-person hourly calls | Hourly raw counts use selected date range and only 09:00–18:59 IST; timezone not independently verified. 4 agent books exported. | team_call_rank |
| Upcoming follow-ups | Four proxy-owned open books contain 517 quotes; overdue/unscheduled categories use snapshot schedules even when date is historical. | panel_owned |
| Live book and travel month | 4 book extracts expose trip date, last contact and next schedule; 51/645 business-wide open quotes have trips before 10 September. | I15_open_past_trips |
| Region/Priority donut | 1 of 4 unavailable panels; authoritative assignment table absent. | schema_unsupported_dimensions |
| Quote timeline | Three timeline lists (queue/calls/stages) use all supplied history; reversal matcher handles misspelling Confrmation only. 20 current rejection records use Confirmation. | I10_stage_spelling |
| Data notes | Documents 5 stand-ins and 4 unavailable panels; the snapshot caveat is real and quantified by 36 later-created basket quotes and 52 changed owners. | I13_future_quotes_in_historical_basket |