<?php
// Recette des espaces en ligne (portail), annonces et notifications. WordPress Playground, base jetable.
define('GVB_ESSAI', true);
$lignes = []; $ok = 0; $ko = 0;
$t = function ($nom, $cond, $detail = '') use (&$lignes, &$ok, &$ko) { $cond ? $ok++ : $ko++; $lignes[] = ($cond ? '  ok  ' : ' ECHEC ') . $nom . (!$cond && $detail !== '' ? ' — ' . substr($detail, 0, 400) : ''); };
register_shutdown_function(function () { $e = error_get_last(); if ($e && in_array($e['type'], [E_ERROR, E_PARSE], true)) file_put_contents('/wordpress/wp-content/out/fatal.txt', print_r($e, true)); });
require '/wordpress/wp-load.php';
do_action('init');
global $wpdb;
$_SERVER['REMOTE_ADDR'] = '203.0.113.50';
$E = gvb_table('etudiants'); $C = gvb_table('comptes'); $SE = gvb_table('sessions'); $J = gvb_table('jetons'); $NO = gvb_table('notifs'); $AN = gvb_table('annonces'); $S = gvb_table('seances'); $P = gvb_table('presences'); $PA = gvb_table('paiements'); $D = gvb_table('demandes');
add_filter('wp_die_handler', fn() => function ($m) { throw new Exception('DIE:' . (is_wp_error($m) ? $m->get_error_message() : (string) $m)); });
add_filter('wp_redirect', function ($loc) { throw new Exception('REDIR:' . $loc); });
gvb_synchroniser_roles();
$envoyer = function ($action, $nonce, array $post) { $_POST = $post + ['_wpnonce' => wp_create_nonce($nonce)]; $_REQUEST = $_POST; try { do_action('admin_post_' . $action); return 'rien'; } catch (Throwable $e) { return urldecode($e->getMessage()); } };
$mk = function ($l, $r, $pole = '') { $id = wp_insert_user(['user_login' => $l, 'user_pass' => wp_generate_password(), 'role' => $r, 'user_email' => "$l@exemple.test"]); if ($pole) update_user_meta($id, 'gv_pole', $pole); return $id; };
$dir = $mk('d', 'gv_direction'); $sco = $mk('s', 'gv_scolarite'); $cpt = $mk('c', 'gv_comptabilite'); $rpL = $mk('rl', 'gv_responsable_pole', 'lycee'); $com = $mk('com', 'gv_communication');
$an = gvb_reglages()['annee']; $hier = gmdate('Y-m-d', time() - 86400); $now = gvb_now();

// Visite du portail : retourne ['html' => , 'redir' => , 'cookie' => ]
$visite = function ($page, array $post = [], array $get = [], $cookie = null) {
    $_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET'; $_POST = $post; $_GET = $get; $_REQUEST = $post + $get; $_COOKIE = $cookie === null ? [] : [GVE_COOKIE => $cookie]; $GLOBALS['gve_cookies'] = [];
    ob_start(); $redir = null;
    try { gve_servir($page); } catch (Exception $e) { if (str_starts_with($e->getMessage(), 'REDIR:')) $redir = substr($e->getMessage(), 6); elseif ($e->getMessage() !== 'FIN') { ob_end_clean(); throw $e; } }
    $html = ob_get_clean();
    return ['html' => $html, 'redir' => $redir, 'cookie' => $GLOBALS['gve_cookies'][GVE_COOKIE] ?? null];
};
$jeton_de = function ($courriel_index = -1) { $m = $GLOBALS['gvb_mails'][$courriel_index < 0 ? count($GLOBALS['gvb_mails']) + $courriel_index : $courriel_index]; preg_match('/[?&]t=([A-Za-z0-9]{40})/', $m[2] ?? '', $x); return $x[1] ?? ''; };

