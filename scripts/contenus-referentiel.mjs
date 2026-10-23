// Données du référentiel (docs/GroupeVITOS_donnees_structurees_site.md, mise à jour du 23 octobre 2026).
// Les contenus « publish » sont affichés sur le site ; « draft » et « private » restent dans l'administration (données à valider, anciennes coordonnées, promotions).

export const reglages = { title: 'Groupe VITO S.', description: 'Ensemble, bâtissons les talents de demain' };

export const identite = {
  marque: 'Groupe VITO S.', etablissement: 'Institut VITO S.', sigle: 'IVS',
  devise: 'Ensemble, bâtissons les talents de demain !',
  signatures: 'Travail – Réussite – Succès\nVITO S. pour tous, tous pour VITO S.',
  reconnaissance: 'Établissement privé d’enseignement supérieur agréé par l’État (arrêté n° 09-1834/MESRS-SG).',
  adresse: 'Kalaban Coura, rue 30, immeuble VITO S., Bamako, Mali',
  reperes: '2009|Création de l’Institut, arrêté n° 09-1834/MESRS-SG du 23 juillet 2009\n2012|Structuration de l’offre de formation\n2013|Schéma directeur de l’offre\n|Ouverture d’une annexe à Sikasso',
  presentation: 'Le Groupe VITO S. accompagne les apprenants dans l’enseignement secondaire, la formation technique et professionnelle et l’enseignement supérieur. À Bamako, l’Institut VITO S. développe une pédagogie associant connaissances, ateliers pratiques et préparation au monde professionnel. Son offre universitaire présente des parcours en gestion, finance, droit, marketing, logistique, communication, numérique et développement.',
};

export const familles = [
  { slug: 'gestion-finance', name: 'Gestion et finance' },
  { slug: 'management-marketing', name: 'Management et marketing' },
  { slug: 'droit-relations-internationales', name: 'Droit et relations internationales' },
  { slug: 'commerce-logistique', name: 'Commerce et logistique' },
  { slug: 'communication-numerique', name: 'Communication et numérique' },
  { slug: 'developpement-secteurs-specialises', name: 'Développement et secteurs spécialisés' },
];

// Catalogue S09 : 17 intitulés, ordre conservé. `slug` explicite pour les fiches déjà publiées.
export const formations = [
  { ref: '01', title: 'Gestion de projets et de développement durable', famille: 'developpement-secteurs-specialises', variantes: 'Gestion de projet de développement durable (S03)' },
  { ref: '02', title: 'Droit des affaires et fiscalité', famille: 'droit-relations-internationales', slug: 'droit-des-affaires-et-fiscalite', variantes: 'Droit des affaires, affaires internationales et fiscalités (S08, S12) : même cursus à vérifier' },
  { ref: '03', title: 'Audit et contrôle de gestion', famille: 'gestion-finance', variantes: 'Comptabilité, contrôle et audit (S03) : équivalence à établir' },
  { ref: '04', title: 'Banque, finance et microfinance', famille: 'gestion-finance', slug: 'banque-finance-microfinance' },
  { ref: '05', title: 'Marketing et stratégie d’entreprise', famille: 'management-marketing', variantes: 'Marketing et stratégie (S03) : à confirmer' },
  { ref: '06', title: 'Marketing et management', famille: 'management-marketing' },
  { ref: '07', title: 'Informatique de gestion', famille: 'communication-numerique', variantes: 'Gestion en information (S08, S12) : libellé à clarifier' },
  { ref: '08', title: 'Commerce international', famille: 'commerce-logistique', variantes: 'Ancien regroupement « Commerce international, transport logistique »' },
  { ref: '09', title: 'Logistique et transport', famille: 'commerce-logistique', slug: 'licence-en-transport-et-logistique', variantes: 'Ancien regroupement « Commerce international, transport logistique »' },
  { ref: '10', title: 'Finance et comptabilité', famille: 'gestion-finance' },
  { ref: '11', title: 'Communication et journalisme', famille: 'communication-numerique' },
  { ref: '12', title: 'Management des services de santé et de pharmacie', famille: 'developpement-secteurs-specialises', sigle: 'MSS' },
  { ref: '13', title: 'Gestion des ressources humaines', famille: 'management-marketing', slug: 'gestion-des-ressources-humaines' },
  { ref: '14', title: 'Gestion du développement durable et des énergies renouvelables', famille: 'developpement-secteurs-specialises' },
  { ref: '15', title: 'Droit public et relations internationales', famille: 'droit-relations-internationales', sigle: 'DPR' },
  { ref: '16', title: 'Management de projets et développement local', famille: 'developpement-secteurs-specialises', sigle: 'MPD' },
  { ref: '17', title: 'Banque et finance', famille: 'gestion-finance' },
];

