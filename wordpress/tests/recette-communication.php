<?php
// Recette du profil « Chargé de communication » (WordPress Playground, base jetable).
define('GVB_ESSAI', true);
$lignes = []; $ok = 0; $ko = 0;
$t = function ($nom, $cond, $detail = '') use (&$lignes, &$ok, &$ko) { $cond ? $ok++ : $ko++; $lignes[] = ($cond ? '  ok  ' : ' ECHEC ') . $nom . (!$cond && $detail !== '' ? ' — ' . $detail : ''); };
register_shutdown_function(function () { $e = error_get_last(); if ($e && in_array($e['type'], [E_ERROR, E_PARSE], true)) file_put_contents('/wordpress/wp-content/out/fatal.txt', print_r($e, true)); });
require '/wordpress/wp-load.php';
do_action('init');
global $wpdb;
add_filter('wp_die_handler', fn() => function ($m) { throw new Exception('DIE:' . (is_wp_error($m) ? $m->get_error_message() : (string) $m)); });
add_filter('wp_redirect', function ($loc) { throw new Exception('REDIR:' . $loc); });
gvb_synchroniser_roles();
$envoyer = function ($action, $nonce, array $post) { $_POST = $post + ['_wpnonce' => wp_create_nonce($nonce)]; $_REQUEST = $_POST; try { do_action('admin_post_' . $action); return 'rien'; } catch (Throwable $e) { return $e->getMessage(); } };
$mk = fn($l, $r) => wp_insert_user(['user_login' => $l, 'user_pass' => wp_generate_password(), 'role' => $r, 'user_email' => "$l@exemple.test"]);
$dir = $mk('d', 'gv_direction'); $sco = $mk('s', 'gv_scolarite'); $com = $mk('com', 'gv_communication'); $com2 = $mk('com2', 'gv_communication');

$lignes[] = '## Profil';
$t('profil « Chargé de communication » créé', (bool) get_role('gv_communication') && GVB_PROFILS['gv_communication'] === 'Chargé de communication');
$t('droits de publication Facebook et d’articles', user_can($com, 'gv_publications_creer') && user_can($com, 'gv_publications_annuler') && user_can($com, 'edit_posts') && user_can($com, 'publish_posts') && user_can($com, 'upload_files') && user_can($com, 'edit_others_posts'));
$t('aucun accès aux admissions, étudiants, inscriptions, réglages, comptes', !user_can($com, 'gv_admissions_voir') && !user_can($com, 'gv_etudiants_voir') && !user_can($com, 'gv_inscriptions_voir') && !user_can($com, 'gv_parametres_voir') && !user_can($com, 'list_users') && !user_can($com, 'manage_options'));
$t('contacts de la plaquette : consultation et traitement, pas d’export', user_can($com, 'gv_contacts_voir') && user_can($com, 'gv_contacts_modifier') && !user_can($com, 'gv_contacts_exporter') && !user_can($com, 'gv_contacts_annuler'));
$t('pas de droit sur les pages ni les plugins', !user_can($com, 'edit_pages') && !user_can($com, 'install_plugins') && !user_can($com, 'edit_theme_options'));
$t('les autres profils n’ont pas les droits d’articles', !user_can($sco, 'edit_posts') && !user_can($dir, 'edit_posts') && !user_can($sco, 'upload_files'));
wp_set_current_user($dir); $roles = apply_filters('editable_roles', wp_roles()->roles);
$t('la Direction peut attribuer ce profil', isset($roles['gv_communication']));
$t('la Direction ne peut pas modifier un chargé de communication… sauf compte métier', user_can($dir, 'edit_user', $com));

