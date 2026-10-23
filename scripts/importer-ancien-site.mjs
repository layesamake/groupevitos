// Importe les contenus nettoyés de l'ancien site (import.json + medias/) dans le WordPress headless, via l'API REST.
// Idempotent : les images déjà présentes (même nom de fichier) et les contenus de même slug sont réutilisés / mis à jour.
//
// Usage : WP_USER=... WP_APP_PASSWORD=... node scripts/importer-ancien-site.mjs ../groupevitos-ancien-site [--appliquer]
// Sans --appliquer : simulation, rien n'est écrit.
import { readFile } from 'node:fs/promises';
import { basename, join } from 'node:path';

const [source = '../groupevitos-ancien-site', flag] = process.argv.slice(2);
const apply = flag === '--appliquer';
const api = `${process.env.WP_URL ?? 'https://admin.groupevitos.com'}/wp-json`;
const { WP_USER: user, WP_APP_PASSWORD: password } = process.env;
if (!user || !password) throw new Error('WP_USER et WP_APP_PASSWORD (mot de passe d’application WordPress) sont requis');
const auth = `Basic ${Buffer.from(`${user}:${password.replace(/\s/g, '')}`).toString('base64')}`;
const MIME = { jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', webp: 'image/webp', avif: 'image/avif', gif: 'image/gif' };

async function wp(method, path, body, headers = {}) {
  const response = await fetch(`${api}${path}`, {
    method, body: body instanceof Buffer ? body : body && JSON.stringify(body),
    headers: { Authorization: auth, ...(body && !(body instanceof Buffer) ? { 'Content-Type': 'application/json' } : {}), ...headers },
  });
  const text = await response.text();
  if (!response.ok) throw new Error(`WordPress ${method} ${path} : HTTP ${response.status} ${text.slice(0, 300)}`);
  return JSON.parse(text);
}

const data = JSON.parse(await readFile(join(source, 'import.json'), 'utf8'));
const me = await wp('GET', '/wp/v2/users/me?context=edit');
console.log(`Connecté en tant que ${me.slug} (${me.roles.join(', ')}) — ${apply ? 'IMPORT RÉEL' : 'simulation'}`);
await wp('GET', '/wp/v2/types/formation').catch(() => { throw new Error('Type « formation » absent : installer d’abord les mu-plugins (scripts/installer-mu-plugins.mjs)'); });

// 1. Médias
const existing = [];
for (let page = 1; ; page++) {
  const batch = await wp('GET', `/wp/v2/media?per_page=100&page=${page}&_fields=id,source_url`).catch(() => []);
  existing.push(...batch);
  if (batch.length < 100) break;
}
const media = {};
for (const file of data.media) {
  const name = file.replaceAll('/', '_');
  const found = existing.find(m => basename(new URL(m.source_url).pathname) === basename(file));
  if (found) { media[file] = found; continue; }
  if (!apply) { media[file] = { id: 0, source_url: `(à envoyer) ${name}` }; continue; }
  const bytes = await readFile(join(source, 'medias', name));
  media[file] = await wp('POST', '/wp/v2/media', bytes, {
    'Content-Type': MIME[file.split('.').pop().toLowerCase()],
    'Content-Disposition': `attachment; filename="${basename(file)}"`,
  });
}
const sent = data.media.filter(file => !existing.some(m => basename(new URL(m.source_url).pathname) === basename(file)));
console.log(`Médias : ${data.media.length - sent.length} déjà présents, ${sent.length} ${apply ? 'envoyés' : 'à envoyer'}`);

const resolve = html => html.replace(/media:([^"\s]+)/g, (_, file) => media[file]?.source_url ?? file);
const featured = file => (file && media[file]?.id) || 0;

// 2. Pages et formations (mise à jour si le slug existe déjà, sinon création)
async function upsert(route, entry, extra = {}) {
  const [current] = await wp('GET', `/wp/v2/${route}?slug=${encodeURIComponent(entry.slug)}&status=publish,draft,private&context=edit&_fields=id`);
  const body = { slug: entry.slug, title: entry.title, content: resolve(entry.content), status: 'publish', menu_order: entry.menu_order, featured_media: featured(entry.featured), ...extra };
  if (apply) await wp('POST', `/wp/v2/${route}${current ? `/${current.id}` : ''}`, body);
  console.log(`  ${current ? 'mise à jour' : 'création'} ${route}/${entry.slug}`);
}
console.log('Pages :');
for (const page of data.pages) await upsert('pages', page);
console.log('Formations :');
for (const formation of data.formations) {
  await upsert('formations', formation, { meta: Object.fromEntries(Object.entries(formation.meta).map(([key, value]) => [key, resolve(value)])) });
}

// 3. Titre et slogan du site
if (apply) await wp('POST', '/wp/v2/settings', { title: data.site.title, description: data.site.description });
console.log(`Réglages : titre « ${data.site.title} », slogan « ${data.site.description} »`);

// 4. Contenus de démonstration de WordPress passés en brouillon (ils apparaîtraient sinon sur le site public)
for (const [route, slugs] of [['posts', ['bonjour-tout-le-monde', 'hello-world']], ['pages', ['page-d-exemple', 'sample-page', 'politique-de-confidentialite', 'privacy-policy']]]) {
  for (const slug of slugs) {
    const [entry] = await wp('GET', `/wp/v2/${route}?slug=${slug}&status=publish&_fields=id`);
    if (!entry) continue;
    if (apply) await wp('POST', `/wp/v2/${route}/${entry.id}`, { status: 'draft' });
    console.log(`Démonstration ${route}/${slug} → brouillon`);
  }
}
console.log(apply ? 'Import terminé.' : 'Simulation terminée : relancer avec --appliquer pour importer.');
