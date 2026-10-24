<?php
// Recette : préférences, résumés par e-mail, aide contextuelle, guide de première connexion (WordPress Playground).
define('GVB_ESSAI', true);
$lignes = []; $ok = 0; $ko = 0;
$t = function ($nom, $cond, $detail = '') use (&$lignes, &$ok, &$ko) { $cond ? $ok++ : $ko++; $lignes[] = ($cond ? '  ok  ' : ' ECHEC ') . $nom . (!$cond && $detail !== '' ? ' — ' . substr($detail, 0, 500) : ''); };
register_shutdown_function(function () { $e = error_get_last(); if ($e && in_array($e['type'], [E_ERROR, E_PARSE], true)) file_put_contents('/wordpress/wp-content/out/fatal.txt', print_r($e, true)); });
require '/wordpress/wp-load.php';
require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
do_action('init');
global $wpdb;
$an = gvb_reglages()['annee']; $now = gvb_now();
add_filter('wp_die_handler', fn() => function ($m) { throw new Exception('DIE:' . (is_wp_error($m) ? $m->get_error_message() : (string) $m)); });
add_filter('wp_redirect', function ($loc) { throw new Exception('REDIR:' . $loc); });
gvb_synchroniser_roles();
$envoyer = function ($action, $nonce, array $post) { $_POST = $post + ['_wpnonce' => wp_create_nonce($nonce)]; $_REQUEST = $_POST; $_SERVER['REQUEST_METHOD'] = 'POST'; try { do_action('admin_post_' . $action); return 'rien'; } catch (Throwable $e) { return urldecode($e->getMessage()); } };
$mk = function ($l, $r, $pole = '') { $id = wp_insert_user(['user_login' => $l, 'user_pass' => wp_generate_password(), 'role' => $r, 'user_email' => "$l@exemple.test", 'first_name' => ucfirst($l)]); if ($pole) update_user_meta($id, 'gv_pole', $pole); return $id; };
$dir = $mk('direction', 'gv_direction'); $sco = $mk('scolarite', 'gv_scolarite'); $cpt = $mk('compta', 'gv_comptabilite'); $rpL = $mk('resplyc', 'gv_responsable_pole', 'lycee'); $com = $mk('comm', 'gv_communication'); $nul = $mk('subscriber', 'subscriber');
wp_set_current_user($dir);
$dem = function ($nom, $niveau, $pole, $statut, $age) use ($wpdb, $an) { $id = gvb_creer_demande(['pole' => $pole, 'annee' => $an, 'niveau' => $niveau, 'formation_id' => 0, 'serie' => '', 'nom' => $nom, 'prenom' => 'X', 'naissance' => '2006-03-14', 'sexe' => 'F', 'nationalite' => '', 'telephone' => '+223 70 00 00 0' . rand(0, 9), 'email' => '', 'adresse' => '', 'parent_nom' => '', 'parent_telephone' => '', 'parent_email' => '', 'provenance' => '', 'message' => ''], 'agence'); $wpdb->update(gvb_table('demandes'), ['statut' => $statut, 'cree_le' => gmdate('Y-m-d H:i:s', time() - $age * 86400)], ['id' => $id]); return $id; };
$dem('Diarra', 'Licence 1', 'universite', 'nouvelle', 1); $dem('Traoré', 'Licence 1', 'universite', 'nouvelle', 2); $dem('Sy', '10e année', 'lycee', 'nouvelle', 1);
$mardi = '2026-10-27'; $lundi = '2026-10-26'; $samedi = '2026-10-31'; $GLOBALS['gvb_mails'] = [];
$mails_a = fn($a) => array_values(array_filter($GLOBALS['gvb_mails'], fn($m) => $m[0] === $a));
$q = fn() => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . gvb_table('notifs') . " WHERE type = 'resume'");

