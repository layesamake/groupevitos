<?php
// Recette du module Étudiants (WordPress Playground, base jetable).
define('GVB_ESSAI', true);
$lignes = []; $ok = 0; $ko = 0;
$t = function ($nom, $cond, $detail = '') use (&$lignes, &$ok, &$ko) { $cond ? $ok++ : $ko++; $lignes[] = ($cond ? '  ok  ' : ' ECHEC ') . $nom . (!$cond && $detail !== '' ? ' — ' . $detail : ''); };
register_shutdown_function(function () { $e = error_get_last(); if ($e && in_array($e['type'], [E_ERROR, E_PARSE], true)) file_put_contents('/wordpress/wp-content/out/fatal.txt', print_r($e, true)); });
require '/wordpress/wp-load.php';
do_action('init');
global $wpdb;
$E = gvb_table('etudiants'); $I = gvb_table('inscriptions'); $D = gvb_table('demandes');
add_filter('wp_die_handler', fn() => function ($m) { throw new Exception('DIE:' . (is_wp_error($m) ? $m->get_error_message() : (string) $m)); });
add_filter('wp_redirect', function ($loc) { throw new Exception('REDIR:' . $loc); });
gvb_synchroniser_roles();
$envoyer = function ($action, $nonce, array $post) { $_POST = $post + ['_wpnonce' => wp_create_nonce($nonce)]; $_REQUEST = $_POST; try { do_action('admin_post_' . $action); return 'rien'; } catch (Throwable $e) { return $e->getMessage(); } };
$mk = function ($l, $r, $pole = '') { $id = wp_insert_user(['user_login' => $l, 'user_pass' => wp_generate_password(), 'role' => $r, 'user_email' => "$l@exemple.test"]); if ($pole) update_user_meta($id, 'gv_pole', $pole); return $id; };
$dir = $mk('d', 'gv_direction'); $sco = $mk('s', 'gv_scolarite'); $cpt = $mk('c', 'gv_comptabilite'); $rpU = $mk('ru', 'gv_responsable_pole', 'universite'); $rpL = $mk('rl', 'gv_responsable_pole', 'lycee');
wp_set_current_user($sco);

$accepter = function ($nom, $prenom, $pole = 'universite') use ($wpdb, $D) {
    $id = gvb_creer_demande(['pole' => $pole, 'annee' => '2026–2027', 'niveau' => 'Licence 1', 'formation_id' => 0, 'serie' => '', 'nom' => $nom, 'prenom' => $prenom, 'naissance' => '2006-03-14', 'sexe' => 'F', 'nationalite' => 'Malienne', 'telephone' => '+223 70 00 00 0' . rand(0, 9), 'email' => strtolower($prenom) . '@exemple.test', 'adresse' => 'Bamako', 'parent_nom' => 'M. ' . $nom, 'parent_telephone' => '+223 66 00 00 00', 'parent_email' => '', 'provenance' => '', 'message' => ''], 'agence');
    $wpdb->update(gvb_table('pieces'), ['recue' => 1], ['demande_id' => $id]);
    $wpdb->update($D, ['statut' => 'acceptee'], ['id' => $id]);
    return $id;
};

$lignes[] = '## Droits';
$t('Scolarité gère les étudiants ; Comptabilité et Responsable consultent', user_can($sco, 'gv_etudiants_modifier') && user_can($cpt, 'gv_etudiants_voir') && !user_can($cpt, 'gv_etudiants_modifier') && user_can($rpU, 'gv_etudiants_voir') && !user_can($rpU, 'gv_etudiants_modifier'));
$t('Comptabilité exporte, Responsable non', user_can($cpt, 'gv_etudiants_exporter') && !user_can($rpU, 'gv_etudiants_exporter'));

$lignes[] = "\n## Création à l’inscription";
$d1 = $accepter('Diarra', 'Awa');
$m1 = gvb_inscrire($d1, 50000, 400000);
$e1 = $wpdb->get_row("SELECT * FROM $E WHERE demande_id = $d1");
$t('fiche étudiante créée avec le matricule de l’inscription', $m1 === 'UNI-2026-0001' && $e1 && $e1->matricule === 'UNI-2026-0001' && $e1->nom === 'Diarra' && $e1->statut === 'actif' && $e1->parent_nom === 'M. Diarra', json_encode($e1));
$t('inscription rattachée à l’étudiant', (int) $wpdb->get_var("SELECT etudiant_id FROM $I WHERE demande_id = $d1") === (int) $e1->id);
$d2 = $accepter('Traoré', 'Moussa', 'lycee'); $m2 = gvb_inscrire($d2, 10000, 150000);
$t('matricule du Lycée distinct et numéroté', $m2 === 'LYC-2026-0001', is_wp_error($m2) ? $m2->get_error_message() : $m2);

