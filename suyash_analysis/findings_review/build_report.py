from pathlib import Path
import json, hashlib, csv
import pandas as pd
P=Path(__file__).resolve().parent; A=json.loads((P/'analysis.json').read_text()); T=A['tables']; sections=[]
def tab(name): return pd.DataFrame(T[name])
def mdtable(rows,cols=None):
    d=pd.DataFrame(rows)
    if cols: d=d[cols]
    def fmt(v):
        if v is None or (isinstance(v,float) and pd.isna(v)): return '—'
        return str(v).replace('|',' / ').replace('\n',' ')
    return '| '+' | '.join(d.columns)+' |\n| '+' | '.join(['---']*len(d.columns))+' |\n'+'\n'.join('| '+' | '.join(fmt(v) for v in row)+' |' for row in d.itertuples(index=False,name=None))
def section(id,title,body,queries): sections.append(dict(id=id,title=title,body=f'## {title}\n\n'+body,queries=queries))
def proof(name): return f'[{name}.csv](evidence/{name}.csv)'
inv=tab('source_inventory'); raw_total=int(inv.rows.sum())
section('overview','1. Overview',f'''**Review copy for Oliver • 21 September 2026 • not shared or published.**

Reviewed Suyash’s local Streamlit dashboard, its calculation code and all **7 supplied CRM CSV exports ({raw_total:,} rows)**. No source data or dashboard code was changed. The files are exports from `dev2yourbestwayh_v5`, per the handover; there was no live-system refresh. File hashes identify the exact version reviewed.

{mdtable(inv[['file','rows','columns']])}

Quote creation spans **16 September 2024–10 September 2026**. Quote analysis uses **9,935 non-deleted quotes**, excluding **38 deleted**; data-quality checks use all **9,973 quotes** unless stated. Stage logs span 2 October 2024–11 September 2026; queue runs cover 25 June–31 August 2026; payment records were added 8 November 2024–20 August 2026. These are record ranges, not known extraction timestamps. There is no shared verified snapshot cutoff.

Dashboard baseline: **Treat as today = 18 August 2026**, all four callers, Per person; trends cover **5–18 August**, with Sundays hidden. By person uses **1–18 August**. Pipeline defaults to **Created / Requote**. The dashboard’s CSV download was saved: **791 quotes**, trip dates **1 August–31 October 2026**, all non-deleted stages. It is a trip-window export, not the full dataset or a sales ledger. The full CSV inputs and derived quote export—not screen readings—drive this report.

**Definitions:** “accepted” means current accepted-family stage, not revenue or unique acceptance events. Resolved win rate = accepted ÷ (accepted + manually rejected family), excluding Auto Rejected and unresolved quotes. “First acceptance” means first *observed* Accepted audit event; audit history is incomplete. Country is destination. FIT/Groups is a quote-number suffix proxy, not a product catalogue. Latest queue assignee is an ownership proxy. Payment source (`initial`/`final`) is not lead source.

All six dashboard tabs were opened and captured. Every panel’s calculation was reviewed, with computed tables exported. Hourly panels inherit the dashboard’s Melbourne-to-India timezone assumption; the CSVs contain no timezone metadata to independently establish it.

Evidence: {proof('source_inventory')}, {proof('date_ranges')}, {proof('quote_analysis_export')}, {proof('dashboard_next_three_months_export')}, and the UI-downloaded file `ui_next_three_months_export.csv`.''',['source_inventory','date_ranges'])
section('key-findings','2. Key findings','''- **Quote demand weakened in August:** 439 quotes in July → 362 in August, **−77 (−17.5%)**. Australia accounts for **−80**, offset by **+3** New Zealand quotes. FIT contributes **−57**, Groups **−20**. These are two different decompositions of the same decline; do not add them together.
- **The apparent conversion improvement is not established:** August’s resolved win rate is **31.75% (20/63)** versus July’s **15.22% (35/230)**. Only **63/362 (17.4%)** of August quotes are in those resolved categories, versus **230/439 (52.4%)** in July. Recent cohorts have less time to resolve.
- **Revenue cannot be assessed reliably:** **9,646/9,973 quote totals are NULL (96.72%)**; 299 of the remaining 327 are zero. Only **28 quotes** have positive totals, all in October 2024. Do not call quote counts or payment-history rows revenue.
- **Agent rankings are incomplete:** **7,198/9,973 quotes (72.17%)** have no proxy owner. Even assigned quotes lack authoritative ownership; **52** queue-owned quotes have a different latest owner from the assignment available on 18 August.
- **Logging and date coverage distort trend interpretation:** **9,626/11,698 calls (82.29%)** lack an outcome. All **9,132 calls before June 2026** lack outcomes; missingness falls to **125/1,415 (8.8%)** in July. From 21–31 August, the four callers logged **1 call** while the queue recorded **2,944 rows**.
- **Record integrity needs reconciliation:** **9,154/221,070 stage-log rows (4.14%)** refer to missing quote IDs. Another **7,846 stage rows** are members of **3,000 identical-payload groups** (4,846 rows beyond the first). These are candidate duplicate emissions, not confirmed duplicates.

The evidence supports a quote-demand decline and several specific data/control weaknesses. It does not establish revenue movement, causal sales drivers, or a fair best/worst-agent verdict.''',['monthly_quote_cohorts','jul_aug_driver_country','jul_aug_driver_segment','issues_register','missing_outcome_by_month','calls_queue_daily','stage_duplicate_group_sizes'])
monthly=tab('monthly_quote_cohorts')
section('trends','3. Sales trends',f'''**August’s decline is concentrated in Australia.** Australian quotes fell **300 → 220 (−26.7%)**; New Zealand increased **139 → 142 (+2.2%)**. FIT fell **327 → 270 (−17.4%)** and Groups **112 → 92 (−17.9%)**. Creation accounts `abhisheks` and `Dhiraj` account for **−37 and −31**, together **68/77 (88.3%)** of the net decline. Creator is a recorded system actor, not an established sales owner or cause.

{mdtable(monthly[monthly.month.ge('2026-01')],[ 'month','quotes','accepted','resolved','resolved_win_pct'])}

**Observed acceptance activity also fell:** first-observed acceptance events decreased **62 in July → 36 in August (−41.9%)**. This is a different time basis from quote-created cohorts. **179 current accepted-family quotes have no matching Accepted audit event**, and **164 quotes with an observed acceptance are no longer in the accepted family**. Neither the audit series nor current-stage counts alone are a complete historical sales ledger.

**Seasonality is suggestive, not established.** The largest complete quote-created month is **September 2025 (662)**; the smallest complete month is **November 2024 (157)**. August 2026 is **253 quotes below August 2025 (615 → 362, −41.1%)**. Fewer than two full annual cycles are available, the first/last months are partial, and logging coverage changes. Do not infer a recurring seasonal pattern from the peak alone. The open trip horizon is concentrated in **November–December 2026: 302/643 future-trip open quotes (47.0%)**, which is useful for workload planning, not proof of seasonal sales.

**September is not a full-month comparison:** just **6 non-deleted quotes** are present through 10 September; quote volume after that is not supplied. Across the creation-date span, **55 days have zero non-deleted quote records**, including **36 Sundays** and **19 other days**. Zero records can reflect inactivity or export gaps. Daily maxima and zero dates are in the linked extracts.

**Call trends cannot explain the sales decline as a cause.** Team calls drop from **66 on 17 August** to **43 on 18 August**, **9 on 19 August** and **1 on 20 August**. There are **7 queue-run days with zero team calls during 21–31 August**, and just **1 logged team call in that entire interval**. Queue rows rise **230 on 18 August → 413 on 31 August (+79.6%)**. A broken/incomplete export and changes in logging behavior remain competing explanations.

Evidence: {proof('monthly_quote_cohorts')}, {proof('first_acceptance_month')}, {proof('jul_aug_driver_country')}, {proof('jul_aug_driver_segment')}, {proof('jul_aug_driver_created_by')}, {proof('daily_quote_volume')}, {proof('zero_quote_days')}, {proof('calls_queue_daily')}, {proof('accepted_without_audit')}, {proof('acceptance_reversals')}.''',['monthly_quote_cohorts','first_acceptance_month','jul_aug_driver_country','jul_aug_driver_segment','jul_aug_driver_created_by','panel_default_horizon','daily_quote_volume','gap_weekdays','calls_queue_daily','accepted_without_audit','acceptance_reversals'])
section('performers','4. Top and bottom performers',f'''**Destination:** Australia leads accepted-quote volume (**745**) versus New Zealand (**238**), mainly alongside a much larger quote base (**7,422 vs 2,438**). The current accepted share of all quotes is similar (**10.04% vs 9.76%**). Resolved win rates are **14.15% vs 12.49%**; this excludes Auto Rejected and cannot establish destination profitability. **75 unknown destinations** have no accepted quotes, but missing location is not a meaningful bottom-performing geography.

{mdtable(T['destinations'],['country','quotes','accepted','rejected','auto_rejected','resolved_win_pct'])}

**Product and vendor: unavailable.** Product ID is missing on **9,973/9,973 quotes**; there are **0 exported product or vendor dimension tables**, and no field dictionary for the opaque custom fields. Tour type and quote type are populated on only **1 record each**, quote category on **2**. No valid top/bottom product or vendor ranking can be produced. **Lead source is also unmapped across the 9,973 quotes**; payment `source` has **859 initial**, **178 final**, and **1,302 NULL** values and must not be substituted.

**Available segment proxy:** FIT has **714 accepted / 6,825 quotes (10.46%)**; Groups has **269 / 3,110 (8.65%)**. Among manually resolved quotes the ordering reverses (**13.42% FIT vs 14.41% Groups**) because the denominators exclude different mixes of Auto Rejected and unresolved quotes. Report both denominators; do not label Groups a weaker product.

**Agents—activity only, 1–18 August:**

{mdtable(T['team_call_rank'],['login','calls','quotes'])}

Hemant logs the most calls (**204**); Karthik the fewest (**4**). The dashboard attributes **14 accepted quotes** to Hemant, **6** to Harsh, **4** to Karthik and **1** to Arun. Karthik’s displayed conversion is **50% from only 8 resolutions**, versus Hemant’s **11.76% from 119**. Latest-owner approximation, different workloads and missing outcomes prevent a fair sales-performance ranking.

**Accounts are customers/organisations, not vendors.** The largest displayed account is internal **#91445**, with **252 included quotes (25 accepted, 225 rejected, 2 open)**; its inclusion in Account insights conflicts with exclusion from Accepted this month. Excluding that internal account, **#526083** leads the displayed volume list (**190 included quotes, 38 accepted**). Account **#53082** has **31 rejected / 31 manually resolved (100%)**, plus **7 open**—a focused follow-up candidate, not proof of why it loses.

Evidence: {proof('destinations')}, {proof('fit_group_proxy')}, {proof('schema_unsupported_dimensions')}, {proof('team_call_rank')}, {proof('panel_person_summary')}, {proof('panel_accounts_volume')}, {proof('panel_accounts_rejection')}, {proof('payment_source_profile')}.''',['destinations','fit_group_proxy','schema_unsupported_dimensions','team_call_rank','panel_person_summary','panel_accounts_volume','panel_accounts_rejection','payment_source_profile','all_field_missingness'])
section('gaps','5. Data gaps','''**Missingness is concentrated, so a single completeness rate is misleading.**

- **Amounts:** 9,646 NULL quote totals; all **327 populated totals are from October 2024**. Every other creation month has **100% missing totals**. Only 28 totals are positive.
- **Outcomes:** 9,626 missing among 11,698 calls; **9,132/9,132 before June 2026**, **362/595 in June (60.8%)**, **125/1,415 in July (8.8%)**, and **62/587 in August (10.6%)**. This looks like a recording/schema change; the cause is unverified. Agent concentrations are attached.
- **Ownership:** 7,198 missing proxy owners, including **4,360 Rejected**, **1,840 Auto Rejected**, **126 Created**, and **2 Requote**. The two open-stage counts total **128/645 (19.8%)** of the open book. All 9,973 quotes lack exported authoritative owner data, even when a proxy exists.
- **Trips:** **86 zero-date sentinels (`0000-00-00`)**, concentrated in **63 Auto Rejected and 23 Rejected quotes**. There are **0 missing trips in the current Created/Requote book**, so this gap does not reduce that default horizon.
- **Location:** **72 empty countries + 3 N/A = 75**. Origin region cannot be recovered from `region_id`: **9,646 NULL + 327 zero**, with no region lookup. Destination and customer origin must remain separate.
- **Payments:** **1,037/2,339 (44.3%)** processing dates missing; **2,339/2,339** cleared dates and balances missing. Amount columns use disjoint populations: **1,037 total_amount rows** and **1,302 trams_received_amount rows**. Their business meanings and currencies are not established, so summing them as a single sales measure would be unsafe.
- **History and source coverage:** queue history is only **57 run dates**, 25 June–31 August; quote-created history spans 25 calendar months. The **179 accepted-family quotes without an Accepted audit event** show that audit coverage does not reconstruct all historical sales.
- **Unavailable panels:** **4** placeholders—Channel mix, Rejection reasons, quote-origin map, and By-person Region/Priority. Missing dependencies include `vtiger_quotes_info`, `tdu_organisation`, `tdu_quote_closure_feedback`, `tdu_quotes_followup_ext`, and `vtiger_groups`. Organisation names cannot be resolved; product, vendor and acquisition-source cuts remain unavailable.

Proof: full field-level completeness, date coverage, missingness by month/stage/agent, unsupported-dimension schema and row-level examples are included in the evidence pack. Empty/NULL values and invalid zero dates are counted separately; they are not silently converted to zero.''',['all_field_missingness','missing_amount_by_month','missing_outcome_by_month','missing_outcome_by_agent','missing_owner_by_stage','missing_trip_by_stage','country_raw_values','date_ranges','numeric_checks','schema_unsupported_dimensions','accepted_without_audit'])
issues=A['issues']
issuebody='Counts below are affected rows unless stated. They overlap and must not be summed. Confirmed observations are separated from candidate errors; large parties and repeated event payloads require business validation. Every item has a full extract and example in section 8.\n\n'
for i in issues:
    unit='run dates' if i['id'].startswith('I01') else 'records'
    issuebody+=f"**{i['id'].split('_')[0]} — {i['title']} ({i['severity']}).** {i['count']:,}/{i['denominator']:,} {unit} ({i['percent']:.2f}%). {i['meaning']}\n\n"
