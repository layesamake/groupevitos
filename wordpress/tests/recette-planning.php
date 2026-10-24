<?php
// Recette du module Emploi du temps, salles et présences (WordPress Playground, base jetable).
define('GVB_ESSAI', true);
$lignes = []; $ok = 0; $ko = 0;
$t = function ($nom, $cond, $detail = '') use (&$lignes, &$ok, &$ko) { $cond ? $ok++ : $ko++; $lignes[] = ($cond ? '  ok  ' : ' ECHEC ') . $nom . (!$cond && $detail !== '' ? ' — ' . $detail : ''); };
register_shutdown_function(function () { $e = error_get_last(); if ($e && in_array($e['type'], [E_ERROR, E_PARSE], true)) file_put_contents('/wordpress/wp-content/out/fatal.txt', print_r($e, true)); });
require '/wordpress/wp-load.php';
do_action('init');
global $wpdb;
$K = gvb_table('cours'); $N = gvb_table('enseignants'); $A = gvb_table('affectations'); $S = gvb_table('seances'); $Z = gvb_table('salles'); $P = gvb_table('presences'); $D = gvb_table('demandes'); $E = gvb_table('etudiants');
add_filter('wp_die_handler', fn() => function ($m) { throw new Exception('DIE:' . (is_wp_error($m) ? $m->get_error_message() : (string) $m)); });
add_filter('wp_redirect', function ($loc) { throw new Exception('REDIR:' . $loc); });
gvb_synchroniser_roles();
$envoyer = function ($action, $nonce, array $post) { $_POST = $post + ['_wpnonce' => wp_create_nonce($nonce)]; $_REQUEST = $_POST; try { do_action('admin_post_' . $action); return 'rien'; } catch (Throwable $e) { return urldecode($e->getMessage()); } };
$mk = function ($l, $r, $pole = '') { $id = wp_insert_user(['user_login' => $l, 'user_pass' => wp_generate_password(), 'role' => $r, 'user_email' => "$l@exemple.test"]); if ($pole) update_user_meta($id, 'gv_pole', $pole); return $id; };
$dir = $mk('d', 'gv_direction'); $sco = $mk('s', 'gv_scolarite'); $cpt = $mk('c', 'gv_comptabilite'); $rpU = $mk('ru', 'gv_responsable_pole', 'universite'); $rpL = $mk('rl', 'gv_responsable_pole', 'lycee'); $com = $mk('com', 'gv_communication');
$an = gvb_reglages()['annee']; $hier = gmdate('Y-m-d', time() - 86400); $demain = gmdate('Y-m-d', time() + 86400);
wp_set_current_user($dir);

// Données de base
$now = gvb_now();
$wpdb->insert($K, ['pole' => 'universite', 'formation_id' => 0, 'niveau' => 'Licence 1', 'intitule' => 'Gestion de projet', 'volume' => 40, 'cree_le' => $now]); $c1 = (int) $wpdb->insert_id;
$wpdb->insert($K, ['pole' => 'universite', 'formation_id' => 0, 'niveau' => 'Licence 1', 'intitule' => 'Comptabilité', 'volume' => 40, 'cree_le' => $now]); $c2 = (int) $wpdb->insert_id;
$wpdb->insert($K, ['pole' => 'lycee', 'formation_id' => 0, 'niveau' => '10e année', 'intitule' => 'Mathématiques', 'volume' => 40, 'cree_le' => $now]); $c3 = (int) $wpdb->insert_id;
$ens = function ($nom, $poles = '') use ($wpdb, $N, $now) { $wpdb->insert($N, ['nom' => $nom, 'prenom' => 'X', 'statut' => 'vacataire', 'poles' => $poles, 'cree_le' => $now, 'modifie_le' => $now]); return (int) $wpdb->insert_id; };
$e1 = $ens('Kouyaté'); $e2 = $ens('Sy', 'lycee'); $e3 = $ens('Cissé');
$aff = function ($c, $e, $h) use ($wpdb, $A, $an, $now) { $wpdb->insert($A, ['cours_id' => $c, 'enseignant_id' => $e, 'annee' => $an, 'heures' => $h, 'cree_le' => $now]); };
$aff($c1, $e1, 4); $aff($c2, $e3, 40); $aff($c3, $e2, 40);

