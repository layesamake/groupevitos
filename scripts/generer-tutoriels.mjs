// Génère le contenu du module « Tutoriels » du back-office à partir de docs/tutoriels/*.md et docs/captures/*.png.
// Sortie : wordpress/mu-plugins/groupevitos-bo-tutoriels/ (fragments HTML, index.json, img/*.png).
// Usage : node scripts/generer-tutoriels.mjs
import { readdir, readFile, writeFile, mkdir, rm } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { unified } from 'unified';
import remarkParse from 'remark-parse';
import remarkGfm from 'remark-gfm';
import remarkRehype from 'remark-rehype';
import rehypeStringify from 'rehype-stringify';
import sharp from 'sharp';

const source = new URL('../docs/tutoriels/', import.meta.url);
const captures = new URL('../docs/captures/', import.meta.url);
const sortie = new URL('../wordpress/mu-plugins/groupevitos-bo-tutoriels/', import.meta.url);

// Public visé et résumé de chaque tutoriel (l'ordre est celui de l'affichage)
const meta = {
  '01-direction': { profil: 'gv_direction', public: 'Direction', resume: 'Gérer les comptes, régler l’outil, décider des admissions et piloter l’activité.', minutes: 20 },
  '02-scolarite': { profil: 'gv_scolarite', public: 'Scolarité', resume: 'Admissions, inscriptions, étudiants, emploi du temps, appel et absences.', minutes: 20 },
  '03-comptabilite': { profil: 'gv_comptabilite', public: 'Comptabilité', resume: 'Paiements, reçus, échéanciers, impayés et exports.', minutes: 15 },
  '04-responsable-de-pole': { profil: 'gv_responsable_pole', public: 'Responsable de pôle', resume: 'Décisions d’admission, cours, enseignants et emploi du temps de votre pôle.', minutes: 20 },
  '05-communication': { profil: 'gv_communication', public: 'Chargé de communication', resume: 'Facebook, annonces, plaquette et actualités du site.', minutes: 15 },
  '06-famille-etudiant': { profil: '', public: 'Espace familles et étudiants', resume: 'Ce que voient les familles et les étudiants : emploi du temps, présences, finances, annonces.', minutes: 10 },
  '07-enseignant': { profil: '', public: 'Espace enseignants', resume: 'Ce que voient les enseignants : séances, appel, heures, annonces.', minutes: 8 },
};

const slugifier = texte => texte.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
const echapper = texte => texte.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

await rm(sortie, { recursive: true, force: true });
await mkdir(new URL('img/', sortie), { recursive: true });

const images = new Set();
const index = [];
for (const fichier of (await readdir(source)).filter(f => f.endsWith('.md')).sort()) {
  const cle = fichier.replace(/\.md$/, '');
  const infos = meta[cle];
  if (!infos) throw new Error(`Métadonnées manquantes pour ${cle}`);
  let texte = await readFile(new URL(fichier, source), 'utf8');
  const titre = /^# (.+)$/m.exec(texte)[1].replace(/^Tutoriel — /, '');
  texte = texte.replace(/^# .*\n/m, '').replace(/^\*Pour qui.*\n/m, '');

  let html = String(await unified().use(remarkParse).use(remarkGfm).use(remarkRehype).use(rehypeStringify).process(texte));
  const sections = [];
  html = html.replace(/<h2>(.*?)<\/h2>/g, (_, contenu) => {
    const id = slugifier(contenu.replace(/<[^>]+>/g, ''));
    sections.push({ id, titre: contenu.replace(/<[^>]+>/g, '') });
    return `<h2 id="${id}">${contenu}</h2>`;
  });
  html = html.replace(/<h3>(.*?)<\/h3>/g, (_, contenu) => `<h3 id="${slugifier(contenu.replace(/<[^>]+>/g, ''))}">${contenu}</h3>`);
  html = html.replace(/<p><img src="captures\/([^"]+)" alt="([^"]*)"><\/p>/g, (_, nom, legende) => {
    images.add(nom);
    return `<figure class="gvt-fig"><a href="{{img:${nom}}}" class="gvt-zoom" target="_blank" rel="noopener"><img src="{{img:${nom}}}" alt="${legende}" loading="lazy"></a><figcaption>${legende}</figcaption></figure>`;
  });
  html = html.replace(/<table>/g, '<div class="gvt-tableau"><table>').replace(/<\/table>/g, '</table></div>').replace(/<input type="checkbox" disabled(?: checked)?>/g, '<span class="gvt-case" aria-hidden="true"></span>');
  if (/captures\//.test(html.replace(/\{\{img:[^}]+\}\}/g, ''))) throw new Error(`Image non convertie dans ${cle}`);

  await writeFile(new URL(`${cle}.html`, sortie), html);
  index.push({ slug: cle.replace(/^\d+-/, ''), fichier: cle, titre, ...infos, sections });
}

for (const nom of images) {
  const png = await sharp(fileURLToPath(new URL(nom, captures))).png({ palette: true, quality: 90, compressionLevel: 9 }).toBuffer();
  await writeFile(new URL(`img/${nom}`, sortie), png);
}
await writeFile(new URL('index.json', sortie), JSON.stringify(index, null, 1));
console.log(`${index.length} tutoriels, ${images.size} images`);
