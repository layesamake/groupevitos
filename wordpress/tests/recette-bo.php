<?php
// Recette du back-office Groupe VITO S. (WordPress Playground, base jetable).
define('GVB_ESSAI', true);
$lignes = []; $ok = 0; $ko = 0;
$t = function ($nom, $cond, $detail = '') use (&$lignes, &$ok, &$ko) { $cond ? $ok++ : $ko++; $lignes[] = ($cond ? '  ok  ' : ' ECHEC ') . $nom . (!$cond && $detail !== '' ? ' — ' . $detail : ''); };
register_shutdown_function(function () { $e = error_get_last(); if ($e && in_array($e['type'], [E_ERROR, E_PARSE], true)) file_put_contents('/wordpress/wp-content/out/fatal.txt', print_r($e, true)); });
require '/wordpress/wp-load.php';
do_action('init');
global $wpdb;
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

// Simule un envoi de formulaire d'administration : retourne [redirection, message d'erreur de wp_die]
add_filter('wp_die_handler', fn() => function ($m) { throw new Exception('DIE:' . (is_wp_error($m) ? $m->get_error_message() : (string) $m)); });
add_filter('wp_redirect', function ($loc) { throw new Exception('REDIR:' . $loc); });
$envoyer = function ($action, $nonce, array $post) {
    $_POST = $post + ['_wpnonce' => wp_create_nonce($nonce)]; $_REQUEST = $_POST; $_GET = [];
    try { do_action('admin_post_' . $action); return 'rien'; } catch (Exception $e) { return $e->getMessage(); }
};
$msg = fn($r) => (string) (parse_str((string) parse_url(substr($r, 6), PHP_URL_QUERY), $q) ?: '') . ($q['gv_err'] ?? $q['gv_msg'] ?? '');
$moi = function ($login, $role, $pole = '') {
    $id = wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(), 'role' => $role, 'user_email' => "$login@exemple.test"]);
    if ($pole) update_user_meta($id, 'gv_pole', $pole);
    return $id;
};

$lignes[] = '## Profils et droits';
$t('schéma créé', (bool) $wpdb->get_var("SHOW TABLES LIKE '" . gvb_table('demandes') . "'") || (bool) $wpdb->get_var('SELECT COUNT(*) FROM ' . gvb_table('demandes')) === false);
gvb_synchroniser_roles();
foreach (array_keys(GVB_PROFILS) as $r) $t("profil $r créé", (bool) get_role($r));
$dir = $moi('direction', 'gv_direction'); $sco = $moi('scolarite', 'gv_scolarite'); $cpt = $moi('compta', 'gv_comptabilite'); $rpU = $moi('resp_uni', 'gv_responsable_pole', 'universite'); $rpL = $moi('resp_lyc', 'gv_responsable_pole', 'lycee'); $rpX = $moi('resp_sans', 'gv_responsable_pole');
$t('scolarité : crée et modifie, ne décide pas', user_can($sco, 'gv_admissions_creer') && user_can($sco, 'gv_admissions_modifier') && !user_can($sco, 'gv_admissions_valider'));
$t('comptabilité : lecture seule des admissions, pas de création', user_can($cpt, 'gv_admissions_voir') && !user_can($cpt, 'gv_admissions_creer') && !user_can($cpt, 'gv_inscriptions_creer'));
$t('responsable de pôle : décide, n’inscrit pas', user_can($rpU, 'gv_admissions_valider') && !user_can($rpU, 'gv_inscriptions_creer'));
$t('aucun profil métier n’a de droit WordPress (contenu, plugins)', !user_can($sco, 'edit_posts') && !user_can($sco, 'manage_options') && !user_can($cpt, 'upload_files') && !user_can($dir, 'manage_options') && !user_can($dir, 'install_plugins'));
$t('direction : tous les droits métier et gestion des comptes', user_can($dir, 'gv_admissions_valider') && user_can($dir, 'gv_parametres_modifier') && user_can($dir, 'create_users'));
$t('direction : accès aux candidatures (CV)', user_can($dir, 'edit_candidatures') && !user_can($sco, 'edit_candidatures'));
$admin = $moi('chef', 'administrator');
$t('la direction ne peut pas modifier un administrateur', !user_can($dir, 'edit_user', $admin) && user_can($dir, 'edit_user', $sco));
wp_set_current_user($dir); $roles = apply_filters('editable_roles', wp_roles()->roles);
$t('la direction ne peut attribuer que des profils métier', !isset($roles['administrator']) && isset($roles['gv_scolarite']));

