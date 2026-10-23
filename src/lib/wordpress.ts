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
export interface Content { available: boolean; site: { name: string; description: string }; pages: Entry[]; posts: Entry[] }

async function get(path: string) {
  const response = await fetch(`${API}${path}`, { signal: AbortSignal.timeout(30000) });
  if (!response.ok) throw new Error(`WordPress ${path} : HTTP ${response.status}`);
  if (!response.headers.get('content-type')?.includes('json')) throw new Error(`WordPress ${path} : réponse non JSON (WordPress installé ?)`);
  return { data: await response.json(), pages: Number(response.headers.get('x-wp-totalpages') ?? 1) };
}

async function all(type: string): Promise<Entry[]> {
  const entries: Entry[] = [];
  for (let page = 1, total = 1; page <= total; page++) {
    const result = await get(`/wp/v2/${type}?per_page=100&page=${page}&_embed=wp:featuredmedia`);
    entries.push(...result.data);
    total = result.pages;
  }
  return entries;
}

async function load(): Promise<Content> {
  try {
    const [root, pages, posts] = await Promise.all([get('/'), all('pages'), all('posts')]);
    return { available: true, site: { name: root.data.name, description: root.data.description }, pages, posts };
  } catch (error) {
    if (required) throw error;
    console.warn(`[wordpress] contenu de repli utilisé : ${(error as Error).message}`);
    return { available: false, site: { name: 'Groupe Vitos', description: '' }, pages: [], posts: [] };
  }
}

let pending: Promise<Content> | undefined;
export const getContent = () => pending ??= load();

// Chemin public d'une page WordPress (gère les pages enfants : /parent/enfant/).
export const pathOf = (entry: Entry) => new URL(entry.link).pathname;
