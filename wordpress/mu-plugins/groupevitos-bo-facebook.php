<?php
/**
 * Plugin Name: Groupe Vitos — Back-office : publications Facebook
 * Description: Publier (ou programmer) du texte, des photos et un lien sur la page Facebook du Groupe, avec historique.
 */

if (!defined('ABSPATH')) exit;

const GVB_FB_VERSION = 'v21.0';
const GVB_FB_IMAGES_MAX = 10;
const GVB_FB_IMAGE_TAILLE = 8 * 1024 * 1024;
const GVB_FB_STATUTS = ['publiee' => 'Publiée', 'programmee' => 'Programmée', 'echec' => 'Échec', 'annulee' => 'Annulée'];

add_action('init', function () {
    if (get_option('gvb_db_fb') === '1') return;
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta('CREATE TABLE ' . gvb_table('publications') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  texte text NULL,
  lien varchar(500) NOT NULL DEFAULT '',
  images text NULL,
  statut varchar(12) NOT NULL DEFAULT 'publiee',
  fb_id varchar(60) NOT NULL DEFAULT '',
  programme_le datetime NULL,
  erreur varchar(300) NOT NULL DEFAULT '',
  auteur_id bigint(20) unsigned NOT NULL DEFAULT 0,
  cree_le datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY statut (statut),
  KEY cree_le (cree_le)
) " . $wpdb->get_charset_collate() . ';');
    update_option('gvb_db_fb', '1', false);
}, 5);

function gvb_fb_reglages() { return wp_parse_args(get_option('gvb_facebook', []), ['page_id' => '1376716678856765', 'jeton' => '', 'actif' => 0]); }

// Appel à l'API Graph. Le jeton n'apparaît jamais dans un message d'erreur.
function gvb_fb_appel($methode, $chemin, array $params = []) {
    $c = gvb_fb_reglages();
    if ($c['jeton'] === '') return new WP_Error('jeton', 'Le jeton Facebook n’est pas renseigné (Réglages).');
    $params['access_token'] = $c['jeton'];
    $url = 'https://graph.facebook.com/' . GVB_FB_VERSION . '/' . ltrim($chemin, '/');
    $mock = apply_filters('gvb_fb_http', null, $methode, $chemin, $params);
    if ($mock !== null) $rep = $mock;
    elseif ($methode === 'GET') $rep = wp_remote_get(add_query_arg($params, $url), ['timeout' => 25]);
    else $rep = wp_remote_request($url, ['method' => $methode, 'timeout' => 25, 'body' => $params]);
    if (is_wp_error($rep)) return new WP_Error('reseau', 'Facebook est injoignable : ' . str_replace($c['jeton'], '***', $rep->get_error_message()));
    $corps = json_decode(wp_remote_retrieve_body($rep), true);
    if (!is_array($corps)) return new WP_Error('reponse', 'Réponse inattendue de Facebook (HTTP ' . wp_remote_retrieve_response_code($rep) . ').');
    if (!empty($corps['error'])) return new WP_Error('facebook', str_replace($c['jeton'], '***', mb_substr((string) ($corps['error']['message'] ?? 'Erreur Facebook'), 0, 280)));
    return $corps;
}

