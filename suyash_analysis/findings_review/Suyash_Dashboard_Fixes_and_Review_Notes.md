# Suyash — dashboard fixes and review decisions

Prepared locally for review. Not sent. These are separate from the sales-facing report.

## T01 — Dashboard proxy owner cannot be assigned

7,198 affected records of 9,973 (72.17%). Owner comparisons are incomplete; all assigned owners are approximations rather than authoritative sales ownership.

Suggested fix: Export vtiger_quotes_info with effective ownership history.

Original proof: evidence/I04_missing_owner.csv

Example: {"quoteid": 9, "quote_no": "TDU00004G", "stage": "On Ground", "created_at": "2024-09-16T22:06:03.000", "trip": "2024-09-22T00:00:00.000", "country": "Australia", "pax": 1, "owner": null}

## T02 — Two spellings of rejection after confirmation

20 affected records of 9,973 (0.20%). Suyash normalizes both spellings for stage groups, but timeline reversal detection searches only Confrmation.

Suggested fix: Use a canonical stage dictionary in all metrics and timeline flags.

Original proof: evidence/I10_stage_spelling.csv

Example: {"quoteid": 36, "quote_no": "TDU00016G", "stage": "Rejected After Confirmation QA pending", "created_at": "2024-09-17T20:20:32.000", "trip": "2025-03-12T00:00:00.000", "country": "New Zealand", "pax": 150, "owner": null}

## T03 — Historical dashboard basket includes later-created quotes

36 affected records of 1,629 (2.21%). Treat as today changes dates but does not reconstruct the historical snapshot; live_basket applies no creation-date cutoff.

Suggested fix: Label panels as export snapshot or reconstruct as-of states before historical comparisons.

Original proof: evidence/I13_future_quotes_in_historical_basket.csv

Example: {"quoteid": 2357967, "quote_no": "TDU27511G", "stage": "Created", "created_at": "2026-08-19T05:53:31.000", "trip": "2026-10-27T00:00:00.000", "country": "Australia", "pax": 200, "owner": "ArunP"}

## T04 — Latest queue owner differs from owner available on 18 August

52 affected records of 1,218 (4.27%). Historical accepted/lifecycle credit uses latest queue owner, including assignments after the selected day.

Suggested fix: Use time-valid ownership and separate activity actor from sales owner.

Original proof: evidence/I14_owner_lookahead.csv

Example: {"quoteid": 2356727, "quote_no": "TDU35990", "stage": "Created", "created_at": "2026-05-27T22:33:03.000", "trip": "2026-11-23T00:00:00.000", "country": "Australia", "pax": 2, "owner": "hemant", "owner_on_aug18": "HarshP"}

## T05 — Account rankings use a different internal-account rule

Account #91445 has 252 included quotes: 25 accepted, 225 rejected and 2 open. The account panel includes it while Accepted this month excludes it. V2’s external-customer ranking excludes it consistently. Confirm the intended rule; this is a scope difference, not necessarily a defect. Proof: evidence/panel_accounts_volume.csv and dashboard functions account_insights / accepted_month.

## Named-caller handling — Suyash review before team circulation

Karthik: 100 current assigned open quotes; 26 worked out of 1,142 surfaced quote-days in 5–18 August; 0 calls in that range, 4 in 1–18 August. The draft flags this explicitly. Check assignment and logging before deciding whether to discuss it privately with Abhishek. No performance conclusion or message has been sent.

## Unresolved logging question

21–31 August contains 1 team call and 2,944 queue rows. Low confidence that work stopped; this may be an incomplete export or a logging change. The v2 team charts end on 18 August. Obtain confirmation from the dev team before circulation.

## Corrections to suggested headlines

- January–July is 270 wins / 2,105 decided quotes = 12.83%, about one in eight.
- Auto Rejected is 2,070/9,935 = 20.84%; status alone does not prove no decision occurred.
- 146/193 = 75.65% is specifically 18 August; shown daily shares range from 66.3% to 88.4%.
- Other follow-up buckets are not necessarily fresh work.
- The original non-empty register contains 22 issues, not 23. Appendix A has 18 data issues; T01–T04 preserve the other four, with an additional scope note T05. The complete master crosswalk is evidence_v2/issue_crosswalk.csv.
- The charts display October 2024–September 2026 (24 months). Partial September 2024 remains in the original series in the appendix.
- Proposed owners and deadlines have not been assigned or communicated.