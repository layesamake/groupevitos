<?php
// Recette du module Publications Facebook (API simulée : aucune requête réelle, aucune publication).
define('GVB_ESSAI', true);
$lignes = []; $ok = 0; $ko = 0;
$t = function ($nom, $cond, $detail = '') use (&$lignes, &$ok, &$ko) { $cond ? $ok++ : $ko++; $lignes[] = ($cond ? '  ok  ' : ' ECHEC ') . $nom . (!$cond && $detail !== '' ? ' — ' . $detail : ''); };
register_shutdown_function(function () { $e = error_get_last(); if ($e && in_array($e['type'], [E_ERROR, E_PARSE], true)) file_put_contents('/wordpress/wp-content/out/fatal.txt', print_r($e, true)); });
require '/wordpress/wp-load.php';
do_action('init');
global $wpdb;
$T = gvb_table('publications');
add_filter('wp_die_handler', fn() => function ($m) { throw new Exception('DIE:' . (is_wp_error($m) ? $m->get_error_message() : (string) $m)); });
add_filter('wp_redirect', function ($loc) { throw new Exception('REDIR:' . $loc); });
gvb_synchroniser_roles();
$envoyer = function ($action, $nonce, array $post) { $_POST = $post + ['_wpnonce' => wp_create_nonce($nonce)]; $_REQUEST = $_POST; try { do_action('admin_post_' . $action); return 'rien'; } catch (Throwable $e) { return $e->getMessage(); } };
$mk = fn($l, $r) => wp_insert_user(['user_login' => $l, 'user_pass' => wp_generate_password(), 'role' => $r, 'user_email' => "$l@exemple.test"]);
$dir = $mk('d', 'gv_direction'); $sco = $mk('s', 'gv_scolarite'); $cpt = $mk('c', 'gv_comptabilite'); $rp = $mk('r', 'gv_responsable_pole');

// API simulée
$appels = [];
add_filter('gvb_fb_http', function ($pre, $methode, $chemin, $params) use (&$appels) {
    $appels[] = [$methode, $chemin, $params];
    if (($params['access_token'] ?? '') === 'JETON-MAUVAIS') return ['response' => ['code' => 400], 'body' => json_encode(['error' => ['message' => 'Invalid OAuth access token JETON-MAUVAIS']])];
    if ($methode === 'DELETE') return ['response' => ['code' => 200], 'body' => '{"success":true}'];
    if ($methode === 'GET') return ['response' => ['code' => 200], 'body' => '{"name":"Groupe VITOS","id":"1"}'];
    if (str_ends_with($chemin, '/photos') && ($params['published'] ?? '') === 'false' && !isset($params['scheduled_publish_time'])) return ['response' => ['code' => 200], 'body' => json_encode(['id' => 'photo' . count($appels)])];
    return ['response' => ['code' => 200], 'body' => json_encode(['id' => '6159_' . count($appels), 'post_id' => '6159_p' . count($appels)])];
}, 10, 4);

$lignes[] = '## Droits';
$t('Direction et Scolarité publient ; Comptabilité et Responsable non', user_can($dir, 'gv_publications_creer') && user_can($sco, 'gv_publications_creer') && !user_can($cpt, 'gv_publications_voir') && !user_can($rp, 'gv_publications_voir'));
$t('Scolarité ne règle pas le jeton', !user_can($sco, 'gv_parametres_modifier'));
wp_set_current_user($sco); $r = $envoyer('gvb_fb_sauver', 'gvb_fb_sauver', ['actif' => '1', 'page_id' => '61596039230928', 'jeton' => 'X']);
$t('réglages Facebook refusés à la Scolarité', str_starts_with($r, 'DIE:'));
wp_set_current_user($cpt); $r = $envoyer('gvb_fb_publier', 'gvb_fb_publier', ['texte' => 'x', 'confirme' => '1']);
$t('publication refusée à la Comptabilité', str_starts_with($r, 'DIE:') && !(int) $wpdb->get_var("SELECT COUNT(*) FROM $T"));