$lignes[] = '## Fréquences et valeurs par défaut';
$t('par défaut : aucun résumé', gvb_resume_frequence($sco) === 'aucun' && gvb_resume_frequence($dir) === 'aucun');
$r = $envoyer('gvb_resumes_sauver', 'gvb_resumes_sauver', ['actif' => '1', 'defaut' => ['gv_scolarite' => 'quotidien', 'gv_direction' => 'hebdo', 'gv_responsable_pole' => 'quotidien', 'gv_comptabilite' => 'pirate']]);
$t('la Direction fixe les valeurs par défaut (valeur invalide ramenée à « aucun »)', gvb_resume_frequence($sco) === 'quotidien' && gvb_resume_frequence($dir) === 'hebdo' && gvb_resume_frequence($cpt) === 'aucun' && gvb_resume_frequence($rpL) === 'quotidien', $r);
wp_set_current_user($sco); $r = $envoyer('gvb_resumes_sauver', 'gvb_resumes_sauver', ['actif' => '1', 'defaut' => ['gv_scolarite' => 'aucun']]); $t('la Scolarité ne peut pas modifier ces réglages', str_starts_with($r, 'DIE:') && gvb_resume_frequence($sco) === 'quotidien');
$r = $envoyer('gvb_prefs_sauver', 'gvb_prefs_sauver', ['frequence' => 'hebdo']); $t('un utilisateur choisit sa fréquence', gvb_resume_frequence($sco) === 'hebdo' && str_contains($r, 'gv_msg'), $r);
$r = $envoyer('gvb_prefs_sauver', 'gvb_prefs_sauver', ['frequence' => 'toutes-les-minutes']); $t('fréquence invalide refusée', str_contains($r, 'invalide') && gvb_resume_frequence($sco) === 'hebdo');
$envoyer('gvb_prefs_sauver', 'gvb_prefs_sauver', ['frequence' => 'quotidien']);
wp_set_current_user($nul); $r = $envoyer('gvb_prefs_sauver', 'gvb_prefs_sauver', ['frequence' => 'quotidien']); $t('un utilisateur sans accès métier ne peut pas régler de résumé', str_starts_with($r, 'DIE:'));
wp_set_current_user($sco); $r = $envoyer('gvb_prefs_sauver', 'mauvais-nonce', ['frequence' => 'aucun']); $t('jeton de sécurité invalide refusé', str_starts_with($r, 'DIE:') && gvb_resume_frequence($sco) === 'quotidien');

$lignes[] = "\n## Envoi des résumés";
wp_set_current_user($dir); $n = gvb_resumes_envoyer($mardi);
$t('mardi : le résumé quotidien part (Scolarité, Responsable du Lycée) ; l’hebdomadaire (Direction) attend lundi', $n === 2 && $q() === 2 && count($mails_a('scolarite@exemple.test')) === 1 && count($mails_a('resplyc@exemple.test')) === 1 && !$mails_a('direction@exemple.test') && !$mails_a('compta@exemple.test'), 'n=' . $n . ' ' . json_encode(array_column($GLOBALS['gvb_mails'], 0)));
$m = $mails_a('scolarite@exemple.test')[0];
$t('contenu : salutation, profil, tâches, lien d’accueil, pas de nom d’étudiant', str_contains($m[2], 'Bonjour Scolarite') && str_contains($m[2], 'Scolarité') && str_contains($m[2], 'Nouvelles demandes à prendre en charge') && str_contains($m[2], 'gv-admissions') && !str_contains($m[2], 'Diarra') && !str_contains($m[2], 'Traoré') && str_contains($m[1], 'résumé du jour'), $m[2]);
$t('pied du message : lien vers « Mes préférences » (et non un désabonnement global)', str_contains($m[2], 'gv-preferences') && !str_contains($m[2], 'desabonner'));
$m = $mails_a('resplyc@exemple.test')[0]; $t('Responsable du Lycée : seulement les demandes de son pôle (1)', str_contains($m[2], '1 — Nouvelles demandes') && str_contains($m[2], 'pôle Lycée'), $m[2]);
$t('second envoi le même jour : rien (idempotent)', gvb_resumes_envoyer($mardi) === 0 && $q() === 2);
$GLOBALS['gvb_mails'] = []; $n = gvb_resumes_envoyer($lundi);
$t('lundi : le résumé hebdomadaire de la Direction part, avec les indicateurs', $n >= 1 && count($mails_a('direction@exemple.test')) === 1 && str_contains($mails_a('direction@exemple.test')[0][2], 'INDICATEURS DE LA SEMAINE') && str_contains($mails_a('direction@exemple.test')[0][1], 'résumé de la semaine'), json_encode(array_column($GLOBALS['gvb_mails'], 0)));
$t('lundi : les quotidiens partent aussi', count($mails_a('scolarite@exemple.test')) === 1);
$GLOBALS['gvb_mails'] = []; $t('samedi : aucun envoi', gvb_resumes_envoyer($samedi) === 0 && !$GLOBALS['gvb_mails']);
// rien à signaler
update_user_meta($com, 'gvb_resume', 'quotidien'); $GLOBALS['gvb_mails'] = []; gvb_resumes_envoyer('2026-11-03');
$t('aucune tâche : pas de résumé vide (Communication)', !$mails_a('comm@exemple.test'));
// désactivation globale
update_option('gvb_resumes', ['actif' => 0, 'defauts' => ['gv_scolarite' => 'quotidien']], false); $GLOBALS['gvb_mails'] = [];
$t('résumés désactivés globalement : rien ne part', gvb_resumes_envoyer('2026-11-04') === 0 && !$GLOBALS['gvb_mails']);
update_option('gvb_resumes', ['actif' => 1, 'defauts' => ['gv_scolarite' => 'quotidien']], false);
update_user_meta($sco, 'gvb_resume', 'aucun'); $GLOBALS['gvb_mails'] = []; gvb_resumes_envoyer('2026-11-05'); $t('un utilisateur qui a choisi « aucun » ne reçoit rien', !$mails_a('scolarite@exemple.test'));
update_user_meta($sco, 'gvb_resume', 'quotidien');
$t('utilisateur sans accès métier : jamais de résumé', !array_filter($GLOBALS['gvb_mails'], fn($m) => $m[0] === 'subscriber@exemple.test'));
wp_set_current_user($sco); $GLOBALS['gvb_mails'] = [];
$r = $envoyer('gvb_prefs_action', 'gvb_prefs_action', ['op' => 'tester']); $t('« m’envoyer un résumé maintenant »', str_contains($r, 'gv_msg') && count($mails_a('scolarite@exemple.test')) === 1, $r);
$wpdb->query('UPDATE ' . gvb_table('demandes') . " SET statut = 'refusee'"); wp_set_current_user($com); $GLOBALS['gvb_mails'] = [];
$envoyer('gvb_prefs_action', 'gvb_prefs_action', ['op' => 'tester']); $t('résumé de test même sans tâche : « rien d’urgent »', str_contains($mails_a('comm@exemple.test')[0][2] ?? '', "Rien d'urgent"), json_encode($mails_a('comm@exemple.test')));

