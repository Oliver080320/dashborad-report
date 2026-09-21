from pathlib import Path
import json,re,hashlib,shutil
import pandas as pd

P=Path(__file__).resolve().parent; ROOT=P.parents[1]; APP=P/'report_app'
A=json.loads((P/'analysis.json').read_text(encoding='utf-8')); T=A['tables']
old=json.loads((P/'archive_v1/findings.json').read_text(encoding='utf-8'))
snapshot=json.loads((APP/'src/data.json').read_text(encoding='utf-8'))
out=P/'evidence_v2';out.mkdir(exist_ok=True)
def add(name,df,definition,files):
    rows=json.loads(df.to_json(orient='records',date_format='iso')) if isinstance(df,pd.DataFrame) else df
    pd.DataFrame(rows).to_csv(out/(name+'.csv'),index=False)
    snapshot['queries'][name]={'rows':rows,'source':{'label':name.replace('v2_','').replace('_',' '),'files':files,'description':definition,'caveats':['Supplied historical exports, not a fresh live-system read. See Appendix B for dates and definitions.'],'metricDefinitions':[{'label':name,'definition':definition}],'evidenceFlow':[{'title':'Reproduce','detail':'Run revise_v2.py after analyze.py; both read the unchanged seven CSV exports. Source fingerprints retained in source_inventory.'}]}}
    return rows
m=pd.DataFrame(T['monthly_quote_cohorts']); m=m[m.month.ge('2024-10')].copy()
m['date']=m.month+'-01';m['complete_quotes']=m.quotes.where(m.month.lt('2026-09'));m['partial_quotes']=m.quotes.where(m.month.ge('2026-08'))
m['import_note']=m.month.eq('2024-10').map({True:'327 quotes share one creation second; suspected import',False:''})
add('v2_monthly',m,'Non-deleted quotes grouped by creation month, October 2024–September 2026 (24 months). September 2026 is partial through 10 September. Full source series also contains partial September 2024 (90 quotes). October 2024 includes 327 suspected imported records.',['vtiger_quotes.csv'])
w=m[m.month.ge('2026-01')].copy();w['decided_win_pct']=100*w.accepted/w.resolved;w['earlier_win_pct']=w.decided_win_pct.where(w.month.le('2026-07'));w['recent_win_pct']=w.decided_win_pct.where(w.month.ge('2026-07'))
add('v2_win',w,'Current accepted (including later delivery/accounting stages) / (current accepted + rejected including rejection after confirmation) by quote-created month. Auto Rejected and unresolved excluded. January–July: 270/2105 = 12.8266%; August–September too recent for equal-maturity comparison.',['vtiger_quotes.csv'])
country=pd.DataFrame(T['jul_aug_driver_country']);country=country.rename(columns={'country':'name','change':'quote_change'})
add('v2_country_change',country,'August 2026 minus July 2026 non-deleted quote creation: Australia -80, New Zealand +3; sum -77.',['vtiger_quotes.csv'])
creator=pd.DataFrame(T['jul_aug_driver_created_by']); keep=creator.created_by.isin(['abhisheks','Dhiraj'])
other=creator[~keep][['2026-07','2026-08','change']].sum();creator2=creator[keep].copy();creator2=pd.concat([creator2,pd.DataFrame([{'created_by':'All other creators',**other.to_dict()}])]).rename(columns={'created_by':'name','change':'quote_change'})
add('v2_creator_change',creator2,'Creation accounts, not established sales owners: abhisheks -37, Dhiraj -31, all other accounts net -9. Sum -77; separate decomposition of the country movement.',['vtiger_quotes.csv'])
segments=[]
for table,dim in [('destinations','country'),('fit_group_proxy','segment')]:
    for r in T[table]:
        if r[dim]=='Unknown':continue
        name=r[dim].split(' (')[0]
        segments.append({'name':name,'quotes':r['quotes'],'accepted':r['accepted'],'decided':r['resolved'],'win_pct':r['resolved_win_pct']})