issuebody+='''**Duplicate interpretation:** no duplicated primary keys or exact full-row duplicates were found in the 7 tables, no duplicate quote numbers were found, and no repeated queue date/user/type/item keys were found. Stage-payload repetition affects **7,846 rows in 3,000 groups**; keeping one per payload would remove **4,846 rows**, but that is a sensitivity count, not a recommended source edit. The follow-up candidate check covers **129 rows** and deliberately excludes free text; these are not proven duplicates.

**Additional definition risks:** the largest passenger record is **2,550 pax (TDU25537G)**; **92** quotes exceed the empirical **200-pax 99th percentile**. A separate `no_pax` measure disagrees with adults + children + infants on **1,433/6,575 populated records (21.79%)**. Confirm measure definitions before labeling either value wrong. A **single future call**, auto_id **24081**, has calltime **28 September 2026** despite created_at **28 May 2026**; it is future-dated relative to this 21 September review.

**Historical KPI comparability:** the 18 August live basket contains **36 later-created quotes**. Owner look-ahead affects **52 of 1,218 quotes with queue ownership available by that date**. Current stages and unchecked schedules also feed historical views. The dashboard’s Data notes disclose snapshot mixing, but the date filter does not create a historical snapshot. Do not compare those displays as point-in-time pipeline balances.

**Confidence:** counts and examples are reproducible from the supplied exports. Root causes, whether repeat emissions are duplicates, and whether old trips/large parties are errors remain unconfirmed. No corrections were applied.'''
section('issues','6. Data issues',issuebody,['issues_register','key_checks','stage_duplicate_group_sizes','followup_duplicate_group_sizes','raw_quote_number_duplicates','queue_composite_duplicates','numeric_checks','I16_large_party_review','I18_future_call_dates'])
section('recommendations','7. Observations & recommendations','''**Before using this dashboard for performance decisions:**

1. **Reconcile logging coverage and outcome definitions.** The post-20-August collapse and 9,626 missing outcomes make inactivity and contact-rate judgments unreliable. Obtain a fresh follow-up export and identify when structured outcomes became required. Treat “unknown outcome” as its own category. `next_call` currently counts as reached; confirm that it represents contact rather than merely scheduling.
2. **Separate snapshot metrics from historical metrics.** Label current stages/owners explicitly; reconstruct historical state only with complete event and ownership history. Validate the 36 future-created basket records and 52 changed owners against effective dates. Use creation-date guards where appropriate; a date guard alone does not reconstruct prior stages.
3. **Establish revenue and dimension coverage.** Obtain invoice/payment semantics, currency mapping, product line items, vendor IDs, acquisition source and their dictionaries. Until then, use “quote count” and “accepted quote count,” with the 9,646 missing totals disclosed. Review the 1,037 missing payment dates and two amount populations before cash reporting.
4. **Investigate the August demand decline.** Start with Australia (−80 quotes), then creation accounts abhisheks (−37) and Dhiraj (−31). Check intake volume, routing and export completeness; these are measured contributions, not established causes. Compare equally mature quote cohorts before claiming improved conversion.
5. **Review workload concentration and stale pipeline.** Karthik has 1,142 surfaced quote-days but 26 worked in 5–18 August, while logging just 4 calls in 1–18 August. Confirm assignment and logging before coaching or reallocating. Review 51 open quotes with past trips individually. Use the November–December 302-quote travel cluster for planning, subject to snapshot limitations.
6. **Reconcile integrity failures without deleting evidence.** Check 9,154 unmatched stage rows against export scope. Investigate the 3,000 repeated stage-payload groups and 129 follow-up candidates. Define canonical stage and country dictionaries, preserve event IDs and retain the raw exports.
7. **Validate temporal and passenger exceptions.** Review 140 trips before quote creation, 286 calls before quote creation, the 1 future-dated call, 1,433 passenger-count disagreements and 92 large-party candidates. Confirm import/timezone/measure definitions before correction.
8. **Make denominator and cohort rules consistent.** Separate event counts from distinct quotes, accepted-current-state from first acceptance, and customer accounts from internal #91445. Restore the 4 unavailable panels only after their source tables are supplied. Add stable checks for key uniqueness, parent coverage, mandatory-field validity, date boundaries and documented outcome completeness.

These are proposed follow-ups for your review. No messages, source edits, closures, publication or sharing have been performed.''',['issues_register','jul_aug_driver_country','jul_aug_driver_created_by','worked_rank_14d','team_call_rank','panel_default_horizon','panel_accounts_volume'])
section('proof','8. Proof','''Each issue below includes an example and an extract of **every affected row** under the stated rule. The report’s source inspector contains the same reviewed evidence. IDs allow records to be traced back to the unchanged CSVs; direct contact information and free-text call descriptions are omitted.

The evidence folder also contains all 6 dashboard-tab screenshots (plus scrolled captures), the UI-downloaded CSV, full source hashes, date/field profiles, all panel calculation exports, and the panel-by-panel review. `analyze.py` and `analysis.ipynb` reproduce the calculations. `SALES_FINDINGS_REPORT.md` is a readable companion to this report. Raw source files remain in the original data folder.

**Validation:** every file was re-hashed after review; quote/custom-field joins retain one row per quote; primary keys and duplicate quote numbers were checked; driver contributions reconcile to the −77 July/August change. The UI export is checked against the independently exported metric function output. Screenshots corroborate layout and scope; findings are calculated from files, not inferred from chart pixels.''',['source_inventory','key_checks','issues_register'])
# Build a concise panel-by-panel review with exact extract identifiers.
panels=[
('Header KPIs','17 August: 66 calls, 96/242 worked (39.7%), 214 carryover rows; 18 August: 43 calls, 44/193 worked (22.8%), 135 current carryover quotes. Backlog units/populations differ.','panel_worked'),
('Calls logged','5–18 August: Hemant 164, Arun 142, Harsh 133, Karthik 0. Sundays hidden in charts; the exported functions retain all dates.','call_rank_14d'),
('Queue worked','Hemant 234/368 (63.6%), Harsh 183/355 (51.5%), Arun 84/489 (17.2%), Karthik 26/1142 (2.3%). Worked can be any caller outcome or a stage change, not necessarily the assigned caller doing the work.','worked_rank_14d'),
('Contact rate','Arun displays 109/109 (100%) recognized outcomes, but 142 calls exist; 33 are outside that denominator. Do not interpret this as all calls connected.','contact_rank_14d'),
('Outcome mix','5–18 August has 403 recognized outcomes across 439 calls. Inspect missing/other outcomes alongside the plotted distribution.','panel_outcomes'),
('Channel mix','1 of 4 unavailable panels; channel extension table absent.','schema_unsupported_dimensions'),
('Calls by hour','90-day in-window raw peak: 343 calls in the 17:00 IST hour, versus 341 at 12:00. Chart divides by per-agent active days, so raw totals and displayed averages differ. Melbourne source timezone is assumed.','hour_total_90d'),
('Accepted this month','1–18 August attributes 25 accepted quotes to the four callers (14/6/4/1); other/unassigned quotes are separate. Excludes internal account #91445.','dashboard_accepted_month_export'),
('Quote lifecycle','Per-owner stage events: 25 accepted, 194 rejected, 6 requote. Event grain can differ from distinct quote grain.','panel_lifecycle'),
('Win rate','Business-wide daily Accepted/(Accepted+Rejected) events use current accepted-family filtering. Not a mature created-cohort conversion rate.','panel_win_rate'),
('Cycle time','Harsh 9.5 days/6 events; Hemant 14.2/14; Karthik 38.8/4; Arun 73/1. Small denominators and current-owner assignment limit comparisons.','panel_cycle_time'),
('Backlog and age','135 current carryover quotes at 18 August, oldest 50 days; snapshot stages and next-call dates affect inclusion/age.','panel_carryover'),
('Coverage','18 August: 193/517 (37.3%). Denominator is current open future-trip quotes ever in queue, not all quotes eligible on that historical date.','panel_coverage'),
('Quotes owned now','Hemant 173, Harsh 170, Karthik 100, Arun 74 = 517 proxy-owned open quotes; 128 of 645 open quotes are outside these owners.','panel_owned'),
('Capacity versus demand','18 August modeled demand 170 versus assumed 336 slots; this is a configuration scenario, not observed staffing capacity. Future schedules and snapshot cohorts can suppress demand.','panel_capacity'),
('Queue composition','18 August follow-up buckets: 146 carryover of 193 rows (75.6%); 37 other queue rows lie outside these buckets.','panel_queue_composition'),
('Unresponsive organisations','1 displayed account #12535579, 3 contact days and 0 reached (100% unanswered). Limited to outcomes and minimum-contact-day threshold.','panel_unresponsive'),
('Account insights','Internal account #91445 ranks first with 252 included quotes. #53082 has 31/31 manually resolved rejected; organisation names unavailable.','panel_accounts_volume'),
('Rejection reasons','1 of 4 unavailable panels; no closure-reason export. Cannot explain 6,205 manually rejected-family active quotes.','quote_stages'),
('Live basket','1,629 quotes includes 983 accepted-family and 645 Created/Requote plus 1 Requote After Confirmation. Includes completed/old-trip states, so “live” is not an open-sales-only count.','quote_stages'),
('FIT/Groups composition','Default 645 open quotes: 396 FIT, 200 Groups <=40 pax, 49 Groups >40. Suffix-based segment, not product.','panel_default_composition'),
('Destination','Default open book: Australia 392 vs New Zealand 253. Current overall quote-base split is different.','panel_default_destination'),
('Travel horizon','643 future-trip open quotes; November 172 and December 130 total 302 (47.0%). Date filter excludes trips on/before 18 August.','panel_default_horizon'),
('Passenger counts','Default open book: 396 FIT average 4.0 pax; 249 Groups average 40.8 pax. Underlying no_pax conflicts with derived pax on 1,433 populated records.','I20_pax_disagreement'),
('Origin map','1 of 4 unavailable panels; country cannot substitute for customer origin.','schema_unsupported_dimensions'),
('Next 3 months export','791 non-deleted quotes with trip dates 1 August–31 October; includes rejected stages and past days within August.','dashboard_next_three_months_export'),
('By-person summary','1–18 August: Hemant 129 created-in-range under current ownership, Harsh 96, Arun 52, Karthik 23; these are not creation-actor counts.','panel_person_summary'),
('By-person hourly calls','Hourly raw counts use selected date range and only 09:00–18:59 IST; timezone not independently verified. 4 agent books exported.','team_call_rank'),
('Upcoming follow-ups','Four proxy-owned open books contain 517 quotes; overdue/unscheduled categories use snapshot schedules even when date is historical.','panel_owned'),
('Live book and travel month','4 book extracts expose trip date, last contact and next schedule; 51/645 business-wide open quotes have trips before 10 September.','I15_open_past_trips'),
('Region/Priority donut','1 of 4 unavailable panels; authoritative assignment table absent.','schema_unsupported_dimensions'),
('Quote timeline','Three timeline lists (queue/calls/stages) use all supplied history; reversal matcher handles misspelling Confrmation only. 20 current rejection records use Confirmation.','I10_stage_spelling'),
('Data notes','Documents 5 stand-ins and 4 unavailable panels; the snapshot caveat is real and quantified by 36 later-created basket quotes and 52 changed owners.','I13_future_quotes_in_historical_basket')]
pd.DataFrame(panels,columns=['panel','finding','evidence_query']).to_csv(P/'evidence/dashboard_panel_review.csv',index=False)
T['dashboard_panel_review']=[dict(panel=p,finding=f,evidence_query=e) for p,f,e in panels]
# Supplement issue proof with small human-readable examples.
evidence=[]
for i in issues:
    cols=list(T[i['id']][0]) if T[i['id']] else []
    preferred=[c for c in ['auto_id','quoteid','quote_no','date','stage','created_at','calltime','trip','country','pax','owner','owner_on_aug18','no_pax','calculated_pax','team_calls','queue_rows','total','process_date'] if c in cols]
    example={k:T[i['id']][0][k] for k in preferred[:8]}
    evidence.append(dict(**i,example_text='; '.join(f'{k}: {v}' for k,v in example.items()),query=i['id']))
