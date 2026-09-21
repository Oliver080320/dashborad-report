from pathlib import Path
import json
from playwright.sync_api import sync_playwright
out=Path(__file__).resolve().parent/'evidence'
with sync_playwright() as p:
    b=p.chromium.launch(channel='msedge',headless=True)
    page=b.new_page(viewport={'width':1600,'height':1100})
    errors=[]; page.on('pageerror',lambda e:errors.append(str(e)))
    page.goto('http://127.0.0.1:8502',wait_until='networkidle')
    page.get_by_role('tab',name='Team activity',exact=True).wait_for(timeout=60000)
    for i,name in enumerate(['Team activity','Workload & capacity','Account intelligence','Quote pipeline','By person','Data notes']):
        page.get_by_role('tab',name=name,exact=True).click()
        page.wait_for_timeout(2200)
        page.locator('[data-testid="stMain"]').evaluate('(e)=>e.scrollTop=0')
        page.wait_for_timeout(500)
        page.screenshot(path=str(out/f'dashboard_{i+1}.png'),full_page=True)
        (out/f'dashboard_{i+1}_visible.txt').write_text(page.locator('body').inner_text(),encoding='utf-8')
        for part in range(1,5):
            page.locator('[data-testid="stMain"]').evaluate('(e)=>e.scrollTop += 850')
            page.wait_for_timeout(500)
            page.screenshot(path=str(out/f'dashboard_{i+1}_part{part}.png'))
    page.get_by_role('tab',name='Quote pipeline',exact=True).click()
    with page.expect_download() as download:
        page.get_by_role('button',name='Download next 3 months report').click()
    download.value.save_as(str(out/'ui_next_three_months_export.csv'))
    (out/'dashboard_browser_check.json').write_text(json.dumps({'errors':errors,'tabs':6,'url':page.url}),encoding='utf-8')
    print({'errors':errors,'tabs':6})
    b.close()
