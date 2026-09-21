import csv, json, collections, datetime, bisect, hashlib
from pathlib import Path

ROOT=Path(__file__).resolve().parent
def read(name):
    with (ROOT/(name+'.csv')).open(encoding='utf-8-sig',newline='') as f: return list(csv.DictReader(f))
def clean(v): return v if v and v!='NULL' else None
def day(v): return v[:10] if clean(v) and v[:4].isdigit() and v[:4]!='0000' else None
def days(a,b): return (datetime.date.fromisoformat(a)-datetime.date.fromisoformat(b)).days if a and b else None
quotes=read('vtiger_quotes'); runs=read('daily_runs'); follows=read('vtiger_quotes_followup'); events=read('vtiger_quote_stage_track'); payments=read('vtiger_payment_history')
qm={r['quoteid']:r for r in quotes}
assert len(qm)==len(quotes)
assert len({(r['run_date'],r['item_id']) for r in runs})==len(runs)
assert all(r['item_id'] in qm for r in runs)
fi=collections.defaultdict(list); ev=collections.defaultdict(list); pay=collections.Counter()
for r in follows: fi[r['quoteid']].append(r)
for r in events:
    if day(r['created_at']): ev[r['quoteid']].append(r['created_at'])
for r in payments: pay[r['quoteid']]+=1
for a in fi.values(): a.sort(key=lambda r:(r['created_at'],int(r['auto_id'])))
for a in ev.values(): a.sort()
country=lambda q: 'Australia' if q['country'].lower()=='australia' else clean(q['country']) or 'Unknown'
qrows=[]; appearances=collections.Counter(); last_run={}
for r in sorted(runs,key=lambda r:r['run_date']):
    q=qm[r['item_id']]; date=r['run_date']; appearances[r['item_id']]+=1
    prior=[f for f in fi[r['item_id']] if day(f['created_at']) and day(f['created_at'])<=date]
    last=prior[-1] if prior else None
    due=day(last['next_follow_up_date']) if last else None
    lag=days(date,due)
    status='No follow-up record' if not last else ('No valid next date' if due is None else ('Past scheduled date' if lag>0 else ('Due that day' if lag==0 else 'Future scheduled date')))
    qrows.append(dict(date=date,quote=q['quote_no'],quoteId=r['item_id'],assignee=r['user_name'],bucket=r['bucket'],country=country(q),position=int(r['position']),carryover=int('carryover' in r['bucket']),appearances=appearances[r['item_id']],followupStatus=status,nextDate=due,lastFollowup=day(last['created_at']) if last else None,daysPast=lag if lag and lag>0 else 0))
    last_run[r['item_id']]=r
cutoff=max(day(r['created_at']) for r in events if day(r['created_at']))
portfolio=[]
for q in quotes:
    if q['deleted']=='1': continue
    id=q['quoteid']; stage=q['quotestage']; last=fi[id][-1] if fi[id] else None
    group='Rejected' if 'Rejected' in stage else ('Sales in progress' if stage in ['Created','Lead','Requote'] else 'Post-acceptance / delivery')
    lastactivity=day(ev[id][-1]) if ev[id] else None
    portfolio.append(dict(quote=q['quote_no'],quoteId=id,stage=stage,stageGroup=group,country=country(q),creator=clean(q['created_by']) or 'Unknown',created=day(q['created_at']),month=day(q['created_at'])[:7] if day(q['created_at']) else 'Unknown',lastActivity=lastactivity,inactiveDays=days(cutoff,lastactivity),followupRecords=len(fi[id]),lastFollowup=day(last['created_at']) if last else None,paymentRecords=pay[id],amountPresent=int(clean(q['total_new']) is not None),pax=int(q['no_pax']) if q['no_pax'].isdigit() else None))
files=[]
for p in sorted(ROOT.glob('*.csv')):
    rows=read(p.stem); files.append(dict(file=p.name,rows=len(rows),sha256=hashlib.sha256(p.read_bytes()).hexdigest()))
