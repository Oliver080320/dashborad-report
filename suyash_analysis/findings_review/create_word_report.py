from pathlib import Path
import re,html
from playwright.sync_api import sync_playwright

P=Path(__file__).resolve().parent
with sync_playwright() as pw:
    b=pw.chromium.launch(channel='msedge',headless=True)
    page=b.new_page(viewport={'width':1440,'height':1000},device_scale_factor=2)
    page.goto('http://127.0.0.1:4175',wait_until='networkidle')
    for ident in ['monthly-volume-chart','destination-chart']:
        card=page.locator(f'[data-component-id="{ident}"]')
        card.scroll_into_view_if_needed();page.wait_for_timeout(700)
        card.screenshot(path=str(P/(ident+'.png')))
    b.close()

def inline(s):
    s=html.escape(s)
    def link(m):
        label,url=m.groups()
        if url.startswith('evidence/'): url=(P/url).as_uri()
        return '<a href="'+url+'">'+label+'</a>'
    s=re.sub(r'\[([^\]]+)\]\(([^)]+)\)',link,s)
    s=re.sub(r'\*\*([^*]+)\*\*',r'<b>\1</b>',s)
    s=re.sub(r'`([^`]+)`',r'<span style="font-family:Consolas;font-size:9pt">\1</span>',s)
    return s

lines=(P/'SALES_FINDINGS_REPORT.md').read_text(encoding='utf-8').splitlines()
body=[]; i=0
while i<len(lines):
    line=lines[i]
    if not line.strip(): i+=1;continue
    if line.startswith('|'):
        rows=[]
        while i<len(lines) and lines[i].startswith('|'):
            row=[x.strip() for x in lines[i].strip().strip('|').split('|')]
            if not all(re.fullmatch(r'[-: ]+',x) for x in row): rows.append(row)
            i+=1
        body.append('<table width="100%" border="1" cellspacing="0" cellpadding="6">')
        for j,row in enumerate(rows):
            tag='th' if j==0 else 'td'
            body.append('<tr>'+''.join(f'<{tag}>'+inline(c)+f'</{tag}>' for c in row)+'</tr>')
        body.append('</table>');continue
    if line.startswith('#'):
        level=len(line)-len(line.lstrip('#'));text=line[level:].strip()
        if text.startswith('4. Top'): body.append('<p><img src="'+(P/'monthly-volume-chart.png').as_uri()+'" width="650"></p>')
        if text.startswith('5. Data'): body.append('<p><img src="'+(P/'destination-chart.png').as_uri()+'" width="650"></p>')
        body.append(f'<h{level}>'+inline(text)+f'</h{level}>');i+=1;continue
    if line.startswith('- '): body.append('<p class="bullet">&#8226; '+inline(line[2:])+'</p>');i+=1;continue
    para=[line]; i+=1
    while i<len(lines) and lines[i].strip() and not lines[i].startswith(('#','|','- ')):
        para.append(lines[i]);i+=1
    body.append('<p>'+inline(' '.join(para))+'</p>')
doc='''<!DOCTYPE html><html><head><meta charset="utf-8"><title>Sales dashboard &amp; dataset findings report</title>
<style>
@page {size: A4; margin: 20mm 18mm;}
body {font-family:Calibri,Arial,sans-serif;font-size:11pt;color:#243247;line-height:1.3;}
h1 {font-size:24pt;color:#163b58;margin-bottom:16pt;}
h2 {font-size:17pt;color:#163b58;page-break-before:always;margin-bottom:12pt;}
h3 {font-size:13pt;color:#163b58;margin-top:18pt;}
p {margin:0 0 9pt;} .bullet {margin-left:14pt;}
table {border-collapse:collapse;font-size:9pt;margin:10pt 0;width:100%;}
td,th {border:1px solid #d5dfe7;padding:6pt;vertical-align:top;}
th {background:#e8f0f6;text-align:left;font-weight:bold;}
tr {page-break-inside:avoid;} a {color:#176292;} img {max-width:100%;}
</style></head><body>'''+''.join(body)+'</body></html>'
(P/'word_report_source.html').write_text(doc,encoding='utf-8')
print('Created Word conversion source with native text/tables and two captured report charts.')