add('v2_segments',segments,'Non-deleted snapshot quotes. FIT/Groups follows quote-number suffix, not product. Win rate uses accepted / manually decided counts. Australia 745/5266; NZ 238/1905; FIT 714/5321; Groups 269/1867. Unknown destinations (75) excluded from the two-country comparison.',['vtiger_quotes.csv'])
q=pd.DataFrame(T['quote_analysis_export']);active=q[q.deleted.eq(0)].copy();stage=active.stage.str.lower()
accepted=set(['accepted','pre qa - pending','pre qa - completed','payment received - release vouchers','final qa','delivered','on ground','accounts - reconciliation','completed (accounts)'])
rejected=set(['rejected','rejected after confrmation qa pending','rejected after confrmation qa completed','rejected after confirmation qa pending','rejected after confirmation qa completed'])
active['accepted']=stage.isin(accepted);active['rejected']=stage.isin(rejected);active['open']=stage.isin(['created','requote'])
acct=active[(active.accepted|active.rejected|active.open)&active.accountid.ne(91445)&active.accountid.notna()].groupby('accountid').agg(quotes=('quoteid','size'),accepted=('accepted','sum'),rejected=('rejected','sum'),open=('open','sum')).reset_index().sort_values(['quotes','accountid'],ascending=[False,True]).head(10)
acct['name']=acct.accountid.astype(int).astype(str).map(lambda x:'#'+x);acct['decided']=acct.accepted+acct.rejected;acct['win_pct']=100*acct.accepted/acct.decided
add('v2_accounts',acct,'Top 10 external accounts by current accepted + manually rejected + Created/Requote quote count. Excludes internal organisation 91445, Auto Rejected, Lead and other states. Account IDs only: name lookup absent.',['vtiger_quotes.csv'])
team=pd.DataFrame(T['worked_rank_14d']).merge(pd.DataFrame(T['call_rank_14d']),on='user',how='left').fillna({'calls':0})
team=team.merge(pd.DataFrame(T['panel_owned']).rename(columns={'owner':'user','n':'open_quotes'}),on='user');names={'hemant':'Hemant','HarshP':'Harsh','ArunP':'Arun','karthik':'Karthik*'}
team['name']=team.user.map(names);team['worked_pct']=100*team.worked/team.surfaced;team['calls']=team.calls.astype(int)
team=team.sort_values('calls',ascending=False)
add('v2_team',team,'Four callers, 5–18 August 2026. Surfaced counts are quote-days (not unique quotes); worked means any logged outcome or qualifying stage change that day, not necessarily assigned-caller activity. Karthik 0 calls in this range, 4 in 1–18 August. Open book is current assigned caller (best available), not historical ownership.',['daily_runs.csv','vtiger_quotes_followup.csv','vtiger_quotes.csv','vtiger_quote_stage_track.csv'])
queue=pd.DataFrame(T['panel_queue_composition']).groupby(['date','bucket']).n.sum().unstack(fill_value=0).reset_index()
queue['carryover']=queue.carryover.astype(int);queue['other_followup']=queue[['sp1','sp2','sp3','sp4']].sum(axis=1).astype(int);queue['total']=queue.carryover+queue.other_followup;queue['carryover_pct']=100*queue.carryover/queue.total
add('v2_queue',queue[['date','carryover','other_followup','total','carryover_pct']],'Four callers, 5–18 August, observed run days; Sundays have no queue rows. Follow-up buckets only. Carryover + other follow-up equals total; other follow-up is SP1–SP4, not necessarily newly created work. 18 August: 146/193; range 66.3%–88.4%.',['daily_runs.csv'])
travel=pd.DataFrame(T['panel_default_horizon']);travel['display']=travel.label;travel['priority']=travel.label.isin(['Nov 2026','Dec 2026']);add('v2_travel',travel,'643 current Created/Requote quotes with trip after 18 August 2026. November 172 + December 130 = 302 (46.97%). Total current open book 645. Snapshot stages mixed with date anchor; not a live September planning count.',['vtiger_quotes.csv','vtiger_quotescf.csv'])
kpis=[{'label':'Quotes in August','value':'362','detail':'Latest complete month • 2026','number':362},{'label':'Change from July','value':'−18%','detail':'439 → 362 • 77 fewer quotes','number':-17.54},{'label':'Win rate on decided quotes','value':'13%','detail':'270 of 2,105 • Jan–Jul 2026','number':12.83},{'label':'Automatically rejected','value':'21%','detail':'2,070 of 9,935 • snapshot','number':20.84},{'label':'Open book','value':'645','detail':'Created / Requote • snapshot','number':645},{'label':'Carry-over backlog','value':'135','detail':'Quotes • 18 Aug view • oldest 50 days','number':135}]
add('v2_kpis',kpis,'Mixed scopes deliberately labeled: latest complete quote month August 2026; win rate January–July; current export snapshot stages for auto rejection and open book; dashboard-derived carryover on 18 August. Counts are quotes, not revenue.',['vtiger_quotes.csv','daily_runs.csv','vtiger_quotes_followup.csv','vtiger_quotescf.csv'])
actions=[
['Check Australian intake and routing','Suyash with Abhishek','Within 2 working days of review','Explain the 80-quote Australia decline and assign any routing fix.'],
['Confirm caller assignments and logging coverage','Suyash with the dev team','Before named team comparisons are shared','Confirm Karthik’s assignment and whether the late-August export is complete.'],
['Review repeat queue entries and automatic closures','Sales operations with the dev team','Within 5 working days of review','Agree an escalation rule; test a repeat-entry cap before rollout.'],
['Triage November–December trips and past-trip open quotes','Abhishek with assigned callers','Within 3 working days of a refreshed export','Give each relevant quote a next step; verify 51 past-trip records individually.'],
['Review customer #53082 before further quoting','Abhishek with the account contact','Within 2 working days of review','Review 7 open quotes and record why the previous 31 decided quotes were lost.'],
['Choose one conversion improvement to test','Abhishek with Suyash','Agree within 1 week of review','Use a same-age quote group and a baseline win rate; avoid a target chosen from immature August results.'],
['Repair the five decision-critical data gaps','Dev team with Suyash','Confirm scope within 1 week of review','Restore logging, ownership, time-valid history and usable commercial fields.']]
pages=[]
def paragraph(text):return {'type':'text','text':text}
def insight(title,text,action,confidence,reason,figure=None):
    return {'type':'insight','title':title,'text':text,'action':action,'confidence':confidence,'reason':reason,'figure':figure}
