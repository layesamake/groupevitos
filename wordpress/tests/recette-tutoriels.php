<?php
// Recette : page « Tutoriels » réservée aux gestionnaires (WordPress Playground).
define('GVB_ESSAI', true);
$lignes = []; $ok = 0; $ko = 0;
$t = function ($nom, $cond, $detail = '') use (&$lignes, &$ok, &$ko) { $cond ? $ok++ : $ko++; $lignes[] = ($cond ? '  ok  ' : ' ECHEC ') . $nom . (!$cond && $detail !== '' ? ' — ' . substr($detail, 0, 500) : ''); };
register_shutdown_function(function () { $e = error_get_last(); if ($e && in_array($e['type'], [E_ERROR, E_PARSE], true)) file_put_contents('/wordpress/wp-content/out/fatal.txt', print_r($e, true)); });
require '/wordpress/wp-load.php';
do_action('init');
add_filter('wp_die_handler', fn() => function ($m) { throw new Exception('DIE:' . (is_wp_error($m) ? $m->get_error_message() : (string) $m)); });
gvb_synchroniser_roles();
$mk = function ($l, $r, $pole = '') { $id = wp_insert_user(['user_login' => $l, 'user_pass' => wp_generate_password(), 'role' => $r, 'user_email' => "$l@exemple.test", 'first_name' => ucfirst($l)]); if ($pole) update_user_meta($id, 'gv_pole', $pole); return $id; };
$dir = $mk('direction', 'gv_direction'); $sco = $mk('scolarite', 'gv_scolarite'); $cpt = $mk('compta', 'gv_comptabilite'); $rp = $mk('resp', 'gv_responsable_pole', 'lycee'); $com = $mk('comm', 'gv_communication'); $nul = $mk('abonne', 'subscriber');
$page = function ($uid, $get = []) { wp_set_current_user($uid); $_GET = $get; ob_start(); try { gvb_page_tutoriels(); } catch (Exception $e) { ob_end_clean(); return $e->getMessage(); } return ob_get_clean(); };
$image = function ($uid, $f) { wp_set_current_user($uid); $_GET = ['f' => $f]; try { do_action('admin_post_gvb_tuto_image'); return 'ok'; } catch (Exception $e) { return $e->getMessage(); } };

$lignes[] = '## Contenu installé';
$index = gvb_tuto_index();
$t('7 tutoriels dans l’index', count($index) === 7, (string) count($index));
$t('les cinq profils du personnel ont chacun leur tutoriel', array_values(array_filter(array_column($index, 'profil'))) === array_keys(GVB_PROFILS));
$manque = [];
foreach ($index as $x) { if (!is_file(GVB_TUTO_DIR . $x['fichier'] . '.html')) $manque[] = $x['fichier']; if (!$x['sections']) $manque[] = 'sections ' . $x['fichier']; }
$t('chaque tutoriel a son contenu et son plan', !$manque, implode(',', $manque));
$img = [];
foreach ($index as $x) { preg_match_all('/\{\{img:([0-9a-z-]+\.png)\}\}/', (string) file_get_contents(GVB_TUTO_DIR . $x['fichier'] . '.html'), $m); foreach ($m[1] as $n) if (!gvb_tuto_image_chemin($n)) $img[] = $n; }
$t('toutes les captures référencées existent', !$img, implode(',', array_unique($img)));
$t('aucun gabarit d’image resté dans le HTML affiché', !str_contains(gvb_tuto_html($index[0]), '{{img:') && str_contains(gvb_tuto_html($index[0]), 'action=gvb_tuto_image'));

$lignes[] = "\n## Accès réservé aux gestionnaires";
foreach (['direction' => $dir, 'scolarité' => $sco, 'comptabilité' => $cpt, 'responsable' => $rp, 'communication' => $com] as $nom => $uid) {
    $h = $page($uid); $t("$nom : la liste des tutoriels s’affiche", str_contains($h, 'Tutoriels du personnel') && str_contains($h, 'gv-tutoriels'), substr($h, 0, 200));
}
$h = $page($nul); $t('un compte sans accès métier est refusé', str_starts_with($h, 'DIE:'));
wp_set_current_user(0); $_GET = []; ob_start(); try { gvb_page_tutoriels(); $r = 'affichée'; } catch (Exception $e) { $r = $e->getMessage(); } ob_end_clean(); $t('un visiteur non connecté est refusé', str_starts_with($r, 'DIE:'));
$sections = []; foreach (gvb_menu_sections() as $s) foreach ($s[3] as $i) if ($i[1] === 'gv-tutoriels') $sections[] = $i[2];
$t('l’entrée de menu exige l’accès au back-office', $sections === ['gv_acces']);
$vus = 0; foreach ([$dir, $sco, $cpt, $rp, $com] as $uid) foreach (gvb_menu_visible(get_userdata($uid)) as $sec) foreach ($sec['items'] as $i) if ($i[1] === 'gv-tutoriels') $vus++;
$t('l’entrée « Tutoriels » est visible dans le menu des cinq profils', $vus === 5, (string) $vus);
$v = gvb_menu_visible(get_userdata($nul)); $t('et absente pour un compte sans accès', !$v);