$lignes[] = "\n## Réinscription";
$r = gvb_reinscrire($e1->id, '2026–2027', 'Licence 1', 0, 0); $t('même année : refusée', is_wp_error($r) && str_contains($r->get_error_message(), 'déjà'));
$r = gvb_reinscrire($e1->id, '2027–2028', 'Licence 2', 55000, 420000);
$e1b = $wpdb->get_row("SELECT * FROM $E WHERE id = {$e1->id}");
$t('réinscription : nouvelle ligne, même matricule, niveau mis à jour', $r === true && $e1b->annee === '2027–2028' && $e1b->niveau === 'Licence 2' && (int) $wpdb->get_var("SELECT COUNT(*) FROM $I WHERE etudiant_id = {$e1->id} AND matricule = 'UNI-2026-0001' AND statut = 'validee'") === 2);
$r = gvb_reinscrire($e1->id, '2027–2028', 'Licence 2', 1, 1); $t('doublon de réinscription refusé', is_wp_error($r));
$r = gvb_reinscrire($e1->id, 'demain', 'L3', 1, 1); $t('année invalide refusée', is_wp_error($r));
$r = gvb_reinscrire($e1->id, '2028–2029', '', 1, 1); $t('niveau obligatoire', is_wp_error($r));
$r = gvb_reinscrire($e1->id, '2028–2029', 'L3', -5, 1); $t('montant négatif refusé', is_wp_error($r));
wp_set_current_user($cpt); $r = $envoyer('gvb_reinscrire', 'gvb_reinscrire', ['id' => $e1->id, 'annee' => '2028–2029', 'niveau' => 'L3', 'frais_inscription' => 1, 'frais_scolarite' => 1]);
$t('Comptabilité ne peut pas réinscrire', str_starts_with($r, 'DIE:'));
wp_set_current_user($sco); $r = $envoyer('gvb_reinscrire', 'gvb_reinscrire', ['id' => $e1->id, 'annee' => '2028–2029', 'niveau' => 'Licence 3', 'frais_inscription' => 55000, 'frais_scolarite' => 420000]);
$t('Scolarité réinscrit via le formulaire', str_contains(urldecode($r), 'gv_msg') && (int) $wpdb->get_var("SELECT COUNT(*) FROM $I WHERE etudiant_id = {$e1->id} AND statut = 'validee'") === 3, $r);

$lignes[] = "\n## Fiche et statut";
$r = $envoyer('gvb_etudiant_sauver', 'gvb_etudiant_sauver', ['id' => $e1->id, 'nom' => 'Diarra-Keita', 'prenom' => 'Awa', 'naissance' => '2006-03-14', 'telephone' => '+223 70 99 99 99', 'email' => 'nouveau@exemple.test']);
$t('modification de l’identité', $wpdb->get_var("SELECT nom FROM $E WHERE id = {$e1->id}") === 'Diarra-Keita' && $wpdb->get_var("SELECT matricule FROM $E WHERE id = {$e1->id}") === 'UNI-2026-0001', $r);
$r = $envoyer('gvb_etudiant_sauver', 'gvb_etudiant_sauver', ['id' => $e1->id, 'nom' => '', 'prenom' => 'Awa']); $t('nom vide refusé', str_contains(urldecode($r), 'obligatoires'));
$r = $envoyer('gvb_etudiant_sauver', 'gvb_etudiant_sauver', ['id' => $e1->id, 'nom' => 'X', 'prenom' => 'Y', 'naissance' => '2999-01-01']); $t('naissance dans le futur refusée', str_contains(urldecode($r), 'invalide'));
wp_set_current_user($cpt); $r = $envoyer('gvb_etudiant_sauver', 'gvb_etudiant_sauver', ['id' => $e1->id, 'nom' => 'Piraté', 'prenom' => 'X']);
$t('Comptabilité ne peut pas modifier la fiche', str_starts_with($r, 'DIE:') && $wpdb->get_var("SELECT nom FROM $E WHERE id = {$e1->id}") === 'Diarra-Keita');
wp_set_current_user($sco);
$r = $envoyer('gvb_etudiant_statut', 'gvb_etudiant_statut', ['id' => $e1->id, 'statut' => 'suspendu', 'motif' => '']); $t('suspension sans motif refusée', str_contains(urldecode($r), 'motif') && $wpdb->get_var("SELECT statut FROM $E WHERE id = {$e1->id}") === 'actif');
$envoyer('gvb_etudiant_statut', 'gvb_etudiant_statut', ['id' => $e1->id, 'statut' => 'suspendu', 'motif' => 'Frais impayés']);
$t('suspension avec motif', $wpdb->get_var("SELECT statut FROM $E WHERE id = {$e1->id}") === 'suspendu' && $wpdb->get_var("SELECT motif FROM $E WHERE id = {$e1->id}") === 'Frais impayés');
$t('un étudiant suspendu ne peut pas être réinscrit', is_wp_error(gvb_reinscrire($e1->id, '2029–2030', 'M1', 1, 1)));
$r = $envoyer('gvb_etudiant_statut', 'gvb_etudiant_statut', ['id' => $e1->id, 'statut' => 'inactif', 'motif' => 'x']); $t('statut « inactif » non attribuable à la main', str_contains(urldecode($r), 'invalide'));
$envoyer('gvb_etudiant_statut', 'gvb_etudiant_statut', ['id' => $e1->id, 'statut' => 'actif']);
$t('retour à « actif » sans motif, motif effacé', $wpdb->get_var("SELECT statut FROM $E WHERE id = {$e1->id}") === 'actif' && (string) $wpdb->get_var("SELECT motif FROM $E WHERE id = {$e1->id}") === '');