$lignes[] = "\n## Saisie d’une demande";
$base = ['pole' => 'universite', 'annee' => '2026–2027', 'niveau' => 'Licence 1', 'nom' => 'Diarra', 'prenom' => 'Awa', 'naissance' => '2006-03-14', 'telephone' => '+223 70 11 22 33', 'email' => 'awa@exemple.test'];
wp_set_current_user($sco);
$r = $envoyer('gvb_demande_creer', 'gvb_demande_creer', $base);
$id = (int) $wpdb->get_var('SELECT MAX(id) FROM ' . gvb_table('demandes'));
$t('création par la scolarité', str_contains($r, 'REDIR:') && $id > 0 && str_contains($r, 'gv_msg'), $r);
$d = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . gvb_table('demandes') . ' WHERE id = %d', $id));
$t('code attribué, statut « nouvelle », pièces du pôle générées', preg_match('/^ADM-2026-\d{5}$/', $d->code) && $d->statut === 'nouvelle' && (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . gvb_table('pieces') . " WHERE demande_id = $id") === count(GVB_PIECES['universite']), $d->code);
$t('historique alimenté', (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . gvb_table('historique') . " WHERE demande_id = $id") >= 1);
$r = $envoyer('gvb_demande_creer', 'gvb_demande_creer', ['nom' => '', 'prenom' => 'X', 'pole' => 'lycee'] + $base);
$t('nom vide : refusé', str_contains($r, 'gv_err'), $r);
$r = $envoyer('gvb_demande_creer', 'gvb_demande_creer', ['pole' => 'inconnu'] + $base); $t('pôle inconnu : refusé', str_contains($r, 'gv_err'), $r);
$r = $envoyer('gvb_demande_creer', 'gvb_demande_creer', ['naissance' => '2999-01-01', 'nom' => 'Futur'] + $base); $t('naissance dans le futur : refusée', str_contains($r, 'gv_err'), $r);
$r = $envoyer('gvb_demande_creer', 'mauvais-jeton', $base);
$t('jeton de sécurité invalide : refusé', str_contains($r, 'DIE:') || $r === 'rien', $r);
$n0 = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . gvb_table('demandes'));
wp_set_current_user($cpt); $r = $envoyer('gvb_demande_creer', 'gvb_demande_creer', ['nom' => 'Compta'] + $base);
$t('comptabilité : création refusée côté serveur', str_starts_with($r, 'DIE:') && (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . gvb_table('demandes')) === $n0, $r);
wp_set_current_user($sco);
$r = $envoyer('gvb_demande_creer', 'gvb_demande_creer', ['nom' => 'DIARRA', 'prenom' => 'awa'] + $base);
$id2 = (int) $wpdb->get_var('SELECT MAX(id) FROM ' . gvb_table('demandes'));
$t('doublon (même identité) détecté et lié', (int) $wpdb->get_var("SELECT doublon_de FROM " . gvb_table('demandes') . " WHERE id = $id2") === $id && $id2 !== $id);

