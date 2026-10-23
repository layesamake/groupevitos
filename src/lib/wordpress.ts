// Contenus lus dans WordPress (admin.groupevitos.com) au moment du build.
// WP_REQUIRED=true (CI) : un WordPress injoignable fait échouer le build, le site en ligne reste intact.
// Sinon (local, ou tant que WordPress n'est pas installé) : le site est construit avec un contenu de repli.
const API = process.env.WP_API_URL ?? 'https://admin.groupevitos.com/wp-json';
const required = process.env.WP_REQUIRED === 'true';

export interface Entry {
  id: number; slug: string; link: string; date: string; parent?: number; menu_order?: number;
  title: { rendered: string }; content: { rendered: string }; excerpt: { rendered: string };
  _embedded?: { 'wp:featuredmedia'?: { source_url?: string; alt_text?: string }[] };
}
export interface Formation extends Entry {
  meta: Record<'niveau' | 'duree' | 'parcours' | 'admission' | 'finalite' | 'enseignements' | 'competences' | 'methodes' | 'debouches' | 'carriere', string>;
}
export interface Content {
  available: boolean; site: { name: string; description: string };
  pages: Entry[]; posts: Entry[]; formations: Formation[]; media: Record<string, string>;
}

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
    const [root, pages, posts, formations, media] = await Promise.all([
      get('/'), all('pages'), all('posts'), all<Formation>('formations'), all<{ source_url: string }>('media', '_fields=source_url'),
    ]);
    return {
      available: true, site: { name: root.data.name, description: root.data.description }, pages, posts,
      formations: formations.sort((a, b) => (a.menu_order ?? 0) - (b.menu_order ?? 0)),
      media: Object.fromEntries(media.map(m => [m.source_url.split('/').pop()!, m.source_url])),
    };
  } catch (error) {
    if (required) throw error;
    console.warn(`[wordpress] contenu de repli utilisé : ${(error as Error).message}`);
    return { available: false, site: { name: 'Groupe Scolaire VITO S.', description: '' }, pages: [], posts: [], formations: [], media: {} };
  }
}

let pending: Promise<Content> | undefined;
export const getContent = () => pending ??= load();

// Chemin public d'une page WordPress (gère les pages enfants : /parent/enfant/).
export const pathOf = (entry: Entry) => new URL(entry.link).pathname;