$lignes[] = "\n## Aide contextuelle";
$page = function ($uid, $slug) { wp_set_current_user($uid); $_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = ['page' => $slug]; ob_start(); gvb_debut('Titre', 'liste'); gvb_fin(); return ob_get_clean(); };
$h = $page($sco, 'gv-admissions'); $t('première visite : aide ouverte, avec le conseil propre au profil', str_contains($h, '<details class="gvb-aide" open>') && str_contains($h, 'À quoi sert cet écran') && str_contains($h, 'Pour votre profil') && str_contains($h, 'saisissez les demandes'), substr(strip_tags($h), 0, 300));
$h = $page($sco, 'gv-admissions'); $t('visites suivantes : aide repliée', str_contains($h, '<details class="gvb-aide">') && !str_contains($h, 'class="gvb-aide" open'));
$h = $page($rpL, 'gv-admissions'); $t('autre profil : autre conseil', str_contains($h, 'Vous décidez') && !str_contains($h, 'saisissez les demandes'));
$h = $page($cpt, 'gv-finances'); $t('Comptabilité : conseil pour encaisser', str_contains($h, 'Enregistrer un paiement'));
$h = $page($dir, 'gv-planning'); $t('aide sans conseil de profil : texte général seulement', str_contains($h, 'conflits') && !str_contains($h, 'Pour votre profil'));
$h = $page($dir, 'page-inconnue'); $t('page sans aide : aucun encart', !str_contains($h, 'gvb-aide'));
update_user_meta($dir, 'gvb_aide_off', 1); $h = $page($dir, 'gv-pilotage'); $t('aide masquée dans les préférences : aucun encart', !str_contains($h, 'gvb-aide')); delete_user_meta($dir, 'gvb_aide_off');
$noms = array_keys(gvb_aide_textes()); $pages = array_merge(array_keys(GVB_PAGES_CACHEES)); foreach (gvb_menu_sections() as $s) foreach ($s[3] as $i) $pages[] = $i[1];
$t('tous les écrans du menu ont un texte d’aide', !array_diff(array_filter(array_unique($pages), fn($p) => !in_array($p, GVB_EXTERNES, true)), $noms), json_encode(array_values(array_diff(array_filter(array_unique($pages), fn($p) => !in_array($p, GVB_EXTERNES, true)), $noms))));
$t('les textes d’aide ne contiennent pas de code HTML', !array_filter(array_map(fn($x) => $x[0] !== strip_tags($x[0]), array_values(gvb_aide_textes()))));