// ── Images : stockées dans un dossier public (Facebook doit pouvoir les télécharger) ──
function gvb_fb_enregistrer_images() {
    $urls = [];
    if (empty($_FILES['images']['name'])) return $urls;
    require_once ABSPATH . 'wp-admin/includes/file.php';
    $f = $_FILES['images'];
    $n = is_array($f['name']) ? count($f['name']) : 0;
    if ($n > GVB_FB_IMAGES_MAX) return new WP_Error('images', 'Au plus ' . GVB_FB_IMAGES_MAX . ' images par publication.');
    add_filter('upload_dir', $filtre = function ($d) { $d['subdir'] = '/gv-publications'; $d['path'] = $d['basedir'] . $d['subdir']; $d['url'] = $d['baseurl'] . $d['subdir']; return $d; });
    for ($i = 0; $i < $n; $i++) {
        if ($f['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
        $un = ['name' => $f['name'][$i], 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
        if ($un['error'] !== UPLOAD_ERR_OK || $un['size'] > GVB_FB_IMAGE_TAILLE) { remove_filter('upload_dir', $filtre); return new WP_Error('images', 'Image refusée : 8 Mo maximum par image.'); }
        $ext = strtolower(pathinfo($un['name'], PATHINFO_EXTENSION));
        $verif = wp_check_filetype_and_ext($un['tmp_name'], 'photo.' . $ext, ['jpg|jpeg' => 'image/jpeg', 'png' => 'image/png']);
        if (empty($verif['type']) || !@getimagesize($un['tmp_name'])) { remove_filter('upload_dir', $filtre); return new WP_Error('images', 'Seules les images JPG et PNG sont acceptées.'); }
        $res = wp_handle_upload($un, ['test_form' => false, 'action' => defined('GVB_ESSAI') ? 'wp_handle_sideload' : 'wp_handle_upload', 'mimes' => ['jpg|jpeg' => 'image/jpeg', 'png' => 'image/png'], 'unique_filename_callback' => fn($dir, $name, $e) => wp_generate_password(20, false) . $e]);
        if (!empty($res['error'])) { remove_filter('upload_dir', $filtre); return new WP_Error('images', 'Envoi de l’image impossible.'); }
        $urls[] = $res['url'];
    }
    remove_filter('upload_dir', $filtre);
    return $urls;
}

// ── Publication ───────────────────────────────────────────────────────────────
function gvb_fb_publier($texte, $lien, array $images, $programme_ts = 0) {
    $c = gvb_fb_reglages(); $page = preg_replace('/\D/', '', $c['page_id']);
    $programme = $programme_ts > 0;
    $commun = $programme ? ['published' => 'false', 'scheduled_publish_time' => (string) $programme_ts, 'unpublished_content_type' => 'SCHEDULED'] : [];
    if (count($images) === 1) {
        $r = gvb_fb_appel('POST', "$page/photos", $commun + ['url' => $images[0], 'caption' => $texte]);
        return is_wp_error($r) ? $r : ($r['post_id'] ?? $r['id'] ?? '');
    }
    $params = $commun + ['message' => $texte];
    if ($lien !== '') $params['link'] = $lien;
    if (count($images) > 1) {
        $ids = [];
        foreach ($images as $url) {
            $p = gvb_fb_appel('POST', "$page/photos", ['url' => $url, 'published' => 'false']);
            if (is_wp_error($p)) return $p;
            $ids[] = ['media_fbid' => $p['id']];
        }
        foreach ($ids as $i => $m) $params["attached_media[$i]"] = wp_json_encode($m);
        unset($params['link']);
    }
    $r = gvb_fb_appel('POST', "$page/feed", $params);
    return is_wp_error($r) ? $r : ($r['id'] ?? '');
}

add_action('admin_post_gvb_fb_publier', function () {
    gvb_garde('publications', 'creer', 'gvb_fb_publier');
    global $wpdb;
    $c = gvb_fb_reglages();
    if (!$c['actif'] || $c['jeton'] === '') gvb_retour('gv-publications', [], null, 'La connexion à Facebook n’est pas activée (Réglages).');
    if (empty($_POST['confirme'])) gvb_retour('gv-publications', [], null, 'Cochez la confirmation avant de publier.');
    $texte = trim(mb_substr(sanitize_textarea_field(wp_unslash($_POST['texte'] ?? '')), 0, 5000));
    $lien = trim((string) wp_unslash($_POST['lien'] ?? ''));
    if ($lien !== '' && (!preg_match('#^https://#i', $lien) || !wp_http_validate_url($lien))) gvb_retour('gv-publications', [], null, 'Le lien doit commencer par https://.');
    $lien = $lien === '' ? '' : esc_url_raw($lien);
    $images = gvb_fb_enregistrer_images();
    if (is_wp_error($images)) gvb_retour('gv-publications', [], null, $images);
    if ($texte === '' && !$images) gvb_retour('gv-publications', [], null, 'Écrivez un texte ou ajoutez une image.');
    $ts = 0; $programme_le = null;
    if (!empty($_POST['programme'])) {
        $local = sanitize_text_field(wp_unslash($_POST['programme']));
        $ts = (int) get_gmt_from_date(str_replace('T', ' ', $local) . ':00', 'U');
        if ($ts < time() + 11 * MINUTE_IN_SECONDS || $ts > time() + 29 * DAY_IN_SECONDS) gvb_retour('gv-publications', [], null, 'La date de programmation doit être comprise entre 11 minutes et 29 jours à partir de maintenant.');
        $programme_le = get_date_from_gmt(gmdate('Y-m-d H:i:s', $ts));
    }
    // Anti double envoi : même texte par le même auteur depuis moins de 2 minutes
    if ($wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . gvb_table('publications') . " WHERE auteur_id = %d AND texte = %s AND statut IN ('publiee','programmee') AND cree_le > %s", get_current_user_id(), $texte, gmdate('Y-m-d H:i:s', time() - 120)))) gvb_retour('gv-publications', [], null, 'Cette publication vient déjà d’être envoyée.');
    $r = gvb_fb_publier($texte, $lien, $images, $ts);
    $ligne = ['texte' => $texte, 'lien' => $lien, 'images' => wp_json_encode($images), 'programme_le' => $programme_le, 'auteur_id' => get_current_user_id(), 'cree_le' => gvb_now()];
    if (is_wp_error($r)) {
        $wpdb->insert(gvb_table('publications'), $ligne + ['statut' => 'echec', 'erreur' => mb_substr($r->get_error_message(), 0, 300)]);
        gvb_log(0, 'Publication Facebook en échec', $r->get_error_message());
        gvb_retour('gv-publications', [], null, 'Facebook a refusé la publication : ' . $r->get_error_message());
    }
    $wpdb->insert(gvb_table('publications'), $ligne + ['statut' => $ts ? 'programmee' : 'publiee', 'fb_id' => (string) $r]);
    gvb_log(0, $ts ? 'Publication Facebook programmée' : 'Publication Facebook envoyée', (string) $r);
    gvb_retour('gv-publications', [], $ts ? 'Publication programmée.' : 'Publication envoyée sur Facebook.');
});

add_action('admin_post_gvb_fb_annuler', function () {
    gvb_garde('publications', 'annuler', 'gvb_fb_annuler');
    global $wpdb;
    $p = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . gvb_table('publications') . ' WHERE id = %d', absint($_POST['id'] ?? 0)));
    if (!$p || $p->statut !== 'programmee') gvb_retour('gv-publications', [], null, 'Seule une publication programmée peut être annulée.');
    $r = gvb_fb_appel('DELETE', $p->fb_id);
    if (is_wp_error($r)) gvb_retour('gv-publications', [], null, 'Annulation impossible : ' . $r->get_error_message());
    $wpdb->update(gvb_table('publications'), ['statut' => 'annulee'], ['id' => $p->id]);
    gvb_log(0, 'Publication Facebook annulée', $p->fb_id);
    gvb_retour('gv-publications', [], 'Publication annulée.');
});

