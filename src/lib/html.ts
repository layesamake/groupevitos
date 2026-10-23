import type { Entry, Media } from './wordpress';

const ADMIN = 'https://admin.groupevitos.com';

// Les liens internes saisis dans WordPress pointent vers admin.groupevitos.com : on les ramène sur le site public,
// sauf les médias (wp-content) qui restent servis par WordPress.
export const publicLinks = (html: string) =>
  html.replace(new RegExp(`${ADMIN}(?!/wp-content/)`, 'g'), '');

export const plain = (html: string) =>
  html.replace(/<[^>]+>/g, '').replace(/&#8217;/g, '’').replace(/&#8211;/g, '–').replace(/&#8230;/g, '…').replace(/&nbsp;|&#160;/g, ' ')
    .replace(/&#038;|&amp;/g, '&').replace(/&#8220;|&#8221;/g, '"').replace(/\s+/g, ' ').trim();

export const formatDate = (date: string) =>
  new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(date));

export interface Img { src: string; srcset?: string; width?: number; height?: number; alt: string }

// Image responsive à partir d'un média WordPress : les tailles générées par WordPress alimentent srcset.
export function image(media: Media | undefined, alt?: string): Img | undefined {
  if (!media?.source_url) return undefined;
  const details = media.media_details ?? {};
  const sizes = Object.values(details.sizes ?? {}).filter(size => size.width >= 300);
  const widths = new Map(sizes.map(size => [size.width, size.source_url]));
  if (details.width) widths.set(details.width, media.source_url);
  return {
    src: media.source_url, width: details.width, height: details.height, alt: alt ?? media.alt_text ?? '',
    srcset: widths.size > 1 ? [...widths].sort((a, b) => a[0] - b[0]).map(([width, url]) => `${url} ${width}w`).join(', ') : undefined,
  };
}

export const featured = (entry: Entry, alt?: string) => image(entry._embedded?.['wp:featuredmedia']?.[0], alt);
