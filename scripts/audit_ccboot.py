"""Read-only wiki audit. Run in Docker; does not execute/download linked software.
Raw text stays in ignored storage; published catalog contains metadata only.
"""
import concurrent.futures, hashlib, html, json, re, time, urllib.request, urllib.error
from datetime import date
from html.parser import HTMLParser
from urllib.parse import urljoin, urlsplit, urldefrag, quote, unquote, urlunsplit, parse_qsl, urlencode
from pathlib import Path
BASE='https://www.ccboot.com/wikis.htm'
TODAY=date.today().isoformat()
ROOT=Path('/work')
OUT=ROOT/'storage/app/knowledge-audit'
OUT.mkdir(parents=True,exist_ok=True)
class Parser(HTMLParser):
 def __init__(self):
  super().__init__(); self.links=[]; self.text=[]; self.a=None; self.skip=0
 def handle_starttag(self,t,attrs):
  a=dict(attrs)
  if t in ('script','style'): self.skip+=1
  if t=='a': self.a=[a.get('href',''),[]]
 def handle_data(self,s):
  if not self.skip:
   self.text.append(s)
   if self.a: self.a[1].append(s)
 def handle_endtag(self,t):
  if t in ('script','style'): self.skip=max(0,self.skip-1)
  if t=='a' and self.a:
   self.links.append((self.a[0],' '.join(' '.join(self.a[1]).split())));self.a=None

def parse(s):
 p=Parser();p.feed(s);return p

def norm(s,base=BASE):
 u=urldefrag(urljoin(base,html.unescape(s)))[0]
 parts=urlsplit(u)
 if parts.scheme not in ('http','https'):return None
 return urlunsplit((parts.scheme,parts.netloc,quote(unquote(parts.path),safe='/-._~'),parts.query,''))

def fetch(u,head=False):
 try:
  req=urllib.request.Request(u,method='HEAD' if head else 'GET',headers={'User-Agent':'CPlusClubDocumentationAudit/1.0','Accept':'text/html,*/*;q=0.5'})
  with urllib.request.urlopen(req,timeout=18) as r:
   ct=r.headers.get('Content-Type','')
   raw=b'' if head or 'html' not in ct else r.read(2_000_001)
   return {'url':u,'status':r.status,'final_url':r.url,'content_type':ct,'body':raw.decode('utf-8','replace') if 'html' in ct else ''}
 except urllib.error.HTTPError as e:
  if head and e.code in (403,404,405):return fetch(u,False)
  return {'url':u,'status':e.code,'final_url':e.url,'body':''}
 except Exception as e:return {'url':u,'status':0,'error':type(e).__name__,'body':''}

index=fetch(BASE)
assert index['status']==200
s=index['body']
# Desktop category sections; the mobile copy is deliberately deduplicated.
sections=re.split(r'<div[^>]+id=[\'\"]menuTitle\d+[\'\"][^>]*>',s)[1:]
catalog={};categories=[]
for block in sections:
 block=block.split('</ul>',1)[0]
 links=parse(block).links
 if not links:continue
 cat=links[0][1];categories.append(cat)
 for href,title in links[1:]:
  u=norm(href)
  if u and '/wikis-' in u:catalog.setdefault(u,{'title':title,'category':cat,'url':u})
assert len(catalog)>100, len(catalog)
print('Catalog articles:',len(catalog),'categories:',len(set(categories)),flush=True)
results={};pending=set(catalog);all_links={u:{'from':[BASE]} for u in catalog}
# Audit every linked wiki article recursively, including related articles.
for depth in range(12):
 if not pending:break
 todo=sorted(pending);pending=set()
 with concurrent.futures.ThreadPoolExecutor(max_workers=3) as pool:
  for row in pool.map(fetch,todo):
   u=row['url'];body=row.pop('body','');parsed=parse(body)
   article=re.split(r'post-content[^>]*>',body,maxsplit=1)[-1].split('<!-- END: LEFT COLUMN',1)[0] if 'post-content' in body else ''
   article=re.split(r'<h5>Related:',article)[0]
   content=' '.join(parse(article).text);content=' '.join(content.split())
   title=re.search(r'<h1[^>]*>(.*?)</h1>',body,re.S)
   title=' '.join(parse(title.group(1)).text).strip() if title else catalog.get(u,{}).get('title','')
   row['title']=title;row['words']=len(content.split());row['sha256']=hashlib.sha256(content.encode()).hexdigest()
   row['checked_at']=TODAY;row['soft_error']=row['status']==200 and (not article or not content or title.lower() in ('404','page not found'))
   source_date=re.search(r'\b20\d{2}-\d{2}-\d{2}\b',content)
   row['source_date']=source_date.group(0) if source_date else None
   row['is_category']='col-md-9' in body.split('post-content',1)[-1][:30]
   results[u]=row
   (OUT/(hashlib.sha256(u.encode()).hexdigest()+'.txt')).write_text(content)
   if u not in catalog:catalog[u]={'url':u,'title':title or u.rsplit('/',1)[-1],'category':'Related articles'}
   for href,label in parsed.links:
    link=norm(href,u)
    if not link:continue
    info=all_links.setdefault(link,{'from':[]})
    if u not in info['from']:info['from'].append(u)
    if urlsplit(link).hostname in ('www.ccboot.com','ccboot.com') and '/wikis-' in urlsplit(link).path and link not in results:
     pending.add(link)
   time.sleep(.12)
 print('Read articles:',len(results),'next:',len(pending),flush=True)
# HEAD-check hyperlinks outside wiki; no downloads, images/scripts excluded.
remaining=sorted(set(all_links)-set(results))
with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
 for row in pool.map(lambda u:fetch(u,True),remaining):
  row.pop('body',None); row['checked_at']=TODAY; results[row['url']]=row
# Persist audit including failures rather than silently treating them as successes.
articles=[]
for u,a in catalog.items():
 result=results.get(u,{'status':0})
 a.update({k:v for k,v in result.items() if k not in ('url','title')})
 a['id']=hashlib.sha256(u.encode()).hexdigest()[:16]
 articles.append(a)
audit={'checked_at':TODAY,'seed':BASE,'article_count':len(articles),'link_count':len(results),'categories':list(dict.fromkeys(categories)),'articles':articles,'links':[dict(v,referrers=all_links.get(u,{}).get('from',[])) for u,v in results.items()]}
# Preserve expiring public download addresses only in ignored storage, not Git.
(OUT/'audit-private.json').write_text(json.dumps(audit,ensure_ascii=False,indent=2))
def sanitize(value):
 if isinstance(value,dict):return {k:sanitize(v) for k,v in value.items()}
 if isinstance(value,list):return [sanitize(v) for v in value]
 if isinstance(value,str) and value.startswith(('http://','https://')):
  u=urlsplit(value);query=parse_qsl(u.query,keep_blank_values=True)
  if any(k.lower() in ('signature','token','key-pair-id','access_token') for k,v in query):
   return urlunsplit((u.scheme,u.netloc,u.path,urlencode([(k,'REDACTED' if k.lower() in ('signature','token','key-pair-id','access_token') else v) for k,v in query]),''))
 return value
audit=sanitize(audit)
assert not pending, 'Recursive wiki frontier was not exhausted; increase depth and rerun'
(ROOT/'resources/knowledge').mkdir(exist_ok=True)
(ROOT/'resources/knowledge/ccboot-audit.json').write_text(json.dumps(audit,ensure_ascii=False,indent=2))
print('Finished:',len(articles),'articles,',len(results),'links,',sum(v.get('status',0)>=400 or not v.get('status') or v.get('soft_error',False) for v in results.values()),'need attention',flush=True)
