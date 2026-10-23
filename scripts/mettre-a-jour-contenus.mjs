// Met à jour WordPress (types de contenus et pages) d'après scripts/contenus-referentiel.mjs, via l'API REST.
// Idempotent : un contenu existant (même slug) est mis à jour, jamais dupliqué. Aucune suppression.
//
// Usage : WP_USER=... WP_APP_PASSWORD=... node scripts/mettre-a-jour-contenus.mjs [--appliquer]
//   Sans --appliquer : simulation, rien n'est écrit.
//   Essai local : WP_URL=http://127.0.0.1:9400 WP_LOGIN_USER=... WP_LOGIN_PASSWORD=... (connexion par cookie, sans mot de passe d'application)
import * as d from './contenus-referentiel.mjs';

const apply = process.argv.includes('--appliquer');
// --seulement=pages,article : limite la mise à jour à certaines étapes (reglages, familles, formations, tarifs, dossiers, coordonnees, partenaires, promotions, pages, article)
const only = process.argv.find(a => a.startsWith('--seulement='))?.slice(12).split(',');
const want = step => !only || only.includes(step);
const base = process.env.WP_URL ?? 'https://admin.groupevitos.com';
const api = `${base}/wp-json`;
let headers = {};

async function connect() {
  if (process.env.WP_LOGIN_USER) {
    const form = new URLSearchParams({ log: process.env.WP_LOGIN_USER, pwd: process.env.WP_LOGIN_PASSWORD, 'wp-submit': 'Log In', testcookie: '1' });
    const login = await fetch(`${base}/wp-login.php`, { method: 'POST', body: form, redirect: 'manual', headers: { Cookie: 'wordpress_test_cookie=WP%20Cookie%20check' } });
    const cookie = login.headers.getSetCookie().map(c => c.split(';')[0]).join('; ');
    const nonce = await (await fetch(`${base}/wp-admin/admin-ajax.php?action=rest-nonce`, { headers: { Cookie: cookie } })).text();
    headers = { Cookie: cookie, 'X-WP-Nonce': nonce };
    return;
  }
  const { WP_USER: user, WP_APP_PASSWORD: password } = process.env;
  if (!user || !password) throw new Error('WP_USER et WP_APP_PASSWORD (mot de passe d’application WordPress) sont requis');
  headers = { Authorization: `Basic ${Buffer.from(`${user}:${password.replace(/\s/g, '')}`).toString('base64')}` };
}

async function wp(method, path, body) {
  const response = await fetch(`${api}${path}`, { method, body: body && JSON.stringify(body), headers: { ...headers, ...(body ? { 'Content-Type': 'application/json' } : {}) } });
  const text = await response.text();
  if (!response.ok) throw new Error(`${method} ${path} : HTTP ${response.status} ${text.slice(0, 300)}`);
  return JSON.parse(text);
}