// Données
wp_set_current_user($dir);
$accepter = function ($nom, $prenom, $niveau, $pole, $parent_email = '') use ($wpdb, $D) {
    $id = gvb_creer_demande(['pole' => $pole, 'annee' => '2026–2027', 'niveau' => $niveau, 'formation_id' => 0, 'serie' => '', 'nom' => $nom, 'prenom' => $prenom, 'naissance' => '2006-03-14', 'sexe' => 'F', 'nationalite' => '', 'telephone' => '', 'email' => '', 'adresse' => '', 'parent_nom' => 'Parent ' . $nom, 'parent_telephone' => '', 'parent_email' => $parent_email, 'provenance' => '', 'message' => ''], 'agence');
    $wpdb->update(gvb_table('pieces'), ['recue' => 1], ['demande_id' => $id]); $wpdb->update($D, ['statut' => 'acceptee'], ['id' => $id]); gvb_inscrire($id, 50000, 400000, '');
    return (int) $wpdb->get_var('SELECT id FROM ' . gvb_table('etudiants') . " WHERE demande_id = $id");
};
$a1 = $accepter('Diarra', 'Awa', 'Licence 1', 'universite', 'parent.awa@exemple.test'); $a2 = $accepter('Traoré', 'Moussa', 'Licence 1', 'universite', 'parent.moussa@exemple.test'); $a3 = $accepter('Sy', 'Fanta', '10e année', 'lycee', 'parent.fanta@exemple.test');
$wpdb->insert(gvb_table('cours'), ['pole' => 'universite', 'formation_id' => 0, 'niveau' => 'Licence 1', 'intitule' => 'Gestion de projet', 'volume' => 40, 'cree_le' => $now]); $c1 = (int) $wpdb->insert_id;
$wpdb->insert(gvb_table('cours'), ['pole' => 'lycee', 'formation_id' => 0, 'niveau' => '10e année', 'intitule' => 'Mathématiques', 'volume' => 40, 'cree_le' => $now]); $c2 = (int) $wpdb->insert_id;
$wpdb->insert(gvb_table('enseignants'), ['nom' => 'Kouyaté', 'prenom' => 'Ibrahim', 'email' => 'ibrahim@exemple.test', 'statut' => 'permanent', 'cree_le' => $now, 'modifie_le' => $now]); $en1 = (int) $wpdb->insert_id;
$wpdb->insert(gvb_table('enseignants'), ['nom' => 'Cissé', 'prenom' => 'Paul', 'email' => 'paul@exemple.test', 'statut' => 'vacataire', 'cree_le' => $now, 'modifie_le' => $now]); $en2 = (int) $wpdb->insert_id;
foreach ([[$c1, $en1], [$c2, $en2]] as [$c, $e]) $wpdb->insert(gvb_table('affectations'), ['cours_id' => $c, 'enseignant_id' => $e, 'annee' => $an, 'heures' => 40, 'cree_le' => $now]);
gvb_creer_seances($c1, $en1, 0, $hier, '08:00', '10:00'); gvb_creer_seances($c1, $en1, 0, $hier, '10:00', '12:00'); gvb_creer_seances($c2, $en2, 0, $hier, '08:00', '10:00');
$s1 = $wpdb->get_row("SELECT * FROM $S WHERE cours_id = $c1 AND debut = '08:00:00'"); $s2 = $wpdb->get_row("SELECT * FROM $S WHERE cours_id = $c1 AND debut = '10:00:00'"); $s3 = $wpdb->get_row("SELECT * FROM $S WHERE cours_id = $c2");
$GLOBALS['gvb_mails'] = [];

$lignes[] = '## Comptes et invitations';
$t('Direction, Scolarité, Communication, Responsable : droits « espaces »', user_can($dir, 'gv_espaces_modifier') && user_can($sco, 'gv_espaces_creer') && user_can($com, 'gv_espaces_creer') && !user_can($com, 'gv_espaces_modifier') && !user_can($cpt, 'gv_espaces_voir'));
$r = gvb_creer_compte('parent', 'Maman.Awa@Exemple.test', 'Mme Diarra', [$a1]);
$t('compte parent créé et rattaché', is_int($r) && count(gvb_etudiants_du_compte($r)) === 1, is_wp_error($r) ? $r->get_error_message() : '');
$p1 = $r;
$t('même e-mail, autre type refusé', is_wp_error(gvb_creer_compte('etudiant', 'maman.awa@exemple.test', 'x', [$a1])));
$t('e-mail invalide refusé', is_wp_error(gvb_creer_compte('parent', 'pas-un-mail', 'x', [$a1])));
$t('compte sans étudiant refusé', is_wp_error(gvb_creer_compte('parent', 'x@exemple.test', 'x', [])));
$t('étudiant inexistant refusé', is_wp_error(gvb_creer_compte('parent', 'x@exemple.test', 'x', [99999])));
$r2 = gvb_creer_compte('parent', 'maman.awa@exemple.test', 'Mme Diarra', [$a2]);
$t('même parent, 2e enfant : compte unique, 2 rattachements', $r2 === $p1 && count(gvb_etudiants_du_compte($p1)) === 2);
$wpdb->query("DELETE FROM " . gvb_table('comptes_etudiants') . " WHERE compte_id = $p1 AND etudiant_id = $a2");
$m = gvb_envoyer_invitation($p1);
$jeton = $jeton_de(); $mail = end($GLOBALS['gvb_mails']);
$t('invitation envoyée avec lien /espace/invitation', $m === true && $mail[0] === 'maman.awa@exemple.test' && str_contains($mail[2], '/espace/invitation/') && strlen($jeton) === 40, json_encode($mail));
$t('le jeton n’est stocké que haché', !$wpdb->get_var("SELECT COUNT(*) FROM $J WHERE jeton_hash = '$jeton'") && (int) $wpdb->get_var("SELECT COUNT(*) FROM $J WHERE jeton_hash = '" . hash('sha256', $jeton) . "'") === 1);

