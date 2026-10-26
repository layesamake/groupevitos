# Compléments pédagogiques des fiches formations

Sources consultées le 26 octobre 2026 : [catalogue AFI-UE](https://afi-ue.sn/programmes/) et pages détaillées référencées dans `src/data/pedagogie-formations.ts`.

Les 17 fiches VITO S. existantes sont enrichies. Seize reçoivent des enseignements structurés en licence et master. Les 13 fiches sans contenu reçoivent aussi des objectifs, compétences, exemples de projets et pistes de métiers. Les quatre fiches déjà documentées conservent leurs textes. Logistique et transport conserve son programme de licence par semestre et reçoit seulement un exemple de mise en pratique ; aucun master ne lui est ajouté.

## Mise à jour

Les compléments sont versionnés dans `src/data/pedagogie-formations.ts` et publiés par le déploiement GitHub habituel. Ils ne sont pas enregistrés dans la base WordPress. `src/lib/pedagogie.ts` les applique uniquement aux rubriques vides lors de la génération des pages. Toute rubrique renseignée ultérieurement dans WordPress prend priorité, y compris les enseignements. Les titres, identifiants, diplômes, parcours DUT, durées, admissions et statuts de validation ne sont pas changés.

## Périmètre des références

| Fiche VITO S. | Références AFI utilisées | Adaptation |
| --- | --- | --- |
| Gestion de projets et développement durable | Management de projet ; licence QHSE | Socle introductif de licence proposé |
| Droit des affaires et fiscalité | Management juridique et fiscal ; Audit juridique et fiscal | Aucun régime fiscal sénégalais transposé |
| Audit et contrôle de gestion | Licence GFCE ; master Audit et contrôle de gestion | Progression comptabilité, budgets, audit |
| Banque, finance, microfinance | Banque assurance finance ; Ingénierie financière | Orientation microfinance, sans diplôme équivalent affirmé |
| Marketing et stratégie d’entreprise | Marketing et communication digitale, licence et master | Positionnement, études, stratégie |
| Marketing et management | Marketing et communication digitale, licence et master | Organisation commerciale et coordination |
| Informatique de gestion | Génie logiciel, licence et master | Applications métier et systèmes d’information |
| Commerce international | Management des affaires internationales ; Stratégie managériale à l’international | Opérations puis stratégie internationale |
| Transport et logistique | Génie logistique ; Supply Chain Management | Flux puis pilotage de chaîne |
| Finance et comptabilité | GFCE, licence et master | Comptabilité puis décisions financières |
| Communication et journalisme | Marketing et communication digitale, licence et master | Seule la communication est documentée ; journalisme à confirmer |
| Management des services de santé et de pharmacie | Management des organisations sanitaires et sociales | Licence introductive proposée ; gestion administrative, sans qualification clinique ou pharmaceutique |
| Gestion des ressources humaines | GRH, licence et master | Administration puis stratégie RH |
| Développement durable et énergies renouvelables | QHSE, licence et master | Management environnemental ; modules techniques énergie à confirmer |
| Droit public et relations internationales | Management juridique et fiscal ; Management public et innovation territoriale ; Stratégie managériale à l’international | Références partielles, modules spécialisés à confirmer |
| Management de projets et développement local | Management de projet ; Management public et innovation territoriale | Licence introductive proposée |
| Banque et finance | Banque assurance finance ; Ingénierie financière | Crédit, investissements et risques |

Les exemples de projets sont des propositions éditoriales, pas des modalités d’évaluation promises. Aucune accréditation, double diplomation, certification, volume horaire, crédit ECTS, stage obligatoire ou condition d’admission d’AFI n’est attribué à VITO S. L’ouverture effective des cycles et la maquette définitive restent à confirmer auprès de l’établissement. Les limites sont visibles sur les fiches, avec les liens des sources.