// Intitulés relevés sur d'anciens supports, ouverture non confirmée : conservés en brouillon, non publiés.
export const formationsAConfirmer = [
  { title: 'Mines et géologie', famille: 'developpement-secteurs-specialises', note: 'S03, S05, S06 : absent du catalogue S09 ; confirmer l’ouverture actuelle.' },
  { title: 'Agro-business', famille: 'developpement-secteurs-specialises', note: 'S03, S05, S06 : libellé officiel (Agrobusiness) et ouverture à confirmer.' },
  { title: 'Gestion en information', famille: 'communication-numerique', note: 'S08, S12 : libellé à clarifier ; ne pas remplacer par Informatique de gestion sans confirmation.' },
  { title: 'Comptabilité, contrôle et audit', famille: 'gestion-finance', note: 'S03 : à distinguer de Audit et contrôle de gestion tant que l’équivalence n’est pas établie.' },
];

const univ = (title, inscription, scolarite, ordre) => ({ title, pole: 'universite', statut: 'a_actualiser', type: 'scolarite', inscription, scolarite, total: inscription + scolarite, ordre });
const sec = (title, pole, total, decembre, mars, ordre) => ({ title, pole, periode: '2026–2027', statut: 'en_vigueur', type: 'scolarite', inscription: 25000, total, decembre, mars, ordre });
const comp = (title, pole, total, ordre, extra = {}) => ({ title, pole, type: 'complement', total, ordre, ...extra });

export const tarifs = [
  univ('Licence 1', 60000, 440000, 1), univ('Licence 2', 60000, 500000, 2), univ('Licence 3', 60000, 600000, 3), univ('Master 1', 100000, 650000, 4), univ('Master 2', 200000, 900000, 5),
  comp('Tenue : une veste, une chemise et un polo', 'universite', 50000, 6, { statut: 'a_actualiser' }),
  sec('Lycée — 10e et 11e années', 'lycee', 175000, 100000, 50000, 1), sec('Lycée — 12e année', 'lycee', 200000, 100000, 75000, 2),
  sec('ITP BT-AD — 1re et 2e années', 'itp', 175000, 100000, 50000, 3), sec('ITP BT-AD — 3e et 4e années', 'itp', 200000, 100000, 75000, 4),
  sec('ITP BT-ID — 1re et 2e années', 'itp', 200000, 100000, 75000, 5), sec('ITP BT-ID — 3e et 4e années', 'itp', 250000, 112500, 112500, 6),
  comp('Tenue fille', 'secondaire', 13000, 7, { periode: '2026–2027', statut: 'en_vigueur', note: 'Échéance non précisée sur le support' }),
  comp('Tenue garçon', 'secondaire', 12000, 8, { periode: '2026–2027', statut: 'en_vigueur', note: 'Échéance non précisée sur le support' }),
  comp('Carte scolaire', 'secondaire', 1000, 9, { periode: '2026–2027', statut: 'en_vigueur', note: 'Échéance non précisée sur le support' }),
  comp('Masse de garantie', 'secondaire', 2000, 10, { periode: '2026–2027', statut: 'en_vigueur', note: 'Fonctionnement non précisé sur le support' }),
];

const li = items => `<ul>${items.map(i => `<li>${i}</li>`).join('')}</ul>`;
const piecesSecondaire = li(['Acte de naissance', 'Attestation légalisée du DEF', 'Photocopie de la pièce d’identité du parent', 'Carte scolaire de la 9e année ou photo d’identité', 'Bulletin pour les anciens élèves du lycée', 'Certificat de résidence de l’élève']);