$lignes[] = "\n## Pièces, décisions";
$p = $wpdb->get_col('SELECT id FROM ' . gvb_table('pieces') . " WHERE demande_id = $id ORDER BY id");
foreach (array_slice($p, 0, 2) as $pid) $envoyer('gvb_piece_basculer', 'gvb_piece_' . $pid, ['id' => $id, 'piece' => $pid]);
$t('pièces reçues cochées', (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . gvb_table('pieces') . " WHERE demande_id = $id AND recue = 1") === 2);
$r = $envoyer('gvb_demande_statut', 'gvb_demande_statut', ['id' => $id, 'statut' => 'acceptee']);
$t('scolarité ne peut pas décider', str_starts_with($r, 'DIE:') && $wpdb->get_var('SELECT statut FROM ' . gvb_table('demandes') . " WHERE id = $id") === 'nouvelle', $r);
wp_set_current_user($rpL);
$r = $envoyer('gvb_demande_statut', 'gvb_demande_statut', ['id' => $id, 'statut' => 'acceptee']);
$t('responsable d’un autre pôle : demande hors périmètre', str_contains($r, 'gv_err') && $wpdb->get_var('SELECT statut FROM ' . gvb_table('demandes') . " WHERE id = $id") === 'nouvelle', $r);
wp_set_current_user($rpX); $t('responsable sans pôle assigné : ne voit rien', gvb_pole_utilisateur() === '__aucun__' && gvb_demande($id) === null && count(gvb_requete_demandes(['annee' => '', 'pole' => '', 'statut' => '', 'q' => ''])) === 0);
wp_set_current_user($rpU);
$t('responsable du pôle : voit sa demande', gvb_demande($id) !== null);
$r = $envoyer('gvb_demande_statut', 'gvb_demande_statut', ['id' => $id, 'statut' => 'refusee', 'motif' => '']);
$t('refus sans motif : rejeté', str_contains($r, 'gv_err') && $wpdb->get_var('SELECT statut FROM ' . gvb_table('demandes') . " WHERE id = $id") === 'nouvelle', $r);
$r = $envoyer('gvb_demande_statut', 'gvb_demande_statut', ['id' => $id, 'statut' => 'inscrite']);
$t('saut direct vers « inscrite » interdit', str_contains($r, 'gv_err'), $r);
$envoyer('gvb_demande_statut', 'gvb_demande_statut', ['id' => $id, 'statut' => 'acceptee']);
$t('acceptation par le responsable du pôle + e-mail au candidat', $wpdb->get_var('SELECT statut FROM ' . gvb_table('demandes') . " WHERE id = $id") === 'acceptee' && !empty($GLOBALS['gvb_mails']) && end($GLOBALS['gvb_mails'])[0] === 'awa@exemple.test');