$lignes[] = "\n## Annulation d’inscription et retour";
wp_set_current_user($dir);
$ins2 = $wpdb->get_row("SELECT * FROM $I WHERE demande_id = $d2");
$envoyer('gvb_inscription_annuler', 'gvb_inscription_annuler', ['ins' => $ins2->id, 'motif' => 'Désistement']);
$e2 = $wpdb->get_row("SELECT * FROM $E WHERE demande_id = $d2");
$t('annulation de l’unique inscription : étudiant « inactif »', $e2->statut === 'inactif' && str_contains($e2->motif, 'Désistement'));
$m2b = gvb_inscrire($d2, 10000, 150000);
$e2b = $wpdb->get_row("SELECT * FROM $E WHERE demande_id = $d2");
$t('nouvelle inscription du même dossier : même matricule, étudiant réactivé, pas de doublon', $m2b === 'LYC-2026-0001' && $e2b->statut === 'actif' && (int) $wpdb->get_var("SELECT COUNT(*) FROM $E WHERE demande_id = $d2") === 1, (string) (is_wp_error($m2b) ? $m2b->get_error_message() : $m2b));
$ins1 = $wpdb->get_row("SELECT * FROM $I WHERE etudiant_id = {$e1->id} ORDER BY id LIMIT 1");
$envoyer('gvb_inscription_annuler', 'gvb_inscription_annuler', ['ins' => $ins1->id, 'motif' => 'Erreur de saisie']);
$t('annulation d’une inscription parmi plusieurs : l’étudiant reste actif', $wpdb->get_var("SELECT statut FROM $E WHERE id = {$e1->id}") === 'actif');

$lignes[] = "\n## Périmètre, pages, export";
wp_set_current_user($rpU); $_GET = [];
$t('Responsable de l’Université : ne voit pas les élèves du Lycée', gvb_etudiant($e1->id) !== null && gvb_etudiant($e2->id) === null && count(gvb_requete_etudiants(['annee' => '', 'pole' => '', 'statut' => '', 'q' => ''])) === 1);
wp_set_current_user($rpL); $_GET = ['id' => $e1->id]; $t('fiche d’un autre pôle : 404', (function () { try { gvb_page_etudiant(); return false; } catch (Exception $e) { return str_starts_with($e->getMessage(), 'DIE:'); } })());
wp_set_current_user($cpt); $_GET = ['id' => $e1->id]; ob_start(); gvb_page_etudiant(); $h = ob_get_clean();
$t('Comptabilité : fiche en lecture seule (pas de formulaire de modification)', str_contains($h, 'UNI-2026-0001') && !str_contains($h, 'gvb_etudiant_sauver') && !str_contains($h, 'gvb_reinscrire'));
wp_set_current_user($dir); $_GET = []; ob_start(); gvb_page_etudiants(); $h = ob_get_clean();
$t('liste des étudiants affichée', str_contains($h, 'UNI-2026-0001') && str_contains($h, 'LYC-2026-0001') && str_contains($h, 'DIARRA-KEITA'));
$_GET = ['q' => 'Traor']; ob_start(); gvb_page_etudiants(); $h = ob_get_clean(); $t('recherche par nom', str_contains($h, 'LYC-2026-0001') && !str_contains($h, 'UNI-2026-0001'));
$_GET = ['id' => $e1->id]; ob_start(); gvb_page_etudiant(); $h = ob_get_clean();
$t('fiche : parcours (3 années), réinscription et statut', str_contains($h, '2028–2029') && str_contains($h, 'Réinscription') && str_contains($h, 'Parcours'));
$wpdb->update($E, ['nom' => '=HYPERLINK("http://x")'], ['id' => $e2->id]);
$_GET = ['_wpnonce' => wp_create_nonce('gvb_export_etudiants')]; $_REQUEST = $_GET; ob_start(); try { do_action('admin_post_gvb_export_etudiants'); } catch (Throwable $x) {} $csv = ob_get_clean();
$t('export CSV : formule neutralisée, matricules présents', str_contains($csv, "'=HYPERLINK") && str_contains($csv, 'UNI-2026-0001'));
wp_set_current_user($rpU); $_GET = ['_wpnonce' => wp_create_nonce('gvb_export_etudiants')]; $_REQUEST = $_GET;
$t('export refusé au Responsable de pôle', (function () { try { ob_start(); do_action('admin_post_gvb_export_etudiants'); ob_end_clean(); return false; } catch (Exception $e) { ob_end_clean(); return str_starts_with($e->getMessage(), 'DIE:'); } })());
wp_set_current_user($dir); $_GET = []; ob_start(); gvb_page_tableau(); $h = ob_get_clean(); $t('tableau de bord : carte « Étudiants actifs »', str_contains($h, 'Étudiants actifs'));

file_put_contents('/wordpress/wp-content/out/resultat.txt', implode("\n", $lignes) . "\n\n=== $ok réussis, $ko échecs ===\n");
