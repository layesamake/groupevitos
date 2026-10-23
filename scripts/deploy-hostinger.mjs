// Déploie une archive zip du site statique sur Hostinger via l'API (upload TUS puis « Deploy static site archive »).
// Usage : HOSTINGER_API_TOKEN=... [BUILD_ID=...] node scripts/deploy-hostinger.mjs site.zip
// Si BUILD_ID est fourni, attend que https://groupevitos.com/build.txt le renvoie pour confirmer la mise en ligne.
import { readFile } from 'node:fs/promises';

const archive = process.argv[2] ?? 'site.zip';
const { HOSTINGER_API_TOKEN: token, BUILD_ID: buildId } = process.env;
const username = process.env.HOSTINGER_USERNAME ?? 'u966150428';
const domain = process.env.SITE_DOMAIN ?? 'groupevitos.com';
const api = 'https://developers.hostinger.com/api/hosting/v1';
if (!token) throw new Error('HOSTINGER_API_TOKEN manquant');

async function call(method, path, body) {
  const response = await fetch(`${api}${path}`, {
    method, body: JSON.stringify(body),
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json', 'Content-Type': 'application/json' },
  });
  if (!response.ok) throw new Error(`Hostinger ${method} ${path} : HTTP ${response.status} ${await response.text()}`);
  return response.json();
}

const bytes = await readFile(archive);
const name = 'site.zip';
const upload = await call('POST', '/files/upload-urls', { username, domain });
const target = `${upload.url.replace(/\/$/, '')}/${name}?override=true`;
const tus = { 'X-Auth': upload.auth_key, 'X-Auth-Rest': upload.rest_auth_key, 'Tus-Resumable': '1.0.0' };

const created = await fetch(target, { method: 'POST', headers: { ...tus, 'Upload-Length': String(bytes.length), 'Upload-Offset': '0' } });
if (created.status !== 201) throw new Error(`Upload (création) : HTTP ${created.status} ${await created.text()}`);
const sent = await fetch(target, { method: 'PATCH', body: bytes, headers: { ...tus, 'Content-Type': 'application/offset+octet-stream', 'Upload-Offset': '0' } });
if (sent.status !== 204 || sent.headers.get('upload-offset') !== String(bytes.length)) throw new Error(`Upload (envoi) : HTTP ${sent.status} ${await sent.text()}`);
console.log(`Archive envoyée : ${(bytes.length / 1024).toFixed(0)} Ko`);

await call('POST', `/accounts/${username}/websites/${domain}/deploy`, { archive_path: name });
console.log('Déploiement demandé à Hostinger');

if (buildId) {
  for (let attempt = 0; attempt < 30; attempt++) {
    await new Promise(resolve => setTimeout(resolve, 10000));
    const live = await fetch(`https://${domain}/build.txt?t=${Date.now()}`).then(r => r.ok ? r.text() : '').catch(() => '');
    if (live.trim() === buildId) { console.log(`En ligne : ${buildId}`); process.exit(0); }
  }
  throw new Error(`Version ${buildId} toujours pas en ligne après 5 minutes`);
}