$lignes[] = "\n## Inscription";
wp_set_current_user($rpU); $r = $envoyer('gvb_inscrire', 'gvb_inscrire', ['id' => $id, 'frais_inscription' => 50000, 'frais_scolarite' => 400000, 'derogation' => 'x']);
$t('le responsable de pôle ne peut pas inscrire', str_starts_with($r, 'DIE:'), $r);
wp_set_current_user($sco);
$r = $envoyer('gvb_inscrire', 'gvb_inscrire', ['id' => $id, 'frais_inscription' => 50000, 'frais_scolarite' => 400000]);
$t('pièces manquantes sans dérogation : inscription refusée', str_contains($r, 'gv_err') && $wpdb->get_var('SELECT statut FROM ' . gvb_table('demandes') . " WHERE id = $id") === 'acceptee', $r);
$r = $envoyer('gvb_inscrire', 'gvb_inscrire', ['id' => $id, 'frais_inscription' => 50000, 'frais_scolarite' => 400000, 'derogation' => 'Acte à fournir avant octobre']);
$ins = $wpdb->get_row('SELECT * FROM ' . gvb_table('inscriptions') . " WHERE demande_id = $id");
$t('inscription avec dérogation : matricule UNI-2026-0001', $ins && $ins->matricule === 'UNI-2026-0001' && $wpdb->get_var('SELECT statut FROM ' . gvb_table('demandes') . " WHERE id = $id") === 'inscrite', $r);
$r = $envoyer('gvb_inscrire', 'gvb_inscrire', ['id' => $id, 'frais_inscription' => 1, 'frais_scolarite' => 1, 'derogation' => 'bis']);
$t('seconde inscription du même candidat refusée', str_contains($r, 'gv_err') && (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . gvb_table('inscriptions') . " WHERE demande_id = $id") === 1, $r);
// deuxième candidat : numérotation continue, pièces toutes reçues
$envoyer('gvb_demande_creer', 'gvb_demande_creer', ['nom' => 'Keita', 'prenom' => 'Sidi', 'naissance' => '2005-01-02', 'email' => 'sidi@exemple.test', 'telephone' => '+223 76 00 00 01'] + $base);
$id3 = (int) $wpdb->get_var('SELECT MAX(id) FROM ' . gvb_table('demandes'));
foreach ($wpdb->get_col('SELECT id FROM ' . gvb_table('pieces') . " WHERE demande_id = $id3") as $pid) $wpdb->update(gvb_table('pieces'), ['recue' => 1], ['id' => $pid]);
$wpdb->update(gvb_table('demandes'), ['statut' => 'acceptee'], ['id' => $id3]);
$m = gvb_inscrire($id3, 50000, 400000);
$t('numérotation continue, sans dérogation si dossier complet', $m === 'UNI-2026-0002', is_wp_error($m) ? $m->get_error_message() : $m);
$wpdb->update(gvb_table('demandes'), ['statut' => 'nouvelle'], ['id' => $id2]);
$t('une demande non acceptée ne peut pas être inscrite', is_wp_error(gvb_inscrire($id2, 1, 1, 'x')));
wp_set_current_user($sco); $r = $envoyer('gvb_inscription_annuler', 'gvb_inscription_annuler', ['ins' => $ins->id, 'motif' => 'x']);
$t('annulation réservée à la direction', str_starts_with($r, 'DIE:'), $r);
wp_set_current_user($dir); $r = $envoyer('gvb_inscription_annuler', 'gvb_inscription_annuler', ['ins' => $ins->id, 'motif' => '']);
$t('annulation sans motif refusée', str_contains($r, 'gv_err'), $r);
$envoyer('gvb_inscription_annuler', 'gvb_inscription_annuler', ['ins' => $ins->id, 'motif' => 'Désistement']);
$t('annulation : la demande revient à « acceptée »', $wpdb->get_var('SELECT statut FROM ' . gvb_table('inscriptions') . " WHERE id = {$ins->id}") === 'annulee' && $wpdb->get_var('SELECT statut FROM ' . gvb_table('demandes') . " WHERE id = $id") === 'acceptee');

$lignes[] = "\n## Formulaire public";
wp_set_current_user(0);
$pub = fn($champs) => (function () use ($champs) { $r = new WP_REST_Request('POST', '/groupevitos/v1/admissions'); $r->set_body_params($champs); $rep = rest_do_request($r); return [$rep->get_status(), $rep->get_data()]; })();
$form = ['pole' => 'lycee', 'niveau' => '10e', 'nom' => 'Coulibaly', 'prenom' => 'Fanta', 'telephone' => '+223 65 00 00 00', 'parent_nom' => 'M. Coulibaly', 'consentement' => '1'];
[$c, $dta] = $pub($form); $idp = (int) $wpdb->get_var('SELECT MAX(id) FROM ' . gvb_table('demandes'));
$t('demande reçue depuis le site', $c === 200 && !empty($dta['code']) && $wpdb->get_var('SELECT source FROM ' . gvb_table('demandes') . " WHERE id = $idp") === 'site', json_encode($dta));
$t('alerte à l’agence envoyée', !empty(array_filter($GLOBALS['gvb_mails'] ?? [], fn($x) => $x[0] === 'contact@groupevitos.com')));
[$c] = $pub(['consentement' => ''] + $form); $t('sans consentement : refusé', $c === 422);
[$c] = $pub(['pole' => 'x'] + $form); $t('pôle invalide : refusé', $c === 422);
[$c] = $pub(['nom' => ''] + $form); $t('nom vide : refusé', $c === 422);
[$c] = $pub(['email' => 'pas-un-mail', 'telephone' => ''] + $form); $t('seul e-mail invalide : refusé', $c === 422);
$n = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . gvb_table('demandes')); [$c] = $pub(['site_web' => 'spam'] + $form);
$t('champ piège : rien enregistré', $c === 200 && (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . gvb_table('demandes')) === $n);
$ok5 = 1; for ($i = 0; $i < 6; $i++) { [$c] = $pub(['nom' => 'Test' . $i] + $form); if ($c === 200) $ok5++; }
$t('limite de 5 demandes par heure et par adresse', $ok5 === 5 && $c === 429, "acceptées : $ok5, dernier code : $c");
$t('le formulaire public ne donne accès à aucune lecture', rest_do_request(new WP_REST_Request('GET', '/groupevitos/v1/admissions'))->get_status() === 404);
$wpdb->query('DELETE FROM ' . gvb_table('demandes') . " WHERE nom LIKE 'Test%'");

