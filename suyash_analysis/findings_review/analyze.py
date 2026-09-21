from pathlib import Path
import sys, json, hashlib
import pandas as pd
ROOT=Path(__file__).resolve().parents[2]
OUT=Path(__file__).resolve().parent
E=OUT/'evidence'; E.mkdir(exist_ok=True)
sys.path.insert(0,str(ROOT/'suyash_dashboard'))
import metrics as m
raw={p.stem:pd.read_csv(p,dtype=str,keep_default_na=False) for p in (ROOT/'data').glob('*.csv')}
D=m.load(); q=D['q']; active=q[q.deleted.eq(0)].copy(); today=pd.Timestamp('2026-08-18')
tables={}; issues=[]
def missing_value(s): return s.str.strip().str.upper().isin(['','NULL','NAN','NONE'])
def save(name,df):
    df.to_csv(E/(name+'.csv'),index=False); tables[name]=json.loads(df.to_json(orient='records',date_format='iso')); return df
def flag(id,title,df,den,meaning,action,severity='High'):
    save(id,df)
    issues.append(dict(id=id,title=title,count=len(df),denominator=den,percent=round(len(df)/den*100,2) if den else None,meaning=meaning,action=action,severity=severity,example=tables[id][:2]))
inventory=[]; missing=[]; keys=[]; dates=[]
keycols={'daily_runs':'id','vtiger_users':'id','vtiger_quotes':'quoteid','vtiger_quotescf':'quoteid'}
for name,df in raw.items():
    p=ROOT/'data'/(name+'.csv'); key=keycols.get(name,'auto_id')
    inventory.append(dict(file=p.name,rows=len(df),columns=len(df.columns),sha256=hashlib.sha256(p.read_bytes()).hexdigest()))
    keys.append(dict(file=p.name,key=key,blank_keys=int(df[key].eq('').sum()),duplicate_key_rows=int(df.duplicated(key,keep=False).sum()),exact_duplicate_rows=int(df.duplicated(keep=False).sum())))
    for col in df:
        n=int(missing_value(df[col]).sum()); missing.append(dict(file=p.name,field=col,blank_or_null=n,empty_string=int(df[col].str.strip().eq('').sum()),null_literal=int(df[col].str.upper().eq('NULL').sum()),rows=len(df),percent=round(n/len(df)*100,2)))
        if col in ['created_at','calltime','run_date','cf_1162','process_date','added_on','cleared_date','next_follow_up_date']:
            v=pd.to_datetime(df[col],errors='coerce',format='mixed'); bad=~missing_value(df[col])&v.isna()
            dates.append(dict(file=p.name,field=col,earliest=str(v.min()),latest=str(v.max()),blank_or_null=int(missing_value(df[col]).sum()),invalid_nonblank=int(bad.sum())))
            if bad.any(): save('invalid_'+name+'_'+col,df.loc[bad,[key,col]])
save('source_inventory',pd.DataFrame(inventory)); save('all_field_missingness',pd.DataFrame(missing)); save('key_checks',pd.DataFrame(keys)); save('date_ranges',pd.DataFrame(dates))
rq=raw['vtiger_quotes']; cf=raw['vtiger_quotescf']; pay=raw['vtiger_payment_history']; dr=D['dr']; calls=D['calls']; fu=D['fu']; ch=D['ch']
safe=['quoteid','quote_no','stage','created_at','trip','country','pax','owner']
save('quote_analysis_export',q[safe+['deleted','accountid','is_group','next_call_date']])
save('dashboard_next_three_months_export',m.export_next_3_months(D,today))
save('dashboard_accepted_month_export',m.accepted_month(D,today)[[c for c in safe if c!='country']+['ev_date','label']])
active['month']=active.created_at.dt.to_period('M').astype(str)
active['accepted']=active.stage_l.isin(m.ACCEPTED_STAGES); active['rejected']=active.stage_l.isin(m.REJECTED_STAGES); active['auto_rejected']=active.stage_l.eq('auto rejected')
def aggregate(df,dim):
    z=df.groupby(dim,dropna=False).agg(quotes=('quoteid','size'),accepted=('accepted','sum'),rejected=('rejected','sum'),auto_rejected=('auto_rejected','sum')).reset_index()
    z['resolved']=z.accepted+z.rejected; z['accepted_share_pct']=(100*z.accepted/z.quotes).round(2); z['resolved_win_pct']=(100*z.accepted/z.resolved.replace(0,float('nan'))).round(2)
    return z.sort_values('quotes',ascending=False)