$lignes[] = '## Droits';
$t('Scolarité et Responsables planifient ; Comptabilité consulte ; Communication exclue', user_can($sco, 'gv_planning_modifier') && user_can($rpU, 'gv_planning_creer') && user_can($cpt, 'gv_planning_voir') && !user_can($cpt, 'gv_planning_creer') && !user_can($com, 'gv_planning_voir'));

$lignes[] = "\n## Salles";
$r = $envoyer('gvb_salle_sauver', 'gvb_salle_sauver', ['id' => 0, 'nom' => 'Amphi A', 'pole' => 'universite', 'capacite' => '120', 'actif' => '1']); $sA = (int) $wpdb->get_var("SELECT id FROM $Z WHERE nom = 'Amphi A'");
$t('création d’une salle', $sA > 0 && str_contains($r, 'gv_msg'), $r);
$envoyer('gvb_salle_sauver', 'gvb_salle_sauver', ['id' => 0, 'nom' => 'Salle 2', 'capacite' => '1', 'actif' => '1']); $s2 = (int) $wpdb->get_var("SELECT id FROM $Z WHERE nom = 'Salle 2'");
$r = $envoyer('gvb_salle_sauver', 'gvb_salle_sauver', ['id' => 0, 'nom' => 'amphi a', 'actif' => '1']); $t('nom de salle en double refusé (insensible à la casse)', str_contains($r, 'déjà'));
$r = $envoyer('gvb_salle_sauver', 'gvb_salle_sauver', ['id' => 0, 'nom' => '', 'actif' => '1']); $t('nom obligatoire', str_contains($r, 'obligatoire'));
wp_set_current_user($cpt); $r = $envoyer('gvb_salle_sauver', 'gvb_salle_sauver', ['id' => 0, 'nom' => 'Intrus', 'actif' => '1']); $t('Comptabilité ne crée pas de salle', str_starts_with($r, 'DIE:'));
wp_set_current_user($sco);

$lignes[] = "\n## Planification et conflits";
$r = gvb_creer_seances($c1, $e1, $sA, $hier, '08:00', '10:00');
$t('séance créée (2 h sur 4 h affectées)', is_array($r) && $r['creees'] === 1, json_encode($r));
$r = gvb_creer_seances($c2, $e1, 0, $hier, '09:00', '11:00'); $t('enseignant non affecté à ce cours : refusé', is_wp_error($r) && str_contains($r->get_error_message(), 'pas affecté'));
$r = gvb_creer_seances($c2, $e3, 0, $hier, '09:00', '11:00'); $t('conflit de groupe (même niveau, même horaire) détecté', is_array($r) && $r['creees'] === 0 && str_contains(implode(' ', $r['ignorees']), 'groupe'), json_encode($r));
$aff($c3, $e1, 10);
$wpdb->update($A, ['heures' => 10], ['cours_id' => $c3, 'enseignant_id' => $e1]);
$wpdb->update($N, ['poles' => ''], ['id' => $e1]);
$r = gvb_creer_seances($c3, $e1, 0, $hier, '09:00', '10:00'); $t('conflit d’enseignant (déjà en cours ailleurs) détecté', is_array($r) && $r['creees'] === 0 && str_contains(implode(' ', $r['ignorees']), 'enseignant'), json_encode($r));
$r = gvb_creer_seances($c3, $e2, $sA, $hier, '09:00', '10:00'); $t('conflit de salle détecté', is_array($r) && $r['creees'] === 0 && str_contains(implode(' ', $r['ignorees']), 'salle'), json_encode($r));
$r = gvb_creer_seances($c3, $e2, $s2, $hier, '09:00', '10:00'); $t('autre salle, autre enseignant, autre groupe : accepté', is_array($r) && $r['creees'] === 1);
$r = gvb_creer_seances($c1, $e1, $sA, $hier, '10:00', '11:00'); $t('séances contiguës acceptées (fin = début)', is_array($r) && $r['creees'] === 1);
$r = gvb_creer_seances($c1, $e1, 0, $hier, '14:00', '17:00'); $t('heures affectées épuisées (4 h déjà planifiées sur 4)', is_array($r) && $r['creees'] === 0 && str_contains(implode(' ', $r['ignorees']), 'épuisées'), json_encode($r));
foreach ([['25:00', '26:00'], ['10:00', '09:00'], ['05:00', '07:00'], ['08:00', '08:10'], ['07:00', '14:00'], ['8h', '9h']] as [$d, $f]) $t("horaire $d–$f refusé", is_wp_error(gvb_creer_seances($c2, $e3, 0, $demain, $d, $f)));
$r = gvb_creer_seances($c2, $e3, 0, 'pas-une-date', '08:00', '09:00'); $t('date invalide refusée', is_wp_error($r));
$r = gvb_creer_seances($c2, $e3, 999, $demain, '08:00', '09:00'); $t('salle inexistante refusée', is_wp_error($r));
$r = gvb_creer_seances($c2, $e3, $s2, $demain, '08:00', '09:00'); $t('salle plus petite que l’effectif : alerte (0 étudiant ici, donc aucune)', is_array($r) && $r['creees'] === 1);
$wpdb->update($Z, ['actif' => 0], ['id' => $s2]); $r = gvb_creer_seances($c2, $e3, $s2, $demain, '14:00', '15:00'); $t('salle inactive refusée', is_wp_error($r));
$wpdb->update($Z, ['actif' => 1], ['id' => $s2]);

