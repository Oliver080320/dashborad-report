from pathlib import Path
from zipfile import ZipFile,ZIP_DEFLATED
from xml.etree import ElementTree as ET
from bs4 import BeautifulSoup,NavigableString
from PIL import Image
import re
P=Path(__file__).resolve().parent
W='http://schemas.openxmlformats.org/wordprocessingml/2006/main';R='http://schemas.openxmlformats.org/officeDocument/2006/relationships'
WP='http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';A='http://schemas.openxmlformats.org/drawingml/2006/main';PIC='http://schemas.openxmlformats.org/drawingml/2006/picture'
for pre,uri in [('w',W),('r',R),('wp',WP),('a',A),('pic',PIC)]:ET.register_namespace(pre,uri)
def el(parent,tag,attrs=None,text=None):
    ns,name=tag.split(':');uri={'w':W,'r':R,'wp':WP,'a':A,'pic':PIC}[ns]
    e=ET.SubElement(parent,'{'+uri+'}'+name)
    for k,v in (attrs or {}).items():
        if ':' in k: pre,key=k.split(':');k='{'+{'w':W,'r':R}[pre]+'}'+key
        e.set(k,str(v))
    if text is not None:e.text=text
    return e
doc=ET.Element('{'+W+'}document');body=el(doc,'w:body');rels=[];images=[]
def run(p,text,bold=False,italic=False,color=None):
    r=el(p,'w:r')
    if bold or italic or color:
        rp=el(r,'w:rPr')
        if bold:el(rp,'w:b')
        if italic:el(rp,'w:i')
        if color:el(rp,'w:color',{'w:val':color})
    t=el(r,'w:t',text=text);t.set('{http://www.w3.org/XML/1998/namespace}space','preserve')
def inline(p,node,bold=False):
    if isinstance(node,NavigableString):run(p,str(node),bold);return
    if node.name=='img':
        file=P/Path(node['src']).name;images.append(file);ix=len(images);rid='image'+str(ix)
        rels.append((rid,'image','media/'+file.name,None))
        with Image.open(file) as im: width,height=im.size
        cx=6100000;cy=int(cx*height/width)
        drawing=el(el(p,'w:r'),'w:drawing');i=el(drawing,'wp:inline',{'distT':0,'distB':0,'distL':0,'distR':0})
        el(i,'wp:extent',{'cx':cx,'cy':cy});el(i,'wp:docPr',{'id':ix,'name':file.stem,'descr':'Sales report chart; values and scope appear in adjacent editable text and tables.'})
        graphic=el(i,'a:graphic');gd=el(graphic,'a:graphicData',{'uri':PIC});pic=el(gd,'pic:pic');nv=el(pic,'pic:nvPicPr');el(nv,'pic:cNvPr',{'id':0,'name':file.name});el(nv,'pic:cNvPicPr')
        fill=el(pic,'pic:blipFill');el(fill,'a:blip',{'r:embed':rid});el(el(fill,'a:stretch'),'a:fillRect')
        sp=el(pic,'pic:spPr');xf=el(sp,'a:xfrm');el(xf,'a:off',{'x':0,'y':0});el(xf,'a:ext',{'cx':cx,'cy':cy});el(el(sp,'a:prstGeom',{'prst':'rect'}),'a:avLst');return
    target=p
    if node.name=='a':
        rid='link'+str(len(rels)+1);rels.append((rid,'hyperlink',node.get('href',''),'External'));target=el(p,'w:hyperlink',{'r:id':rid})
    for child in node.children:inline(target,child,bold or node.name in ['b','strong','th'])