export const dossiers = [
  { title: 'Dossier d’admission — Université VITO S.', pole: 'universite', annee: 'Rentrée en cours', ordre: 1,
    pieces: li(['Deux photos d’identité récentes', 'Une copie légalisée de l’extrait d’acte de naissance', 'Une copie légalisée de l’attestation du baccalauréat pour la Licence 1',
      'Des copies légalisées de l’attestation de Licence pour une inscription en Master (la liste exacte des relevés et justificatifs est précisée par le service admission)', 'Une fiche d’engagement à retirer à la direction', 'Une demande manuscrite adressée au Directeur général']),
    note: '<p>Les conditions d’accès au DUT, aux années supérieures de Licence et aux différentes années de Master ne sont pas détaillées ici : contactez le service admission.</p>' },
  { title: 'Dossier d’admission — Lycée VITO S.', pole: 'lycee', annee: 'Rentrée 2026–2027', ordre: 2, pieces: piecesSecondaire, note: '' },
  { title: 'Dossier d’admission — ITP VITO S.', pole: 'itp', annee: 'Rentrée 2026–2027', ordre: 3, pieces: piecesSecondaire, note: '' },
];

const tel = (valeur, usage, poles, note, status = 'publish') => ({ title: `${usage} ${valeur}`, type: 'telephone', valeur, usage, poles, note, status });
export const coordonnees = [
  tel('+223 82 06 82 06', 'Institut', 'universite', 'Supports récents S01, S02, S03, S09'),
  tel('+223 90 12 90 12', 'Institut', 'universite', 'Supports récents S01, S02, S03, S09'),
  tel('+223 76 40 10 10', 'Standard commun', 'tous', 'Supports S08, S09, S10, S12 ; affectation par pôle à confirmer'),
  tel('+223 76 42 10 48', 'Lycée et ITP', 'lycee,itp', 'S10 ; rôle de chaque numéro non précisé'),
  tel('+223 71 67 24 44', 'Lycée et ITP', 'lycee,itp', 'S10 ; rôle de chaque numéro non précisé'),
  tel('+223 75 37 58 70', 'Lycée et ITP', 'lycee,itp', 'S10 ; rôle de chaque numéro non précisé'),
  tel('+223 76 26 18 30', 'Lycée et ITP', 'lycee,itp', 'S10 ; rôle de chaque numéro non précisé'),
  tel('+223 72 72 79 22', 'Annexe', 'universite', 'Ancien site, absent du référentiel du 23 octobre 2026 : à confirmer'),
  tel('+223 73 38 38 93', 'Annexe', 'universite', 'Ancien site, absent du référentiel du 23 octobre 2026 : à confirmer'),
  { title: 'E-mail du groupe', type: 'email', valeur: 'contact@groupevitos.com', usage: 'Courriel public', poles: 'tous', note: 'Boîte du domaine du projet. Courriel principal à valider avec la direction.', status: 'publish' },
  { title: 'E-mail imprimé sur les supports', type: 'email', valeur: 'contact@institutvitos.com', usage: 'Courriel imprimé (S02)', poles: 'tous', note: 'Validité de la boîte à confirmer avant publication.', status: 'draft' },
  { title: 'Adresse principale', type: 'adresse', valeur: 'Kalaban Coura, rue 30, immeuble VITO S., Bamako, Mali', usage: 'Siège', poles: 'tous', note: 'S02, S03, S09', status: 'publish' },
  { title: 'Adresse de la fiche secondaire', type: 'adresse', valeur: 'Carrefour Kalaban Coura / Daoudabougou, rue 30, Bamako', usage: 'Fiche Lycée et ITP (S10)', poles: 'lycee,itp', note: 'Formulation différente : à rapprocher de l’adresse principale.', status: 'draft' },
  { title: 'Facebook', type: 'reseau', valeur: 'https://www.facebook.com/institutvitosmali', usage: 'Facebook', poles: 'tous', note: 'Références communiquées pour le projet', status: 'publish' },
  { title: 'Instagram', type: 'reseau', valeur: 'https://www.instagram.com/institut.vitos/', usage: 'Instagram', poles: 'tous', note: 'Références communiquées pour le projet', status: 'publish' },
  { title: 'LinkedIn', type: 'reseau', valeur: 'https://www.linkedin.com/company/institut-vito-s/', usage: 'LinkedIn', poles: 'tous', note: 'Références communiquées pour le projet', status: 'publish' },
  { title: 'Orange Money', type: 'paiement', valeur: '+223 94 58 90 87', usage: 'Paiement des frais', poles: 'universite', note: 'Moyen de paiement, pas un contact admission. Bénéficiaire et validité à confirmer avant publication.', status: 'draft' },
  { title: 'Ancien téléphone', type: 'telephone', valeur: '+223 66 73 02 20', usage: 'Ancien', poles: 'tous', note: 'S08, S12 : ancienne coordonnée', status: 'private' },
  { title: 'Ancien courriel', type: 'email', valeur: 'info@groupevitos.ml', usage: 'Ancien', poles: 'tous', note: 'S08, S12 : ancienne coordonnée, domaine .ml', status: 'private' },
];