$lignes[] = "\n## Connexion et mots de passe";
$v = $visite('connexion'); $t('page de connexion', str_contains($v['html'], 'Se connecter') && str_contains($v['html'], 'noindex'));
$v = $visite('invitation', [], ['t' => $jeton]); $t('lien d’invitation valide : formulaire de mot de passe', str_contains($v['html'], 'Créer votre mot de passe') && str_contains($v['html'], 'maman.awa@exemple.test'));
$v = $visite('invitation', [], ['t' => str_repeat('a', 40)]); $t('jeton inconnu : lien expiré', str_contains($v['html'], 'plus valable'));
$v = $visite('reinitialiser', [], ['t' => $jeton]); $t('un jeton d’invitation ne sert pas pour une réinitialisation', str_contains($v['html'], 'plus valable'));
foreach ([['court1', 'court1'], ['sanschiffres', 'sanschiffres'], ['1234567890', '1234567890'], ['MotDePasse123', 'Different123']] as [$a, $b]) { $v = $visite('invitation', ['t' => $jeton, 'mdp' => $a, 'mdp2' => $b]); $t("mot de passe « $a » refusé", str_contains($v['html'], 'class="msg err"') && (string) $wpdb->get_var("SELECT mdp FROM $C WHERE id = $p1") === ''); }
$v = $visite('invitation', ['t' => $jeton, 'mdp' => 'MotDePasse123', 'mdp2' => 'MotDePasse123']);
$t('mot de passe enregistré (haché, jamais en clair)', str_contains($v['html'], 'enregistré') && $wpdb->get_var("SELECT mdp FROM $C WHERE id = $p1") !== 'MotDePasse123' && wp_check_password('MotDePasse123', $wpdb->get_var("SELECT mdp FROM $C WHERE id = $p1")));
$v = $visite('invitation', ['t' => $jeton, 'mdp' => 'AutreMotDePasse9', 'mdp2' => 'AutreMotDePasse9']); $t('le jeton est à usage unique', str_contains($v['html'], 'plus valable'));
$wpdb->insert($J, ['compte_id' => $p1, 'type' => 'reset', 'jeton_hash' => hash('sha256', str_repeat('b', 40)), 'expire' => gmdate('Y-m-d H:i:s', time() - 60), 'cree_le' => $now]);
$v = $visite('reinitialiser', [], ['t' => str_repeat('b', 40)]); $t('jeton expiré refusé', str_contains($v['html'], 'plus valable'));

$v = $visite('connexion', ['email' => 'maman.awa@exemple.test', 'mdp' => 'mauvais']); $t('mauvais mot de passe : message générique', str_contains($v['html'], 'incorrect') && !$v['redir']);
$v = $visite('connexion', ['email' => 'inconnu@exemple.test', 'mdp' => 'MotDePasse123']); $t('compte inconnu : même message (pas d’énumération)', str_contains($v['html'], 'incorrect'));
$v = $visite('connexion', ['email' => 'maman.awa@exemple.test', 'mdp' => 'MotDePasse123']);
$cookie = $v['cookie'];
$t('connexion réussie : cookie de session + redirection', $v['redir'] && str_ends_with(rtrim($v['redir'], '/'), '/espace') && strlen((string) $cookie) === 48, json_encode($v['redir']));
$t('la session n’est stockée que hachée', !$wpdb->get_var("SELECT COUNT(*) FROM $SE WHERE jeton_hash = '$cookie'") && (int) $wpdb->get_var("SELECT COUNT(*) FROM $SE WHERE jeton_hash = '" . hash('sha256', $cookie) . "'") === 1);
for ($i = 0; $i < 6; $i++) $v = $visite('connexion', ['email' => 'bloque@exemple.test', 'mdp' => 'x' . $i]);
$t('limite de tentatives (6e tentative bloquée)', str_contains($v['html'], 'Trop de tentatives'));
foreach (['', 'planning', 'presences', 'finances', 'annonces', 'recu', 'appel', 'cours'] as $pg) { $v = $visite($pg); $t("page « $pg » sans session : redirigée vers la connexion", (bool) $v['redir'] && str_contains($v['redir'], 'connexion') && !str_contains($v['html'], 'Awa')); }
$v = $visite('', [], [], 'x' . str_repeat('a', 47)); $t('cookie forgé refusé', (bool) $v['redir']);