save('monthly_quote_cohorts',aggregate(active,'month').sort_values('month'))
save('destinations',aggregate(active,'country'))
active['segment']=active.is_group.map({True:'Groups (quote suffix G)',False:'FIT (no G suffix)'})
save('fit_group_proxy',aggregate(active,'segment'))
save('creator_cohorts',aggregate(active,'created_by')); save('proxy_owner_cohorts',aggregate(active,'owner'))
save('quote_stages',active.groupby('stage').size().rename('quotes').reset_index().sort_values('quotes',ascending=False))
save('month_destination',active.groupby(['month','country']).size().rename('quotes').reset_index())
save('month_segment',active.groupby(['month','segment']).size().rename('quotes').reset_index())
save('month_creator',active.groupby(['month','created_by']).size().rename('quotes').reset_index())
daily=pd.DataFrame({'date':pd.date_range(active.created_at.min().normalize(),active.created_at.max().normalize())})
daily['quotes']=daily.date.map(active.groupby(active.created_at.dt.normalize()).size()).fillna(0).astype(int)
save('daily_quote_volume',daily); save('zero_quote_days',daily[daily.quotes.eq(0)])
event=ch[ch.ev.eq('accepted')].merge(q[['quoteid','deleted','stage_l']],on='quoteid',how='left')
first=event.sort_values('created_at').drop_duplicates('quoteid'); first=first[first.deleted.eq(0)]
save('first_acceptance_month',first.groupby(first.created_at.dt.to_period('M').astype(str)).size().rename('quotes_first_accepted').reset_index())
save('acceptance_reversals',first[~first.stage_l.isin(m.ACCEPTED_STAGES)][['quoteid','created_at','stage_l']])
team=calls[calls.login.notna()]
dailycalls=pd.DataFrame({'date':pd.date_range('2026-06-01','2026-09-10')})
dailycalls['team_calls']=dailycalls.date.map(team.groupby('call_date').size()).fillna(0).astype(int)
dailycalls['all_calls']=dailycalls.date.map(calls.groupby('call_date').size()).fillna(0).astype(int)
dailycalls['queue_rows']=dailycalls.date.map(dr.groupby('run_date').size()).fillna(0).astype(int)
save('calls_queue_daily',dailycalls)
zero=dailycalls[dailycalls.queue_rows.gt(0)&dailycalls.team_calls.eq(0)]
flag('I01_queue_without_team_calls','Queue runs continue on days without team call logs',zero,len(dailycalls[dailycalls.queue_rows.gt(0)]),'A missing log is not proof of no work. Call KPIs cannot be used as a productivity verdict after the logging break.','Re-export follow-ups and reconcile logging coverage with source owners.')
flag('I02_missing_amount','Quote total is NULL',rq.loc[missing_value(rq.total),['quoteid','quote_no','created_at','quotestage','total','currency_id']],len(rq),'Revenue and monetary product rankings are not supportable. Currency ID 1 has no exported currency-name mapping.','Obtain populated quote/invoice line totals, currency definitions and revenue recognition rules.')
flag('I03_missing_trip','Trip date is missing or unparseable',q.loc[q.trip.isna(),safe],len(q),'These quotes disappear from trip-horizon and future-trip cohorts.','Confirm when a trip date is mandatory; backfill from authoritative itinerary data.')
flag('I04_missing_owner','Dashboard proxy owner cannot be assigned',q.loc[q.owner.isna(),safe],len(q),'Owner comparisons are incomplete; all assigned owners are approximations rather than authoritative sales ownership.','Export vtiger_quotes_info with effective ownership history.')
flag('I05_country_unusable','Country is blank or N/A',rq.loc[rq.country.str.strip().isin(['','N/A']),['quoteid','quote_no','country','created_at','quotestage']],len(rq),'These records cannot be assigned to Australia or New Zealand and are grouped as Unknown by the dashboard. Country is destination, not customer origin.','Validate destination capture and obtain origin-region lookup tables.')
flag('I06_zero_pax','Passenger total is zero',q.loc[q.pax.eq(0),safe],len(q),'Zero-passenger records are hidden from passenger charts; they may include incomplete quotes.','Check mandatory passenger entry by quote stage.','Medium')
flag('I07_trip_before_creation','Trip date precedes quote creation date',q.loc[q.trip.lt(q.created_at.dt.normalize()),safe],len(q),'Historical imports or date errors could explain these records; not automatically invalid sales.','Verify examples against source itineraries and import history.','Medium')
for name,df in raw.items():
    if 'quoteid' in df and name not in ['vtiger_quotes','vtiger_quotescf']:
        bad=~df.quoteid.isin(rq.quoteid)
        if bad.any(): flag('orphan_'+name,'Unmatched quote references in '+name,df.loc[bad,[c for c in ['auto_id','quoteid','created_at','stage','followup_type','total_amount'] if c in df]],len(df),'Inner joins silently drop these child records.','Reconcile export scope and parent IDs before combining tables.')