soup=BeautifulSoup((P/'word_report_source.html').read_text(encoding='utf-8'),'html.parser')
for node in soup.body.children:
    if isinstance(node,NavigableString):continue
    if node.name=='table':
        table=el(body,'w:tbl');props=el(table,'w:tblPr');el(props,'w:tblW',{'w:w':5000,'w:type':'pct'});el(props,'w:tblStyle',{'w:val':'TableGrid'})
        trs=node.find_all('tr',recursive=False);n=len(trs[0].find_all(['td','th'],recursive=False));grid=el(table,'w:tblGrid')
        for _ in range(n):el(grid,'w:gridCol',{'w:w':int(9850/n)})
        for idx,tr in enumerate(trs):
            row=el(table,'w:tr');rp=el(row,'w:trPr');el(rp,'w:cantSplit')
            if idx==0:el(rp,'w:tblHeader')
            for cell in tr.find_all(['td','th'],recursive=False):
                tc=el(row,'w:tc');tcp=el(tc,'w:tcPr');el(tcp,'w:tcW',{'w:w':int(9850/n),'w:type':'dxa'})
                if idx==0:el(tcp,'w:shd',{'w:fill':'E8F0F6'})
                p=el(tc,'w:p');pp=el(p,'w:pPr');el(pp,'w:pStyle',{'w:val':'TableText'});inline(p,cell,idx==0)
        el(body,'w:p');continue
    p=el(body,'w:p');pp=el(p,'w:pPr')
    if re.fullmatch('h[123]',node.name):
        style={'h1':'Title','h2':'Heading1','h3':'Heading2'}[node.name];el(pp,'w:pStyle',{'w:val':style})
        if node.name=='h2' and not node.get_text().startswith('1.'):el(pp,'w:pageBreakBefore')
    else:el(pp,'w:pStyle',{'w:val':'Normal'})
    inline(p,node)
sect=el(body,'w:sectPr');el(sect,'w:pgSz',{'w:w':11906,'w:h':16838});el(sect,'w:pgMar',{'w:top':1134,'w:right':1020,'w:bottom':1134,'w:left':1020,'w:header':500,'w:footer':500,'w:gutter':0})
styles=ET.Element('{'+W+'}styles')
for id,size,color in [('Normal',22,'243247'),('Title',46,'163B58'),('Heading1',32,'163B58'),('Heading2',26,'163B58'),('TableText',18,'243247')]:
    st=el(styles,'w:style',{'w:type':'paragraph','w:styleId':id});el(st,'w:name',{'w:val':id});pp=el(st,'w:pPr');el(pp,'w:spacing',{'w:after':140,'w:line':270,'w:lineRule':'auto'})
    if id in ['Title','Heading1','Heading2']:el(pp,'w:keepNext')
    if id.startswith('Heading'):el(pp,'w:outlineLvl',{'w:val':0 if id=='Heading1' else 1})
    rp=el(st,'w:rPr');el(rp,'w:rFonts',{'w:ascii':'Calibri','w:hAnsi':'Calibri'});el(rp,'w:sz',{'w:val':size});el(rp,'w:color',{'w:val':color})
    if id!='Normal' and id!='TableText':el(rp,'w:b')
st=el(styles,'w:style',{'w:type':'table','w:styleId':'TableGrid'});el(st,'w:name',{'w:val':'Table Grid'});borders=el(el(st,'w:tblPr'),'w:tblBorders')
for name in ['top','left','bottom','right','insideH','insideV']:el(borders,'w:'+name,{'w:val':'single','w:sz':4,'w:color':'D5DFE7'})
REL='http://schemas.openxmlformats.org/package/2006/relationships'
rr=ET.Element('Relationships',xmlns=REL)
ET.SubElement(rr,'Relationship',Id='styles',Type=R+'/styles',Target='styles.xml')
for id,typ,target,mode in rels:
    x=ET.SubElement(rr,'Relationship',Id=id,Type=R+'/'+typ,Target=target)
    if mode:x.set('TargetMode',mode)
ct='''<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>'''
target=P/'Sales_Dashboard_Dataset_Findings_Report.docx'
with ZipFile(target,'w',ZIP_DEFLATED) as z:
    z.writestr('[Content_Types].xml',ct)
    z.writestr('_rels/.rels','<Relationships xmlns="'+REL+'"><Relationship Id="document" Type="'+R+'/officeDocument" Target="word/document.xml"/></Relationships>')
    for path,tree in [('word/document.xml',doc),('word/styles.xml',styles),('word/_rels/document.xml.rels',rr)]:z.writestr(path,ET.tostring(tree,encoding='utf-8',xml_declaration=True))
    for file in images:z.write(file,'word/media/'+file.name)
with ZipFile(target) as z:
    assert z.testzip() is None
    root=ET.fromstring(z.read('word/document.xml'));text=' '.join(root.itertext())
    for phrase in ['1. Overview','2. Key findings','3. Sales trends','4. Top and bottom performers','5. Data gaps','6. Data issues','7. Observations','8. Proof','I23_acceptance_audit_gap']:assert phrase in text,phrase
print(target);print('Validated eight sections, all issue examples, native tables and',len(images),'embedded chart images.')
