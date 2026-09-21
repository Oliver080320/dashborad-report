import React, {useState} from 'react';
import {Section, EvidenceChart, DataComponent, DataTable, NativeSelect} from '../../data-app-public.jsx';

export function QueueMovement({rows,prior,priorDate,date}) {
 const [movement,setMovement]=useState('all');
 if (!priorDate) return <Section id="queue-movement-section" title="Movement since the previous run"><p className="sales-note">No earlier run is supplied for this date; movement cannot be calculated.</p></Section>;
 const before=new Map(prior.map(r=>[r.quoteId,r])),after=new Map(rows.map(r=>[r.quoteId,r]));
 const changes=[...rows.map(r=>({...r,movement:before.has(r.quoteId)?'Stayed':'Entered',previousAssignee:before.get(r.quoteId)?.assignee??null})),...prior.filter(r=>!after.has(r.quoteId)).map(r=>({...r,movement:'Left',previousAssignee:r.assignee}))];
 const data=['Entered','Stayed','Left'].map(label=>({label,quotes:changes.filter(r=>r.movement===label).length}));
 const details=changes.filter(r=>movement==='all'||r.movement===movement);
 const ids=new Set(details.map(r=>r.quoteId));
 return <Section id="queue-movement-section" title="Movement since the previous run">
  <EvidenceChart id="queue-movement-chart" queryId="queue" title="Entered, stayed and left" variant="card" height={220} rows={data} sourceRows={[...prior,...rows]} spec={{type:'horizontalBar',x:'label',y:'quotes',valueDecimals:0}}/>
  <p className="sales-context">{priorDate} → {date}: {prior.length} previous + {data[0].quotes} entered − {data[2].quotes} left = {rows.length} current. Movement compares the selected assignee and destination in both runs, so transfers can appear as entries or exits. Leaving this queue does not confirm a sale or completed task.</p>
  <DataComponent id="queue-movement-records" title="Inspect queue movement" queryId="queue" kind="table" variant="card" displayRows={details} sourceRows={[...prior,...rows].filter(r=>ids.has(r.quoteId))}>
   <DataTable rows={details} rowKey="quoteId" label="Queue movement records" toolbarControls={<NativeSelect label="Movement" showLabel value={movement} choices={['all','Entered','Stayed','Left']} onChange={setMovement}/>} columns={[{field:'quote',label:'Quote'},{field:'movement',label:'Movement'},{field:'assignee',label:'Assignee in shown run'},{field:'previousAssignee',label:'Previous assignee'},{field:'date',label:'Shown run'},{field:'country',label:'Destination'},{field:'bucket',label:'Bucket'}]}/>
  </DataComponent>
 </Section>;
}