stage_raw=raw['vtiger_quote_stage_track']; sig=['quoteid','user_name','stage','created_at']; dup=stage_raw.duplicated(sig,keep=False)
flag('I08_stage_duplicate_candidates','Repeated identical stage-log payloads with different IDs',stage_raw.loc[dup,['auto_id']+sig],len(stage_raw),'Candidate duplicate emissions; event counts can inflate. Separate IDs are not proof of distinct business transitions.','Review repeated payloads and define an event idempotency key. Preserve raw logs.')
fraw=raw['vtiger_quotes_followup']; sigfu=['quoteid','followup_type','next_follow_up_date','outcome','followup','calltime','created_at','created_by']
dup=fraw.duplicated(sigfu,keep=False)
flag('I09_followup_duplicate_candidates','Repeated structured follow-up payloads',fraw.loc[dup,['auto_id']+sigfu],len(fraw),'Candidate duplicates, not proven duplicate conversations: free-text description and contact fields are intentionally excluded.','Inspect candidate records in CRM before deduplication.','Medium')
flag('I10_stage_spelling','Two spellings of rejection after confirmation',q.loc[q.stage.str.contains('After Confirmation',case=False)&q.stage_l.isin(m.REJECTED_STAGES),safe],len(q),'Suyash normalizes both spellings for stage groups, but timeline reversal detection searches only Confrmation.','Use a canonical stage dictionary in all metrics and timeline flags.','Medium')
flag('I11_call_outcomes_missing','Call entries have no outcome',calls.loc[calls.outcome.isna(),['auto_id','quoteid','calltime','created_by','outcome']],len(calls),'Contact-rate denominator excludes these calls, while call volume includes them.','Measure outcome completeness by agent/time and define a separate unknown-outcome category.')
bad=calls.calltime.lt(calls.quoteid.map(q.set_index('quoteid').created_at))
flag('I12_call_before_creation','Call timestamp precedes quote creation',calls.loc[bad,['auto_id','quoteid','calltime','created_at','created_by']],len(calls),'This can distort first-response time; imports or backdated logs need checking.','Reconcile source timezone and imported quote/call timestamps.','Medium')
future=q[(q.deleted.eq(0))&~q.stage_l.isin(m.LIVE_EXCLUDED)&q.created_at.ge(today+m.DAY)]
flag('I13_future_quotes_in_historical_basket','Historical dashboard basket includes later-created quotes',future[safe],len(m.live_basket(D)),'Treat as today changes dates but does not reconstruct the historical snapshot; live_basket applies no creation-date cutoff.','Label panels as export snapshot or reconstruct as-of states before historical comparisons.')
oldowner=dr[dr.run_date.le(today)].sort_values(['run_date','id']).groupby('item_id').user_name.last()
changed=q[q.quoteid.isin(oldowner.index)&q.owner.ne(q.quoteid.map(oldowner))].copy(); changed['owner_on_aug18']=changed.quoteid.map(oldowner)
flag('I14_owner_lookahead','Latest queue owner differs from owner available on 18 August',changed[safe+['owner_on_aug18']],len(oldowner),'Historical accepted/lifecycle credit uses latest queue owner, including assignments after the selected day.','Use time-valid ownership and separate activity actor from sales owner.')
openq=active[active.stage_l.isin(m.OPEN_STAGES)]
flag('I15_open_past_trips','Open Created/Requote quotes have trips before last quote-record date',openq.loc[openq.trip.lt(active.created_at.max().normalize()),safe],len(openq),'Stale pipeline candidates as of 10 September; not proof the travel did not occur.','Review disposition and trip rescheduling; do not automatically close records.','Medium')
for id,df in [('missing_trip',q[q.trip.isna()]),('missing_owner',q[q.owner.isna()]),('zero_pax',q[q.pax.eq(0)])]:
    save(id+'_by_stage',df.groupby('stage').size().rename('affected').reset_index())
    save(id+'_by_month',df.groupby(df.created_at.dt.to_period('M').astype(str)).size().rename('affected').reset_index())
