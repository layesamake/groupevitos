# Back-office Groupe VITO S. — socle et admissions

Extension « mu-plugins » WordPress (admin.groupevitos.com), même architecture que le back-office OPENTOURS.

## Fichiers (`wordpress/mu-plugins/`)
- `groupevitos-bo-core.php` — tables, profils et droits, journal, numérotation, menu **GROUPE VITOS**, identité et page de connexion.
- `groupevitos-bo-admissions.php` — demandes d'admission, pièces, décisions, doublons, export CSV, formulaire public (`POST /wp-json/groupevitos/v1/admissions`).
- `groupevitos-bo-inscriptions.php` — inscriptions (matricule), fiche imprimable, tableau de bord, réglages.
- Site : `src/components/AdmissionForm.astro`, affiché sur `/inscription/`.
- Recette : `wordpress/tests/recette-bo.php` (62 contrôles, WordPress Playground).

## Profils
| Profil | Admissions | Inscriptions | Réglages / comptes / CV |
|---|---|---|---|
| Direction | tous droits | tous droits (dont annulation) | oui |
| Scolarité | voir, créer, modifier, exporter | voir, créer, modifier, exporter | non |
| Comptabilité | voir | voir, exporter | non |
| Responsable de pôle | voir, créer, modifier, **décider** (son pôle seulement) | voir | non |

Le pôle d'un responsable se règle sur sa fiche utilisateur (« Pôle géré »). Sans pôle, il ne voit rien. Les droits sont contrôlés côté serveur ; les profils métier n'ont aucun droit WordPress sur les contenus.

## Parcours
Nouvelle → En étude → (Dossier incomplet / Liste d'attente) → Acceptée / Refusée → **Inscrite** (matricule `UNI-2026-0001`, `LYC-…`, `ITP-…`, sans trou ni doublon). Un refus, un abandon ou une liste d'attente exige un motif. Inscrire avec des pièces manquantes exige une dérogation motivée. Toute action est tracée dans l'historique de la demande.

## À valider par la Direction
Liste des pièces par pôle (`GVB_PIECES`), durée de conservation des demandes, montants des frais (saisis à l'inscription en attendant le module Scolarité et finances), e-mail d'alerte (Réglages).

## Modules ajoutés ensuite
- **Contacts (plaquette)** : demandes de plaquette par e-mail (Brevo) ou WhatsApp ; l'équipe envoie WhatsApp avec le bouton de l'écran Contacts.
- **Publications Facebook** : texte, lien, photos, programmation, historique ; préremplissage depuis une formation ou une actualité (écran Publications ou lien « Publier sur Facebook » dans les listes WordPress). Le jeton de la page est saisi dans Réglages et jamais réaffiché.
- **Étudiants et élèves** : fiche créée à l'inscription (matricule permanent), statuts (actif, suspendu, diplômé, abandon, transféré, inactif), réinscription annuelle, parcours, export CSV. Une inscription annulée rend l'étudiant « inactif » ; une nouvelle inscription du même dossier le réactive avec le même matricule.
- **Chargé de communication** (profil) : gère les articles (menu « Actualités », « Médias ») et les publications Facebook, voit les contacts de la plaquette ; n'a aucun droit sur les formations, pages, admissions, étudiants ni réglages (contrôlé côté serveur).
- **Scolarité et finances** : échéancier créé à l'inscription et à la réinscription (frais d'inscription + scolarité en N tranches, réglages dans Réglages → Échéancier et relances), paiements imputés par ordre d'échéance, reçus numérotés `REC-AAAA-00001` (jamais réutilisés, annulation conservée), impayés avec relances (e-mail limité à une par semaine, WhatsApp via lien), journal et exports CSV. Droits : Comptabilité encaisse et ajuste, Direction annule un paiement, Scolarité et Responsables de pôle consultent.
- **Programmes et enseignants** : cours (maquette par pôle, formation et niveau, avec volume horaire), enseignants (permanents ou vacataires, pôles d'intervention, plafond annuel, taux horaire visible seulement de la Direction et de la Comptabilité, création possible depuis une candidature « retenue »), affectations (volume du cours et plafond respectés) et charges horaires (affectées, planifiées, effectuées, montant à rémunérer).
- **Emploi du temps, salles et présences** : séances planifiées par semaine (répétition hebdomadaire possible), détection des conflits d'enseignant, de salle et de groupe, heures affectées non dépassables, report et annulation motivée, appel par séance (présent, retard, absent, excusé), séance « tenue » après appel, taux de présence et absences par étudiant, export CSV. Le groupe d'une séance = étudiants actifs du même pôle, niveau et formation.
- Les **notes** ne sont pas couvertes (décision de la Direction).
- **Espaces en ligne** (`/espace/` sur admin.groupevitos.com) : comptes parents, étudiants et enseignants **distincts des comptes WordPress**, créés sur invitation (jamais d'inscription libre), connexion par e-mail et mot de passe (10 caractères minimum, haché), sessions de 12 h hachées en base, limites de tentatives, jetons d'invitation et de réinitialisation à usage unique, protection CSRF, pages jamais mises en cache ni indexées. Un parent ou un étudiant ne voit que les étudiants rattachés à son compte : emploi du temps du groupe, présences et absences, échéances, paiements et reçus, annonces. Un enseignant voit son planning, ses cours et heures, et fait l'appel de ses séances (jusqu'à 14 jours après).
- **Annonces** ciblées (familles, enseignants ou tous ; pôle et niveau), publiées dans l'espace et, si on le souhaite, par e-mail.
- **Notifications par e-mail** (file d'envoi avec reprise automatique, plafond de 250 par jour, désabonnement par lien signé) : absence d'un étudiant, séance reportée ou annulée, reçu de paiement, annonces. Chaque type se règle dans Réglages.
