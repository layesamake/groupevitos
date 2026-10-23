// Contenus lus dans WordPress (admin.groupevitos.com) au moment du build.
// WP_REQUIRED=true (CI) : un WordPress injoignable fait échouer le build, le site en ligne reste intact.
// Sinon (local, ou tant que WordPress n'est pas installé) : le site est construit avec un contenu de repli.
const API = process.env.WP_API_URL ?? 'https://admin.groupevitos.com/wp-json';
const required = process.env.WP_REQUIRED === 'true';

export interface Media {
  source_url: string; alt_text?: string;
  media_details?: { width?: number; height?: number; sizes?: Record<string, { source_url: string; width: number; height: number }> };
}
export interface Entry {
  id: number; slug: string; link: string; date: string; parent?: number; menu_order?: number;
  title: { rendered: string }; content: { rendered: string }; excerpt: { rendered: string };
  _embedded?: { 'wp:featuredmedia'?: Media[]; 'wp:term'?: { id: number; slug: string; name: string; taxonomy: string }[][] };
}
export interface Formation extends Entry {
  meta: Record<'reference' | 'sigle' | 'diplomes' | 'variantes' | 'validation' | 'niveau' | 'duree' | 'parcours' | 'admission' | 'finalite' | 'enseignements' | 'competences' | 'methodes' | 'debouches' | 'carriere', string>;
}
export type Pole = 'universite' | 'lycee' | 'itp';
export interface Tarif extends Entry {
  meta: { pole: Pole | 'secondaire'; periode: string; statut: 'en_vigueur' | 'a_actualiser'; type: 'scolarite' | 'complement'; inscription: number; scolarite: number; total: number; decembre: number; mars: number; note: string };
}
export interface Dossier extends Entry { meta: { pole: Pole; annee: string; pieces: string; note: string } }
export interface Coordonnee extends Entry { meta: { type: 'telephone' | 'email' | 'reseau' | 'adresse' | 'paiement'; valeur: string; poles: string; usage: string; note: string } }
export interface Partenaire extends Entry { meta: { sigle: string; note: string } }
export interface Identite { marque: string; etablissement: string; sigle: string; devise: string; signatures: string; reconnaissance: string; adresse: string; reperes: string; presentation: string }
export interface Content {
  available: boolean; site: { name: string; description: string };
  pages: Entry[]; posts: Entry[]; formations: Formation[];
  tarifs: Tarif[]; dossiers: Dossier[]; coordonnees: Coordonnee[]; partenaires: Partenaire[]; identite: Identite;
  media: Record<string, Media>; // par nom de fichier
}

const FALLBACK_IDENTITE: Identite = { marque: 'Groupe VITO S.', etablissement: 'Institut VITO S.', sigle: 'IVS', devise: 'Ensemble, bâtissons les talents de demain !', signatures: '', reconnaissance: '', adresse: '', reperes: '', presentation: '' };

async function get(path: string) {
  const response = await fetch(`${API}${path}`, { signal: AbortSignal.timeout(30000) });
  if (!response.ok) throw new Error(`WordPress ${path} : HTTP ${response.status}`);
  if (!response.headers.get('content-type')?.includes('json')) throw new Error(`WordPress ${path} : réponse non JSON (WordPress installé ?)`);
  return { data: await response.json(), pages: Number(response.headers.get('x-wp-totalpages') ?? 1) };
}

async function all<T = Entry>(type: string, query = '_embed=wp:featuredmedia'): Promise<T[]> {
  const entries: T[] = [];
  for (let page = 1, total = 1; page <= total; page++) {
    const result = await get(`/wp/v2/${type}?per_page=100&page=${page}&${query}`);
    entries.push(...result.data);
    total = result.pages;
  }
  return entries;
}

async function load(): Promise<Content> {
  try {
    const [root, pages, posts, formations, tarifs, dossiers, coordonnees, partenaires, identite, media] = await Promise.all([
      get('/'), all('pages'), all('posts'), all<Formation>('formations', '_embed=wp:featuredmedia,wp:term'), all<Tarif>('tarifs'), all<Dossier>('dossiers'), all<Coordonnee>('coordonnees'), all<Partenaire>('partenaires'),
      get('/groupevitos/v1/identite'), all<Media>('media', '_fields=source_url,alt_text,media_details'),
    ]);
    const byOrder = <T extends Entry>(list: T[]) => list.sort((a, b) => (a.menu_order ?? 0) - (b.menu_order ?? 0));
    return {
      available: true, site: { name: root.data.name, description: root.data.description }, pages, posts,
      formations: byOrder(formations), tarifs: byOrder(tarifs), dossiers: byOrder(dossiers), coordonnees: byOrder(coordonnees), partenaires: byOrder(partenaires), identite: identite.data,
      media: Object.fromEntries(media.map(m => [m.source_url.split('/').pop()!, m])),
    };
  } catch (error) {
    if (required) throw error;
    console.warn(`[wordpress] contenu de repli utilisé : ${(error as Error).message}`);
    return { available: false, site: { name: 'Groupe VITO S.', description: '' }, pages: [], posts: [], formations: [], tarifs: [], dossiers: [], coordonnees: [], partenaires: [], identite: FALLBACK_IDENTITE, media: {} };
  }
}

let pending: Promise<Content> | undefined;
export const getContent = () => pending ??= load();

// Chemin public d'une page WordPress (gère les pages enfants : /parent/enfant/).
export const pathOf = (entry: Entry) => new URL(entry.link).pathname;
export const pageBySlug = (pages: Entry[], slug: string) => pages.find(page => page.slug === slug);

// Famille d'une formation (filtre du catalogue), tirée des termes intégrés à la réponse.
export const familleOf = (formation: Formation) => formation._embedded?.['wp:term']?.flat().find(term => term.taxonomy === 'famille_formation');
export const familiesOf = (formations: Formation[]) => {
  const seen = new Map<string, { slug: string; name: string }>();
  for (const formation of formations) { const f = familleOf(formation); if (f && !seen.has(f.slug)) seen.set(f.slug, { slug: f.slug, name: f.name }); }
  return [...seen.values()];
};