def page(id,section,title,blocks,queries):pages.append({'id':id,'section':section,'title':title,'blocks':blocks,'queries':queries})
page('scorecard','1. Scorecard','Quote requests fell 18%; repeat work needs attention',[
 {'type':'kpis'},
 paragraph('Five things to know: **362 quotes in August, down from 439. Australia accounts for the whole net fall.** The underlying win rate is **13% for January–July decided quotes**. **76% of the 18 August follow-up list is carry-over.** **21% of the full quote base is automatically rejected.** **47% of open future trips fall in November–December 2026.**'),
 paragraph('The three operational problems to resolve first are repeated work filling the call list, automatically closed quotes without a visible explanation, and uncertainty over who is assigned the work and whether calls are being recorded. The recommended response is to check intake, confirm assignments, then improve follow-up rules.'),
 {'type':'table','columns':['First action','Proposed owner','When'],'rows':[[actions[0][0],actions[0][1],actions[0][2]],[actions[1][0],actions[1][1],actions[1][2]],[actions[2][0],actions[2][1],actions[2][2]]]},
 paragraph('**Review status:** draft for Suyash before circulation to Abhishek or the team. Names in the team comparison require assignment checks. Action owners and timing below are proposals for approval, not confirmed commitments.'),
 paragraph('**What these tiles measure:** quote counts, not revenue. August is the last complete quote month in the supplied files; the team review uses 5–18 August, and the backlog uses the 18 August view. Snapshot counts reflect the supplied export. Confidence is High for recorded quote counts, Medium for assigned workload and modeled backlog. The reporting gaps are explained on the trust page.')
],['v2_kpis','v2_country_change','v2_queue','v2_travel'])
page('trends','2. How sales are trending','Fewer quote requests; the apparent win-rate jump is too early to judge',[
 insight('August received 77 fewer quotes than July','July produced **439 quotes** and August **362**, an **18% decline**. August is also **41% below August 2025’s 615 quotes**. The longer series shows that the fall is meaningful in context, rather than simply a comparison with one unusually busy day.','Suyash with Abhishek: review Australian intake and routing within 2 working days.','High','Counts are taken directly from quote records; September is partial.','monthly-volume-chart'),
 insight('The useful benchmark is 13%, not August’s 32%','Across January–July 2026, **270 of 2,105 decided quotes were wins (13%)**, about **one in eight**. Monthly rates ran from **11% to 18%**. August shows **20 wins from only 63 decided quotes (32%)**, while most August quotes remain undecided.','Abhishek with Suyash: choose one conversion improvement to test and compare equally aged quotes.','High','The counts are reliable; recent months have not had equal time to finish.','win-rate-chart'),
 paragraph('The trend spans 24 displayed months, October 2024–September 2026. The grey endpoint is partial. The October 2024 annotation marks **327 records created in the same second**—a suspected import that should be checked before treating that month as normal demand. No recurring annual sales cycle is established by this history.')
],['v2_monthly','v2_win','I22_creation_batch'])
page('drivers','2. How sales are trending · continued','Australia explains the fall; two creation accounts concentrate it',[
 insight('Australia drove the August drop','Australian quotes fell **300 → 220 (−80)**; New Zealand edged up **139 → 142 (+3)**. These movements reconcile to the **77-quote overall decline**. The creation accounts **abhisheks (−37)** and **Dhiraj (−31)** together account for **68 of the 77 fewer quotes (88%)**.','Suyash with Abhishek: check intake and routing with the people responsible for these creation accounts.','High','This identifies where recorded volume changed, not why it changed.','drivers-figure'),
 paragraph('The first check is whether fewer Australian requests arrived, whether work was routed differently, or whether requests were recorded elsewhere. Compare inbound requests with created quotes for the same two months. A creator account shows who entered the record; it is not automatically the person who sold or owned the opportunity.'),
 paragraph('The same decline can be viewed by trip segment: **FIT fell 327 → 270 (−57)** and **Groups 112 → 92 (−20)**. Both fell by about **18%**, so the change is broader than a single segment. Country, creator and segment are alternative views of the same 77-quote fall; they must not be added together.'),
 paragraph('The expected result is a short explanation of the missing volume and a specific intake or routing action where one is warranted. A fall in requests does not by itself prove that callers converted less effectively. Keep the intake investigation separate from the conversion test.')
],['v2_country_change','v2_creator_change','jul_aug_driver_segment'])
page('wins','3. Where the wins come from','Australia supplies more wins; rates are close across segments',[
 insight('Volume matters more than the small rate differences','Australia has **745 accepted quotes** versus **238** for New Zealand, alongside a much larger quote base. Win rates on decided quotes are **14% and 12%**. FIT and Groups are similarly close at **13% and 14%**; there is no clear segment winner from these small differences alone.','Abhishek: use volume and customer potential alongside win rate when prioritising follow-ups.','High','These are counted outcomes; the comparison does not measure profitability.','segments-figure'),
 insight('Customer #526083 leads external volume; #53082 needs a review','Customer **#526083** leads this ranking with **190 quotes and 38 wins**. Separately, **#53082 lost all 31 decided quotes and still has 7 open**. Review those seven before more quoting effort goes in. The outcome history supports a conversation, not a conclusion about the customer’s reasons.','Abhishek with the account contact: review the seven open quotes within 2 working days.','Medium','Customer names and loss reasons are absent; account IDs identify the records.','accounts-figure'),
 paragraph('The top ten list counts accepted, rejected and open quotes; automatic closures are reviewed separately. It excludes the internal organisation. FIT and Groups describe the trip segment, not individual products. Prioritise a useful next conversation over a league table based on small differences.')
],['v2_segments','v2_accounts','panel_accounts_rejection'])
page('team','4. How the team is working','Carry-over dominates the list; confirm assignment before judging activity',[
 insight('The assigned workload and recorded activity do not line up','In **5–18 August**, recorded calls are **Hemant 164, Arun 142, Harsh 133 and Karthik 0**. The share of assigned quote-days worked is **64%, 17%, 52% and 2%**, respectively. The current assigned open book is **173, 74, 170 and 100 quotes**.','Suyash: confirm assignments and logging before any named comparison reaches the team.','Medium','Assigned caller is the best available record; this is not a settled performance assessment.','team-figure'),
 paragraph('**Suyash review required:** Karthik has **100 assigned open quotes**, **26 worked quote-days out of 1,142**, and **4 logged calls in the wider 1–18 August window**. The narrower chart window contains none. Check his role, assignment and logging first; decide separately whether this needs a private conversation with Abhishek.'),
 insight('Carry-over fills three-quarters of the 18 August follow-up list','On **18 August, 146 of 193 follow-up rows were carry-over (76%)**. Across the shown run days, the share ranges from **66% to 88%**. That is repeated follow-up occupying most of the list, not evidence that each quote was ignored.','Sales operations with the dev team: review repeat-entry and escalation rules before testing a cap.','High','Queue composition is directly observed; the other work may also be repeat follow-up.','queue-chart')
],['v2_team','v2_queue','team_call_rank'])
page('pipeline','5. What is stuck in the pipeline','Review automatic closures and time-sensitive open quotes',[
 paragraph('**One in five quotes is automatically rejected:** **2,070 of 9,935 quotes (21%)** carry that status. The label does not reveal the trigger or prove nobody reviewed the quote. **Action — Sales operations with the dev team:** establish the closure rule and decide where an advance warning would help. **Confidence: High** for the count; closure reasons need confirmation.'),
 paragraph('**The 18 August view contains 135 carry-over quotes**, with the oldest **50 days behind**. That is a different measure from the 146 carry-over rows in that day’s list. **Action — Sales operations:** review the oldest records and repeated appearances together. **Confidence: Medium** because the backlog view combines historical dates with the later export.'),
 insight('Nearly half the open future trips fall in November and December','Of **643 open quotes with travel after 18 August**, **172 travel in November** and **130 in December**: **302 in total (47%)**. This is the largest visible travel cluster and a useful starting point for follow-up planning once the list is refreshed.','Abhishek with assigned callers: refresh these records and agree the next contact within 3 working days.','High','The recorded travel dates are counted directly; current availability needs a fresh check.','travel-chart'),
 paragraph('**51 of 645 open quotes (8%) have travel dates before 10 September.** They may need a revised date or an updated outcome. **Action — Abhishek with assigned callers:** inspect each before closing anything. **Confidence: Medium** because past travel is a review flag, not proof that the quote is invalid.')
],['v2_travel','quote_stages','panel_carryover','I15_open_past_trips'])
page('actions','6. What we will do','Seven proposed actions, each with a clear result',[
 paragraph('Start with the intake and assignment checks. They establish whether the team is seeing the right opportunities and whether the activity records can support a fair review. Then improve the follow-up rules and test one conversion change. The aim is a short list of decisions, not a broad data-cleaning exercise.'),
 {'type':'table','columns':['Action','Proposed owner','By when','Expected result'],'rows':actions},
 paragraph('**Timing starts when Suyash approves the draft.** The refreshed export is a prerequisite for the travel-priority action. The dev team should confirm the scope of data repairs rather than be assigned an unverified completion date. Owners may change after review; no assignment or deadline has been sent.'),
 paragraph('For the next readout, check whether the intake gap is explained, the caller assignments are confirmed, the seven customer quotes have next steps, and the oldest carry-over records have been reviewed. Report conversion using equally aged quotes. Do not use August’s early 32% rate as a target or a claimed improvement.')
],['v2_country_change','v2_team','v2_travel','issues_register'])
page('trust','7. How far to trust these numbers','Five limits that matter to the decisions above',[
 {'type':'table','columns':['Problem','What we know','How to use the report'],'rows':[
 ['Call records may be incomplete','Only 1 team call is logged on 21–31 August while 2,944 queue rows exist.','Low confidence in a logging-stop explanation until the dev team confirms it. Team comparisons stop at 18 August.'],
 ['Assigned caller is not fully reliable','128 of 645 open quotes have no assigned caller in the available data.','Medium confidence in workload attribution. Confirm assignments before assessing individuals.'],
 ['Historical views mix different dates','The files contain current stages, schedules and caller assignments.','Medium confidence in historical backlog. Treat 18 August as a review view, not a reconstructed live snapshot.'],
 ['Recent win rates are unfinished','Only 63 of 362 August quotes are decided, against 230 of 439 July quotes.','Use the 13% January–July benchmark and compare quotes with equal time to finish.'],
 ['Commercial detail and record checks are incomplete','9,646 of 9,973 quote totals are missing; possible repeated records and missing history remain.','Quote volume is usable; revenue and some event-based comparisons need reconciliation.'] ]},
 paragraph('Product, vendor and acquisition-source rankings are unavailable from the mapped fields supplied. This does not block the country, trip-segment and customer findings above.'),
 paragraph('**Confidence tags:** High means the stated count or comparison is directly supported by the supplied records; it does not establish a cause. Medium means an assignment, definition or small sample needs checking before action. Low marks the unresolved logging question. Exact counts, examples, definitions and evidence are in the appendices.'),
 paragraph('No source records were changed. This revision reorganises the same historical evidence and recalculates only the explicitly stated comparisons. It is a review draft: Suyash should clear the named-caller handling before team circulation. The complete earlier audit remains archived, and dashboard fixes are documented separately.')
],['calls_queue_daily','missing_owner_by_stage','v2_win','I02_missing_amount'])