for s in sections:
    s['body']=s['body'].replace('9,132','9,074')
    s['body']=s['body'].replace('NULL creation actors','missing creation actors').replace('its inclusion in Account insights conflicts with exclusion','its inclusion in Account insights differs from exclusion')
    if s['id']=='trends':
        s['body']=s['body'].replace('Daily maxima and zero dates are in the linked extracts.','The largest daily spike is **330 quotes on 8 October 2024**: **327 share exactly 17:04:46**, have missing creation actors, and account for every populated quote total. This strongly suggests a batch/import boundary; the origin is unconfirmed. Treat this spike separately from organic demand. The complete affected extract and an example appear under I22 in section 8.')
        s['queries'].append('I22_creation_batch')
    if s['id']=='recommendations':
        s['body']=s['body'].replace('Define canonical stage and country dictionaries, preserve event IDs and retain the raw exports.','Define canonical stage and country dictionaries, preserve event IDs and retain the raw exports. Confirm the 327-record creation batch before using October 2024 as a baseline.')
md='# Sales dashboard & dataset findings report\n\n'+'\n\n'.join(s['body'] for s in sections)
md+='\n\n### Issue examples and full extracts\n\n'
for i in evidence: md+=f"**{i['id']} — {i['count']:,} affected.** Example: {i['example_text']}.\n\nRecommendation: {i['action']}\n\nFull proof: {proof(i['id'])}\n\n"
md+='### Dashboard panel review\n\n'+mdtable(T['dashboard_panel_review'])
(P/'SALES_FINDINGS_REPORT.md').write_text(md,encoding='utf-8')
app=P/'report_app'; snapshot=json.loads((app/'src/data.json').read_text(encoding='utf-8'))
snapshot.update(title='Sales dashboard & dataset findings report',surface='report',buildStatus='creating',filters=[],status='reviewed',generatedAt='2026-09-21T00:00:00Z')
snapshot['queries']={}
for name,rows in T.items():
    snapshot['queries'][name]={'rows':rows,'source':{'label':name.replace('_',' '),'files':[r['file'] for r in T['source_inventory']],'description':f'Reviewed extract: evidence/{name}.csv. Generated from the seven supplied CSV files by analyze.py.','caveats':['Read-only supplied exports; no live refresh. Export timestamps and timezone metadata unavailable. Quote performance excludes 38 deleted quotes; quality checks include all rows unless stated.','Event counts, quote counts, and queue quote-days have different grains. Missingness includes blank and NULL literal; zero dates treated as invalid.'],'evidenceFlow':[{'title':'Read source files','detail':'Use the seven local CRM exports listed in source_inventory; verify SHA256 hashes.'},{'title':'Reproduce calculations','detail':'Run python suyash_analysis/findings_review/analyze.py. Rules and source columns are preserved in that script and analysis.ipynb.'}],'metricDefinitions':[{'label':'Reviewed table','definition':f'{name}: exact rows in its evidence CSV. Interpret using the report population, dates and denominators.','componentIds': [s['id'] for s in sections if name in s['queries']]}]}}