$lignes[] = "\n## Espace famille : périmètre strict";
$v = $visite('', [], [], $cookie); $t('accueil : l’enfant rattaché, pas les autres', str_contains($v['html'], 'Awa') && !str_contains($v['html'], 'Moussa') && !str_contains($v['html'], 'Fanta') && str_contains($v['html'], 'Reste à payer'), strip_tags($v['html']));
$v = $visite('planning', [], ['e' => $a2], $cookie); $t('planning d’un étudiant non rattaché : introuvable', str_contains($v['html'], 'Étudiant introuvable') && !str_contains($v['html'], 'Gestion de projet'));
$v = $visite('presences', [], ['e' => $a2], $cookie); $t('présences d’un étudiant non rattaché : introuvables', !str_contains($v['html'], 'Taux de présence'));
$v = $visite('finances', [], ['e' => $a3], $cookie); $t('finances d’un étudiant non rattaché : introuvables', !str_contains($v['html'], 'Total dû'));
$v = $visite('planning', [], ['sem' => gvb_lundi($hier)], $cookie); $t('planning de l’enfant : séances du groupe, pas celles du Lycée', str_contains($v['html'], 'Gestion de projet') && str_contains($v['html'], '08:00–10:00') && !str_contains($v['html'], 'Mathématiques'), strip_tags($v['html']));
$v = $visite('finances', [], [], $cookie); $t('finances : échéancier visible', str_contains($v['html'], 'Total dû') && str_contains($v['html'], 'tranche 1/3') && str_contains($v['html'], 'Aucun paiement'));
$pid = gvb_enregistrer_paiement($a1, 60000, 'especes', gvb_aujourdhui(), '', ''); $pid2 = gvb_enregistrer_paiement($a2, 10000, 'especes', gvb_aujourdhui(), '', '');
$v = $visite('finances', [], [], $cookie); $t('paiement et lien vers le reçu', str_contains($v['html'], 'REC-' . gmdate('Y') . '-00001') && str_contains($v['html'], 'recu'));
$v = $visite('recu', [], ['id' => $pid, 'e' => $a1], $cookie); $t('reçu de son enfant', str_contains($v['html'], 'soixante mille francs CFA') && str_contains($v['html'], 'DIARRA'));
$v = $visite('recu', [], ['id' => $pid2], $cookie); $t('reçu d’un autre étudiant : introuvable', str_contains($v['html'], 'Reçu introuvable') && !str_contains($v['html'], 'TRAORÉ'));
$v = $visite('appel', [], [], $cookie); $t('un parent ne peut pas accéder à l’appel', str_contains($v['html'], 'Page introuvable'));
$v = $visite('cours', [], [], $cookie); $t('un parent ne peut pas accéder aux cours d’enseignant', str_contains($v['html'], 'Page introuvable'));

$lignes[] = "\n## Annonces ciblées";
wp_set_current_user($com);
$r = $envoyer('gvb_annonce_creer', 'gvb_annonce_creer', ['titre' => 'Rentrée Université', 'corps' => "Reprise des cours lundi.\nBonne rentrée.", 'audience' => 'familles', 'pole' => 'universite', 'niveau' => 'Licence 1', 'par_email' => '1']);
$t('annonce familles (Université, Licence 1) créée avec e-mails en file', str_contains($r, 'gv_msg') && (int) $wpdb->get_var("SELECT COUNT(*) FROM $NO WHERE type = 'annonce'") >= 1, $r);
$envoyer('gvb_annonce_creer', 'gvb_annonce_creer', ['titre' => 'Lycée seulement', 'corps' => 'Réunion', 'audience' => 'familles', 'pole' => 'lycee']);
$envoyer('gvb_annonce_creer', 'gvb_annonce_creer', ['titre' => 'Réunion pédagogique', 'corps' => 'Vendredi 15h', 'audience' => 'enseignants', 'pole' => '']);
$envoyer('gvb_annonce_creer', 'gvb_annonce_creer', ['titre' => 'Fermeture exceptionnelle', 'corps' => 'Lundi férié', 'audience' => 'tous', 'pole' => '']);
$v = $visite('annonces', [], [], $cookie);
$t('la famille voit les annonces qui la concernent seulement', str_contains($v['html'], 'Rentrée Université') && str_contains($v['html'], 'Fermeture exceptionnelle') && !str_contains($v['html'], 'Lycée seulement') && !str_contains($v['html'], 'Réunion pédagogique'));
$t('l’annonce par e-mail va au parent, pas aux autres niveaux', (int) $wpdb->get_var("SELECT COUNT(*) FROM $NO WHERE cle LIKE 'annonce:%' AND email = 'maman.awa@exemple.test' AND sujet = 'Rentrée Université'") === 1 && (int) $wpdb->get_var("SELECT COUNT(*) FROM $NO WHERE sujet = 'Rentrée Université' AND email = 'parent.fanta@exemple.test'") === 0);
$r = $envoyer('gvb_annonce_creer', 'gvb_annonce_creer', ['titre' => '', 'corps' => 'x', 'audience' => 'tous']); $t('annonce sans titre refusée', str_contains($r, 'obligatoires'));
wp_set_current_user($rpL); $envoyer('gvb_annonce_creer', 'gvb_annonce_creer', ['titre' => 'Annonce forcée au Lycée', 'corps' => 'x', 'audience' => 'tous', 'pole' => 'universite']);
$an2 = $wpdb->get_row("SELECT * FROM $AN WHERE titre = 'Annonce forcée au Lycée'"); $t('un Responsable de pôle ne publie que pour son pôle (forcé)', $an2 && $an2->pole === 'lycee' && $an2->audience === 'familles');
wp_set_current_user($cpt); $r = $envoyer('gvb_annonce_creer', 'gvb_annonce_creer', ['titre' => 'Intrus', 'corps' => 'x', 'audience' => 'tous']); $t('Comptabilité ne publie pas d’annonce', str_starts_with($r, 'DIE:'));

