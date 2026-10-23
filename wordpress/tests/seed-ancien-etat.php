<?php
// Reproduit l'état du site avant la mise à jour du référentiel (4 formations, 6 pages) pour l'essai local. Jamais exécuté en production.
require_once '/wordpress/wp-load.php';
wp_set_password('recette-gv-2026', 1);
$page = fn($slug, $titre, $contenu, $ordre = 0) => wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $titre, 'post_content' => $contenu, 'menu_order' => $ordre]);
$page('accueil', 'Accueil', '<h2>Pourquoi choisir l’Institut VITO S. ?</h2><ul><li><strong>Une formation polyvalente</strong>, alliant théorie et pratique ;</li><li><strong>Un enseignement tourné vers la pratique</strong>, en phase avec les entreprises ;</li><li><strong>Un écosystème dynamique</strong>, vie associative ;</li><li><strong>Une pédagogie axée sur le leadership</strong>.</li></ul><h2>Nos partenaires</h2><figure><img src="https://admin.groupevitos.com/wp-content/uploads/ESMC.png" alt="ESMC"></figure><h2>Rejoignez-nous</h2><p>Contactez-nous.</p>');
$page('a-propos', 'À propos', '<h2>À propos</h2><p>Ancien texte.</p>');
$page('admission', 'Admission', '<h2>Procédure</h2><h3>1. Dépôt du dossier</h3><p>Formulaire.</p><h2>Dossiers à fournir</h2><ul><li>2 photos</li></ul><h2>Frais</h2><table><thead><tr><th><strong>Années</strong></th><th><strong>Inscription</strong></th><th><strong>Scolarité</strong></th><th><strong>Montant</strong></th></tr></thead><tbody><tr><td>Licence 1</td><td>60 000 F CFA</td><td>440 000 F CFA</td><td>500 000 F CFA</td></tr></tbody></table>');
$page('inscription', 'Inscription', '<p>Contactez-nous.</p>');
$page('contacts', 'Contacts', '<ul><li>Bamako : <a href="tel:+22382068206">+223 82 06 82 06</a></li><li>E-mail : <a href="mailto:contact@groupevitos.com">contact@groupevitos.com</a></li></ul><p>Adresse : <a href="https://maps.app.goo.gl/x">Kalaban Coura, Bamako — Rue 30, Immeuble VITO S.</a></p><p><iframe src="https://maps.google.com/maps?q=insitut%20vito%20s&amp;output=embed" title="Plan"></iframe></p>');
$page('galeries', 'Galeries', '<p>Photos.</p>');
foreach ([['banque-finance-microfinance', 'Banque – Finance – Microfinance'], ['gestion-des-ressources-humaines', 'Gestion des Ressources Humaines'], ['droit-des-affaires-et-fiscalite', 'Droit des Affaires et Fiscalité'], ['licence-en-transport-et-logistique', 'LICENCE EN TRANSPORT ET LOGISTIQUE']] as $i => [$slug, $titre]) {
    wp_insert_post(['post_type' => 'formation', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $titre, 'post_content' => '<p>Objectifs de la formation.</p>', 'menu_order' => $i, 'meta_input' => ['niveau' => 'DUT, Licence, Master', 'duree' => 'DUT : 2 ans, Licence : 3 ans, Master : 2 ans', 'debouches' => '<table><tr><td>Banque</td><td>Conseiller</td></tr></table>']]);
}
