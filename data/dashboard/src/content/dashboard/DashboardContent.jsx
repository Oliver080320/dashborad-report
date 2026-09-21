import React, { useState } from 'react';
import { useDataApp, useDashboardTabs, DataComponent, MetricCard, EvidenceChart, DataTable, Section, SortableRegion, SortableItem, NativeSelect } from '../../data-app-public.jsx';
import './sales.css';
import {QueueMovement} from './QueueMovement.jsx';
const num=n=>n.toLocaleString('en-AU');
const pct=(a,b)=>b?`${(100*a/b).toFixed(1)}%`:'—';
const choices=(rows,k)=>['all',...new Set(rows.map(r=>r[k]))].sort((a,b)=>a==='all'?-1:b==='all'?1:String(a).localeCompare(String(b)));
const count=(rows,k)=>Object.entries(rows.reduce((a,r)=>(a[r[k]]=(a[r[k]]||0)+1,a),{})).map(([label,quotes])=>({label,quotes})).sort((a,b)=>b.quotes-a.quotes);
function Grid({id,children,cols=2,metrics=false}) { const cards=React.Children.toArray(children); return <SortableRegion id={id} label="Dashboard blocks" variant="canvas" columns={12} spacing="standard" rows={Array.from({length:Math.ceil(cards.length/cols)},(_,i)=>({id:`${id}-row-${i}`,kind:metrics?'metrics':undefined,items:cards.slice(i*cols,i*cols+cols).map(c=>c.props.id)}))}>{cards.map(c=><SortableItem key={c.props.id} id={c.props.id} label={c.props.title} kind={metrics?'metric':'chart'} span={12/cols}>{c}</SortableItem>)}</SortableRegion> }
function Metric({id,title,value,rows,queryId,note,evidence=rows}) {return <MetricCard id={id} title={title} queryId={queryId} value={value} sourceRows={evidence} displayRows={[{metric:title,value}]}><p className="sales-note">{note}</p></MetricCard>}
function Bars({id,title,rows,data,queryId,height=270}) {return <EvidenceChart id={id} title={title} queryId={queryId} variant="card" rows={data} sourceRows={rows} height={height} spec={{type:'horizontalBar',x:'label',y:'quotes',valueDecimals:0}}/>}
function Select({label,value,set,values}) {return <NativeSelect label={label} showLabel value={value} choices={values} onChange={set}/>}
function Queue({all}) {
 const dates=[...new Set(all.map(r=>r.date))].sort();
 const [date,setDate]=useState(dates.at(-1)),[owner,setOwner]=useState('all'),[country,setCountry]=useState('all'),[flag,setFlag]=useState('all');
 const history=all.filter(r=>(owner==='all'||r.assignee===owner)&&(country==='all'||r.country===country));
 const rows=history.filter(r=>r.date===date), carry=rows.filter(r=>r.carryover), past=rows.filter(r=>r.followupStatus==='Past scheduled date'), no=rows.filter(r=>r.followupStatus==='No follow-up record');
 const priorDate=dates[dates.indexOf(date)-1],prior=history.filter(r=>r.date===priorDate),change=rows.length-prior.length;
 const trend=dates.map(date=>{const rr=history.filter(r=>r.date===date);return {date,quotes:rr.length,carryover:rr.filter(r=>r.carryover).length}});
 const actionable=r=>['Past scheduled date','No follow-up record','No valid next date'].includes(r.followupStatus);
 const details=rows.filter(r=>flag==='all'||(flag==='Needs review'?actionable(r):r.followupStatus===flag)).map(r=>({...r,reviewReason:r.followupStatus==='Past scheduled date'?`Check follow-up: ${r.daysPast} days past scheduled date`:r.followupStatus==='No follow-up record'?'Check contact history and schedule next follow-up':r.followupStatus==='No valid next date'?'Confirm the next follow-up date':r.followupStatus==='Due that day'?'Follow-up scheduled for this run date':'Future follow-up recorded'})).sort((a,b)=>Number(actionable(b))-Number(actionable(a))||b.daysPast-a.daysPast||b.appearances-a.appearances);
 return <>
  <div className="sales-controls"><Select label="Queue assignee" value={owner} set={setOwner} values={choices(all,'assignee')}/><Select label="Destination" value={country} set={setCountry} values={choices(all,'country')}/><button className="sales-reset" onClick={()=>{setOwner('all');setCountry('all');setDate(dates.at(-1));setFlag('all')}}>Reset view</button></div>
  <Section id="queue-snapshot" title="Queue snapshot" spacing="none" filters={<Select label="Run date" value={date} set={setDate} values={dates}/>}>
   <Grid id="queue-kpis" cols={4} metrics>
    <Metric id="queue-total" title="Queued quotes" value={num(rows.length)} rows={rows} evidence={[...prior,...rows]} queryId="queue" note={priorDate?`${change>=0?'+':''}${change} vs ${priorDate} (${num(prior.length)} quotes)`:'First observed run date'}/>
    <Metric id="queue-carry" title="Carryover share" value={pct(carry.length,rows.length)} rows={rows} queryId="queue" note={`${num(carry.length)} of ${num(rows.length)} quotes in carryover buckets`}/>
    <Metric id="queue-past" title="Past scheduled date" value={num(past.length)} rows={rows} queryId="queue" note="Latest recorded next date is before this run date"/>
    <Metric id="queue-no" title="No follow-up record" value={num(no.length)} rows={rows} queryId="queue" note="No follow-up created by the end of this run date"/>
   </Grid>
   <p className="sales-context">Queue history ends 31 August 2026. A past scheduled date is a review flag, not a confirmed missed task. Run time and source timezone are unavailable; follow-ups are evaluated at the end of each run date.</p>
   <DataComponent id="queue-records" title="Quotes to review" queryId="queue" kind="table" variant="card" displayRows={details} sourceRows={details}>
    <DataTable rows={details} rowKey="quoteId" label="Queue quotes to review" toolbarControls={<Select label="Follow-up flag" value={flag} set={setFlag} values={['all','Needs review',...choices(rows,'followupStatus').filter(v=>v!=='all')]}/>} columns={[{field:'quote',label:'Quote'},{field:'assignee',label:'Assignee'},{field:'bucket',label:'Bucket'},{field:'country',label:'Destination'},{field:'reviewReason',label:'Reason to review'},{field:'nextDate',label:'Scheduled date'},{field:'daysPast',label:'Days past date'},{field:'appearances',label:'Observed appearances'}]}/>
    <p className="sales-note">Review flags come first, then days past scheduled date and repeated queue appearances. Suggested checks are not task-completion claims. Appearances count observed run dates up to this date, not consecutive days. Bucket codes retain their source labels.</p>
   </DataComponent>
   <Grid id="queue-breakdowns">
    <Bars id="queue-owners" title="Workload by assignee" rows={rows} data={count(rows,'assignee')} queryId="queue"/>
    <Bars id="queue-followups" title="Follow-up coverage" rows={rows} data={count(rows,'followupStatus')} queryId="queue"/>
   </Grid>
  </Section>
  <QueueMovement rows={rows} prior={prior} priorDate={priorDate} date={date}/>
  <Section id="queue-history" title="Queue workload over time">
   <EvidenceChart id="queue-trend" title="Quotes per observed run" queryId="queue" variant="card" rows={trend} sourceRows={history} height={300} spec={{type:'line',x:'date',y:'quotes',fields:['quotes','carryover'],stackable:false,valueDecimals:0,legend:{labels:{quotes:'All queued quotes',carryover:'Carryover'}}}}/>
   <p className="sales-note">25 June–31 August 2026 · Assignee and destination filters apply. Run-date and follow-up-flag selections affect the snapshot above only. Missing run dates are unobserved; lines connect recorded runs.</p>
  </Section>
 </>
}
function Portfolio({all}) {
 const [review,setReview]=useState('all');
 const [country,setCountry]=useState('all'),[group,setGroup]=useState('all'),[month,setMonth]=useState('all');
 const rows=all.filter(r=>(country==='all'||r.country===country)&&(group==='all'||r.stageGroup===group)&&(month==='all'||r.month===month));
 const sales=rows.filter(r=>r.stageGroup==='Sales in progress'),stale=sales.filter(r=>r.inactiveDays!==null&&r.inactiveDays>=30),unknown=sales.filter(r=>r.inactiveDays===null);
 const months=[...new Set(rows.map(r=>r.month))].sort(),groups=['Sales in progress','Post-acceptance / delivery','Rejected'];
 const cohort=months.flatMap(month=>groups.map(stageGroup=>({month:month+'-01',stageGroup,quotes:rows.filter(r=>r.month===month&&r.stageGroup===stageGroup).length})));
 const age=count(sales.map(r=>({...r,age:r.inactiveDays===null?'No activity timestamp':r.inactiveDays<7?'0–6 days':r.inactiveDays<30?'7–29 days':r.inactiveDays<90?'30–89 days':'90+ days'})),'age');
 const details=sales.filter(r=>review==='all'||(review==='30+ days without activity'?r.inactiveDays!==null&&r.inactiveDays>=30:r.followupRecords===0)).map(r=>({...r,reviewReason:[r.inactiveDays!==null&&r.inactiveDays>=30?`${r.inactiveDays} days without logged activity`:null,r.followupRecords===0?'No follow-up record':null].filter(Boolean).join('; ')||'No selected review flag'})).sort((a,b)=>(b.inactiveDays??-1)-(a.inactiveDays??-1));
 return <>
  <div className="sales-controls"><Select label="Destination" value={country} set={setCountry} values={choices(all,'country')}/><Select label="Stage group" value={group} set={setGroup} values={choices(all,'stageGroup')}/><Select label="Created month" value={month} set={setMonth} values={choices(all,'month')}/><button className="sales-reset" onClick={()=>{setCountry('all');setGroup('all');setMonth('all');setReview('all')}}>Reset view</button></div>
  <Grid id="portfolio-kpis" cols={4} metrics>
   <Metric id="portfolio-total" title="Quotes in export" value={num(rows.length)} rows={rows} queryId="portfolio" note="Excludes records marked deleted"/>
   <Metric id="portfolio-sales" title="Sales in progress" value={num(sales.length)} rows={rows} queryId="portfolio" note="Stages: Created, Lead or Requote"/>
   <Metric id="portfolio-stale" title="No activity for 30+ days" value={num(stale.length)} rows={rows} queryId="portfolio" note={`${pct(stale.length,sales.length)} of sales in progress; ${unknown.length} have unknown activity age`}/>
   <Metric id="portfolio-rejected" title="Rejected-stage share" value={pct(rows.filter(r=>r.stageGroup==='Rejected').length,rows.length)} rows={rows} queryId="portfolio" note="Includes auto-rejected and rejected-after-confirmation stages"/>
  </Grid>
  <p className="sales-context">Current stages from the quote export; activity age is measured at 11 September 2026, the latest logged event. Stage mix is not a conversion rate. Logged activity includes product edits as well as stage changes.</p>
  <Section id="portfolio-stage-section" title="Pipeline composition" spacing="after-metrics">
   <Grid id="portfolio-charts">
    <Bars id="portfolio-stages" title="Current quote stages" rows={rows} data={count(rows,'stage')} queryId="portfolio" height={440}/>
    <Bars id="portfolio-inactivity" title="Sales-in-progress activity age" rows={sales} data={age} queryId="portfolio" height={440}/>
   </Grid>
  </Section>
  <Section id="portfolio-cohorts" title="Quote cohorts and destinations">
   <Grid id="portfolio-cohort-charts">
    <EvidenceChart id="portfolio-months" title="Created month × current stage group" queryId="portfolio" variant="card" rows={cohort} sourceRows={rows} height={300} spec={{type:'stackedBar',x:'month',y:'quotes',series:'stageGroup',valueDecimals:0}}/>
    <EvidenceChart id="portfolio-countries" title="Destination × current stage group" queryId="portfolio" variant="card" rows={[...new Set(rows.map(r=>r.country))].flatMap(country=>groups.map(stageGroup=>({country,stageGroup,quotes:rows.filter(r=>r.country===country&&r.stageGroup===stageGroup).length})))} sourceRows={rows} height={300} spec={{type:'horizontalStackedBar',x:'country',y:'quotes',series:'stageGroup',valueDecimals:0}}/>
   </Grid>
   <p className="sales-note">September is a partial creation month. Cohorts show exported current stage labels, not stage histories. Older cohorts have had longer to progress. Post-acceptance / delivery groups non-rejected stages other than Created, Lead and Requote.</p>
  </Section>
  <Section id="portfolio-review" title="Investigate inactive sales quotes">
   <DataComponent id="portfolio-records" title="Sales-in-progress records" queryId="portfolio" kind="table" variant="card" displayRows={details} sourceRows={details}>
    <DataTable rows={details} rowKey="quoteId" label="Sales-in-progress records" toolbarControls={<Select label="Review reason" value={review} set={setReview} values={['all','30+ days without activity','No follow-up record']}/>} columns={[{field:'quote',label:'Quote'},{field:'stage',label:'Stage'},{field:'reviewReason',label:'Reason to review'},{field:'country',label:'Destination'},{field:'created',label:'Created'},{field:'lastActivity',label:'Last activity'},{field:'inactiveDays',label:'Inactive days'},{field:'followupRecords',label:'Follow-up records'},{field:'paymentRecords',label:'Payment records'}]}/>
   </DataComponent>
  </Section>
 </>
}
function Quality({queries}) { const rows=queries.quality.rows; return <Section id="quality-section" title="Source coverage" spacing="none"><DataComponent id="source-inventory" title="Supplied CSV files" queryId="quality" kind="table" variant="card" displayRows={rows} sourceRows={rows}><DataTable rows={rows} searchable={false} columns={[{field:'file',label:'Source file'},{field:'rows',label:'Rows'}]}/></DataComponent><div className="sales-quality"><h3>Interpretation limits</h3><ul><li>Queue: 25 June–31 August 2026. Follow-up records: through 4 September. Quote creation: through 10 September. Activity logs: through 11 September. These are record dates, not confirmed extraction times.</li><li>9,935 non-deleted quotes; 38 deleted quotes excluded from the portfolio. Historical queue rows remain intact.</li><li>9,608 of 9,935 non-deleted quotes (96.7%) have no total_new value. Currency ID 1 has no currency-code dictionary. Revenue, pipeline value and cash totals are therefore omitted.</li><li>9,154 activity-log rows reference quote IDs absent from the quote export; they are excluded from quote-level activity calculations. Other used joins have no unmatched quote IDs.</li><li>Custom cf_* fields need a field dictionary. The users file is inventoried but not used to infer quote ownership. Queue assignees come directly from daily_runs.</li><li>Filters are local to the current tab session and are not included in share links. Search and table sorting affect the table only.</li></ul></div></Section> }
export function DashboardContent() { const {queries}=useDataApp(); const {activeTabId}=useDashboardTabs([{id:'queue',label:'Queue & follow-ups',filterIds:[]},{id:'portfolio',label:'Quote portfolio',filterIds:[]},{id:'coverage',label:'Source coverage',filterIds:[]}]); return <div className="sales-dashboard">{activeTabId==='portfolio'?<Portfolio all={queries.portfolio.rows}/>:activeTabId==='coverage'?<Quality queries={queries}/>:<Queue all={queries.queue.rows}/>}</div> }
