// Lecture du référentiel (tarifs, dossiers, coordonnées) selon le pôle.
import type { Coordonnee, Dossier, Pole, Tarif } from './wordpress';

export const POLES: { slug: Pole; name: string; short: string; path: string; lead: string }[] = [
  { slug: 'universite', name: 'Université VITO S.', short: 'Université', path: '/universite/', lead: 'DUT, Licence et Master · catalogue de 17 formations' },
  { slug: 'lycee', name: 'Lycée VITO S.', short: 'Lycée', path: '/lycee/', lead: '10e, 11e et 12e années · toutes séries' },
  { slug: 'itp', name: 'ITP VITO S.', short: 'ITP', path: '/itp/', lead: 'Institut Technique et Professionnel · BT-AD et BT-ID' },
];

export const fcfa = (amount: number) => `${new Intl.NumberFormat('fr-FR').format(amount).replace(/[  ]/g, ' ')} F CFA`;
export const telHref = (value: string) => `tel:${value.replace(/[^\d+]/g, '')}`;

const forPoles = (poles: string, pole: Pole) => poles.split(',').map(item => item.trim()).some(item => item === 'tous' || item === pole);
export const contactsFor = (coordonnees: Coordonnee[], pole: Pole) => coordonnees.filter(c => c.meta.type === 'telephone' && forPoles(c.meta.poles, pole));
export const ofType = (coordonnees: Coordonnee[], type: Coordonnee['meta']['type']) => coordonnees.filter(c => c.meta.type === type);

// Tarifs d'un pôle : les frais « secondaire » (tenues, carte scolaire…) concernent le Lycée et l'ITP.
export const tarifsFor = (tarifs: Tarif[], pole: Pole) => {
  const mine = tarifs.filter(t => t.meta.pole === pole || (t.meta.pole === 'secondaire' && pole !== 'universite'));
  return { scolarite: mine.filter(t => t.meta.type === 'scolarite'), complements: mine.filter(t => t.meta.type === 'complement') };
};
export const dossierFor = (dossiers: Dossier[], pole: Pole) => dossiers.find(d => d.meta.pole === pole);
export const identityLines = (text: string) => text.split('\n').map(line => line.trim()).filter(Boolean);