const slugify = text => text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[’']/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
const log = (action, what) => console.log(`${apply ? '' : '[simulation] '}${action.padEnd(9)} ${what}`);
const total = { créé: 0, 'mis à jour': 0 };

// Crée ou met à jour une entrée identifiée par son slug.
async function upsert(restBase, slug, fields, label) {
  const found = await wp('GET', `/wp/v2/${restBase}?slug=${encodeURIComponent(slug)}&status=any&context=edit&per_page=1&_fields=id,slug`);
  const action = found.length ? 'mis à jour' : 'créé';
  total[action]++;
  log(action, `${restBase} · ${label ?? fields.title}`);
  if (!apply) return found[0]?.id ?? 0;
  return (found.length ? await wp('POST', `/wp/v2/${restBase}/${found[0].id}`, fields) : await wp('POST', `/wp/v2/${restBase}`, { slug, ...fields })).id;
}

await connect();
const me = await wp('GET', '/wp/v2/users/me?context=edit');
console.log(`Connecté en tant que ${me.slug} (${me.roles.join(', ')}) — ${apply ? 'MISE À JOUR RÉELLE' : 'simulation'}`);
for (const type of ['tarif', 'dossier', 'coordonnee', 'partenaire', 'promotion']) await wp('GET', `/wp/v2/types/${type}`).catch(() => { throw new Error(`Type « ${type} » absent : installer d’abord les mu-plugins (scripts/installer-mu-plugins.mjs)`); });

// 1. Titre du site, devise, identité
if (want('reglages')) log('réglages', `${d.reglages.title} — ${d.reglages.description}`);
if (apply && want('reglages')) {
  await wp('POST', '/wp/v2/settings', d.reglages);
  await wp('POST', '/groupevitos/v1/identite', d.identite);
}

// 2. Familles de formations
const familles = {};
for (const famille of want('familles') || want('formations') ? d.familles : []) {
  const found = await wp('GET', `/wp/v2/familles-formations?slug=${famille.slug}&per_page=1&hide_empty=false`);
  log(found.length ? 'existe' : 'créé', `famille · ${famille.name}`);
  familles[famille.slug] = found[0]?.id ?? (apply ? (await wp('POST', '/wp/v2/familles-formations', famille)).id : 0);
}

// 3. Formations : catalogue S09 (17 intitulés) ; les 4 fiches déjà rédigées gardent leur contenu
let order = 0;
for (const f of want('formations') ? d.formations : []) {
  order++;
  const slug = f.slug ?? slugify(f.title);
  await upsert('formations', slug, {
    title: f.title, status: 'publish', menu_order: Number(f.ref), 'familles-formations': [familles[f.famille]],
    meta: { reference: f.ref, sigle: f.sigle ?? '', variantes: f.variantes ?? '', validation: f.slug ? 'validee' : 'catalogue' },
  });
}
for (const f of want('formations') ? d.formationsAConfirmer : []) {
  await upsert('formations', slugify(f.title), { title: f.title, status: 'draft', menu_order: 90, 'familles-formations': [familles[f.famille]], meta: { validation: 'a_confirmer', variantes: f.note } }, `${f.title} (brouillon, à confirmer)`);
}

// 4. Tarifs, dossiers, coordonnées, partenaires, promotions
for (const t of want('tarifs') ? d.tarifs : []) {
  const { title, ordre, ...meta } = t;
  await upsert('tarifs', slugify(`${t.pole}-${title}-${t.periode ?? 'sans-periode'}`), { title, status: 'publish', menu_order: ordre, meta: { periode: '', statut: 'en_vigueur', note: '', inscription: 0, scolarite: 0, decembre: 0, mars: 0, ...meta } });
}
for (const x of want('dossiers') ? d.dossiers : []) await upsert('dossiers', slugify(`dossier-${x.pole}`), { title: x.title, status: 'publish', menu_order: x.ordre, meta: { pole: x.pole, annee: x.annee, pieces: x.pieces, note: x.note } });
let n = 0;
for (const c of want('coordonnees') ? d.coordonnees : []) {
  const { title, status, ...meta } = c;
  await upsert('coordonnees', slugify(`${c.type}-${c.valeur}`).slice(0, 80), { title, status, menu_order: ++n, meta });
}
const media = {};
for (let page = 1; ; page++) {
  const batch = await wp('GET', `/wp/v2/media?per_page=100&page=${page}&_fields=id,source_url`).catch(() => []);
  for (const m of batch) media[m.source_url.split('/').pop()] = m.id;
  if (batch.length < 100) break;
}
n = 0;
for (const x of want('partenaires') ? d.partenaires : []) {
  const fields = { title: x.title, status: x.shown ? 'publish' : 'draft', menu_order: ++n, meta: { sigle: x.sigle ?? '', note: x.note ?? '' } };
  if (x.logo && media[x.logo]) fields.featured_media = media[x.logo];
  await upsert('partenaires', slugify(x.title), fields);
}
n = 0;
for (const x of want('promotions') ? d.promotions : []) { const { title, ...meta } = x; await upsert('promotions', slugify(title), { title, status: 'private', menu_order: ++n, meta }); }

// 5. Pages : contenu rédigé ci-dessus ; l'accueil perd sa liste de logos (les partenaires viennent du type « Partenaires »)
for (const [slug, page] of want('pages') ? Object.entries(d.pages) : []) {
  const found = await wp('GET', `/wp/v2/pages?slug=${slug}&status=any&context=edit&per_page=1&_fields=id`);
  const menu = ['universite', 'lycee', 'itp', 'admission', 'partenaires'].indexOf(slug) + 10;
  await upsert('pages', slug, { title: page.title, content: page.content, status: 'publish', menu_order: found.length ? undefined : menu, excerpt: '' }, page.title);
}
const accueil = (await wp('GET', '/wp/v2/pages?slug=accueil&context=edit&per_page=1&_fields=id,content'))[0];
const raw = accueil?.content?.raw ?? '';
const cut = !want('pages') ? -1 : raw.search(/<h2[^>]*>\s*Nos partenaires\s*<\/h2>/);
if (cut >= 0) {
  const rest = raw.slice(cut).match(/<h2[^>]*>\s*Rejoignez-nous[\s\S]*$/)?.[0] ?? '';
  log('mis à jour', 'pages · Accueil (liste de logos retirée)');
  if (apply) await wp('POST', `/wp/v2/pages/${accueil.id}`, { content: raw.slice(0, cut) + rest });
}

// 6. Article de conseils
if (want('article')) await upsert('posts', d.article.slug, { title: d.article.title, content: d.article.content, status: 'publish' });

console.log(`\n${total.créé} créé(s), ${total['mis à jour']} mis à jour${apply ? '' : ' (simulation : relancer avec --appliquer)'}.`);