save('missing_amount_by_month',rq.assign(month=rq.created_at.str[:7],missing=missing_value(rq.total)).groupby('month').agg(rows=('quoteid','size'),missing=('missing','sum')).reset_index())
save('missing_outcome_by_agent',calls.assign(missing=calls.outcome.isna()).groupby('created_by',dropna=False).agg(calls=('auto_id','size'),missing=('missing','sum')).reset_index())
save('missing_outcome_by_month',calls.assign(month=calls.calltime.dt.to_period('M').astype(str),missing=calls.outcome.isna()).groupby('month').agg(calls=('auto_id','size'),missing=('missing','sum')).reset_index())
save('country_raw_values',rq.country.value_counts(dropna=False).rename_axis('country').rename('rows').reset_index())
for col in ['productidwp','tour_type','quote_type','quote_category','mode','currency_id','region_id','duplicate','no_pax']:
    save('profile_'+col,rq[col].value_counts(dropna=False).rename_axis(col).rename('rows').reset_index())
# Numeric checks are candidates; large groups and negative payments can be legitimate.
numeric=[]
for name,cols in [('vtiger_quotes',['total','total_new','adults','children','infants','no_pax']),('vtiger_payment_history',['total_amount','trams_received_amount','balance_amount'])]:
    df=raw[name]
    for c in cols:
        x=pd.to_numeric(df[c],errors='coerce'); numeric.append(dict(file=name,field=c,populated=int(x.notna().sum()),invalid=int((~missing_value(df[c])&x.isna()).sum()),negative=int(x.lt(0).sum()),zero=int(x.eq(0).sum()),median=x.median(),p99=x.quantile(.99),maximum=x.max()))
save('numeric_checks',pd.DataFrame(numeric))
p99=q.pax.quantile(.99); flag('I16_large_party_review','Passenger count exceeds empirical 99th percentile',q.loc[q.pax.gt(p99),safe],len(q),f'Review candidates above {p99:g} pax; large groups are not inherently errors.','Compare passenger manifest and FIT/Groups classification for the largest cases.','Low')
# Export every computable panel at the default dashboard settings.
start=m.trend_days(today)[0]; panel_errors=[]
for name,fn,args in [('calls',m.calls_by_day,(start,today)),('worked',m.surfaced_worked_by_day,(start,today)),('contact',m.contact_by_day,(start,today)),('outcomes',m.outcome_mix,(start,today)),('lifecycle',m.lifecycle_month,(today,)),('win_rate',m.win_rate_month,(today,)),('cycle_time',m.cycle_time_month,(today,)),('backlog',m.backlog_by_day,(start,today)),('carryover',m.carryover_now,(today,list(m.CALLERS))),('coverage',m.coverage,(m.trend_days(today),)),('owned',m.quotes_owned_now,()),('queue_composition',m.queue_composition,(start,today)),('unresponsive',m.unresponsive_accounts,(today,)),('accounts_volume',m.account_insights,('volume',)),('accounts_rejection',m.account_insights,('rate',)),('composition',m.composition,(m.live_basket(D),)),('travel_horizon',m.travel_horizon,(m.live_basket(D),today))]:
    try:
        # composition / horizon take quote frames instead of D
        result=fn(*args) if name in ['composition','travel_horizon'] else fn(D,*args)
        save('panel_'+name,result)
    except Exception as ex: panel_errors.append({'panel':name,'error':str(ex)})
cap,budget=m.capacity_vs_demand(D,m.trend_days(today),today); save('panel_capacity',cap)
hour,active_days=m.calls_by_hour(D,today); save('panel_hourly',hour); save('panel_hour_active_days',active_days)
fit,grp=m.pax_buckets(m.live_basket(D)); save('panel_pax_fit',fit); save('panel_pax_groups',grp)
persons=[]
for login in m.CALLERS:
    ps=m.person_summary(D,login,today.replace(day=1),today)
    persons.append({'agent':login,**{k:v for k,v in ps.items() if not isinstance(v,(pd.DataFrame,list))}})
    book=m.live_book(D,login,today); save('panel_book_'+login,book); save('panel_upcoming_'+login,m.upcoming_buckets(book,today))