// `shown` : publié ; sinon brouillon (dénomination ou statut à confirmer). La présence d'un logo sur un support ne prouve pas une convention active.
export const partenaires = [
  { title: 'Holmes Institute Canada', logo: 'holmes.png', shown: true },
  { title: 'Université de Gestion et du Développement Durable', sigle: 'UniGDD', logo: 'uggd.jpg', shown: true },
  { title: 'ESMC Business School', logo: 'ESMC.png', shown: true },
  { title: 'CIESA Canada', shown: true, note: 'Dénomination complète à obtenir' },
  { title: 'IES Business School', shown: true, note: 'Campus de Vitré (S11)' },
  { title: 'Olga’s International Academy', shown: true },
  { title: 'Collège Boréal', shown: true },
  { title: 'Institut de Pédagogie Universitaire', sigle: 'IPU', logo: 'IPU.png', shown: false, note: 'Dénomination à confirmer sur le logo original' },
  { title: 'Faculté des Sciences Économiques et de Gestion', sigle: 'FSEG', logo: 'fseg.png', shown: false, note: 'Établissement et rattachement exacts à préciser' },
  { title: 'ESPRIM', shown: false, note: 'ESPRIM et ESPRIMS : deux graphies observées, équivalence non établie' },
  { title: 'ESPRIMS', shown: false, note: 'ESPRIM et ESPRIMS : deux graphies observées, équivalence non établie' },
];

// Registre interne (privé) : aucune promotion n'est appliquée aux barèmes ni affichée comme active.
export const promotions = [
  { title: 'Réduction de 33 %', taux: 33, periode: 'Jusqu’à fin septembre 2026', perimetre: 'Visuel S09', statut: 'expiree', source: 'S09' },
  { title: 'Réduction de 20 % — nouveaux inscrits 2024–2025', taux: 20, periode: 'Frais de scolarité des nouveaux inscrits 2024–2025', perimetre: 'Frais de scolarité', statut: 'rattachee', source: 'S05, S06' },
  { title: 'Réduction de 30 % — rentrée du 7 octobre 2024', taux: 30, periode: 'Rentrée du 7 octobre 2024', perimetre: 'Frais de formation', statut: 'historique', source: 'S03' },
  { title: 'Réduction de 30 % — « Allier voyage et études »', taux: 30, periode: 'Sans échéance visible', perimetre: 'Non précisé', statut: 'non_precisee', source: 'S01' },
];