$lignes[] = "\n## Séries hebdomadaires, report, annulation";
$lundi = gmdate('Y-m-d', strtotime('monday +3 weeks'));
$wpdb->insert($S, ['cours_id' => $c3, 'enseignant_id' => $e2, 'salle_id' => 0, 'annee' => $an, 'pole' => 'lycee', 'niveau' => '10e année', 'date_seance' => gmdate('Y-m-d', strtotime($lundi) + 14 * 86400), 'debut' => '14:00:00', 'fin' => '16:00:00', 'cree_le' => $now]);
$r = gvb_creer_seances($c3, $e2, 0, $lundi, '14:00', '16:00', 4);
$t('série de 4 semaines : 3 créées, 1 ignorée pour conflit', is_array($r) && $r['creees'] === 3 && count($r['ignorees']) === 1 && isset($r['ignorees'][gmdate('Y-m-d', strtotime($lundi) + 14 * 86400)]), json_encode($r));
$r = $envoyer('gvb_seance_creer', 'gvb_seance_creer', ['cours' => $c2, 'enseignant' => $e3, 'salle' => 0, 'date' => $lundi, 'debut' => '08:00', 'fin' => '09:30', 'semaines' => '2']);
$t('création par formulaire (série de 2)', str_contains($r, 'gv_msg') && str_contains($r, '2 séance(s)'), $r);
$sc = $wpdb->get_row("SELECT * FROM $S WHERE cours_id = $c2 AND date_seance = '$lundi'");
$r = $envoyer('gvb_seance_reporter', 'gvb_seance_reporter', ['id' => $sc->id, 'date' => $lundi, 'debut' => '14:00', 'fin' => '15:00']); $t('report sur un créneau du même groupe : accepté (pas de conflit)', str_contains($r, 'gv_msg'), $r);
$r = $envoyer('gvb_seance_reporter', 'gvb_seance_reporter', ['id' => $sc->id, 'date' => $lundi, 'debut' => '15:00', 'fin' => '16:00']); $t('report accepté', str_contains($r, 'gv_msg') && $wpdb->get_var("SELECT debut FROM $S WHERE id = {$sc->id}") === '15:00:00', $r);
$lundi_c3 = $wpdb->get_row("SELECT * FROM $S WHERE cours_id = $c3 AND date_seance = '$lundi'");
$wpdb->insert($S, ['cours_id' => $c1, 'enseignant_id' => $e1, 'salle_id' => 0, 'annee' => $an, 'pole' => 'universite', 'niveau' => 'Licence 1', 'date_seance' => $lundi, 'debut' => '16:00:00', 'fin' => '17:00:00', 'cree_le' => $now]); $busy = (int) $wpdb->insert_id;
$r = $envoyer('gvb_seance_reporter', 'gvb_seance_reporter', ['id' => $sc->id, 'date' => $lundi, 'debut' => '16:30', 'fin' => '17:30']); $t('report vers un créneau occupé par le groupe : refusé', str_contains($r, 'impossible') && $wpdb->get_var("SELECT debut FROM $S WHERE id = {$sc->id}") === '15:00:00', $r);
$r = $envoyer('gvb_seance_annuler', 'gvb_seance_annuler', ['id' => $busy, 'motif' => '']); $t('annulation : motif obligatoire', str_contains($r, 'motif'));
$envoyer('gvb_seance_annuler', 'gvb_seance_annuler', ['id' => $busy, 'motif' => 'Enseignant absent']);
$t('annulation enregistrée', $wpdb->get_var("SELECT statut FROM $S WHERE id = $busy") === 'annulee');
$r = $envoyer('gvb_seance_reporter', 'gvb_seance_reporter', ['id' => $sc->id, 'date' => $lundi, 'debut' => '16:30', 'fin' => '17:30']); $t('un créneau annulé se libère', str_contains($r, 'gv_msg'), $r);
wp_set_current_user($cpt); $r = $envoyer('gvb_seance_annuler', 'gvb_seance_annuler', ['id' => $sc->id, 'motif' => 'x']); $t('Comptabilité ne peut pas annuler', str_starts_with($r, 'DIE:'));
wp_set_current_user($sco);