# Preserve all 22 original issues, with a continuous master crosswalk. Four technical findings
# belong in the separate Suyash note, while the sales appendix contains 18 data-quality entries.
technical={'I04_missing_owner','I10_stage_spelling','I13_future_quotes_in_historical_basket','I14_owner_lookahead'}
issues=[];crosswalk=[]; tech=[]
for n,i in enumerate(A['issues'],1):
    entry=dict(i);entry['master_id']=f'I{n:02d}';entry['original_id']=i['id'];entry['query']=i['id']
    if i['id'] in technical:
        entry['display_id']='T'+str(len(tech)+1).zfill(2);tech.append(entry);target='Separate dashboard review'
    else:
        entry['display_id']='I'+str(len(issues)+1).zfill(2);issues.append(entry);target='Appendix A'
    crosswalk.append({'master_id':entry['master_id'],'original_reference':i['id'],'location':target,'display_id':entry['display_id'],'count':i['count']})
titles={'orphan_vtiger_quote_stage_track':'Records linked to quotes absent from the export','I08_stage_duplicate_candidates':'Possible duplicate stage records','I09_followup_duplicate_candidates':'Possible duplicate follow-up records','I02_missing_amount':'Quote totals are missing','I03_missing_trip':'Trip dates contain a zero-date placeholder','I11_call_outcomes_missing':'Calls have no recorded outcome'}
for i in issues:i['title']=titles.get(i['id'],i['title']).replace('NULL','missing')
pd.DataFrame(crosswalk).to_csv(out/'issue_crosswalk.csv',index=False)
add('v2_issue_log',[{k:v for k,v in i.items() if k not in ['example']} for i in issues],'18 sales-data issues, consecutive I01–I18. Four dashboard-specific findings moved to separate T01–T04; master crosswalk preserves all 22 original records and proof files.',[r['file'] for r in T['source_inventory']])
appendices=[{'id':'appendix-a','title':'Appendix A — detailed data issue log','text':'Counts and examples are preserved from v1. All affected rows remain downloadable. Issue numbers below are consecutive; the crosswalk records each old reference. Counts overlap and must not be summed. Possible duplicates and large parties are review candidates, not confirmed errors.'},
{'id':'appendix-b','title':'Appendix B — method and sources','text':old['sections'][0]['body'].replace('## 1. Overview','').replace('**Review copy for Oliver • 21 September 2026 • not shared or published.**','Original analysis prepared 21 September 2026; revised for Suyash’s review.').strip()},
{'id':'appendix-c','title':'Appendix C — supporting analysis and panel evidence','text':'The earlier calculations, detailed profiles and dashboard-panel extracts remain available below. Sales findings retain the same definitions and caveats. Dashboard implementation findings are in the separate Suyash note, and the complete unmodified v1 report is preserved in archive_v1.'}]
support=[]
for s in old['sections']:
    if s['id'] in ['trends','performers','gaps']:
        paragraphs=s['body'].split('\n\n')
        clean=[p for p in paragraphs if not any(t in p for t in ['**Agents','Hemant logs','**Accounts are','**Ownership','organisation names','Unavailable panels']) and not p.startswith('## ')]
        support.append({'title':s['title'],'text':'\n\n'.join(clean),'queries':s['queries']})