const p = text => `<p>${text}</p>`;
export const pages = {
  'a-propos': { title: 'Le Groupe', content: [
    '<h2>Le Groupe VITO S.</h2>', p(identite.presentation),
    '<h3>Trois pôles</h3>', li(['<strong>Université VITO S.</strong> : DUT, Licence et Master.', '<strong>Lycée VITO S.</strong> : 10e, 11e et 12e années, toutes séries.', '<strong>ITP VITO S.</strong> : Institut Technique et Professionnel, parcours BT-AD et BT-ID.']),
    '<h3>Un établissement au cœur de Bamako</h3>',
    p('Créé en 2009 (arrêté n° 09-1834/MESRS-SG du 23 juillet 2009), l’Institut VITO S. (I.V.S.) est un établissement privé d’enseignement supérieur et de formation professionnelle, situé à <strong>Kalaban Coura</strong>, dans la commune V de <strong>Bamako, Mali</strong>.'),
    p('Fondé par <strong>M. Fousseynou TANDINA</strong> et baptisé en hommage au professeur italien <strong>Vito Soccomandi</strong>, l’I.V.S. a pour mission de former des cadres, techniciens et dirigeants compétents, capables de répondre aux enjeux économiques, sociaux et technologiques du monde contemporain.'),
    '<h3>Une formation axée sur la pratique et l’employabilité</h3>',
    li(['Un enseignement académique de qualité', 'Des ateliers pratiques et des projets concrets tout au long du cursus, avec une initiation au management dès la Licence', 'Des enseignants présentés principalement comme des cadres en activité', 'Une vie associative qui favorise l’épanouissement personnel, l’adaptation, la créativité et l’initiative']),
    '<h3>Implantations</h3>', p('Le siège est à Bamako. L’Institut dispose d’annexes régionales, dont celle de Sikasso citée dans son historique.'),
    p('<strong>Ensemble, bâtissons les talents de demain !</strong>'),
  ].join('') },
  admission: { title: 'Admissions et frais', content: [
    p('<strong>Choisissez votre pôle</strong> : chaque pôle a son dossier, ses tarifs et son contact. Les frais d’inscription, de scolarité, de tenue et les autres frais sont présentés séparément.'),
    '<h2>Comment candidater ?</h2>',
    '<h3>1. Constituer le dossier</h3>', p('Rassemblez les pièces demandées pour votre pôle et votre niveau.'),
    '<h3>2. Déposer ou envoyer le dossier</h3>', p('Déposez-le à l’établissement ou contactez le service admission pour connaître les modalités de dépôt.'),
    '<h3>3. Étude et réponse</h3>', p('Votre dossier est examiné et vous êtes informé de la décision.'),
  ].join('') },
  inscription: { title: 'Inscription', content: p('Choisissez votre pôle pour accéder à son dossier, à ses tarifs et à son contact. Notre équipe vous accompagne dans la constitution de votre dossier. Les pièces à fournir sont détaillées sur la page <a href="/admission/">Admissions et frais</a>.') },
  universite: { title: 'Université VITO S.', content: [
    p('L’Université VITO S. propose des parcours de <strong>DUT</strong>, de <strong>Licence</strong> et de <strong>Master</strong> en gestion, finance, droit, marketing, logistique, communication, numérique et développement.'),
    p('Le catalogue ci-dessous présente les 17 intitulés annoncés. Le cycle et le diplôme de chaque formation sont précisés au fur et à mesure de leur validation par l’établissement : contactez l’admission pour connaître les niveaux ouverts.'),
  ].join('') },
  lycee: { title: 'Lycée VITO S.', content: [
    p('Le Lycée VITO S. accueille les élèves des <strong>10e, 11e et 12e années</strong>, toutes séries (la liste précise des séries sera publiée prochainement).'),
    p('Les tarifs et pièces ci-dessous concernent la rentrée 2026–2027.'),
  ].join('') },
  itp: { title: 'ITP VITO S.', content: [
    p('L’Institut Technique et Professionnel (ITP) VITO S. propose les parcours <strong>BT-AD</strong> et <strong>BT-ID</strong>, en quatre années.'),
    p('Les tarifs et pièces ci-dessous concernent la rentrée 2026–2027. Le développement des sigles BT-AD et BT-ID sera précisé avec l’établissement.'),
  ].join('') },
  partenaires: { title: 'Partenaires et vie étudiante', content: [
    p('L’Institut présente ici les établissements et organismes cités dans ses supports. La nature de chaque partenariat est précisée sur demande auprès de l’admission.'),
    p('Une pédagogie concrète, des ateliers pratiques, une vie associative active : découvrez la vie à VITO S. dans la <a href="/galeries/">galerie photos</a>.'),
  ].join('') },
};

export const article = {
  slug: 'cinq-conseils-pour-choisir-son-stage', title: 'Cinq conseils pour choisir son stage',
  content: [
    p('Le stage est une étape clé du parcours. Voici cinq repères pour bien le choisir.'),
    '<h3>1. Viser un stage lié à sa formation</h3>', p('Un stage qui prolonge ce que vous apprenez vous permet de mettre en pratique vos enseignements et de gagner en crédibilité.'),
    '<h3>2. Rechercher des missions enrichissantes</h3>', p('Préférez des missions où vous apprenez et assumez des responsabilités, plutôt que des tâches répétitives.'),
    '<h3>3. Valoriser ses points forts</h3>', p('Préparez votre candidature en mettant en avant vos atouts et les compétences acquises pendant vos études.'),
    '<h3>4. Comparer la culture d’entreprise</h3>', p('Chaque organisation a sa manière de travailler : informez-vous pour choisir un environnement qui vous convient.'),
    '<h3>5. Tenir compte de l’emplacement</h3>', p('Le trajet, le coût et l’organisation du quotidien comptent dans la réussite d’un stage.'),
    p('<em>Ces conseils sont généraux ; ils ne constituent pas une garantie de placement en stage.</em>'),
  ].join(''),
};