$lignes[] = "\n## Export, tableau de bord, accès";
$wpdb->update(gvb_table('demandes'), ['nom' => '=HYPERLINK("http://x")'], ['id' => $id3]);
wp_set_current_user($dir); $_GET = ['_wpnonce' => wp_create_nonce('gvb_export_demandes')]; $_REQUEST = $_GET;
ob_start(); try { do_action('admin_post_gvb_export_demandes'); } catch (Throwable $e) {} $csv = ob_get_clean();
$t('export CSV : formule neutralisée', str_contains($csv, "'=HYPERLINK") && !preg_match('/;=HYPERLINK/', $csv), substr($csv, 0, 300));
wp_set_current_user($cpt); $_GET = ['_wpnonce' => wp_create_nonce('gvb_export_demandes')];
$t('export CSV refusé à la comptabilité', (function () { try { ob_start(); do_action('admin_post_gvb_export_demandes'); ob_end_clean(); return false; } catch (Exception $e) { ob_end_clean(); return true; } })());
wp_set_current_user($rpU); $k = gvb_indicateurs();
$t('indicateurs limités au pôle du responsable', array_sum(array_map('array_sum', array_diff_key($k['poles'], ['universite' => 1]))) === 0);
wp_set_current_user($dir); $k = gvb_indicateurs();
$t('indicateurs : inscrits et statuts cohérents', $k['inscrits'] === 1 && $k['statuts']['acceptee'] >= 1, json_encode($k['statuts']));
foreach (['gvb_page_tableau', 'gvb_page_admissions', 'gvb_page_inscriptions', 'gvb_page_reglages'] as $fn) { ob_start(); $_GET = []; try { $fn(); $h = ob_get_clean(); $t("page $fn s’affiche", strlen($h) > 200 && !str_contains($h, 'Fatal')); } catch (Throwable $e) { ob_end_clean(); $t("page $fn s’affiche", false, $e->getMessage()); } }
$_GET = ['id' => $id]; ob_start(); gvb_page_demande(); $h = ob_get_clean(); $t('fiche demande affichée avec historique', str_contains($h, 'Historique') && str_contains($h, 'Diarra'));
$_GET = ['id' => $ins->id]; ob_start(); gvb_page_inscription(); $h = ob_get_clean(); $t('fiche d’inscription affichée', str_contains($h, 'UNI-2026-0001') || str_contains($h, 'Fiche d’inscription'));
wp_set_current_user($rpL); $_GET = ['id' => $id]; $t('fiche hors périmètre : refusée (404)', (function () { try { gvb_page_demande(); return false; } catch (Exception $e) { return str_contains($e->getMessage(), 'DIE:'); } })());
wp_set_current_user($sco);
$t('réglages : modification refusée à la scolarité', str_starts_with($envoyer('gvb_reglages_sauver', 'gvb_reglages_sauver', ['annee' => '2027–2028', 'email' => 'a@b.test']), 'DIE:') && gvb_reglages()['annee'] === '2026–2027');
wp_set_current_user($dir); $envoyer('gvb_reglages_sauver', 'gvb_reglages_sauver', ['annee' => '2027–2028', 'email' => 'a@b.test']);
$t('réglages : modifiés par la direction', gvb_reglages()['annee'] === '2027–2028');
$r = $envoyer('gvb_reglages_sauver', 'gvb_reglages_sauver', ['annee' => 'n’importe quoi', 'email' => 'a@b.test']); $t('année invalide refusée', str_contains($r, 'gv_err'));

file_put_contents('/wordpress/wp-content/out/resultat.txt', implode("\n", $lignes) . "\n\n=== $ok réussis, $ko échecs ===\n");
