<?php
// Recette du dépôt de CV (WordPress Playground, base jetable). Écrit le compte rendu dans /wordpress/wp-content/out/resultat.txt
define('GVC_ESSAI', true);
$lignes = []; $ok = 0; $ko = 0;
$t = function ($nom, $cond, $detail = '') use (&$lignes, &$ok, &$ko) { $cond ? $ok++ : $ko++; $lignes[] = ($cond ? '  ok  ' : ' ECHEC ') . $nom . (!$cond && $detail !== '' ? ' — ' . $detail : ''); };
register_shutdown_function(function () { $e = error_get_last(); if ($e && in_array($e['type'], [E_ERROR, E_PARSE], true)) file_put_contents('/wordpress/wp-content/out/fatal.txt', print_r($e, true)); });
require '/wordpress/wp-load.php';
do_action('init');
global $wpdb;
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

$tmp = function ($contenu, $nom) { $p = sys_get_temp_dir() . '/' . wp_generate_password(8, false) . '-' . $nom; file_put_contents($p, $contenu); return $p; };
$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
$envoyer = function (array $champs, ?array $fichier) {
    $r = new WP_REST_Request('POST', '/groupevitos/v1/candidatures');
    $r->set_body_params($champs);
    if ($fichier) $r->set_file_params(['cv' => $fichier]);
    $rep = rest_do_request($r);
    return [$rep->get_status(), $rep->get_data()];
};
$fic = fn($chemin, $nom, $taille = null) => ['name' => $nom, 'type' => 'application/pdf', 'tmp_name' => $chemin, 'error' => 0, 'size' => $taille ?? filesize($chemin)];
$base = ['nom' => 'Traoré', 'prenom' => 'Mariam', 'email' => 'mariam@exemple.test', 'telephone' => '+223 70 00 00 00', 'pole' => 'universite', 'discipline' => 'Comptabilité', 'niveau' => 'Licence', 'experience' => '6', 'consentement' => '1'];

$lignes[] = '## Dépôt de CV';
[$code, $data] = $envoyer($base, $fic($tmp($pdf, 'cv.pdf'), 'CV Mariam.pdf'));
$id = (int) $wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'candidature' ORDER BY ID DESC LIMIT 1");
$t('dépôt valide accepté', $code === 200 && !empty($data['ok']) && $id > 0, json_encode($data));
$f = get_post_meta($id, 'gvc_fichier', true);
$t('fichier rangé dans le dossier privé, nom aléatoire', $f && is_file(gvc_dossier() . '/' . $f) && !str_contains($f, 'Mariam') && is_file(gvc_dossier() . '/.htaccess') && str_contains(file_get_contents(gvc_dossier() . '/.htaccess'), 'Require all denied'));
$t('candidature enregistrée avec ses champs', get_post_meta($id, 'gvc_statut', true) === 'recue' && get_post_meta($id, 'gvc_discipline', true) === 'Comptabilité' && get_post_status($id) === 'private');
$t('alerte à la direction et accusé de réception partis', count($GLOBALS['gvc_mails'] ?? []) === 2 && $GLOBALS['gvc_mails'][0][0] === 'contact@groupevitos.com' && $GLOBALS['gvc_mails'][1][0] === 'mariam@exemple.test', json_encode($GLOBALS['gvc_mails'] ?? []));

