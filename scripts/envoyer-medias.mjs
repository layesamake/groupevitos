// Envoie des images dans la médiathèque WordPress (mot de passe d'application). Idempotent : un fichier déjà présent (même nom) est conservé.
// Usage : WP_USER=... WP_APP_PASSWORD=... node scripts/envoyer-medias.mjs "texte alternatif::chemin/fichier.webp" ...
import { readFile } from 'node:fs/promises';
import { basename } from 'node:path';
const api = `${process.env.WP_URL ?? 'https://admin.groupevitos.com'}/wp-json`;
const { WP_USER: user, WP_APP_PASSWORD: password } = process.env;
if (!user || !password) throw new Error('WP_USER et WP_APP_PASSWORD sont requis');
const auth = `Basic ${Buffer.from(`${user}:${password.replace(/\s/g, '')}`).toString('base64')}`;
for (const arg of process.argv.slice(2)) {
  const [alt, path] = arg.split('::');
  const name = basename(path);
  const found = await (await fetch(`${api}/wp/v2/media?search=${encodeURIComponent(name.replace(/\.[^.]+$/, ''))}&per_page=20&_fields=id,source_url`, { headers: { Authorization: auth } })).json();
  const existing = found.find(m => m.source_url.endsWith(`/${name}`));
  if (existing) { console.log(`déjà présent : ${name}`); continue; }
  const response = await fetch(`${api}/wp/v2/media`, { method: 'POST', body: await readFile(path), headers: { Authorization: auth, 'Content-Type': 'image/webp', 'Content-Disposition': `attachment; filename="${name}"` } });
  const media = await response.json();
  if (!response.ok) throw new Error(`${name} : HTTP ${response.status} ${JSON.stringify(media).slice(0, 200)}`);
  await fetch(`${api}/wp/v2/media/${media.id}`, { method: 'POST', body: JSON.stringify({ alt_text: alt }), headers: { Authorization: auth, 'Content-Type': 'application/json' } });
  console.log(`envoyé : ${name} → ${media.source_url}`);
}
