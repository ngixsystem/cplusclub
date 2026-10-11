"""Build the versioned library from editorial guides and the read-only source audit.
Run: docker compose -f compose.test.yaml run --rm --no-deps python python scripts/build_knowledge.py
No runtime dependency on CCBoot. Source bodies and screenshots are never published.
"""
import json, math, re
from pathlib import Path
from urllib.parse import unquote

ROOT = Path('/work')
DIR = ROOT / 'resources/knowledge'
audit = json.loads((DIR / 'ccboot-audit.json').read_text())
guides = json.loads((DIR / 'guides.json').read_text())
definitions = [
 ('System requirements','requirements','Требования и оборудование','Подбор сервера, накопителей, сетевого оборудования и совместимость клиентских ПК.'),
 ('Installation','installation','Установка и запуск','Первичная настройка сервера, сети и загрузки клиентов CCBoot.'),
 ('Boot image','boot-images','Загрузочные образы','Создание образов Windows, драйверы и подготовка клиентской системы.'),
 ('CCBoot menus','menus','Интерфейс сервера','Меню и команды управления сервером CCBoot.'),
 ('Cloud control panel','control-panel','Облачная панель','Карточки ПК, назначение образов, управление дисками и клиентами.'),
 ('Image management','image-management','Управление образами','Обновление, точки восстановления, драйверы и обслуживание образов.'),
 ('Settings pages','settings','Настройки','Параметры загрузки, статистика, журналы и настройки центра.'),
 ('DHCP and PXE boot','network','Сеть, DHCP и PXE','Адресация клиентов, загрузочный сервер и диагностика сетевой загрузки.'),
 ('CCBoot Hybrid','hybrid','Гибридный режим','Локальная загрузка VHD, обновление и восстановление клиентов Hybrid.'),
 ('Windows boot issues','windows','Ошибки загрузки Windows','Остановки загрузки, драйверы NIC, сетевые привязки и ошибки Windows.'),
 ('BSOD issues','bsod','Синие экраны','Коды остановки, неисправности памяти, драйверов и накопителей.'),
 ('Diskless speed issues','performance','Производительность','Скорость загрузки, сеть, кеш и нагрузка серверных дисков.'),
 ('Game issues','games','Игры и античиты','Лаунчеры, файлы игр, GameFix, FACEIT и другие античиты.'),
 ('Applications issues','applications','Приложения','Установка и диагностика приложений, периферии и сетевых утилит.'),
 ('Payment FAQs','license','Лицензии и оплата','Срок лицензии, количество мест, продление и платёжные вопросы.'),
 ('CCProxy related','ccproxy','CCProxy','Регистрация и восстановление лицензии продукта CCProxy.'),
 ('Support','support','Поддержка','Диагностические данные и подготовка обращения к специалисту.'),
 ('Related articles','other','Другие материалы','Дополнительные страницы, найденные по внутренним ссылкам официальной wiki.'),
]
category_map={en:ident for en,ident,title,description in definitions}
categories=[dict(id=ident,title=title,description=description) for en,ident,title,description in definitions]
cat_by_id={c['id']:c for c in categories}
source_by_slug={unquote(a['url'].split('/wikis-')[-1].removesuffix('.htm')):a for a in audit['articles']}
synonyms={
 'image':'образ образы', 'nic':'сетевая карта адаптер драйвер', 'driver':'драйвер',
 'boot':'загрузка запуск', 'disk':'диск накопитель', 'writeback':'write back запись',
 'write-back':'writeback запись', 'cache':'кеш кэш', 'bsod':'синий экран', 'ram':'оперативная память',
 'memory':'память', 'network':'сеть', 'slow':'медленно скорость', 'speed':'скорость',
 'black screen':'чёрный экран', 'update':'обновление', 'install':'установка',
 'license':'лицензия', 'payment':'оплата', 'restore':'восстановление откат',
 'superclient':'super client суперклиент', 'super client':'superclient суперклиент',
 'secure boot':'безопасная загрузка', 'motherboard':'материнская плата', 'windows':'виндовс',
 'display':'монитор экран', 'sound':'звук', 'mouse':'мышь', 'error':'ошибка',
 'freeze':'зависание', 'freezing':'зависание', 'reboot':'перезагрузка', 'shutdown':'выключение',
 'crash':'вылет сбой', 'fail':'сбой ошибка', 'games':'игры', 'game':'игра',
 'temperature':'температура', 'steam':'стим', 'faceit':'фейсит античит', 'valorant':'валорант',
 'dota':'дота', 'counter':'кс', 'cs2':'кс2 кс', 'discord':'дискорд', 'nvidia':'видеокарта',
 'gpu':'видеокарта', 'cpu':'процессор', 'logs':'журналы логи', 'command':'команды',
 'dhcp':'айпи ip адрес', 'pxe':'сетевая загрузка', 'tftp':'таймаут загрузчик',
}
def search_terms(title,category):
 lower=title.lower()
 return ' '.join([cat_by_id[category]['title'],*[v for k,v in synonyms.items() if k in lower]])