// ── Écran ─────────────────────────────────────────────────────────────────────
function gvb_page_publications() {
    gvb_exiger('publications', 'voir');
    global $wpdb;
    $c = gvb_fb_reglages();
    gvb_debut('Publications Facebook', 'rapport');
    if (!$c['actif'] || $c['jeton'] === '') echo '<div class="gvb-alerte">La connexion à Facebook n’est pas activée. ' . (gvb_can('parametres', 'modifier') ? '<a href="' . esc_url(gvb_url('gv-reglages')) . '">Ouvrir les Réglages</a>' : 'Contactez la Direction.') . '</div>';
    if (gvb_can('publications', 'creer')) {
        $texte = isset($_GET['texte']) ? sanitize_textarea_field(wp_unslash($_GET['texte'])) : '';
        $lien = isset($_GET['lien']) ? esc_url(wp_unslash($_GET['lien'])) : '';
        echo '<div class="gvb-boite"><h2>' . gvb_icone('plus', 18) . 'Nouvelle publication</h2>' . gvb_form('gvb_fb_publier', 'gvb_fb_publier', [], 'enctype="multipart/form-data"')
            . gvb_zone('texte', 'Texte de la publication', $texte, 6) . gvb_champ('lien', 'Lien (facultatif, https://…)', $lien, 'url', 'maxlength="500"')
            . '<label class="gvb-champ"><span>Photos JPG ou PNG (facultatif, ' . GVB_FB_IMAGES_MAX . ' maximum, 8 Mo chacune)</span><input type="file" name="images[]" accept="image/jpeg,image/png" multiple></label>'
            . gvb_champ('programme', 'Programmer (laisser vide pour publier maintenant)', '', 'datetime-local')
            . '<label class="gvb-cases"><input type="checkbox" name="confirme" value="1" required> Je confirme la publication sur la page Facebook du Groupe VITO S.</label>' . gvb_bouton('Publier') . '</form></div>';
    }
    $lignes = [];
    foreach ($wpdb->get_results('SELECT * FROM ' . gvb_table('publications') . ' ORDER BY id DESC LIMIT 100') as $p) {
        $u = get_userdata($p->auteur_id);
        $img = (array) json_decode((string) $p->images, true);
        $etat = ['publiee' => 'validee', 'programmee' => 'etude', 'echec' => 'incomplete', 'annulee' => 'annulee'][$p->statut] ?? 'annulee';
        $act = '';
        if ($p->fb_id && $p->statut !== 'annulee') $act .= '<a class="button" target="_blank" rel="noopener" href="' . esc_url('https://www.facebook.com/' . $p->fb_id) . '">Voir sur Facebook</a> ';
        if ($p->statut === 'programmee' && gvb_can('publications', 'annuler')) $act .= gvb_form('gvb_fb_annuler', 'gvb_fb_annuler', ['id' => $p->id], 'class="gvb-inline" onsubmit="return confirm(\'Annuler cette publication programmée ?\')"') . gvb_bouton('Annuler', false) . '</form>';
        $lignes[] = '<tr><td>' . esc_html(gvb_dt($p->cree_le)) . ($p->programme_le ? '<br><small>pour le ' . esc_html(gvb_dt($p->programme_le)) . '</small>' : '') . '</td><td>' . esc_html(mb_strimwidth((string) $p->texte, 0, 140, '…')) . ($img ? ' <small>(' . count($img) . ' image' . (count($img) > 1 ? 's' : '') . ')</small>' : '') . '</td><td>' . esc_html($u ? $u->display_name : '—') . '</td><td>' . gvb_badge($etat, GVB_FB_STATUTS[$p->statut] ?? $p->statut) . ($p->erreur ? '<br><small class="gvb-ko">' . esc_html($p->erreur) . '</small>' : '') . '</td><td>' . $act . '</td></tr>';
    }
    echo '<div class="gvb-boite"><h2>' . gvb_icone('journal', 18) . 'Historique</h2>' . gvb_tableau(['Date', 'Publication', 'Auteur', 'État', 'Actions'], $lignes, 'Aucune publication pour le moment.') . '</div>';
    gvb_fin();
}

