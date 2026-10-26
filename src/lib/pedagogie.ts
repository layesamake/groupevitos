import type { Formation } from './wordpress';
import { pedagogies } from '../data/pedagogie-formations.ts';

// Les marqueurs WordPress et les paragraphes vides ne constituent pas un contenu.
const hasText = (value: string = '') => Boolean(value
  .replace(/<!--[\s\S]*?-->/g, '')
  .replace(/<[^>]*>/g, '')
  .replace(/&nbsp;|&#160;|&#xA0;/gi, ' ')
  .trim());
const escape = (value: string) => value.replace(/[&<>"']/g, char => ({
  '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
}[char]!));
const paragraph = (value: string) => `<p>${escape(value)}</p>`;

/** Complète uniquement les rubriques vides, sans modifier les données WordPress. */
export function completePedagogy(original: Formation) {
  const pedagogie = pedagogies[original.slug];
  const supplemented: string[] = [];
  if (!pedagogie) return { formation: original, pedagogie, supplemented };

  const formation = { ...original, content: { ...original.content }, meta: { ...original.meta } };
  if (!hasText(formation.content.rendered)) {
    formation.content.rendered = paragraph(pedagogie.objectif);
    supplemented.push('objectifs');
  }
  const defaults = {
    competences: `<ul>${pedagogie.competences.map(item => `<li>${escape(item)}</li>`).join('')}</ul>`,
    methodes: paragraph(`Exemple de mise en pratique proposé : ${pedagogie.projet}`),
    debouches: paragraph(`Exemples de fonctions à explorer selon le diplôme, l’expérience et les conditions d’accès au métier : ${pedagogie.debouches}`),
  };
  for (const key of Object.keys(defaults) as (keyof typeof defaults)[]) {
    if (!hasText(formation.meta[key])) {
      formation.meta[key] = defaults[key];
      supplemented.push(key);
    }
  }
  // La présentation par cycle est rendue par le composant, sans HTML injecté.
  if (!hasText(formation.meta.enseignements)) supplemented.push('enseignements');
  return { formation, pedagogie, supplemented };
}