$lignes[] = "\n## Notifications automatiques";
wp_set_current_user($sco);
$GLOBALS['gvb_mails'] = [];
$q0 = (int) $wpdb->get_var("SELECT COUNT(*) FROM $NO WHERE type = 'absence'");
$r = $envoyer('gvb_appel_sauver', 'gvb_appel_sauver', ['id' => $s1->id, 'statut' => [$a1 => 'absent', $a2 => 'present']]);
$t('appel avec une absence : une notification pour le parent de l’absent', (int) $wpdb->get_var("SELECT COUNT(*) FROM $NO WHERE type = 'absence'") === $q0 + 1 && (int) $wpdb->get_var("SELECT COUNT(*) FROM $NO WHERE cle = 'absence:{$s1->id}:$a1:maman.awa@exemple.test'") === 1, $r);
$n = end($GLOBALS['gvb_mails']);
$t('e-mail d’absence envoyé immédiatement, avec lien de désabonnement', $n && $n[0] === 'maman.awa@exemple.test' && str_contains($n[1], 'Absence de Awa') && str_contains($n[2], 'désabonner') && str_contains($n[2], 'Gestion de projet'), json_encode($n));
$envoyer('gvb_appel_sauver', 'gvb_appel_sauver', ['id' => $s1->id, 'statut' => [$a1 => 'absent', $a2 => 'absent']]);
$t('appel corrigé : pas de doublon pour Awa, nouvelle notification pour Moussa (parent du dossier)', (int) $wpdb->get_var("SELECT COUNT(*) FROM $NO WHERE type = 'absence'") === $q0 + 2 && (int) $wpdb->get_var("SELECT COUNT(*) FROM $NO WHERE email = 'parent.moussa@exemple.test' AND type = 'absence'") === 1);
$t('statut de la notification : envoyée', $wpdb->get_var("SELECT statut FROM $NO WHERE cle = 'absence:{$s1->id}:$a1:maman.awa@exemple.test'") === 'envoye');
$GLOBALS['gvb_mails'] = [];
$envoyer('gvb_seance_annuler', 'gvb_seance_annuler', ['id' => $s2->id, 'motif' => 'Enseignant malade']);
$dest = array_column($GLOBALS['gvb_mails'], 0);
$t('séance annulée : groupe (parents) et enseignant prévenus, pas le Lycée', in_array('maman.awa@exemple.test', $dest, true) && in_array('parent.moussa@exemple.test', $dest, true) && in_array('ibrahim@exemple.test', $dest, true) && !in_array('parent.fanta@exemple.test', $dest, true), json_encode($dest));
$GLOBALS['gvb_mails'] = [];
$sx = $wpdb->get_row("SELECT * FROM $S WHERE id = {$s3->id}");
$envoyer('gvb_seance_reporter', 'gvb_seance_reporter', ['id' => $s3->id, 'date' => gmdate('Y-m-d', time() + 5 * 86400), 'debut' => '14:00', 'fin' => '16:00']);
$t('séance reportée : le groupe du Lycée et l’enseignant sont prévenus', in_array('parent.fanta@exemple.test', array_column($GLOBALS['gvb_mails'], 0), true) && in_array('paul@exemple.test', array_column($GLOBALS['gvb_mails'], 0), true), json_encode(array_column($GLOBALS['gvb_mails'], 0)));
$GLOBALS['gvb_mails'] = [];
wp_set_current_user($cpt); $envoyer('gvb_paiement_saisir', 'gvb_paiement_saisir', ['id' => $a1, 'montant' => '20000', 'mode' => 'mobile', 'reference' => 'TX1', 'date' => gvb_aujourdhui(), 'jeton' => 'tok-espace-1']);
$t('paiement : avis de reçu au parent', in_array('maman.awa@exemple.test', array_column($GLOBALS['gvb_mails'], 0), true) && str_contains(end($GLOBALS['gvb_mails'])[1], 'Reçu de paiement'));
// désabonnement
$lien = gvb_desabonnement_lien('maman.awa@exemple.test'); parse_str((string) wp_parse_url($lien, PHP_URL_QUERY), $q);
$v = $visite('desabonner', [], ['e' => $q['e'], 's' . '' => 'faux']); $t('lien de désabonnement falsifié refusé', str_contains($v['html'], 'pas valide') && !gvb_est_desinscrit('maman.awa@exemple.test'));
$v = $visite('desabonner', [], $q); $t('désabonnement par lien signé', str_contains($v['html'], 'ne recevrez plus') && gvb_est_desinscrit('maman.awa@exemple.test') && (int) $wpdb->get_var("SELECT notif_ok FROM $C WHERE id = $p1") === 0);
$GLOBALS['gvb_mails'] = []; wp_set_current_user($sco);
$envoyer('gvb_appel_sauver', 'gvb_appel_sauver', ['id' => $s1->id, 'statut' => [$a1 => 'absent']]); gvb_creer_seances($c1, $en1, 0, gmdate('Y-m-d', time() - 2 * 86400), '08:00', '09:00');
$s4 = $wpdb->get_row("SELECT * FROM $S WHERE date_seance = '" . gmdate('Y-m-d', time() - 2 * 86400) . "' AND cours_id = $c1"); $envoyer('gvb_appel_sauver', 'gvb_appel_sauver', ['id' => $s4->id, 'statut' => [$a1 => 'absent']]);
$t('après désabonnement : plus aucune notification à cette adresse', !in_array('maman.awa@exemple.test', array_column($GLOBALS['gvb_mails'], 0), true) && (int) $wpdb->get_var("SELECT COUNT(*) FROM $NO WHERE cle = 'absence:{$s4->id}:$a1:maman.awa@exemple.test'") === 0);
// réglages et échecs
wp_set_current_user($dir); $envoyer('gvb_notifs_sauver', 'gvb_notifs_sauver', ['seance' => '1']);
$q1 = (int) $wpdb->get_var("SELECT COUNT(*) FROM $NO"); wp_set_current_user($sco); gvb_creer_seances($c1, $en1, 0, gmdate('Y-m-d', time() - 3 * 86400), '08:00', '09:00'); $s5 = $wpdb->get_row("SELECT * FROM $S WHERE date_seance = '" . gmdate('Y-m-d', time() - 3 * 86400) . "' AND cours_id = $c1");
$envoyer('gvb_appel_sauver', 'gvb_appel_sauver', ['id' => $s5->id, 'statut' => [$a2 => 'absent']]); $t('notification d’absence désactivée dans les réglages : rien en file', (int) $wpdb->get_var("SELECT COUNT(*) FROM $NO") === $q1);
wp_set_current_user($dir); $envoyer('gvb_notifs_sauver', 'gvb_notifs_sauver', ['absence' => '1', 'seance' => '1', 'paiement' => '1']);
add_filter('gvb_mail_echec', fn($f, $a) => $a === 'echec@exemple.test', 10, 2);
gvb_notif_ajouter('test:echec', 'echec@exemple.test', 'Sujet', 'Corps', 'test');
for ($i = 0; $i < 3; $i++) gvb_notifs_traiter(10);
$n = $wpdb->get_row("SELECT * FROM $NO WHERE cle = 'test:echec'"); $t('envoi en échec : 3 tentatives puis statut « échec »', $n->statut === 'echec' && (int) $n->tentatives === 3);
$envoyer('gvb_notifs_action', 'gvb_notifs_action', ['op' => 'reessayer']); $t('« réessayer les échecs » remet en file', $wpdb->get_var("SELECT statut FROM $NO WHERE cle = 'test:echec'") === 'attente');
$t('notification en double (même clé) ignorée', gvb_notif_ajouter('test:echec', 'echec@exemple.test', 'x', 'x', 'test') === false);