articles=[]
for guide in guides:
 sources=[]
 for slug in guide['sources']:
  source=source_by_slug.get(unquote(slug))
  assert source, f'Missing source: {slug}'
  assert source['status']==200 and not source.get('soft_error'), f'Unreadable guide source: {slug}'
  sources.append(dict(title=source['title'],url=source['url']))
 body=' '.join(' '.join(s.get('paragraphs',[])+s.get('steps',[])+[s.get('note','')]) for s in guide['sections'])
 articles.append(dict(guide,kind='guide',sources=sources,search=body+' '+search_terms(guide['title'],guide['category']),status='ok',updated_at=audit['checked_at'],minutes=max(2,math.ceil(len(body.split())/160))))

for source in audit['articles']:
 if source.get('is_category'):continue
 category=category_map[source['category']]
 title=source['title']
 if source['url'].endswith('/wikis-diskless-boot-with-uefi-bios.htm'):
  category='installation'
  title='UEFI boot — архивный адрес инструкции'
 status='broken' if source['status'] in (404,410) else 'ok' if 200<=source['status']<300 and not source.get('soft_error') else 'unverified'
 articles.append(dict(id=source['id'],title=title,original_title=source['title'],kind='source',category=category,
  summary=cat_by_id[category]['description'],tags=[],search=search_terms(source['title'],category),
  updated_at=audit['checked_at'],source_date=source.get('source_date'),status=status,url=source['url']))
for category in categories:category['count']=sum(a['category']==category['id'] for a in articles)
categories=[c for c in categories if c['count']]
assert len({a['id'] for a in articles})==len(articles)
broken=sum(x['status'] in (404,410) for x in audit['links'])
unverified=sum(x['status'] not in (404,410) and (not 200<=x['status']<300 or x.get('soft_error',False)) for x in audit['links'])
library=dict(categories=categories,articles=articles,audit=dict(checked_at=audit['checked_at'],pages=audit['article_count'],links=audit['link_count'],broken=broken,unverified=unverified,guides=len(guides)))
(DIR/'library.json').write_text(json.dumps(library,ensure_ascii=False,indent=2))
print(f'Built {len(guides)} guides + {len(articles)-len(guides)} source entries across {len(categories)} categories.')
# Reviewable, sanitized audit findings. Full source bodies remain in ignored storage.
lines=['# CCBoot: проверка ссылок', '',f"Дата: {audit['checked_at']}. Начальная страница: {audit['seed']}", '',
 f"Проверено страниц wiki: {audit['article_count']}; уникальных HTTP(S)-ссылок: {audit['link_count']}. HTTP 404/410: {broken}; остальные неподтверждённые: {unverified}.", '',
 'Wiki проверяется GET с чтением HTML; прочие ссылки — HEAD, при 403/404/405 повтор GET. Ссылки на файлы проверяются без чтения бинарного тела. Перенаправления учитываются. Фрагменты страниц, изображения и JavaScript-обработчики не проверяются. HTTP 200 не подтверждает техническую актуальность. Запрет автоматического доступа не считается доказательством неработающей ссылки.', '',
 'Категорийные страницы не дублируются как статьи в библиотеке. URL-алиасы приведены к одинаковому percent-encoding. Оригинальные тексты и иллюстрации не скопированы в приложение. Подписанные параметры внешних загрузок скрыты в отчёте.', '',
 '| Адрес | Результат | Найден на странице |','|---|---|---|']
for x in audit['links']:
 if not 200<=x['status']<300 or x.get('soft_error'):
  lines.append('| '+x['url'].replace('|','%7C')+' | '+str(x['status'] or x.get('error','network error'))+(' / content missing' if x.get('soft_error') else '')+' | '+', '.join(x.get('referrers',[])[:3]).replace('|','%7C')+' |')
(ROOT/'docs/ccboot-link-audit.md').write_text('\n'.join(lines)+'\n')