save('panel_person_summary',pd.DataFrame(persons))
save('payment_source_profile',pay.source.value_counts().rename_axis('payment_source').rename('records').reset_index())
save('payment_month_volume',pay.assign(month=pay.added_on.str[:7]).groupby('month').size().rename('payment_records').reset_index())
flag('I17_invalid_call_dates','Non-null calltime contains an invalid date',fraw.loc[~missing_value(fraw.calltime)&pd.to_datetime(fraw.calltime,errors='coerce',format='mixed').isna(),['auto_id','quoteid','followup_type','calltime','created_at']],len(fraw),'Invalid dates become NaT and cannot appear in time-based analyses; scheduled records and call records must be distinguished.','Replace zero-date sentinels with true nulls and validate call dates by follow-up type.','Medium')
flag('I18_future_call_dates','Call records are dated after the review date',calls.loc[calls.calltime.ge(pd.Timestamp('2026-09-22')),['auto_id','quoteid','followup_type','calltime','created_at','created_by']],len(calls),'These are future-dated call_info entries as of 21 September 2026, not completed historical calls.','Check whether scheduled contact was recorded as a completed call.','Medium')
paydates=pd.to_datetime(pay.process_date,errors='coerce',format='mixed')
flag('I19_payment_dates','Payment processing date is missing or invalid',pay.loc[paydates.isna(),['auto_id','quoteid','source','process_date','added_on','total_amount']],len(pay),'Payment-date trends are incomplete; added_on is a record timestamp, not necessarily cash receipt date.','Confirm the payment lifecycle and canonical receipt date before cash-flow reporting.')
save('invalid_dates_by_followup_type',fraw.assign(invalid=(~missing_value(fraw.calltime)&pd.to_datetime(fraw.calltime,errors='coerce',format='mixed').isna())).groupby('followup_type').agg(rows=('auto_id','size'),invalid=('invalid','sum')).reset_index())
save('monthly_call_volume',calls.assign(month=calls.calltime.dt.to_period('M').astype(str)).groupby('month').size().rename('calls').reset_index())
save('team_call_rank',team[team.call_date.between('2026-08-01',today)].groupby('login').agg(calls=('auto_id','size'),quotes=('quoteid','nunique')).reset_index())
save('panel_default_composition',m.composition(m.scope(D,'open')))
save('panel_default_horizon',m.travel_horizon(m.scope(D,'open'),today))
save('panel_default_destination',m.scope(D,'open').groupby('country').size().rename('quotes').reset_index())
for label,frame in zip(['fit','groups'],m.pax_buckets(m.scope(D,'open'))): save('panel_default_pax_'+label,frame)
npax=pd.to_numeric(rq.no_pax,errors='coerce'); rq2=rq[['quoteid','quote_no','no_pax','adults','children','infants']].copy(); rq2['calculated_pax']=q.pax.values
flag('I20_pax_disagreement','Populated no_pax differs from adults + children + infants',rq2[npax.notna()&npax.ne(q.pax)],int(npax.notna().sum()),'Two passenger measures disagree; no_pax may use a different definition. Dashboard uses adults + children + infants.','Confirm whether infants/free-of-charge passengers belong in each measure before standardizing.','Medium')
rawspelling=rq.country.eq('AUSTRALIA')
flag('I21_country_case','Country uses inconsistent casing',rq.loc[rawspelling,['quoteid','quote_no','country']],len(rq),'Raw groupings split Australia into two labels; dashboard title-case normalization already combines them.','Standardize the country dictionary upstream.','Low')
latest_stage=ch.sort_values(['created_at','auto_id']).groupby('quoteid').tail(1)
save('latest_stage_events',latest_stage[['quoteid','ev','created_at']])
# Export issue examples with original source dates for malformed trip sentinels.
save('I03_raw_trip_dates',cf.loc[cf.quoteid.isin(q.loc[q.trip.isna(),'quoteid'].astype(str)),['quoteid','cf_1162']])
save('stage_duplicate_group_sizes',stage_raw.groupby(sig).size().rename('copies').reset_index().query('copies > 1'))
save('followup_duplicate_group_sizes',fraw.groupby(sigfu).size().rename('copies').reset_index().query('copies > 1'))
save('raw_quote_number_duplicates',rq[rq.duplicated('quote_no',keep=False)][['quoteid','quote_no','created_at']])
save('queue_composite_duplicates',raw['daily_runs'][raw['daily_runs'].duplicated(['run_date','user_name','item_type','item_id'],keep=False)])
save('monthly_payment_measure_coverage',pay.assign(month=pay.added_on.str[:7],has_total=~missing_value(pay.total_amount),has_trams=~missing_value(pay.trams_received_amount)).groupby('month').agg(records=('auto_id','size'),total_populated=('has_total','sum'),trams_populated=('has_trams','sum')).reset_index())
for dim in ['country','segment','created_by']:
    z=active[active.month.isin(['2026-07','2026-08'])].groupby([dim,'month']).size().unstack(fill_value=0).reset_index()
    z['change']=z['2026-08']-z['2026-07']; save('jul_aug_driver_'+dim,z.sort_values('change'))