$lignes[] = "\n## Réglages";
wp_set_current_user($dir);
$envoyer('gvb_fb_sauver', 'gvb_fb_sauver', ['actif' => '1', 'page_id' => '61596039230928', 'jeton' => 'JETON-SECRET']);
$envoyer('gvb_fb_sauver', 'gvb_fb_sauver', ['actif' => '1', 'page_id' => '61596039230928', 'jeton' => '']);
$t('jeton conservé si le champ est vide', gvb_fb_reglages()['jeton'] === 'JETON-SECRET');
ob_start(); $_GET = []; gvb_page_reglages(); $h = ob_get_clean(); $t('le jeton n’est jamais réaffiché', !str_contains($h, 'JETON-SECRET') && str_contains($h, 'Page Facebook'));
$r = $envoyer('gvb_fb_tester', 'gvb_fb_tester', []); $t('test de connexion', str_contains(urldecode($r), 'Connexion réussie') && str_contains(urldecode($r), 'Groupe VITOS'), $r);

$lignes[] = "\n## Publication";
$r = $envoyer('gvb_fb_publier', 'gvb_fb_publier', ['texte' => 'Inscriptions ouvertes !', 'lien' => 'https://groupevitos.com/admission/']);
$t('confirmation obligatoire', str_contains(urldecode($r), 'confirmation') && !(int) $wpdb->get_var("SELECT COUNT(*) FROM $T"), $r);
$r = $envoyer('gvb_fb_publier', 'gvb_fb_publier', ['texte' => 'Inscriptions ouvertes !', 'lien' => 'https://groupevitos.com/admission/', 'confirme' => '1']);
$p = $wpdb->get_row("SELECT * FROM $T ORDER BY id DESC LIMIT 1");
$dernier = end($appels);
$t('publication texte + lien envoyée à la bonne page', $p && $p->statut === 'publiee' && $dernier[1] === '61596039230928/feed' && $dernier[2]['message'] === 'Inscriptions ouvertes !' && $dernier[2]['link'] === 'https://groupevitos.com/admission/', json_encode($dernier));
$t('identifiant Facebook enregistré, journal alimenté', $p && $p->fb_id !== '' && (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . gvb_table('historique') . " WHERE action LIKE 'Publication Facebook%'") >= 1);
$n = (int) $wpdb->get_var("SELECT COUNT(*) FROM $T");
$r = $envoyer('gvb_fb_publier', 'gvb_fb_publier', ['texte' => 'Inscriptions ouvertes !', 'confirme' => '1']);
$t('double envoi du même texte bloqué', (int) $wpdb->get_var("SELECT COUNT(*) FROM $T") === $n && str_contains(urldecode($r), 'déjà'), $r);
$r = $envoyer('gvb_fb_publier', 'gvb_fb_publier', ['texte' => 'Autre', 'lien' => 'http://non-securise.test', 'confirme' => '1']); $t('lien non https refusé', str_contains(urldecode($r), 'https'));
$r = $envoyer('gvb_fb_publier', 'gvb_fb_publier', ['texte' => '', 'confirme' => '1']); $t('publication vide refusée', str_contains(urldecode($r), 'Écrivez') || str_contains(urldecode($r), 'crivez'));
$r = $envoyer('gvb_fb_publier', 'gvb_fb_publier', ['texte' => 'Trop tôt', 'programme' => gmdate('Y-m-d\TH:i', time() + 300), 'confirme' => '1']); $t('programmation trop proche refusée', str_contains(urldecode($r), 'programmation'));
$ts = time() + 2 * DAY_IN_SECONDS; $loc = get_date_from_gmt(gmdate('Y-m-d H:i:s', $ts), 'Y-m-d\TH:i');
$r = $envoyer('gvb_fb_publier', 'gvb_fb_publier', ['texte' => 'Programmée', 'programme' => $loc, 'confirme' => '1']);
$p2 = $wpdb->get_row("SELECT * FROM $T ORDER BY id DESC LIMIT 1"); $d2 = end($appels);
$t('programmation : non publiée + horodatage envoyé', $p2->statut === 'programmee' && $d2[2]['published'] === 'false' && abs((int) $d2[2]['scheduled_publish_time'] - $ts) < 120, json_encode($d2));
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$f1 = sys_get_temp_dir() . '/a.png'; $f2 = sys_get_temp_dir() . '/b.png'; file_put_contents($f1, $png); file_put_contents($f2, $png);
$_FILES = ['images' => ['name' => ['a.png'], 'type' => ['image/png'], 'tmp_name' => [$f1], 'error' => [0], 'size' => [strlen($png)]]];
$envoyer('gvb_fb_publier', 'gvb_fb_publier', ['texte' => 'Une photo', 'confirme' => '1']); $d3 = end($appels);
$p3 = $wpdb->get_row("SELECT * FROM $T ORDER BY id DESC LIMIT 1");
$t('une photo : envoi par adresse publique avec légende', $d3[1] === '61596039230928/photos' && str_contains($d3[2]['url'], '/gv-publications/') && $d3[2]['caption'] === 'Une photo' && $p3->statut === 'publiee', json_encode($d3));
file_put_contents($f1, $png); file_put_contents($f2, $png);
$_FILES = ['images' => ['name' => ['a.png', 'b.png'], 'type' => ['image/png', 'image/png'], 'tmp_name' => [$f1, $f2], 'error' => [0, 0], 'size' => [strlen($png), strlen($png)]]];
$av = count($appels); $envoyer('gvb_fb_publier', 'gvb_fb_publier', ['texte' => 'Deux photos', 'confirme' => '1']); $nouv = array_slice($appels, $av); $d4 = end($nouv);
$t('plusieurs photos : médias rattachés à la publication', count($nouv) === 3 && $d4[1] === '61596039230928/feed' && isset($d4[2]['attached_media[1]']), json_encode($d4));
$f3 = sys_get_temp_dir() . '/x.php'; file_put_contents($f3, '<?php echo 1;');
$_FILES = ['images' => ['name' => ['x.php'], 'type' => ['image/png'], 'tmp_name' => [$f3], 'error' => [0], 'size' => [20]]];
$r = $envoyer('gvb_fb_publier', 'gvb_fb_publier', ['texte' => 'Piège', 'confirme' => '1']); $t('script déguisé en image : refusé', str_contains(urldecode($r), 'JPG et PNG'), $r);
$_FILES = [];

