"""Prépare l'import des contenus de l'ancien site (Elementor) vers le WordPress headless.

Lit groupevitos-ancien-site/contenus.json (extrait de la sauvegarde Duplicator) et écrit import.json à côté :
HTML réduit à une liste blanche de balises, sans attributs Elementor, images référencées par « media:<fichier> »
(remplacées par les URLs de la médiathèque au moment de l'import).

Usage : python scripts/nettoyer-ancien-site.py ../groupevitos-ancien-site
"""
import html, json, re, sys
from html.parser import HTMLParser
from pathlib import Path

ALLOWED = {
    'h2': [], 'h3': [], 'h4': [], 'p': [], 'ul': [], 'ol': [], 'li': [], 'strong': [], 'em': [], 'br': [], 'hr': [],
    'a': ['href'], 'img': ['src', 'alt'], 'table': [], 'thead': [], 'tbody': [], 'tr': [], 'th': [], 'td': [],
    'figure': [], 'figcaption': [], 'blockquote': [], 'iframe': ['src', 'title'],
}
RENAME = {'b': 'strong', 'i': 'em', 'h1': 'h2', 'h5': 'h4', 'h6': 'h4'}
DROP_WITH_CONTENT = {'svg', 'script', 'style', 'noscript', 'form', 'button'}
VOID = {'br', 'hr', 'img'}
OLD = re.compile(r'^https?://(www\.)?groupevitos\.com')


def media_ref(url):
    """URL d'upload de l'ancien site -> media:<fichier d'origine> (sans suffixe de taille -300x300)."""
    path = OLD.sub('', url).split('?')[0]
    if not path.startswith('/wp-content/uploads/'): return url
    path = path[len('/wp-content/uploads/'):]
    return 'media:' + re.sub(r'-\d+x\d+(?=\.\w+$)', '', path)


