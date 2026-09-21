from pathlib import Path
import json,hashlib,shutil
import pandas as pd
from playwright.sync_api import sync_playwright
P=Path(__file__).resolve().parent; ROOT=P.parents[1]; A=json.loads((P/'analysis.json').read_text()); T=A['tables']
for row in T['source_inventory']:
    assert hashlib.sha256((ROOT/'data'/row['file']).read_bytes()).hexdigest()==row['sha256']
for issue in A['issues']:
    assert len(pd.read_csv(P/'evidence'/(issue['id']+'.csv')))==issue['count']
for dim in ['country','segment','created_by']:
    assert sum(r['change'] for r in T['jul_aug_driver_'+dim])==-77
expected=pd.read_csv(P/'evidence/dashboard_next_three_months_export.csv')
actual=pd.read_csv(P/'evidence/ui_next_three_months_export.csv')
pd.testing.assert_frame_equal(actual,expected)
assert len(actual)==791
dest=P/'report_app/dist/evidence'; dest.mkdir(exist_ok=True)
for src in (P/'evidence').glob('*.csv'): shutil.copy2(src,dest/src.name)
with sync_playwright() as pw:
    browser=pw.chromium.launch(channel='msedge',headless=True)
    page=browser.new_page(viewport={'width':1440,'height':1000})
    errors=[]; page.on('pageerror',lambda e:errors.append(str(e)))
    page.goto('http://127.0.0.1:4175',wait_until='networkidle')
    page.get_by_role('heading',name='Sales dashboard & dataset findings report',exact=True).wait_for(timeout=60000)
    page.wait_for_timeout(2000)
    assert page.locator('.findings-section').count()==8
    assert page.locator('.issue-evidence details').count()==len(A['issues'])
    for id in ['overview','key-findings','trends','performers','gaps','issues','recommendations','proof']:
        el=page.locator('#section-'+id); el.scroll_into_view_if_needed();page.wait_for_timeout(350)
        page.screenshot(path=str(P/'evidence'/('report_'+id+'.png')))
    # Confirm actual rendered marks, not only chart containers.
    for id in ['monthly-volume-chart','destination-chart']:
        chart=page.locator('[data-component-id="'+id+'"]');chart.scroll_into_view_if_needed();page.wait_for_timeout(500)
        assert chart.locator('svg').count()>0
        page.screenshot(path=str(P/'evidence'/('report_'+id+'.png')))
    details=page.locator('.issue-evidence details').first
    details.locator('summary').click()
    with page.expect_download() as download: details.get_by_role('button').click()
    download.value.save_as(str(P/'evidence/verified_report_download.csv'))
    assert len(pd.read_csv(P/'evidence/verified_report_download.csv'))==A['issues'][0]['count']
    # Source-backed report and table text must load with no render errors.
    assert '9,074' in page.locator('body').inner_text()
    for width in [1440,390]:
        page.set_viewport_size({'width':width,'height':1000})
        page.locator('#section-overview').scroll_into_view_if_needed();page.wait_for_timeout(300)
        page.screenshot(path=str(P/'evidence'/f'report_width_{width}.png'))
        overflow=page.evaluate('document.documentElement.scrollWidth > innerWidth + 2')
        if overflow: errors.append(f'Horizontal page overflow at {width}')
    result={'source_hashes_unchanged':True,'ui_export_matches':True,'issue_counts_match':True,'drivers_reconcile':True,'sections':8,'issue_extracts':len(A['issues']),'browser_errors':errors}
    (P/'verification.json').write_text(json.dumps(result,indent=2),encoding='utf-8'); print(result)
    browser.close()
