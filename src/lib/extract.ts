// Lecture structurée du HTML saisi dans WordPress, pour le mettre en forme « Escalier »
// (listes en marches, tableaux en cartes, étapes numérotées). Chaque fonction renvoie [] si la structure attendue
// n'est pas trouvée : la page retombe alors sur l'affichage simple du contenu.
import { plain } from './html';

const cells = (row: string) => [...row.matchAll(/<t[dh][^>]*>([\s\S]*?)<\/t[dh]>/g)].map(match => match[1].trim());

// Lignes d'un tableau, sans la ligne d'en-tête (thead, ou première ligne en gras).
export function tableRows(html: string): string[][] {
  const table = html.match(/<table[\s\S]*?<\/table>/)?.[0];
  if (!table) return [];
  const rows = [...table.replace(/<thead[\s\S]*?<\/thead>/, '').matchAll(/<tr[^>]*>([\s\S]*?)<\/tr>/g)].map(match => cells(match[1]));
  const header = !/<thead/.test(table) && rows[0]?.every(cell => /^<strong>[\s\S]*<\/strong>$/.test(cell));
  return (header ? rows.slice(1) : rows).filter(row => row.length);
}

const capitalize = (text: string) => text.charAt(0).toUpperCase() + text.slice(1);

// Éléments de liste « <strong>Titre</strong>, suite » → { title, text }.
export function listItems(html: string) {
  return [...html.matchAll(/<li[^>]*>([\s\S]*?)<\/li>/g)].map(match => {
    const strong = match[1].match(/^\s*<strong>([\s\S]*?)<\/strong>([\s\S]*)$/);
    return strong
      ? { title: plain(strong[1]), text: capitalize(plain(strong[2]).replace(/^[,:;–-]\s*/, '').replace(/\s*;$/, '')) }
      : { title: '', text: plain(match[1]) };
  });
}

// Étapes « <h3>1. Titre</h3><p>Description</p> ».
export function steps(html: string) {
  return [...html.matchAll(/<h3[^>]*>\s*(\d+)\.\s*([\s\S]*?)<\/h3>\s*(?:<p[^>]*>([\s\S]*?)<\/p>)?/g)]
    .map(match => ({ number: match[1], title: plain(match[2]), text: plain(match[3] ?? '') }));
}

// Section du contenu qui suit un titre <h2> donné, jusqu'au <h2> suivant.
export function section(html: string, heading: RegExp) {
  const parts = html.split(/(?=<h2[^>]*>)/);
  return parts.find(part => heading.test(plain(part.match(/<h2[^>]*>([\s\S]*?)<\/h2>/)?.[1] ?? ''))) ?? '';
}

export function figures(html: string) {
  return [...html.matchAll(/<img[^>]*src="([^"]+)"[^>]*>(?:[\s\S]*?<figcaption[^>]*>([\s\S]*?)<\/figcaption>)?/g)]
    .map(match => ({ src: match[1], alt: plain(match[0].match(/alt="([^"]*)"/)?.[1] ?? ''), caption: plain(match[2] ?? '') }));
}

export const links = (html: string) =>
  [...html.matchAll(/<li[^>]*>([\s\S]*?)<a[^>]*href="([^"]+)"[^>]*>([\s\S]*?)<\/a>/g)]
    .map(match => ({ label: plain(match[1]).replace(/\s*:\s*$/, ''), href: match[2], text: plain(match[3]) }));

// Découpe un slogan en marches pour le titre en escalier : un mot par ligne, les mots courts (« les », « de »)
// restant attachés au mot suivant : « Bâtissons / ensemble / les cadres / de demain ».
export function stairLines(text: string) {
  const lines: string[] = [];
  let carry = '';
  for (const word of text.trim().split(/\s+/)) {
    if (word.length <= 3) carry += `${word} `;
    else { lines.push(carry + word); carry = ''; }
  }
  if (carry) lines.length ? lines[lines.length - 1] += ` ${carry.trim()}` : lines.push(carry.trim());
  return lines;
}