$lignes[] = "\n## Appel et présences";
// étudiants de Licence 1 (Université)
$accepter = function ($nom, $prenom, $niveau, $pole = 'universite') use ($wpdb, $D) {
    $id = gvb_creer_demande(['pole' => $pole, 'annee' => '2026–2027', 'niveau' => $niveau, 'formation_id' => 0, 'serie' => '', 'nom' => $nom, 'prenom' => $prenom, 'naissance' => '2006-03-14', 'sexe' => 'F', 'nationalite' => '', 'telephone' => '+223 70 00 00 01', 'email' => '', 'adresse' => '', 'parent_nom' => '', 'parent_telephone' => '', 'parent_email' => '', 'provenance' => '', 'message' => ''], 'agence');
    $wpdb->update(gvb_table('pieces'), ['recue' => 1], ['demande_id' => $id]); $wpdb->update($D, ['statut' => 'acceptee'], ['id' => $id]); gvb_inscrire($id, 0, 0, '');
    return (int) $wpdb->get_var('SELECT id FROM ' . gvb_table('etudiants') . " WHERE demande_id = $id");
}; wp_set_current_user($dir);
$a1 = $accepter('Diarra', 'Awa', 'Licence 1'); $a2 = $accepter('Traoré', 'Moussa', 'licence 1'); $a3 = $accepter('Keita', 'Sidi', 'Licence 2'); $a4 = $accepter('Sy', 'Fanta', '10e année', 'lycee');
wp_set_current_user($sco);
$seance = $wpdb->get_row("SELECT * FROM $S WHERE cours_id = $c1 AND date_seance = '$hier' AND debut = '08:00:00'");
$groupe = gvb_etudiants_du_groupe($seance);
$t('le groupe = actifs du même pôle et niveau (insensible à la casse), pas les autres', count($groupe) === 2 && in_array($a1, array_map(fn($x) => (int) $x->id, $groupe)) && in_array($a2, array_map(fn($x) => (int) $x->id, $groupe)));
$r = $envoyer('gvb_appel_sauver', 'gvb_appel_sauver', ['id' => $seance->id, 'statut' => [$a1 => 'present', $a2 => 'absent'], 'note' => [$a2 => 'Malade']]);
$t('appel enregistré, séance marquée « tenue »', str_contains($r, 'gv_msg') && $wpdb->get_var("SELECT statut FROM $S WHERE id = {$seance->id}") === 'tenue' && (int) $wpdb->get_var("SELECT COUNT(*) FROM $P WHERE seance_id = {$seance->id}") === 2, $r);
$r = $envoyer('gvb_appel_sauver', 'gvb_appel_sauver', ['id' => $seance->id, 'statut' => [$a2 => 'excuse']]); $t('l’appel peut être corrigé (pas de doublon)', $wpdb->get_var("SELECT statut FROM $P WHERE seance_id = {$seance->id} AND etudiant_id = $a2") === 'excuse' && (int) $wpdb->get_var("SELECT COUNT(*) FROM $P WHERE seance_id = {$seance->id}") === 2);
$r = $envoyer('gvb_appel_sauver', 'gvb_appel_sauver', ['id' => $seance->id, 'statut' => [$a3 => 'present']]); $t('un étudiant d’un autre niveau est refusé', str_contains($r, 'ne fait pas partie'), $r);
$r = $envoyer('gvb_appel_sauver', 'gvb_appel_sauver', ['id' => $seance->id, 'statut' => [$a1 => 'dormant']]); $t('statut de présence invalide refusé', str_contains($r, 'invalide'));
$futur = gvb_creer_seances($c2, $e3, 0, gmdate('Y-m-d', time() + 3 * 86400), '08:00', '09:00'); $sf = $wpdb->get_row("SELECT * FROM $S WHERE cours_id = $c2 AND date_seance = '" . gmdate('Y-m-d', time() + 3 * 86400) . "'");
$r = $envoyer('gvb_appel_sauver', 'gvb_appel_sauver', ['id' => $sf->id, 'statut' => [$a1 => 'present']]); $t('pas d’appel pour une séance à venir', str_contains($r, 'à venir'), $r);
$r = $envoyer('gvb_appel_sauver', 'gvb_appel_sauver', ['id' => $busy, 'statut' => [$a1 => 'present']]); $t('pas d’appel pour une séance annulée', str_contains($r, 'annulée'));
wp_set_current_user($cpt); $r = $envoyer('gvb_appel_sauver', 'gvb_appel_sauver', ['id' => $seance->id, 'statut' => [$a1 => 'absent']]); $t('Comptabilité ne peut pas saisir l’appel', str_starts_with($r, 'DIE:') && $wpdb->get_var("SELECT statut FROM $P WHERE seance_id = {$seance->id} AND etudiant_id = $a1") === 'present');
wp_set_current_user($rpL); $r = $envoyer('gvb_appel_sauver', 'gvb_appel_sauver', ['id' => $seance->id, 'statut' => [$a1 => 'absent']]); $t('Responsable du Lycée : séance de l’Université hors périmètre', str_contains($r, 'introuvable'), $r);
wp_set_current_user($sco);
// deuxième séance du même groupe, pour des statistiques
$s2h = $wpdb->get_row("SELECT * FROM $S WHERE cours_id = $c1 AND date_seance = '$hier' AND debut = '10:00:00'");
$envoyer('gvb_appel_sauver', 'gvb_appel_sauver', ['id' => $s2h->id, 'statut' => [$a1 => 'absent', $a2 => 'retard']]);
$st = gvb_stats_presence(['du' => '2000-01-01', 'au' => gvb_aujourdhui(), 'pole' => '', 'niveau' => '']);
$t('statistiques : Awa 1 présente, 1 absente → 50 %', isset($st[$a1]) && $st[$a1]['total'] === 2 && $st[$a1]['absent'] === 1 && $st[$a1]['taux'] === 50, json_encode($st[$a1] ?? null));
$t('statistiques : Moussa 1 excusée, 1 retard → 100 %', $st[$a2]['excuse'] === 1 && $st[$a2]['retard'] === 1 && $st[$a2]['taux'] === 100);
$h = gvb_heures_seances($e1, $an); $t('heures effectuées de l’enseignant = séances tenues (2 h + 1 h)', $h['effectuees'] == 3 && $h['planifiees'] >= 3, json_encode($h));
$envoyer('gvb_seance_tenue', 'gvb_seance_tenue', ['id' => $sc->id]); $t('« marquer tenue » refusé pour une séance à venir', $wpdb->get_var("SELECT statut FROM $S WHERE id = {$sc->id}") === 'planifiee');

