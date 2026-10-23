// Déploie une archive zip du site statique sur Hostinger via l'API (upload TUS puis « Deploy static site archive »).
// Usage : HOSTINGER_API_TOKEN=... [BUILD_ID=...] node scripts/deploy-hostinger.mjs site.zip
// Si BUILD_ID est fourni, attend que https://groupevitos.com/build.txt le renvoie pour confirmer la mise en ligne.
import { readFile } from 'node:fs/promises';
import { call, upload, username } from './hostinger.mjs';

const archive = process.argv[2] ?? 'site.zip';
const domain = process.env.SITE_DOMAIN ?? 'groupevitos.com';
const buildId = process.env.BUILD_ID;

const bytes = await readFile(archive);
await upload(domain, 'site.zip', bytes);
console.log(`Archive envoyée : ${(bytes.length / 1024).toFixed(0)} Ko`);

await call('POST', `/accounts/${username}/websites/${domain}/deploy`, { archive_path: 'site.zip' });
console.log('Déploiement demandé à Hostinger');

if (buildId) {
  for (let attempt = 0; attempt < 30; attempt++) {
    await new Promise(resolve => setTimeout(resolve, 10000));
    const live = await fetch(`https://${domain}/build.txt?t=${Date.now()}`).then(r => r.ok ? r.text() : '').catch(() => '');
    if (live.trim() === buildId) { console.log(`En ligne : ${buildId}`); process.exit(0); }
  }
  throw new Error(`Version ${buildId} toujours pas en ligne après 5 minutes`);
}