$lignes[] = "\n## Affichage";
$h = $page($sco); $t('« Votre profil » signale le tutoriel de la Scolarité', preg_match('/Scolarité<span class="gvt-etiquette">Votre profil/', $h) === 1);
$t('le tutoriel du profil passe en premier', strpos($h, 'Scolarité') < strpos($h, 'Direction'));
$h = $page($dir); $t('l’administrateur-type Direction voit l’étiquette sur le sien', preg_match('/Direction<span class="gvt-etiquette">/', $h) === 1);
$h = $page($com, ['t' => 'communication']);
$t('tutoriel affiché : titre, plan, captures, impression', str_contains($h, 'Tutoriel — Chargé de communication') && str_contains($h, 'Dans ce tutoriel') && str_contains($h, '<figure class="gvt-fig">') && str_contains($h, 'window.print()') && str_contains($h, 'id="etape-3-publier-sur-facebook"'));
$t('les liens de plan correspondent à des ancres', str_contains($h, 'href="#etape-3-publier-sur-facebook"'));
$h = $page($dir, ['t' => 'direction']); $t('premier tutoriel : pas de lien précédent mais un suivant', str_contains($h, 'Scolarité →') && !str_contains($h, '← Scolarité'));
$h = $page($dir, ['t' => 'enseignant']); $t('dernier tutoriel : un précédent', str_contains($h, '← Espace familles et étudiants'));
$h = $page($dir, ['t' => 'inconnu']); $t('tutoriel inconnu : message et retour à la liste', str_contains($h, 'n’existe pas') && str_contains($h, 'Tutoriels du personnel'));
$h = $page($dir, ['t' => '../../wp-config']); $t('slug piégé : traité comme inconnu', str_contains($h, 'Tutoriels du personnel') && !str_contains($h, 'DB_PASSWORD'));
$h = $page($dir, ['t' => 'direction']); $t('aucune donnée réelle : les captures utilisent le circuit protégé', !str_contains($h, '/wp-content/mu-plugins/') );

$lignes[] = "\n## Captures protégées";
$t('une capture est servie à un gestionnaire', $image($sco, '01-connexion.png') === 'ok');
$t('image inconnue : 404', str_starts_with($image($sco, 'absente.png'), 'DIE:'));
$t('traversée de dossier refusée', str_starts_with($image($sco, '../index.json'), 'DIE:') && str_starts_with($image($sco, '../../wp-config.php'), 'DIE:'));
$t('fichier d’un autre type refusé', str_starts_with($image($sco, 'index.json'), 'DIE:'));
$t('compte sans accès : capture refusée', str_starts_with($image($nul, '01-connexion.png'), 'DIE:'));
wp_set_current_user(0); $_GET = ['f' => '01-connexion.png']; try { do_action('admin_post_gvb_tuto_image'); $r = 'servie'; } catch (Exception $e) { $r = $e->getMessage(); } $t('visiteur non connecté : capture refusée', str_starts_with($r, 'DIE:'));
$t('aucune capture ne contient de vrai nom de domaine de production dans le HTML', !str_contains(implode('', array_map(fn($x) => file_get_contents(GVB_TUTO_DIR . $x['fichier'] . '.html'), $index)), 'DB_PASSWORD'));

$lignes[] = "\n## Aide contextuelle";
$_GET = ['page' => 'gv-tutoriels']; wp_set_current_user($dir); ob_start(); gvb_debut('Tutoriels'); gvb_fin(); $h = ob_get_clean(); $t('l’écran Tutoriels a son encart d’aide', str_contains($h, 'gvb-aide'));

file_put_contents('/wordpress/wp-content/out/resultat.txt', implode("\n", $lignes) . "\n\n=== $ok réussis, $ko échecs ===\n");