$lignes[] = "\n## Mot de passe oublié";
$GLOBALS['gvb_mails'] = []; $v = $visite('oubli', ['email' => 'inconnu@exemple.test']); $t('compte inconnu : même message, aucun e-mail', str_contains($v['html'], 'Si cette adresse correspond') && !$GLOBALS['gvb_mails']);
$v = $visite('oubli', ['email' => 'maman.awa@exemple.test']); $t('compte connu : même message, e-mail de réinitialisation', str_contains($v['html'], 'Si cette adresse correspond') && count($GLOBALS['gvb_mails']) === 1 && str_contains($GLOBALS['gvb_mails'][0][2], '/espace/reinitialiser/'));
$jr = $jeton_de(); $v = $visite('reinitialiser', ['t' => $jr, 'mdp' => 'NouveauMotDePasse7', 'mdp2' => 'NouveauMotDePasse7']); $t('réinitialisation : nouveau mot de passe, sessions fermées', str_contains($v['html'], 'enregistré') && !(int) $wpdb->get_var("SELECT COUNT(*) FROM $SE WHERE compte_id = $p1"));
$v = $visite('', [], [], $cookie); $t('l’ancienne session ne fonctionne plus', (bool) $v['redir']);
$v = $visite('connexion', ['email' => 'maman.awa@exemple.test', 'mdp' => 'MotDePasse123']); $t('ancien mot de passe refusé', str_contains($v['html'], 'incorrect'));
$v = $visite('connexion', ['email' => 'MAMAN.awa@exemple.test', 'mdp' => 'NouveauMotDePasse7']); $cookie = $v['cookie']; $t('nouveau mot de passe accepté (e-mail insensible à la casse)', (bool) $cookie);