$lignes[] = "\n## Contenus du site";
register_post_type('formation', ['public' => true, 'supports' => ['title', 'editor']]);
$fid = wp_insert_post(['post_type' => 'formation', 'post_status' => 'publish', 'post_title' => 'Gestion', 'post_name' => 'gestion']);
$pid = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Admission']);
$aid = wp_insert_post(['post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Rentrée', 'post_author' => $com2]);
$t('peut modifier un article, même écrit par un collègue', user_can($com, 'edit_post', $aid));
$t('peut publier et supprimer ses propres articles', user_can($com, 'publish_posts') && user_can($com, 'delete_posts'));
$t('ne peut pas supprimer les articles des autres', !user_can($com, 'delete_others_posts') && !user_can($com, 'delete_post', $aid));
$t('ne peut pas modifier une formation', !user_can($com, 'edit_post', $fid) && !user_can($com, 'delete_post', $fid));
$t('ne peut pas modifier une page du site', !user_can($com, 'edit_post', $pid));
wp_set_current_user($com); $nouv = wp_insert_post(['post_type' => 'formation', 'post_status' => 'publish', 'post_title' => 'Intrus']);
$t('ne peut pas créer de formation (ni par l’API)', !$nouv && !is_object(get_page_by_title('Intrus', OBJECT, 'formation')));
$nouvp = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Intruse']); $t('ne peut pas créer de page', !$nouvp);
$nouva = wp_insert_post(['post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Actualité du jour']); $t('peut créer un article', $nouva > 0);
wp_set_current_user($dir);
$adm = $mk('adm', 'administrator'); $t('l’administrateur garde la main', user_can($adm, 'edit_post', $fid), json_encode(['t' => get_post_type($fid), 'cap' => get_post_type_object('formation')->cap, 'ep' => user_can($adm, 'edit_posts'), 'eo' => user_can($adm, 'edit_others_posts'), 'user' => $adm]));
wp_set_current_user($com);
$GLOBALS['pagenow'] = 'post-new.php'; $_GET = ['post_type' => 'formation'];
$t('accès direct à « nouvelle formation » refusé', (function () { try { do_action('admin_init'); return false; } catch (Exception $e) { return str_starts_with($e->getMessage(), 'DIE:'); } })());
$GLOBALS['pagenow'] = 'post.php'; $_GET = ['post' => $fid];
$t('accès direct à l’édition d’une formation refusé', (function () { try { do_action('admin_init'); return false; } catch (Exception $e) { return str_starts_with($e->getMessage(), 'DIE:'); } })());
$GLOBALS['pagenow'] = 'post-new.php'; $_GET = [];
$t('nouvel article autorisé', (function () { try { do_action('admin_init'); return true; } catch (Exception $e) { return false; } })());
$_GET = [];

$lignes[] = "\n## Publications Facebook";
add_filter('gvb_fb_http', function ($pre, $methode, $chemin, $params) { return ['response' => ['code' => 200], 'body' => json_encode(['id' => '1376716678856765_42'])]; }, 10, 4);
update_option('gvb_facebook', ['page_id' => '1376716678856765', 'jeton' => 'JETON-SECRET', 'actif' => 1], false);
$r = $envoyer('gvb_fb_publier', 'gvb_fb_publier', ['texte' => 'Rentrée 2026 : les inscriptions se poursuivent.', 'lien' => 'https://groupevitos.com/admission/', 'confirme' => '1']);
$t('publication Facebook par le chargé de communication', str_contains(urldecode($r), 'gv_msg') && (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . gvb_table('publications') . " WHERE auteur_id = $com AND statut = 'publiee'") === 1, $r);
$r = $envoyer('gvb_fb_sauver', 'gvb_fb_sauver', ['actif' => '1', 'page_id' => '1', 'jeton' => 'X']);
$t('réglage du jeton Facebook réservé à la Direction', str_starts_with($r, 'DIE:') && gvb_fb_reglages()['jeton'] === 'JETON-SECRET');
$post = get_post($aid); $acts = apply_filters('post_row_actions', [], $post);
$t('raccourci « Publier sur Facebook » sur les articles', isset($acts['gvb_fb']));

$lignes[] = "\n## Écrans";
$_GET = []; ob_start(); gvb_page_tableau(); $h = ob_get_clean();
$t('tableau de bord dédié (publications, actualités)', str_contains($h, 'Publications programmées') && str_contains($h, 'Actualités en ligne') && str_contains($h, 'Nouvelle publication Facebook') && !str_contains($h, 'Aucun module') && !str_contains($h, 'Nouvelles demandes'));
foreach (['gvb_page_admissions', 'gvb_page_etudiants', 'gvb_page_inscriptions', 'gvb_page_reglages'] as $fn) $t("écran $fn refusé", (function () use ($fn) { try { $fn(); return false; } catch (Exception $e) { return str_starts_with($e->getMessage(), 'DIE:'); } })());
ob_start(); gvb_page_publications(); $h = ob_get_clean(); $t('écran Publications accessible', str_contains($h, 'Nouvelle publication'));
ob_start(); gvb_page_contacts(); $h = ob_get_clean(); $t('écran Contacts accessible sans export', str_contains($h, 'Contacts') && !str_contains($h, 'Exporter (CSV)'));
$_GET = ['_wpnonce' => wp_create_nonce('gvb_export_contacts')]; $_REQUEST = $_GET;
$t('export des contacts refusé', (function () { try { ob_start(); do_action('admin_post_gvb_export_contacts'); ob_end_clean(); return false; } catch (Exception $e) { ob_end_clean(); return str_starts_with($e->getMessage(), 'DIE:'); } })());

file_put_contents('/wordpress/wp-content/out/resultat.txt', implode("\n", $lignes) . "\n\n=== $ok réussis, $ko échecs ===\n");