# All issue evidence is inspectable and downloadable; no personal contact fields included.
(app/'src/data.json').write_text(json.dumps(snapshot,ensure_ascii=False),encoding='utf-8')
(app/'src/content/report/findings.json').write_text(json.dumps({'sections':sections,'issues':evidence,'panels':T['dashboard_panel_review']},ensure_ascii=False),encoding='utf-8')
notebook={'nbformat':4,'nbformat_minor':5,'metadata':{'kernelspec':{'display_name':'Python 3','language':'python','name':'python3'}},'cells':[{'cell_type':'markdown','metadata':{},'source':['# Sales dashboard dataset audit\nRead-only calculations. Run from the findings_review directory. Source files are never written. See analyze.py and the source inventory for rules and hashes.'],'id':'overview'},{'cell_type':'code','execution_count':None,'metadata':{},'outputs':[],'source':['%run analyze.py'],'id':'run-audit'},{'cell_type':'code','execution_count':None,'metadata':{},'outputs':[],'source':['import pandas as pd\npd.read_csv("evidence/issues_register.csv")'],'id':'inspect-results'}]}
(P/'analysis.ipynb').write_text(json.dumps(notebook,indent=2),encoding='utf-8')
print('Report prepared:',len(sections),'sections;',len(issues),'issues;',len(T),'evidence tables')
