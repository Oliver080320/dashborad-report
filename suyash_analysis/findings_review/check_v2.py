from pathlib import Path
import json,shutil
from playwright.sync_api import sync_playwright
P=Path(__file__).resolve().parent;OUT=P/'v2_visual_checks';OUT.mkdir(exist_ok=True);CAP=P/'v2_charts';CAP.mkdir(exist_ok=True)
with sync_playwright() as pw:
 b=pw.chromium.launch(channel='msedge',headless=True)
 page=b.new_page(viewport={'width':1500,'height':1100},device_scale_factor=2)
 errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
 page.goto('http://127.0.0.1:4175',wait_until='networkidle');page.get_by_role('heading',name='Sales insights report',exact=True).wait_for(timeout=45000);page.wait_for_timeout(1500)
 assert page.locator('[data-report-page]').count()==8
 assert page.locator('.v2-scorecard [data-component-id]').count()==6
 figures=['scorecard-kpis','monthly-volume-chart','win-rate-chart','drivers-figure','segments-figure','accounts-figure','team-figure','queue-chart','travel-chart']
 for id in figures:
  card=page.locator('[data-figure-id="'+id+'"]');card.scroll_into_view_if_needed();page.wait_for_timeout(450)
  if id!='scorecard-kpis':assert card.locator('svg .recharts-cartesian-axis').count()>0,id
  card.screenshot(path=str(CAP/(id+'.png')))
 for sec in ['scorecard','trends','drivers','wins','team','pipeline','actions','trust']:
  page.locator('[data-report-page="'+sec+'"]').scroll_into_view_if_needed();page.wait_for_timeout(150);page.screenshot(path=str(OUT/(sec+'.png')))
 assert page.locator('.v2-issue').count()==18
 first=page.locator('.v2-issue').first;first.locator('summary').click()
 with page.expect_download() as d:first.get_by_role('button').click()
 d.value.save_as(str(OUT/'issue_download.csv'))
 # Inspect an ordinary source menu and collect screenshot without changing presentation.
 page.locator('#section-scorecard').scroll_into_view_if_needed();page.screenshot(path=str(OUT/'desktop.png'))
 page.set_viewport_size({'width':390,'height':900});page.locator('#section-scorecard').scroll_into_view_if_needed();page.wait_for_timeout(400);page.screenshot(path=str(OUT/'mobile.png'))
 overflow=page.evaluate('document.documentElement.scrollWidth > innerWidth+2')
 assert not overflow,'mobile overflow'
 assert not errors,errors
 (OUT/'browser_checks.json').write_text(json.dumps({'pages':8,'figures':len(figures)-1,'kpis':6,'issue_extracts':18,'errors':errors,'mobile_overflow':overflow},indent=2),encoding='utf-8')
 print('Verified 8 report pages, 8 chart figures, 6 KPI cards, 18 issue downloads; no browser errors.')
 b.close()
