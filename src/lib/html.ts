const ADMIN = 'https://admin.groupevitos.com';

// Les liens internes saisis dans WordPress pointent vers admin.groupevitos.com : on les ramène sur le site public,
// sauf les médias (wp-content) qui restent servis par WordPress.
export const publicLinks = (html: string) =>
  html.replace(new RegExp(`${ADMIN}(?!/wp-content/)`, 'g'), '');

export const plain = (html: string) =>
  html.replace(/<[^>]+>/g, '').replace(/&#8217;/g, '’').replace(/&#8211;/g, '–').replace(/&nbsp;|&#160;/g, ' ').replace(/&amp;/g, '&').replace(/\s+/g, ' ').trim();

export const featured = (entry: { _embedded?: { 'wp:featuredmedia'?: { source_url?: string; alt_text?: string }[] } }) =>
  entry._embedded?.['wp:featuredmedia']?.[0];

export const formatDate = (date: string) =>
  new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(date));