$lignes[] = "\n## Échecs et annulation";
update_option('gvb_facebook', ['page_id' => '61596039230928', 'jeton' => 'JETON-MAUVAIS', 'actif' => 1], false);
$r = $envoyer('gvb_fb_publier', 'gvb_fb_publier', ['texte' => 'Avec mauvais jeton', 'confirme' => '1']); $p5 = $wpdb->get_row("SELECT * FROM $T ORDER BY id DESC LIMIT 1");
$t('échec Facebook enregistré, jeton masqué dans le message', $p5->statut === 'echec' && !str_contains($p5->erreur, 'JETON-MAUVAIS') && str_contains($p5->erreur, '***'), $p5->erreur);
update_option('gvb_facebook', ['page_id' => '61596039230928', 'jeton' => 'JETON-SECRET', 'actif' => 1], false);
wp_set_current_user($sco); $r = $envoyer('gvb_fb_annuler', 'gvb_fb_annuler', ['id' => $p2->id]);
$t('Scolarité annule une publication programmée', $wpdb->get_var("SELECT statut FROM $T WHERE id = {$p2->id}") === 'annulee' && end($appels)[0] === 'DELETE', $r);
$r = $envoyer('gvb_fb_annuler', 'gvb_fb_annuler', ['id' => $p->id]); $t('une publication déjà publiée ne peut pas être annulée', str_contains(urldecode($r), 'programm'));
update_option('gvb_facebook', ['page_id' => '61596039230928', 'jeton' => 'JETON-SECRET', 'actif' => 0], false);
$r = $envoyer('gvb_fb_publier', 'gvb_fb_publier', ['texte' => 'Désactivé', 'confirme' => '1']); $t('connexion désactivée : publication impossible', str_contains(urldecode($r), 'activ'));
wp_set_current_user($dir); $_GET = []; ob_start(); gvb_page_publications(); $h = ob_get_clean();
$t('écran Publications : formulaire et historique', str_contains($h, 'Nouvelle publication') && str_contains($h, 'Inscriptions ouvertes') && str_contains($h, 'Historique'));

file_put_contents('/wordpress/wp-content/out/resultat.txt', implode("\n", $lignes) . "\n\n=== $ok réussis, $ko échecs ===\n");