class Cleaner(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.out, self.skip, self.links = [], 0, []

    def handle_starttag(self, tag, attrs):
        if tag in DROP_WITH_CONTENT:
            self.skip += 1; return
        tag = RENAME.get(tag, tag)
        if self.skip or tag not in ALLOWED: return
        kept = []
        for name, value in attrs:
            if name not in ALLOWED[tag] or value is None: continue
            if name in ('src', 'href'):
                if value.startswith('#'): continue
                value = media_ref(value) if '/wp-content/uploads/' in value else OLD.sub('', value) or '/'
            kept.append(f' {name}="{html.escape(value, quote=True)}"')
        if tag == 'a':
            self.links.append(bool(kept))
            if not kept: return  # ancres internes et boutons Elementor : la balise fermante sera ignorée aussi
        self.out.append(f'<{tag}{"".join(kept)}>')

    def handle_endtag(self, tag):
        if tag in DROP_WITH_CONTENT:
            self.skip = max(0, self.skip - 1); return
        tag = RENAME.get(tag, tag)
        if self.skip or tag not in ALLOWED or tag in VOID: return
        if tag == 'a' and not (self.links.pop() if self.links else False): return
        self.out.append(f'</{tag}>')

    def handle_data(self, data):
        if not self.skip: self.out.append(html.escape(data, quote=False))


def clean(source):
    c = Cleaner(); c.feed(source or ''); c.close()
    out = re.sub(r'\s+', ' ', ''.join(c.out))
    out = re.sub(r'<a[^>]*>\s*</a>', '', out)  # liens orphelins (boutons vidés)
    for _ in range(3): out = re.sub(r'<(p|li|strong|em|h[2-4]|td|th)>\s*</\1>', '', out)
    out = re.sub(r'<p>\s*(<(?:ul|ol|table|h[2-4])>)', r'\1', out)  # <li><p>…</p></li> issus de copier-coller
    out = re.sub(r'<li>\s*<p>(.*?)</p>\s*</li>', r'<li>\1</li>', out)
    out = re.sub(r'<(td|th)>\s*<p>(.*?)</p>\s*</\1>', r'<\1>\2</\1>', out)
    out = re.sub(r'\s*(</?(?:p|ul|ol|li|h[2-4]|table|thead|tbody|tr|figure|blockquote|hr)\b[^>]*>)\s*', r'\1', out)
    out = re.sub(r'(</(?:p|ul|ol|h[2-4]|table|figure|blockquote)>|<hr>)', r'\1\n', out)
    out = re.sub(r'\s*=&gt;\s*(?=</h[2-4]>)', '', out)
    return out.replace(' =&gt; ', ' : ').strip()


def strip_emoji(text):
    return re.sub(r'[\U0001F000-\U0001FAFF☀-➿️]', '', text).strip()


def title(text):
    text = strip_emoji(html.unescape(text))
    return text[:1].upper() + text[1:] if text.islower() else text


def slugify(text):
    import unicodedata
    text = unicodedata.normalize('NFKD', strip_emoji(text)).encode('ascii', 'ignore').decode().lower()
    return re.sub(r'[^a-z0-9]+', '-', text).strip('-')


src = Path(sys.argv[1])
data = json.loads((src / 'contenus.json').read_text(encoding='utf-8'))
pages = {p['slug']: p for p in data['pages']}
image = lambda e: e['image_une'] and e['image_une']['fichier']

# Galerie : les liens Elementor pointent vers les images, la catégorie vient de l'ordre des filtres (0 Graduation, 1 Culture)
gallery = [(m.group(2), {'0': 'Remises de diplômes', '1': 'Vie culturelle'}.get(m.group(1), ''))
           for m in re.finditer(r'data-e-gallery-tags="(\d)" href="([^"]+)"', pages['galeries']['contenu_html'])]
gallery_html = ''.join(f'<figure><img src="{media_ref(url)}" alt="{html.escape(category)}"><figcaption>{html.escape(category)}</figcaption></figure>\n'
                       for url, category in gallery)

result = {
    'site': {'title': 'Groupe Scolaire VITO S.', 'description': html.unescape(data['site']['blogdescription'] or '')},
    'pages': [],
    'formations': [],
}
JOIN = '<h2>Rejoignez-nous</h2>\n<p>Pour réserver votre place ou obtenir des renseignements, <a href="/contacts/">contactez-nous</a>.</p>'
CONTACTS = ('<ul><li>Bamako : <a href="tel:+22382068206">+223 82 06 82 06</a></li><li>Bamako : <a href="tel:+22390129012">+223 90 12 90 12</a></li>'
            '<li>Annexe : <a href="tel:+22372727922">+223 72 72 79 22</a></li><li>Annexe : <a href="tel:+22373383893">+223 73 38 38 93</a></li>'
            '<li>E-mail : <a href="mailto:contact@groupevitos.com">contact@groupevitos.com</a></li></ul>\n'
            '<p>Adresse : <a href="https://maps.app.goo.gl/ywYba1iFuBw9Xa8k6">Kalaban Coura, Bamako — Rue 30, Immeuble VITO S.</a></p>\n'
            '<iframe src="https://maps.google.com/maps?q=insitut%20vito%20s&amp;t=m&amp;z=14&amp;output=embed" title="Plan d’accès à l’Institut VITO S."></iframe>')


def curate(slug, content):
    """Corrections propres à chaque page : blocs de mise en page Elementor, texte de démonstration, doublons de titre."""
    if slug == 'accueil':  # le bandeau (titre, slogan, boutons) est rendu par Astro ; « Rejoignez… » contenait un texte « Lorem ipsum »
        content = content[content.index('<img'):content.index("<h2>Rejoignez")] + JOIN
    if slug == 'admission':
        content = content.replace('<h2>ADMISSION</h2>', '', 1)
        content = content[:content.index('<h2>Rejoignez-nous')] + JOIN
    if slug == 'contacts': content = CONTACTS
    if slug == 'inscription':
        content = '<p>Pour vous inscrire, contactez-nous par téléphone ou par e-mail : notre équipe vous accompagne dans la constitution de votre dossier. Les pièces à fournir sont détaillées sur la page <a href="/admission/">Admission</a>.</p>'
    return content.strip()


TITLES = {'a-propos': 'À propos'}
order = {'accueil': 0, 'a-propos': 1, 'admission': 2, 'galeries': 3, 'inscription': 4, 'contacts': 5}
for slug, position in order.items():
    p = pages[slug]
    content = gallery_html if slug == 'galeries' else curate(slug, clean(p['contenu_html']))
    result['pages'].append({'slug': slug, 'title': TITLES.get(slug) or title(p['titre']), 'menu_order': position, 'content': content, 'featured': image(p)})

FIELD_MAP = {'niveau': 'niveau', 'duree_': 'duree', 'admission': 'admission', 'enseignements_principaux': 'enseignements',
             'competences': 'competences', 'debouches': 'debouches', 'carriere': 'carriere',
             '🛠️_methodes_pedagogiques': 'methodes', '🎯_finalite_de_la_formation': 'finalite'}
for position, f in enumerate(sorted(data['formations'], key=lambda f: f['id'])):
    meta = {'parcours': ''}
    for old, new in FIELD_MAP.items():
        value = (f['champs_acf'].get(old) or '').strip()
        if '<' not in value: value = '<p>' + value.replace('\n', '<br>') + '</p>' if value else ''
        meta[new] = clean(value)
    # « niveau » est soit un texte (« Licence (Bac +3) »), soit un tableau Niveau / Durée / Objectif :
    # le tableau devient le champ « parcours », niveau et durée sont résumés à partir de ses colonnes.
    if meta['niveau'].startswith('<table'):
        meta['parcours'] = meta['niveau']
        cells = [re.findall(r'<td>(.*?)</td>', row) for row in re.findall(r'<tr>(.*?)</tr>', meta['niveau'])[1:]]
        plain = lambda v: html.unescape(re.sub(r'<[^>]+>', '', v)).strip()
        meta['niveau'] = ', '.join(plain(c[0]) for c in cells if c)
        meta['duree'] = meta['duree'] or ', '.join(f'{plain(c[0])} : {plain(c[1])}' for c in cells if len(c) > 1)
    else:
        meta['niveau'] = html.unescape(re.sub(r'<[^>]+>', '', meta['niveau'])).strip()
    meta['duree'] = html.unescape(re.sub(r'<[^>]+>', '', meta['duree'])).strip()
    name = title(f['titre'])
    result['formations'].append({'slug': slugify(name), 'title': name, 'menu_order': position, 'content': clean(f['contenu_html']),
                                 'featured': image(f), 'meta': meta})

result['media'] = sorted({m['fichier'] for m in data['medias']})
(src / 'import.json').write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding='utf-8')
print(f"pages={len(result['pages'])} formations={len(result['formations'])} galerie={len(gallery)} images medias={len(result['media'])}")
texts = [e['content'] for e in result['pages'] + result['formations']] + [v for f in result['formations'] for v in f['meta'].values()]
missing = {ref[6:] for t in texts for ref in re.findall(r'media:[^"\s]+', t)} - set(result['media'])
print('références d’images introuvables :', sorted(missing) or 'aucune')