$lignes[] = "\n## Écrans, périmètre, exports";
$_GET = ['sem' => gvb_lundi($hier)]; ob_start(); gvb_page_planning(); $h = ob_get_clean();
$t('emploi du temps : séances, formulaire, navigation', str_contains($h, 'Gestion de projet') && str_contains($h, 'Planifier une séance') && str_contains($h, 'Semaine suivante') && (str_contains($h, 'Faire l’appel') || str_contains($h, 'Voir l’appel')));
$_GET = ['sem' => gvb_lundi($hier), 'ens' => $e2]; ob_start(); gvb_page_planning(); $h = ob_get_clean(); $t('filtre par enseignant (le cours de 08:00–10:00 de Kouyaté disparaît)', str_contains($h, '09:00–10:00') && !str_contains($h, '08:00–10:00'));
wp_set_current_user($rpL); $_GET = ['sem' => gvb_lundi($hier)]; ob_start(); gvb_page_planning(); $h = ob_get_clean(); $t('Responsable du Lycée : voit seulement ses séances', str_contains($h, 'Mathématiques') && !str_contains($h, 'Gestion de projet'));
$_GET = ['id' => $seance->id]; $t('page d’appel d’un autre pôle : refusée', (function () { try { gvb_page_seance(); return false; } catch (Exception $e) { return str_starts_with($e->getMessage(), 'DIE:'); } })());
wp_set_current_user($sco); $_GET = ['id' => $seance->id]; ob_start(); gvb_page_seance(); $h = ob_get_clean(); $t('page d’appel : étudiants du groupe seulement', str_contains($h, 'DIARRA') && str_contains($h, 'TRAORÉ') && !str_contains($h, 'KEITA') && str_contains($h, 'Enregistrer l’appel'));
wp_set_current_user($cpt); ob_start(); gvb_page_seance(); $h = ob_get_clean(); $t('Comptabilité : appel en consultation seule', str_contains($h, 'Consultation seule') && !str_contains($h, 'Enregistrer l’appel'));
wp_set_current_user($sco); $_GET = []; ob_start(); gvb_page_salles(); $h = ob_get_clean(); $t('liste des salles', str_contains($h, 'Amphi A') && str_contains($h, 'Nouvelle salle'));
$_GET = ['du' => '2000-01-01']; ob_start(); gvb_page_absences(); $h = ob_get_clean(); $t('écran des absences', str_contains($h, 'DIARRA') && str_contains($h, '50 %'));
$_GET = ['id' => $a1]; ob_start(); gvb_page_etudiant(); $h = ob_get_clean(); $t('fiche étudiant : bloc de présence', str_contains($h, 'Présence') && str_contains($h, '50 %') && str_contains($h, 'Gestion de projet'));
$wpdb->update($N, ['nom' => '=HYPERLINK("http://x")'], ['id' => $e1]); $_GET = ['sem' => gvb_lundi($hier), 'pole' => '', '_wpnonce' => wp_create_nonce('gvb_export_planning')]; $_REQUEST = $_GET;
ob_start(); try { do_action('admin_post_gvb_export_planning'); } catch (Throwable $x) {} $csv = ob_get_clean(); $t('export de l’emploi du temps : formule neutralisée', str_contains($csv, "'=HYPERLINK") && str_contains($csv, 'Gestion de projet'));
$_GET = ['du' => '2000-01-01', '_wpnonce' => wp_create_nonce('gvb_export_absences')]; $_REQUEST = $_GET; ob_start(); try { do_action('admin_post_gvb_export_absences'); } catch (Throwable $x) {} $csv = ob_get_clean(); $t('export des absences', str_contains($csv, 'UNI-') || str_contains($csv, 'Diarra'));
wp_set_current_user($cpt); $_GET = ['_wpnonce' => wp_create_nonce('gvb_export_absences')]; $_REQUEST = $_GET; $t('export refusé à la Comptabilité', (function () { try { ob_start(); do_action('admin_post_gvb_export_absences'); ob_end_clean(); return false; } catch (Exception $e) { ob_end_clean(); return str_starts_with($e->getMessage(), 'DIE:'); } })());
wp_set_current_user($sco); $wpdb->update($Z, ['actif' => 1], ['id' => $sA]);
$r = $envoyer('gvb_salle_sauver', 'gvb_salle_sauver', ['id' => $sA, 'nom' => 'Amphi A', 'pole' => 'universite', 'capacite' => '120']);
$wpdb->update($Z, ['actif' => 1], ['id' => $sA]);
$wpdb->insert($S, ['cours_id' => $c1, 'enseignant_id' => $e1, 'salle_id' => $sA, 'annee' => $an, 'pole' => 'universite', 'niveau' => 'Licence 1', 'date_seance' => gmdate('Y-m-d', time() + 10 * 86400), 'debut' => '08:00:00', 'fin' => '09:00:00', 'cree_le' => $now]);
$r = $envoyer('gvb_salle_sauver', 'gvb_salle_sauver', ['id' => $sA, 'nom' => 'Amphi A', 'pole' => 'universite', 'capacite' => '120']);
$t('désactivation de salle refusée tant qu’une séance est à venir', str_contains($r, 'séances à venir') && (int) $wpdb->get_var("SELECT actif FROM $Z WHERE id = $sA") === 1, $r);

file_put_contents('/wordpress/wp-content/out/resultat.txt', implode("\n", $lignes) . "\n\n=== $ok réussis, $ko échecs ===\n");
