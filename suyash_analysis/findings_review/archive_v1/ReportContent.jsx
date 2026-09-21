import React from 'react';
import { ReportSection, RichNarrative, EvidenceChart, DataComponent, useDataApp } from '../../data-app-public.jsx';
import content from './findings.json';

function NarrativeWithTables({id,body}) {
  const labels={file:'Export',rows:'Rows',columns:'Columns',month:'Month',quotes:'Quotes',accepted:'Accepted',resolved:'Resolved',resolved_win_pct:'Resolved win %',country:'Destination',rejected:'Rejected',auto_rejected:'Auto rejected',login:'Agent',calls:'Calls'};
  const chunks=body.split(/(\|[^\n]*\|\n\|[\s\S]*?)(?=\n\n|$)/g);
  return chunks.map((part,n)=>{
    if(part.startsWith('|')&&part.includes('\n| ---')){
      const lines=part.trim().split('\n').map(line=>line.slice(1,-1).split('|').map(x=>x.trim()));
      return <div key={n} className="evidence-table" data-reviewed-rows><table><thead><tr>{lines[0].map((c,j)=><th key={j}>{labels[c]??c}</th>)}</tr></thead><tbody>{lines.slice(2).map((r,k)=><tr key={k}>{r.map((v,j)=><td key={j} className={j>0&&/^\d+(\.\d+)?$/.test(v)?'numeric':''}>{v}</td>)}</tr>)}</tbody></table></div>;
    }
    return part.trim()?<RichNarrative key={n} id={id+':body-'+n} value={part} />:null;
  });
}

function csvDownload(name, rows) {
  if (!rows.length) return;
  const cols=Object.keys(rows[0]);
  const quote=v=>'"'+String(v??'').replaceAll('"','""')+'"';
  const csv=[cols.map(quote).join(','),...rows.map(r=>cols.map(c=>quote(r[c])).join(','))].join('\r\n');
  const url=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8'}));
  const a=document.createElement('a'); a.href=url;a.download=name+'.csv';a.click();URL.revokeObjectURL(url);
}
export function ReportContent(){
  const {snapshot,appTitle}=useDataApp();
  const rows=id=>snapshot.queries[id]?.rows??[];
  return <article className="report-content findings-report">
    <header className="report-hero"><h1>{appTitle}</h1><RichNarrative id="review-status" value="Prepared for your review • 21 September 2026 • Read-only analysis • Not shared" /></header>
    <nav className="findings-nav" aria-label="Report sections">{content.sections.map(s=><a key={s.id} href={'#section-'+s.id}>{s.title}</a>)}</nav>
    {content.sections.map(s=><section key={s.id} id={'section-'+s.id} className="findings-section">
      <ReportSection id={s.id} title={s.title} showHeading={false} queryId={s.queries[0]} queryIds={s.queries} sourceRowsByQuery={Object.fromEntries(s.queries.map(id=>[id,rows(id)]))}>
        <NarrativeWithTables id={s.id} body={s.body} />
      </ReportSection>
      {s.id==='trends'&&<EvidenceChart id="monthly-volume-chart" queryId="monthly_quote_cohorts" title="Monthly quotes: October 2024–August 2026" description="Partial first and last months excluded. October 2024 contains the 327-record batch described above." spec={{type:'bar',x:'month',y:'quotes',yLabel:'Quotes',valueDecimals:0}} rows={rows('monthly_quote_cohorts').filter(r=>r.month>'2024-09'&&r.month<'2026-09')} sourceRows={rows('monthly_quote_cohorts').filter(r=>r.month>'2024-09'&&r.month<'2026-09')} height={320} />}
      {s.id==='performers'&&<EvidenceChart id="destination-chart" queryId="destinations" title="Accepted quote volume by destination" spec={{type:'horizontalBar',x:'country',y:'accepted',valueDecimals:0}} rows={rows('destinations')} sourceRows={rows('destinations')} height={300} />}
      {s.id==='proof'&&<>
        <div className="issue-evidence">{content.issues.map(i=><DataComponent key={i.id} id={'evidence-'+i.id} queryId={i.query} kind="table" title={i.title} sourceRows={rows(i.query)} displayRows={rows(i.query)}>
          <details><summary>{i.id.split('_')[0]} · {i.title} · {i.count.toLocaleString()} affected</summary>
            <RichNarrative id={i.id+':example'} value={'**Example:** '+i.example_text+'\n\n**Recommendation:** '+i.action} />
            <button className="extract-button" onClick={()=>csvDownload(i.query,rows(i.query))}>Download all {i.count.toLocaleString()} affected rows</button>
            <div className="evidence-table" data-reviewed-rows><table><thead><tr>{Object.keys(rows(i.query)[0]??{}).map(c=><th key={c}>{c}</th>)}</tr></thead><tbody>{rows(i.query).slice(0,3).map((r,n)=><tr key={n}>{Object.entries(r).map(([c,v])=><td key={c}>{String(v??'—')}</td>)}</tr>)}</tbody></table></div>
          </details>
        </DataComponent>)}</div>
        <ReportSection id="panel-review" title="Dashboard panel-by-panel review" queryId="dashboard_panel_review" sourceRows={rows('dashboard_panel_review')}>
          <div className="evidence-table" data-reviewed-rows><table><thead><tr><th>Panel</th><th>Finding and implication</th></tr></thead><tbody>{content.panels.map(p=><tr key={p.panel}><td>{p.panel}</td><td>{p.finding}<button className="extract-button" onClick={()=>csvDownload(p.evidence_query,rows(p.evidence_query))}>Data extract</button></td></tr>)}</tbody></table></div>
        </ReportSection>
      </>}
    </section>)}
  </article>;
}