$lignes[] = "\n## Déconnexion et désactivation";
$v = $visite('deconnexion', [], [], $cookie); $t('déconnexion par simple lien (GET) sans effet', (int) $wpdb->get_var("SELECT COUNT(*) FROM $SE WHERE compte_id = $p1") === 1);
$v = $visite('deconnexion', ['csrf' => 'faux'], [], $cookie); $t('déconnexion sans jeton CSRF valide : sans effet', (int) $wpdb->get_var("SELECT COUNT(*) FROM $SE WHERE compte_id = $p1") === 1);
$csrf = hash_hmac('sha256', hash('sha256', $cookie), wp_salt('auth'));
$v = $visite('deconnexion', ['csrf' => $csrf], [], $cookie); $t('déconnexion avec jeton CSRF : session supprimée, cookie effacé', !(int) $wpdb->get_var("SELECT COUNT(*) FROM $SE WHERE compte_id = $p1") && $v['cookie'] === '');
$v = $visite('connexion', ['email' => 'maman.awa@exemple.test', 'mdp' => 'NouveauMotDePasse7']); $cookie = $v['cookie'];
wp_set_current_user($sco); $envoyer('gvb_compte_action', 'gvb_compte_action', ['id' => $p1, 'op' => 'bascule']);
$v = $visite('', [], [], $cookie); $t('compte désactivé : session coupée immédiatement', (bool) $v['redir']);
$v = $visite('connexion', ['email' => 'maman.awa@exemple.test', 'mdp' => 'NouveauMotDePasse7']); $t('compte désactivé : connexion impossible', str_contains($v['html'], 'incorrect'));
$envoyer('gvb_compte_action', 'gvb_compte_action', ['id' => $p1, 'op' => 'bascule']);
wp_set_current_user($cpt); $r = $envoyer('gvb_compte_action', 'gvb_compte_action', ['id' => $p1, 'op' => 'bascule']); $t('Comptabilité ne peut pas désactiver un compte', str_starts_with($r, 'DIE:'));