panel_exclude={'Account insights','Quote timeline','Data notes','Quotes owned now','Coverage','Capacity versus demand','By-person summary'}
panels=[p for p in old['panels'] if p['panel'] not in panel_exclude]
content={'version':2,'title':'Sales insights report','subtitle':'Review draft for Suyash • Intended audience: Abhishek and the sales team • Historical exports reviewed 21 September 2026','pages':pages,'issues':issues,'appendices':appendices,'support':support,'panels':panels,'kpis':kpis,'actions':actions}
(APP/'src/content/report/findings-v2.json').write_text(json.dumps(content,ensure_ascii=False,indent=2),encoding='utf-8')
snapshot.update(title='Sales insights report',buildStatus='updating',surface='report')
snapshot['revision']={'version':2,'basis':'Revision_Brief_Sales_Insights_Report_Oliver.docx','sourceDataUnchanged':True}
(APP/'src/data.json').write_text(json.dumps(snapshot,ensure_ascii=False),encoding='utf-8')

notes=['# Suyash — dashboard fixes and review decisions\n\nPrepared locally for review. Not sent. These are separate from the sales-facing report.']
for i in tech:notes.append(f"## {i['display_id']} — {i['title']}\n\n{i['count']:,} affected records of {i['denominator']:,} ({i['percent']:.2f}%). {i['meaning']}\n\nSuggested fix: {i['action']}\n\nOriginal proof: evidence/{i['id']}.csv\n\nExample: "+json.dumps(i['example'][0],ensure_ascii=False))
notes.append('## T05 — Account rankings use a different internal-account rule\n\nAccount #91445 has 252 included quotes: 25 accepted, 225 rejected and 2 open. The account panel includes it while Accepted this month excludes it. V2’s external-customer ranking excludes it consistently. Confirm the intended rule; this is a scope difference, not necessarily a defect. Proof: evidence/panel_accounts_volume.csv and dashboard functions account_insights / accepted_month.')
notes.append('## Named-caller handling — Suyash review before team circulation\n\nKarthik: 100 current assigned open quotes; 26 worked out of 1,142 surfaced quote-days in 5–18 August; 0 calls in that range, 4 in 1–18 August. The draft flags this explicitly. Check assignment and logging before deciding whether to discuss it privately with Abhishek. No performance conclusion or message has been sent.')
notes.append('## Unresolved logging question\n\n21–31 August contains 1 team call and 2,944 queue rows. Low confidence that work stopped; this may be an incomplete export or a logging change. The v2 team charts end on 18 August. Obtain confirmation from the dev team before circulation.')
notes.append('## Corrections to suggested headlines\n\n- January–July is 270 wins / 2,105 decided quotes = 12.83%, about one in eight.\n- Auto Rejected is 2,070/9,935 = 20.84%; status alone does not prove no decision occurred.\n- 146/193 = 75.65% is specifically 18 August; shown daily shares range from 66.3% to 88.4%.\n- Other follow-up buckets are not necessarily fresh work.\n- The original non-empty register contains 22 issues, not 23. Appendix A has 18 data issues; T01–T04 preserve the other four, with an additional scope note T05. The complete master crosswalk is evidence_v2/issue_crosswalk.csv.\n- The charts display October 2024–September 2026 (24 months). Partial September 2024 remains in the original series in the appendix.\n- Proposed owners and deadlines have not been assigned or communicated.')
(P/'Suyash_Dashboard_Fixes_and_Review_Notes.md').write_text('\n\n'.join(notes),encoding='utf-8')

def alltext(block):
    if block['type']=='text':return block['text']
    if block['type']=='insight':return ' '.join(str(block[k]) for k in ['title','text','action','confidence','reason'])
    if block['type']=='table':return ' '.join(block['columns'])+' '+' '.join(' '.join(r) for r in block['rows'])
    return ' '.join(r['label']+' '+r['value']+' '+r['detail'] for r in kpis)
wc=sum(len(re.findall(r"\b[\w’-]+\b",alltext(b))) for p in pages for b in p['blocks'])
checks={'main_body_words':wc,'planned_main_pages':len(pages),'sales_issues':len(issues),'separate_technical_issues':len(tech),'all_original_issues_preserved':len(issues)+len(tech)==len(A['issues']),'source_hashes_unchanged':all(hashlib.sha256((ROOT/'data'/r['file']).read_bytes()).hexdigest()==r['sha256'] for r in T['source_inventory'])}
assert checks['source_hashes_unchanged'] and checks['all_original_issues_preserved']
(P/'revision_checks.json').write_text(json.dumps(checks,indent=2),encoding='utf-8')
print(checks);print('Top accounts:',acct[['name','quotes','accepted']].to_dict('records'))
