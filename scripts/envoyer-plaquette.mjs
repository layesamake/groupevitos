// Dépose la plaquette dans le dossier privé de admin.groupevitos.com (servie uniquement via un lien personnel).
// Usage : HOSTINGER_API_TOKEN=... node scripts/envoyer-plaquette.mjs docs/GroupeVITOS_Plaquette.pdf
import { readFile } from 'node:fs/promises';
import { upload } from './hostinger.mjs';

const source = process.argv[2];
if (!source) throw new Error('Indiquez le chemin du PDF.');
const domain = process.env.ADMIN_DOMAIN ?? 'admin.groupevitos.com';
await upload(domain, 'wp-content/uploads/groupevitos-prive/plaquette.pdf', await readFile(source));
console.log('plaquette déposée : wp-content/uploads/groupevitos-prive/plaquette.pdf');