$lignes[] = "\n## Espace enseignant";
wp_set_current_user($sco); $r = $envoyer('gvb_compte_creer', 'gvb_compte_creer', ['type' => 'enseignant', 'enseignant' => $en1, 'email' => 'ibrahim@exemple.test']);
$pt = (int) $wpdb->get_var("SELECT id FROM $C WHERE enseignant_id = $en1"); $t('compte enseignant créé depuis le back-office', $pt > 0 && str_contains($r, 'gv_msg'), $r);
$jt = $jeton_de(); $visite('invitation', ['t' => $jt, 'mdp' => 'ProfPassword2026', 'mdp2' => 'ProfPassword2026']);
$v = $visite('connexion', ['email' => 'ibrahim@exemple.test', 'mdp' => 'ProfPassword2026']); $ck = $v['cookie']; $t('connexion de l’enseignant', strlen((string) $ck) === 48);
wp_set_current_user($dir); $wpdb->insert($S, ['cours_id' => $c1, 'enseignant_id' => $en1, 'salle_id' => 0, 'annee' => $an, 'pole' => 'universite', 'niveau' => 'Licence 1', 'date_seance' => gvb_aujourdhui(), 'debut' => '16:00:00', 'fin' => '17:00:00', 'cree_le' => $now]); $s6 = (int) $wpdb->insert_id;
$v = $visite('', [], [], $ck); $t('accueil enseignant : séance du jour avec lien d’appel', str_contains($v['html'], 'Aujourd’hui') && str_contains($v['html'], 'Gestion de projet') && str_contains($v['html'], 'Faire l’appel'));
$v = $visite('planning', [], ['sem' => gvb_lundi($hier)], $ck); $t('planning de l’enseignant : ses séances, pas celles du collègue', str_contains($v['html'], 'Gestion de projet') && !str_contains($v['html'], 'Mathématiques'));
$v = $visite('cours', [], [], $ck); $t('« mes cours » : heures', str_contains($v['html'], 'Heures affectées') && str_contains($v['html'], 'Gestion de projet'));
$v = $visite('appel', [], ['id' => $s3->id], $ck); $t('l’appel d’une séance d’un autre enseignant : introuvable', str_contains($v['html'], 'Séance introuvable') && !str_contains($v['html'], 'FANTA'));
$v = $visite('appel', [], ['id' => $s6], $ck); $t('page d’appel : étudiants du groupe, sans coordonnées', str_contains($v['html'], 'DIARRA') && str_contains($v['html'], 'TRAORÉ') && !str_contains($v['html'], 'parent.awa') && !str_contains($v['html'], '@exemple.test'));
$csrf = hash_hmac('sha256', hash('sha256', $ck), wp_salt('auth'));
$v = $visite('appel', ['id' => $s6, 'csrf' => 'faux', 'statut' => [$a1 => 'absent']], [], $ck); $t('appel sans jeton CSRF refusé', str_contains($v['html'], 'Session expirée') && !(int) $wpdb->get_var("SELECT COUNT(*) FROM $P WHERE seance_id = $s6"));
$GLOBALS['gvb_mails'] = [];
$v = $visite('appel', ['id' => $s6, 'csrf' => $csrf, 'statut' => [$a1 => 'absent', $a2 => 'present'], 'note' => [$a1 => 'Prévenu']], [], $ck);
$t('l’enseignant enregistre l’appel : séance « tenue », absence enregistrée', str_contains($v['html'], 'Appel enregistré') && $wpdb->get_var("SELECT statut FROM $S WHERE id = $s6") === 'tenue' && $wpdb->get_var("SELECT statut FROM $P WHERE seance_id = $s6 AND etudiant_id = $a1") === 'absent');
$t('parent désabonné : aucune notification d’absence pour cet appel', (int) $wpdb->get_var("SELECT COUNT(*) FROM $NO WHERE cle LIKE 'absence:$s6:$a1:%'") === 0);
$v = $visite('appel', ['id' => $s6, 'csrf' => $csrf, 'statut' => [$a3 => 'absent']], [], $ck); $t('un étudiant d’un autre groupe est refusé', str_contains($v['html'], 'ne fait pas partie') || str_contains($v['html'], 'class="msg err"'));
$wpdb->update($S, ['date_seance' => gmdate('Y-m-d', time() - 20 * 86400)], ['id' => $s6]);
$v = $visite('appel', ['id' => $s6, 'csrf' => $csrf, 'statut' => [$a1 => 'present']], [], $ck); $t('appel modifiable seulement 14 jours', str_contains($v['html'], '14 jours') && $wpdb->get_var("SELECT statut FROM $P WHERE seance_id = $s6 AND etudiant_id = $a1") === 'absent');
$v = $visite('annonces', [], [], $ck); $t('l’enseignant voit les annonces enseignants et générales, pas celles des familles', str_contains($v['html'], 'Réunion pédagogique') && str_contains($v['html'], 'Fermeture exceptionnelle') && !str_contains($v['html'], 'Rentrée Université'));
$v = $visite('presences', [], [], $ck); $t('l’enseignant n’a pas accès aux pages famille', str_contains($v['html'], 'Page introuvable'));
$v = $visite('finances', [], [], $ck); $t('… ni aux finances', str_contains($v['html'], 'Page introuvable') && !str_contains($v['html'], 'Total dû'));

$lignes[] = "\n## Écrans du back-office";
wp_set_current_user($dir); $_GET = []; ob_start(); gvb_page_espaces(); $h = ob_get_clean(); $t('liste des comptes', str_contains($h, 'maman.awa@exemple.test') && str_contains($h, 'ibrahim@exemple.test') && str_contains($h, 'Inviter'));
ob_start(); gvb_page_annonces(); $h = ob_get_clean(); $t('liste des annonces', str_contains($h, 'Rentrée Université') && str_contains($h, 'Nouvelle annonce'));
ob_start(); gvb_page_notifs(); $h = ob_get_clean(); $t('écran des notifications', str_contains($h, 'En attente') && str_contains($h, 'Envoyées') && str_contains($h, 'Absence de Awa'));
$_GET = ['id' => $a1]; ob_start(); gvb_page_etudiant(); $h = ob_get_clean(); $t('fiche étudiant : bloc « Espace en ligne »', str_contains($h, 'Espace en ligne') && str_contains($h, 'maman.awa@exemple.test'));
$_GET = ['id' => $en1]; ob_start(); gvb_page_enseignant(); $h = ob_get_clean(); $t('fiche enseignant : bloc « Espace enseignant »', str_contains($h, 'Espace enseignant') && str_contains($h, 'ibrahim@exemple.test'));
wp_set_current_user($rpL); $_GET = []; ob_start(); gvb_page_espaces(); $h = ob_get_clean(); $t('Responsable du Lycée : ne voit que les comptes de son pôle', !str_contains($h, 'maman.awa@exemple.test'));

file_put_contents('/wordpress/wp-content/out/resultat.txt', implode("\n", $lignes) . "\n\n=== $ok réussis, $ko échecs ===\n");
