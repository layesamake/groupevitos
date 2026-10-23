// Client minimal de l'API Hostinger (hébergement web) partagé par les scripts de déploiement.
const api = 'https://developers.hostinger.com/api/hosting/v1';
export const username = process.env.HOSTINGER_USERNAME ?? 'u966150428';

function token() {
  if (!process.env.HOSTINGER_API_TOKEN) throw new Error('HOSTINGER_API_TOKEN manquant');
  return process.env.HOSTINGER_API_TOKEN;
}

export async function call(method, path, body) {
  const response = await fetch(`${api}${path}`, {
    method, body: body && JSON.stringify(body),
    headers: { Authorization: `Bearer ${token()}`, Accept: 'application/json', 'Content-Type': 'application/json' },
  });
  if (!response.ok) throw new Error(`Hostinger ${method} ${path} : HTTP ${response.status} ${await response.text()}`);
  return response.json();
}

// Envoie un fichier dans public_html du site (protocole TUS), en écrasant l'existant.
export async function upload(domain, relativePath, bytes) {
  const { url, auth_key, rest_auth_key } = await call('POST', '/files/upload-urls', { username, domain });
  const target = `${url.replace(/\/$/, '')}/${relativePath}?override=true`;
  const tus = { 'X-Auth': auth_key, 'X-Auth-Rest': rest_auth_key, 'Tus-Resumable': '1.0.0' };
  const created = await fetch(target, { method: 'POST', headers: { ...tus, 'Upload-Length': String(bytes.length), 'Upload-Offset': '0' } });
  if (created.status !== 201) throw new Error(`Upload ${relativePath} (création) : HTTP ${created.status} ${await created.text()}`);
  const sent = await fetch(target, { method: 'PATCH', body: bytes, headers: { ...tus, 'Content-Type': 'application/offset+octet-stream', 'Upload-Offset': '0' } });
  if (sent.status !== 204 || sent.headers.get('upload-offset') !== String(bytes.length)) throw new Error(`Upload ${relativePath} (envoi) : HTTP ${sent.status} ${await sent.text()}`);
}
