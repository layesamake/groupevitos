// Installe les mu-plugins de wordpress/mu-plugins/ sur admin.groupevitos.com (wp-content/mu-plugins/).
// Usage : HOSTINGER_API_TOKEN=... node scripts/installer-mu-plugins.mjs
import { readdir, readFile } from 'node:fs/promises';
import { upload } from './hostinger.mjs';

const domain = process.env.ADMIN_DOMAIN ?? 'admin.groupevitos.com';
const dir = new URL('../wordpress/mu-plugins/', import.meta.url);

for (const name of (await readdir(dir)).filter(file => file.endsWith('.php'))) {
  await upload(domain, `wp-content/mu-plugins/${name}`, await readFile(new URL(name, dir)));
  console.log(`installé : wp-content/mu-plugins/${name}`);
}

// Le type « formations » n'apparaît dans l'API que si les mu-plugins sont chargés.
const response = await fetch(`https://${domain}/wp-json/wp/v2/types/formation`);
console.log(response.ok ? 'Vérification : type « formation » présent dans l’API REST' : `Vérification : type « formation » absent (HTTP ${response.status})`);