// ── Réglages (Direction) ──────────────────────────────────────────────────────
add_action('gvb_reglages_apres', function () {
    if (!gvb_can('parametres', 'modifier')) return;
    $c = gvb_fb_reglages();
    echo '<div class="gvb-boite" style="max-width:640px"><h2>' . gvb_icone('rapport', 18) . 'Page Facebook</h2>' . gvb_form('gvb_fb_sauver', 'gvb_fb_sauver')
        . '<label class="gvb-cases"><input type="checkbox" name="actif" value="1"' . checked($c['actif'], 1, false) . '> Activer les publications</label>'
        . gvb_champ('page_id', 'Identifiant de la page', $c['page_id'], 'text', 'pattern="[0-9]+" required')
        . gvb_champ('jeton', 'Jeton d’accès de la page (laisser vide pour conserver l’actuel)', '', 'password', 'autocomplete="new-password" placeholder="' . ($c['jeton'] !== '' ? '•••••••• enregistré' : '') . '"')
        . gvb_bouton('Enregistrer') . '</form>' . gvb_form('gvb_fb_tester', 'gvb_fb_tester', [], 'style="margin-top:8px"') . gvb_bouton('Tester la connexion', false) . '</form></div>';
});
add_action('admin_post_gvb_fb_sauver', function () {
    gvb_garde('parametres', 'modifier', 'gvb_fb_sauver');
    $a = gvb_fb_reglages(); $page = preg_replace('/\D/', '', (string) wp_unslash($_POST['page_id'] ?? ''));
    $jeton = trim((string) wp_unslash($_POST['jeton'] ?? ''));
    if ($page === '') gvb_retour('gv-reglages', [], null, 'Identifiant de page invalide.');
    update_option('gvb_facebook', ['page_id' => $page, 'jeton' => $jeton !== '' ? $jeton : $a['jeton'], 'actif' => empty($_POST['actif']) ? 0 : 1], false);
    gvb_log(0, 'Réglages Facebook modifiés');
    gvb_retour('gv-reglages', [], 'Réglages Facebook enregistrés.');
});
add_action('admin_post_gvb_fb_tester', function () {
    gvb_garde('parametres', 'modifier', 'gvb_fb_tester');
    $r = gvb_fb_appel('GET', preg_replace('/\D/', '', gvb_fb_reglages()['page_id']), ['fields' => 'name']);
    if (is_wp_error($r)) gvb_retour('gv-reglages', [], null, 'Connexion refusée : ' . $r->get_error_message());
    gvb_retour('gv-reglages', [], 'Connexion réussie : page « ' . ($r['name'] ?? '?') . ' ».');
});