def source(label,files,caveats,definitions):
    return dict(label=label,files=files,description=label,caveats=caveats,metricDefinitions=definitions,evidenceFlow=[dict(title='Read supplied CSV exports',detail='Parsed UTF-8 CSVs using Python csv.DictReader. No live system refresh. See prepare_dashboard.py for reproducible joins and calculations.'),dict(title='Validate grain',detail='Unique quote IDs and unique queue date/quote pairs checked. Follow-ups and payments aggregated before joining; unmatched event rows excluded.')])
snapshot=dict(id='sales-queue-insights-20260921',surface='dashboard',title='Sales queue insights',buildStatus='creating',status='reviewed',generatedAt=datetime.datetime.now(datetime.timezone.utc).isoformat(),filters=[],queries={
 'queue':dict(rows=qrows,source=source('Daily queue assignments, 25 Jun–31 Aug 2026',['daily_runs.csv','vtiger_quotes.csv','vtiger_quotes_followup.csv'],['A queue appearance is one quote on one observed run date; missing run dates are not zero workload.','Latest follow-up record created on or before the end of the selected run date supplies the scheduled date. Past scheduled date is a review flag, not proof of an incomplete task. Intraday run time and source timezone are unavailable.','Bucket codes sp1–sp4 are preserved because their business definitions were not supplied. Assignee is from daily_runs, not quote creator.'],[dict(label='Carryover share',definition='Queue rows whose bucket contains carryover divided by all selected queue rows.'),dict(label='Observed appearances',definition='Cumulative count of observed queue dates for a quote up to the selected run date; not consecutive days or total age.')]),methods=[dict(language='Python',code='Run python prepare_dashboard.py from the directory holding the seven supplied CSV files.')]),
 'portfolio':dict(rows=portfolio,source=source('Non-deleted quotes in supplied export',['vtiger_quotes.csv','vtiger_quotes_followup.csv','vtiger_quote_stage_track.csv','vtiger_payment_history.csv'],['Snapshot stage is from vtiger_quotes. Monthly cohorts group quote creation dates and show current stages, not historical stage counts or conversion rates. September is partial.','Activity includes any stage-track log event, including product edits; inactive days are measured at 11 September 2026, the latest observed event date, not today.','Sales in progress means Created, Lead or Requote. Post-acceptance / delivery groups remaining non-rejected stages and is not a verified win metric.','Payment records indicate recorded history only; no revenue or collection totals are inferred.','Currency ID 1 has no supplied currency-code mapping. Most total_new values are missing. Custom cf_* fields are not interpreted without a dictionary.'],[dict(label='Quotes',definition='Distinct quote IDs excluding deleted=1 (38 rows excluded).'),dict(label='No activity for 30+ days',definition='Sales-in-progress quotes with a last recorded activity date at least 30 days before 11 September 2026. Quotes with no activity timestamp are shown separately.')]),methods=[dict(language='Python',code='Run python prepare_dashboard.py. Join each pre-aggregated child table by quoteid, retaining one row per non-deleted quote.')]),
 'quality':dict(rows=files,source=source('Source inventory',[x['file'] for x in files],['Export timestamps and source timezone were not supplied. Source record maxima are not extraction timestamps.','vtiger_quotescf and vtiger_users are inventoried but unused in metrics; opaque custom-field definitions and user identity mappings were not assumed.'],[]))},analysisDate=cutoff)
(ROOT/'reviewed_snapshot.json').write_text(json.dumps(snapshot,ensure_ascii=False),encoding='utf-8')
latest=[r for r in qrows if r['date']==max(x['date'] for x in qrows)]
summary=dict(queueLatest=latest[0]['date'],latestQuotes=len(latest),carryover=sum(r['carryover'] for r in latest),followup=collections.Counter(r['followupStatus'] for r in latest),portfolioQuotes=len(portfolio),stageGroups=collections.Counter(r['stageGroup'] for r in portfolio),amountMissing=sum(not r['amountPresent'] for r in portfolio),unmatchedStageEvents=sum(r['quoteid'] not in qm for r in events),salesStale=sum(r['stageGroup']=='Sales in progress' and r['inactiveDays'] is not None and r['inactiveDays']>=30 for r in portfolio))
(ROOT/'analysis_summary.json').write_text(json.dumps(summary,indent=2),encoding='utf-8')
print(json.dumps(summary,indent=2))