save('trip_month_all',active.assign(trip_month=active.trip.dt.to_period('M').astype(str)).groupby('trip_month').size().rename('quotes').reset_index())
save('gap_weekdays',daily[daily.quotes.eq(0)].assign(weekday=lambda x:x.date.dt.day_name()).groupby('weekday').size().rename('days').reset_index())
# Analytical support for all panel interpretations.
save('contact_rank_14d',m.contact_by_day(D,start,today).groupby('user')[['reached','not_reached']].sum().reset_index())
save('worked_rank_14d',m.surfaced_worked_by_day(D,start,today).groupby('user')[['surfaced','worked']].sum().reset_index())
save('call_rank_14d',m.calls_by_day(D,start,today).groupby('user').calls.sum().reset_index())
save('hour_total_90d',hour.groupby('hour').calls.sum().reset_index())
save('accepted_without_audit',active[active.accepted&~active.quoteid.isin(first.quoteid)][safe])
save('schema_unsupported_dimensions',pd.DataFrame([
 {'dimension':'Product','eligible_quotes':len(rq),'usable_product_ids':int((~missing_value(rq.productidwp)).sum()),'reason':'productidwp empty/NULL; no product table or line-item export'},
 {'dimension':'Vendor','eligible_quotes':len(rq),'usable_product_ids':None,'reason':'No mapped vendor column or vendor table; opaque cf codes not interpreted'},
 {'dimension':'Lead source','eligible_quotes':len(rq),'usable_product_ids':None,'reason':'No mapped lead-source field. Payment source is initial/final and is not acquisition channel'},
 {'dimension':'Origin location','eligible_quotes':len(rq),'usable_product_ids':None,'reason':'Country is destination; region_id has 327 zeros and 9646 NULLs; region lookup absent'}]))
issues=[i for i in issues if i['count']>0]
flag('I22_creation_batch','Quote creation timestamps cluster in one second',rq.loc[rq.created_at.eq('2024-10-08 17:04:46'),['quoteid','quote_no','created_at','created_by','total','quotestage']],len(rq),'327 records share one second, have missing creation actors, and are exactly the only quotes with populated totals. This strongly suggests a batch/import boundary, not an organic demand spike; import provenance is unconfirmed.','Confirm migration history and preserve original creation timestamps before using October 2024 as a trend baseline.','High')
flag('I23_acceptance_audit_gap','Current accepted-family quotes lack an Accepted audit event',active.loc[active.accepted&~active.quoteid.isin(first.quoteid),safe],int(active.accepted.sum()),'Current state and event history cannot be fully reconciled; the first-observed acceptance series is incomplete.','Obtain full audit history or an authoritative acceptance timestamp before measuring historical sales.')
save('deleted_quotes',q.loc[q.deleted.ne(0),safe+['deleted']])
save('issues_register',pd.DataFrame([{k:v for k,v in i.items() if k!='example'} for i in issues]))
result={'issues':issues,'tables':tables,'panel_errors':panel_errors,'budget':budget,'as_of':'2026-08-18','active_quotes':len(active),'raw_quotes':len(q),'pax_p99':p99}
(OUT/'analysis.json').write_text(json.dumps(result,indent=2,default=str),encoding='utf-8')
print(json.dumps({'issues':[{k:v for k,v in i.items() if k not in ['example','meaning','action']} for i in issues],'panel_errors':panel_errors},indent=2))
