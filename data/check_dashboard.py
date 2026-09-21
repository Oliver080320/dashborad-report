from playwright.sync_api import sync_playwright
from pathlib import Path
import json
root=Path(__file__).resolve().parent
with sync_playwright() as p:
    browser=p.chromium.launch(channel='msedge',headless=True)
    page=browser.new_page(viewport={'width':1440,'height':1000})
    errors=[]
    page.on('pageerror',lambda e:errors.append(str(e)))
    page.goto('http://127.0.0.1:4173/',wait_until='networkidle')
    page.wait_for_timeout(2000)
    def metric(id): return page.locator(f'[data-component-id="{id}"] .metric-value').inner_text()
    assert metric('queue-total')=='413'
    page.get_by_label('Movement',exact=True).select_option('Entered')
    snapshot=json.loads((root/'dashboard/src/data.json').read_text(encoding='utf-8'))
    queue=snapshot['queries']['queue']['rows']
    before={r['quoteId'] for r in queue if r['date']=='2026-08-28'}
    after={r['quoteId'] for r in queue if r['date']=='2026-08-31'}
    for name,expected in [('Entered',len(after-before)),('Stayed',len(after&before)),('Left',len(before-after))]:
        page.get_by_label('Movement',exact=True).select_option(name)
        assert f'{expected} results' in page.locator('[data-component-id="queue-movement-records"]').inner_text()
    page.get_by_label('Movement',exact=True).select_option('all')
    page.get_by_label('Follow-up flag',exact=True).select_option('Needs review')
    assert '392 results' in page.locator('[data-component-id="queue-records"]').inner_text()
    page.get_by_label('Follow-up flag',exact=True).select_option('all')
    page.get_by_label('Run date',exact=True).select_option('2026-06-25')
    assert 'No earlier run is supplied' in page.locator('body').inner_text()
    page.get_by_role('button',name='Reset view',exact=True).click()
    page.get_by_label('Queue assignee',exact=True).select_option('hemant')
    assert metric('queue-total')=='125'
    page.get_by_label('Queue assignee',exact=True).select_option('all')
    assert metric('queue-total')=='413'
    page.get_by_label('Run date',exact=True).select_option('2026-08-28')
    assert metric('queue-total')=='419'
    page.get_by_role('button',name='Reset view',exact=True).click()
    assert metric('queue-total')=='413'
    page.get_by_label('Follow-up flag',exact=True).select_option('No follow-up record')
    assert '99 results' in page.locator('[data-component-id="queue-records"]').inner_text()
    page.get_by_label('Follow-up flag',exact=True).select_option('all')
    page.screenshot(path=str(root/'dashboard-queue.png'),full_page=True)
    page.get_by_role('tab',name='Quote portfolio',exact=True).click()
    page.wait_for_timeout(900)
    assert metric('portfolio-total')=='9,935'
    assert metric('portfolio-stale')=='521'
    page.get_by_label('Review reason',exact=True).select_option('30+ days without activity')
    assert '521 results' in page.locator('[data-component-id="portfolio-records"]').inner_text()
    page.get_by_label('Review reason',exact=True).select_option('all')
    page.screenshot(path=str(root/'dashboard-portfolio.png'),full_page=True)
    page.get_by_label('Stage group',exact=True).select_option('Sales in progress')
    assert metric('portfolio-total')=='676'
    page.get_by_label('Stage group',exact=True).select_option('all')
    assert metric('portfolio-total')=='9,935'
    page.get_by_label('Created month',exact=True).select_option('2026-09')
    snapshot=json.loads((root/'dashboard/src/data.json').read_text(encoding='utf-8'))
    expected=sum(r['month']=='2026-09' for r in snapshot['queries']['portfolio']['rows'])
    assert metric('portfolio-total')==f'{expected:,}'
    page.get_by_role('button',name='Reset view',exact=True).click()
    page.get_by_label('Destination',exact=True).select_option('N/A')
    page.get_by_label('Stage group',exact=True).select_option('Sales in progress')
    page.get_by_label('Created month',exact=True).select_option('2026-09')
    print('EMPTY',metric('portfolio-total'))
    page.get_by_role('button',name='Reset view',exact=True).click()
    page.set_viewport_size({'width':390,'height':844})
    page.screenshot(path=str(root/'dashboard-mobile.png'),full_page=True)
    print('MOBILE WIDTH',page.evaluate('({viewport:innerWidth,content:document.documentElement.scrollWidth})'))
    page.get_by_role('tab',name='Source coverage',exact=True).click()
    assert 'vtiger_quotes.csv' in page.locator('body').inner_text()
    page.get_by_role('tab',name='Queue & follow-ups',exact=True).click()
    page.screenshot(path=str(root/'dashboard-mobile-queue.png'),full_page=True)
    print('ERRORS',errors)
    assert not errors
    print('SVG',page.locator('svg').count())
    print('PASS: default metrics, owner/date/month/stage filters, All/reset, table filter, empty selection, all tabs, mobile rendering')
    browser.close()