$lignes[] = "\n## Guide de première connexion";
$accueil = function ($uid) { wp_set_current_user($uid); $_GET = []; $_SERVER['REQUEST_METHOD'] = 'GET'; ob_start(); gvb_page_tableau(); return ob_get_clean(); };
$h = $accueil($sco); $t('première connexion : guide de la Scolarité sur l’accueil', str_contains($h, 'Bienvenue, voici l’essentiel') && str_contains($h, 'Admissions') && str_contains($h, 'Emploi du temps et appel'), strip_tags(substr($h, 0, 300)));
$h = $accueil($cpt); $t('Comptabilité : guide spécifique (paiement, impayés)', str_contains($h, 'Enregistrer un paiement') && str_contains($h, 'Impayés et relances') && !str_contains($h, 'Emploi du temps et appel'));
$h = $accueil($com); $t('Communication : guide spécifique (Facebook, actualités)', str_contains($h, 'Publications Facebook') && str_contains($h, 'Actualités du site'));
$h = $accueil($dir); $t('Direction : guide spécifique (pilotage, réglages, utilisateurs)', str_contains($h, 'Pilotage') && str_contains($h, 'Utilisateurs'));
$r = $envoyer('gvb_guide_ok', 'gvb_guide_ok', []); wp_set_current_user($cpt); $r = $envoyer('gvb_guide_ok', 'gvb_guide_ok', []);
$t('« j’ai compris » : le guide ne revient plus', str_contains($r, 'gv_msg') && !str_contains($accueil($cpt), 'Bienvenue, voici l’essentiel') && str_contains($accueil($sco), 'Bienvenue, voici l’essentiel'));
wp_set_current_user($cpt); $r = $envoyer('gvb_prefs_action', 'gvb_prefs_action', ['op' => 'guide']); $t('« revoir le guide » le fait réapparaître', str_contains($r, 'gv_msg') && str_contains($accueil($cpt), 'Bienvenue, voici l’essentiel'));
$t('le guide du profil est propre à chaque profil (étapes distinctes)', gvb_guide_etapes('gv_scolarite') !== gvb_guide_etapes('gv_comptabilite') && count(gvb_guide_etapes('gv_responsable_pole')) >= 3 && gvb_guide_etapes('inconnu') === []);
wp_set_current_user($nul); $r = $envoyer('gvb_guide_ok', 'gvb_guide_ok', []); $t('un utilisateur sans accès métier ne peut pas valider le guide', str_starts_with($r, 'DIE:'));

$lignes[] = "\n## Page « Mes préférences »";
wp_set_current_user($sco); $_GET = ['page' => 'gv-preferences']; ob_start(); gvb_page_preferences(); $h = ob_get_clean();
$t('page des préférences : fréquence, aide, résumé de test, guide', str_contains($h, 'Résumé par e-mail') && str_contains($h, 'Chaque matin') && str_contains($h, 'Masquer l’aide') && str_contains($h, 'M’envoyer un résumé maintenant') && str_contains($h, 'Revoir le guide'));
wp_set_current_user($nul); $t('page des préférences refusée sans accès métier', (function () { try { gvb_page_preferences(); return false; } catch (Exception $e) { return str_starts_with($e->getMessage(), 'DIE:'); } })());
wp_set_current_user($sco); $b = new WP_Admin_Bar(); $b->initialize(); $b->add_menus(); do_action_ref_array('admin_bar_menu', [&$b]); $t('menu du nom d’utilisateur : « Mes préférences »', isset($b->get_nodes()['gvb-prefs']) && $b->get_node('gvb-prefs')->parent === 'user-actions');
wp_set_current_user($dir); $_GET = []; ob_start(); gvb_page_reglages(); $h = ob_get_clean(); $t('Réglages : section « Résumés par e-mail » pour les 5 profils', str_contains($h, 'Résumés par e-mail') && str_contains($h, 'Activer les résumés'));
$envoyer('gvb_epingler', 'gvb_epingler', ['page' => 'gv-finances']); update_user_meta($dir, 'gvb_recents', [['x', 'y']]); $r = $envoyer('gvb_prefs_action', 'gvb_prefs_action', ['op' => 'epingles']); $t('effacer raccourcis et récents', !get_user_meta($dir, 'gvb_epingles', true) && !get_user_meta($dir, 'gvb_recents', true), $r);
$t('tâche planifiée des résumés enregistrée', (bool) wp_next_scheduled('gvb_resumes_cron'));

file_put_contents('/wordpress/wp-content/out/resultat.txt', implode("\n", $lignes) . "\n\n=== $ok réussis, $ko échecs ===\n");