$lignes[] = "\n## Refus";
[$c] = $envoyer($base, null); $t('sans CV : refusé', $c === 422);
[$c] = $envoyer($base, $fic($tmp("<?php echo 'x';", 'faux.pdf'), 'faux.pdf')); $t('script déguisé en PDF : refusé', $c === 422);
[$c] = $envoyer($base, $fic($tmp($pdf, 'cv.exe'), 'cv.exe')); $t('mauvaise extension : refusée', $c === 422);
$gros = $tmp($pdf, 'gros.pdf'); [$c] = $envoyer($base, $fic($gros, 'gros.pdf', 6 * 1024 * 1024)); $t('plus de 5 Mo : refusé', $c === 422);
[$c] = $envoyer(['consentement' => ''] + $base, $fic($tmp($pdf, 'a.pdf'), 'a.pdf')); $t('sans consentement : refusé', $c === 422);
[$c] = $envoyer(['email' => 'pas-un-mail'] + $base, $fic($tmp($pdf, 'a.pdf'), 'a.pdf')); $t('e-mail invalide : refusé', $c === 422);
[$c] = $envoyer(['pole' => 'inconnu'] + $base, $fic($tmp($pdf, 'a.pdf'), 'a.pdf')); $t('pôle inconnu : refusé', $c === 422);
$avant = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'candidature'");
[$c, $d] = $envoyer(['site_web' => 'http://spam'] + $base, $fic($tmp($pdf, 'a.pdf'), 'a.pdf'));
$t('champ piège : rien d’enregistré', $c === 200 && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'candidature'") === $avant);
$n = 1; for ($i = 0; $i < 6; $i++) { [$c] = $envoyer($base, $fic($tmp($pdf, 'a.pdf'), 'a.pdf')); if ($c === 200) $n++; }
$t('limite de 5 dépôts par heure et par adresse', $n === 5 && $c === 429, "acceptés : $n, dernier code : $c");

$lignes[] = "\n## Accès";
$t('le type n’est pas exposé dans l’API REST', empty(get_post_type_object('candidature')->show_in_rest) && rest_do_request(new WP_REST_Request('GET', '/wp/v2/candidatures'))->get_status() === 404);
wp_set_current_user(0);
add_filter('wp_die_handler', fn() => function ($m) { throw new Exception(is_wp_error($m) ? $m->get_error_message() : (string) $m); });
$telecharger = function ($nonce) use ($id) { $_GET = ['id' => $id, '_wpnonce' => $nonce]; $_REQUEST = $_GET; try { ob_start(); do_action('admin_post_gvc_cv'); ob_end_clean(); return false; } catch (Exception $e) { ob_end_clean(); return true; } };
$t('visiteur non connecté : téléchargement refusé', $telecharger(wp_create_nonce('gvc_cv_' . $id)));
$editeur = wp_insert_user(['user_login' => 'editeur', 'user_pass' => wp_generate_password(), 'role' => 'editor']);
wp_set_current_user($editeur);
$t('éditeur (non administrateur) : téléchargement refusé', $telecharger(wp_create_nonce('gvc_cv_' . $id)) && !user_can($editeur, 'edit_candidatures'));
wp_set_current_user(1);
$t('administrateur : droit de consultation et de suppression', user_can(1, 'edit_candidatures') && user_can(1, 'delete_candidatures'));
$t('jeton de sécurité invalide : téléchargement refusé', $telecharger('mauvais'));
$lien = gvc_lien_cv($id);
$t('lien de téléchargement signé', str_contains($lien, '_wpnonce=') && str_contains($lien, 'action=gvc_cv'));

$lignes[] = "\n## Conservation";
update_option('groupevitos_candidatures', ['email' => 'rh@exemple.test', 'conservation_mois' => 1]);
$wpdb->update($wpdb->posts, ['post_date' => wp_date('Y-m-d H:i:s', strtotime('-3 months')), 'post_date_gmt' => gmdate('Y-m-d H:i:s', strtotime('-3 months'))], ['ID' => $id]);
clean_post_cache($id);
ob_start(); do_action('manage_candidature_posts_custom_column', 'purge', $id); $col = ob_get_clean();
$t('candidature au-delà de la durée : signalée « À supprimer »', str_contains($col, 'À supprimer'), $col);
$chemin = gvc_dossier() . '/' . $f;
wp_delete_post($id, true);
$t('suppression : le CV est effacé avec la candidature', !is_file($chemin) && !get_post($id));

file_put_contents('/wordpress/wp-content/out/resultat.txt', implode("\n", $lignes) . "\n\n=== $ok réussis, $ko échecs ===\n");
