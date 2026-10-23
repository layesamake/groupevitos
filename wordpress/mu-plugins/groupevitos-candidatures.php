<?php
/**
 * Plugin Name: Groupe Vitos — Candidatures des enseignants
 * Description: Dépôt de CV depuis le site (formulaire public), stockage privé des fichiers, suivi des candidatures dans l'administration, alertes e-mail et rappel de conservation.
 *
 * Les CV contiennent des données personnelles : ils sont rangés dans un dossier privé (accès direct refusé), jamais exposés par l'API publique,
 * et téléchargés uniquement par un administrateur connecté.
 */

if (!defined('ABSPATH')) exit;

const GVC_ORIGINES = ['https://groupevitos.com', 'https://www.groupevitos.com', 'http://localhost:4321', 'http://localhost:4322'];
const GVC_TAILLE_MAX = 5 * 1024 * 1024;
const GVC_FORMATS = ['pdf' => 'application/pdf', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
const GVC_STATUTS = ['recue' => 'Reçue', 'etude' => 'À étudier', 'entretien' => 'Entretien', 'retenue' => 'Retenue', 'refusee' => 'Refusée', 'archivee' => 'Archivée'];
const GVC_POLES = ['universite' => 'Université', 'lycee' => 'Lycée', 'itp' => 'ITP', 'plusieurs' => 'Plusieurs pôles'];

function gvc_reglages() {
    return wp_parse_args(get_option('groupevitos_candidatures', []), ['email' => 'contact@groupevitos.com', 'conservation_mois' => 24]);
}

// ── Type de contenu et droits : réservé aux administrateurs ────────────────────
add_action('init', function () {
    register_post_type('candidature', [
        'label' => 'Candidatures', 'labels' => ['singular_name' => 'Candidature', 'edit_item' => 'Candidature', 'search_items' => 'Chercher une candidature', 'not_found' => 'Aucune candidature'],
        'public' => false, 'show_ui' => true, 'show_in_rest' => false, 'menu_icon' => 'dashicons-id', 'menu_position' => 11,
        'capability_type' => 'candidature', 'map_meta_cap' => true, 'capabilities' => ['create_posts' => 'do_not_allow'], 'supports' => ['title'],
    ]);
    if ($role = get_role('administrator')) {
        foreach (['edit_candidatures', 'edit_others_candidatures', 'edit_published_candidatures', 'edit_private_candidatures', 'read_private_candidatures', 'delete_candidatures', 'delete_others_candidatures', 'delete_published_candidatures', 'delete_private_candidatures', 'publish_candidatures'] as $cap) {
            if (empty($role->capabilities[$cap])) $role->add_cap($cap);
        }
    }
});

// ── Stockage privé ─────────────────────────────────────────────────────────────
function gvc_dossier() {
    $dir = trailingslashit(wp_upload_dir()['basedir']) . 'groupevitos-prive';
    if (!is_dir($dir)) {
        wp_mkdir_p($dir);
        file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        file_put_contents($dir . '/index.html', '');
    }
    return $dir;
}

// ── Réception : POST /wp-json/groupevitos/v1/candidatures (formulaire multipart) ─
add_action('rest_api_init', function () {
    register_rest_route('groupevitos/v1', '/candidatures', ['methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'gvc_recevoir']);
});

add_filter('rest_pre_serve_request', function ($servi, $resultat, $requete) {
    if (str_starts_with($requete->get_route(), '/groupevitos/v1/candidatures') && in_array(get_http_origin(), GVC_ORIGINES, true)) {
        header('Access-Control-Allow-Origin: ' . get_http_origin());
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Vary: Origin');
    }
    return $servi;
}, 10, 3);

function gvc_recevoir(WP_REST_Request $requete) {
    $d = $requete->get_body_params() ?: $requete->get_json_params();
    if (!empty($d['site_web'])) return ['ok' => true]; // champ piège : robot

    $cle = 'gvc_depot_' . md5(sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''));
    if ((int) get_transient($cle) >= 5) return new WP_Error('limite', 'Trop de dépôts envoyés. Réessayez dans une heure ou contactez-nous.', ['status' => 429]);

    $c = [
        'nom' => sanitize_text_field($d['nom'] ?? ''), 'prenom' => sanitize_text_field($d['prenom'] ?? ''), 'email' => sanitize_email($d['email'] ?? ''), 'telephone' => sanitize_text_field($d['telephone'] ?? ''),
        'pole' => sanitize_key($d['pole'] ?? ''), 'discipline' => sanitize_text_field($d['discipline'] ?? ''), 'niveau' => sanitize_text_field($d['niveau'] ?? ''), 'diplome' => sanitize_text_field($d['diplome'] ?? ''),
        'experience' => min(60, absint($d['experience'] ?? 0)), 'disponibilite' => sanitize_text_field($d['disponibilite'] ?? ''), 'message' => mb_substr(sanitize_textarea_field($d['message'] ?? ''), 0, 2000),
    ];
    if ($c['nom'] === '' || $c['prenom'] === '' || !is_email($c['email']) || $c['discipline'] === '') return new WP_Error('champs', 'Indiquez votre nom, votre prénom, une adresse e-mail valide et votre discipline.', ['status' => 422]);
    if (!isset(GVC_POLES[$c['pole']])) return new WP_Error('pole', 'Choisissez le pôle concerné.', ['status' => 422]);
    if (empty($d['consentement'])) return new WP_Error('consentement', 'Merci d’accepter l’utilisation de vos données pour étudier votre candidature.', ['status' => 422]);

    $fichier = $requete->get_file_params()['cv'] ?? null;
    $verif = gvc_verifier_fichier($fichier);
    if (is_wp_error($verif)) return $verif;

    $nom_stocke = wp_generate_password(32, false) . '.' . $verif['ext'];
    $ok = is_uploaded_file($fichier['tmp_name']) ? move_uploaded_file($fichier['tmp_name'], gvc_dossier() . '/' . $nom_stocke) : (defined('GVC_ESSAI') && copy($fichier['tmp_name'], gvc_dossier() . '/' . $nom_stocke));
    if (!$ok) return new WP_Error('stockage', 'Le fichier n’a pas pu être enregistré. Réessayez ou contactez-nous.', ['status' => 500]);

    $id = wp_insert_post(['post_type' => 'candidature', 'post_status' => 'private', 'post_title' => mb_strtoupper($c['nom']) . ' ' . $c['prenom'] . ' — ' . $c['discipline']]);
    if (!$id || is_wp_error($id)) { @unlink(gvc_dossier() . '/' . $nom_stocke); return new WP_Error('enregistrement', 'La candidature n’a pas pu être enregistrée.', ['status' => 500]); }
    foreach ($c as $k => $v) update_post_meta($id, 'gvc_' . $k, $v);
    update_post_meta($id, 'gvc_statut', 'recue');
    update_post_meta($id, 'gvc_fichier', $nom_stocke);
    update_post_meta($id, 'gvc_fichier_nom', sanitize_file_name($fichier['name']));
    set_transient($cle, (int) get_transient($cle) + 1, HOUR_IN_SECONDS);

    $reg = gvc_reglages();
    $lien = admin_url('post.php?post=' . $id . '&action=edit');
    gvc_mail($reg['email'], 'Nouvelle candidature enseignant : ' . $c['prenom'] . ' ' . $c['nom'], "Pôle : " . GVC_POLES[$c['pole']] . "\nDiscipline : {$c['discipline']}\nNiveau : {$c['niveau']}\nTéléphone : {$c['telephone']}\nE-mail : {$c['email']}\n\nVoir la candidature : $lien");
    gvc_mail($c['email'], 'Votre candidature au Groupe VITO S.', "Bonjour {$c['prenom']},\n\nNous avons bien reçu votre CV. Il sera étudié par notre équipe ; nous reviendrons vers vous si votre profil correspond à nos besoins.\n\nGroupe VITO S.");
    return ['ok' => true];
}

// Vérifie la présence, la taille et le vrai type du fichier (pas seulement son extension)
function gvc_verifier_fichier($f) {
    if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return new WP_Error('cv', 'Joignez votre CV (PDF, DOC ou DOCX).', ['status' => 422]);
    if (($f['error'] ?? 0) !== UPLOAD_ERR_OK || ($f['size'] ?? 0) <= 0) return new WP_Error('cv', 'Le fichier n’a pas pu être envoyé. Réessayez.', ['status' => 422]);
    if ($f['size'] > GVC_TAILLE_MAX) return new WP_Error('cv', 'Le CV dépasse 5 Mo : réduisez-le puis réessayez.', ['status' => 422]);
    $v = wp_check_filetype_and_ext($f['tmp_name'], $f['name'], GVC_FORMATS);
    if (empty($v['ext']) || empty($v['type'])) return new WP_Error('cv', 'Format non accepté : envoyez un fichier PDF, DOC ou DOCX.', ['status' => 422]);
    return $v;
}

function gvc_mail($a, $sujet, $corps) {
    if (defined('GVC_ESSAI')) { $GLOBALS['gvc_mails'][] = [$a, $sujet]; return true; }
    $hote = (string) wp_parse_url(home_url(), PHP_URL_HOST);
    if ($hote === 'localhost' || str_starts_with($hote, '127.')) return false; // jamais d'e-mail réel depuis un poste de développement
    return wp_mail($a, $sujet, $corps);
}

// ── Administration ─────────────────────────────────────────────────────────────
add_filter('manage_candidature_posts_columns', fn() => ['cb' => '<input type="checkbox" />', 'title' => 'Candidat', 'pole' => 'Pôle', 'statut' => 'Statut', 'depot' => 'Déposée le', 'cv' => 'CV', 'purge' => 'Conservation']);
add_action('manage_candidature_posts_custom_column', function ($col, $id) {
    $pole = get_post_meta($id, 'gvc_pole', true);
    if ($col === 'pole') echo esc_html(GVC_POLES[$pole] ?? '—');
    if ($col === 'statut') echo esc_html(GVC_STATUTS[get_post_meta($id, 'gvc_statut', true)] ?? '—');
    if ($col === 'depot') echo esc_html(get_the_date('d/m/Y', $id));
    if ($col === 'cv') echo '<a href="' . esc_url(gvc_lien_cv($id)) . '">Télécharger</a>';
    if ($col === 'purge') { $fin = strtotime(get_the_date('Y-m-d', $id) . ' +' . (int) gvc_reglages()['conservation_mois'] . ' months'); echo $fin < time() ? '<strong style="color:#B42318">À supprimer</strong>' : esc_html(wp_date('d/m/Y', $fin)); }
}, 10, 2);

function gvc_lien_cv($id) {
    return wp_nonce_url(admin_url('admin-post.php?action=gvc_cv&id=' . (int) $id), 'gvc_cv_' . (int) $id);
}

add_action('add_meta_boxes_candidature', function () {
    add_meta_box('gvc-detail', 'Candidature', function ($post) {
        wp_nonce_field('gvc_candidature', 'gvc_nonce');
        $m = fn($k) => get_post_meta($post->ID, 'gvc_' . $k, true);
        echo '<table class="form-table"><tbody>';
        foreach (['nom' => 'Nom', 'prenom' => 'Prénom', 'email' => 'E-mail', 'telephone' => 'Téléphone', 'discipline' => 'Discipline', 'niveau' => 'Niveau d’enseignement', 'diplome' => 'Diplôme le plus élevé', 'experience' => 'Années d’expérience', 'disponibilite' => 'Disponibilité'] as $k => $label) echo '<tr><th>' . esc_html($label) . '</th><td>' . esc_html($m($k)) . '</td></tr>';
        echo '<tr><th>Pôle</th><td>' . esc_html(GVC_POLES[$m('pole')] ?? '') . '</td></tr><tr><th>Message</th><td>' . nl2br(esc_html($m('message'))) . '</td></tr>';
        echo '<tr><th>CV</th><td><a class="button" href="' . esc_url(gvc_lien_cv($post->ID)) . '">Télécharger ' . esc_html($m('fichier_nom')) . '</a></td></tr>';
        echo '<tr><th><label for="gvc-statut">Statut</label></th><td><select id="gvc-statut" name="gvc_statut">';
        foreach (GVC_STATUTS as $k => $label) echo '<option value="' . esc_attr($k) . '"' . selected($m('statut'), $k, false) . '>' . esc_html($label) . '</option>';
        echo '</select></td></tr><tr><th><label for="gvc-notes">Notes internes</label></th><td><textarea id="gvc-notes" name="gvc_notes" rows="5" class="large-text">' . esc_textarea($m('notes')) . '</textarea></td></tr></tbody></table>';
    }, 'candidature', 'normal', 'high');
});

add_action('save_post_candidature', function ($id) {
    if (!isset($_POST['gvc_nonce']) || !wp_verify_nonce($_POST['gvc_nonce'], 'gvc_candidature') || !current_user_can('edit_post', $id)) return;
    $statut = sanitize_key($_POST['gvc_statut'] ?? '');
    if (isset(GVC_STATUTS[$statut])) update_post_meta($id, 'gvc_statut', $statut);
    update_post_meta($id, 'gvc_notes', sanitize_textarea_field(wp_unslash($_POST['gvc_notes'] ?? '')));
});

// Téléchargement du CV : administrateur connecté, jeton de sécurité obligatoire
add_action('admin_post_gvc_cv', function () {
    $id = absint($_GET['id'] ?? 0);
    check_admin_referer('gvc_cv_' . $id);
    if (!current_user_can('edit_candidatures') || get_post_type($id) !== 'candidature') wp_die('Accès refusé', 'Accès refusé', 403);
    $chemin = gvc_dossier() . '/' . basename((string) get_post_meta($id, 'gvc_fichier', true));
    if (!is_file($chemin)) wp_die('Fichier introuvable.', '', 404);
    $type = wp_check_filetype($chemin, GVC_FORMATS)['type'] ?: 'application/octet-stream';
    nocache_headers();
    header('Content-Type: ' . $type);
    header('Content-Length: ' . filesize($chemin));
    header('Content-Disposition: attachment; filename="' . rawurlencode((string) get_post_meta($id, 'gvc_fichier_nom', true)) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($chemin);
    exit;
});

// Suppression d'une candidature : le fichier privé est supprimé avec elle
add_action('before_delete_post', function ($id) {
    if (get_post_type($id) !== 'candidature') return;
    $f = basename((string) get_post_meta($id, 'gvc_fichier', true));
    if ($f && is_file(gvc_dossier() . '/' . $f)) @unlink(gvc_dossier() . '/' . $f);
});

// Réglages : e-mail d'alerte et durée de conservation
add_action('admin_menu', function () {
    add_submenu_page('edit.php?post_type=candidature', 'Réglages des candidatures', 'Réglages', 'manage_options', 'gvc-reglages', function () {
        $r = gvc_reglages();
        echo '<div class="wrap"><h1>Réglages des candidatures</h1><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('gvc_reglages');
        echo '<input type="hidden" name="action" value="gvc_reglages"><table class="form-table"><tr><th><label for="gvc-email">E-mail qui reçoit les alertes</label></th><td><input type="email" class="regular-text" id="gvc-email" name="email" value="' . esc_attr($r['email']) . '"></td></tr>'
            . '<tr><th><label for="gvc-mois">Conservation (mois)</label></th><td><input type="number" min="1" max="120" id="gvc-mois" name="conservation_mois" value="' . (int) $r['conservation_mois'] . '"><p class="description">Au-delà, la liste signale « À supprimer » : la suppression reste manuelle.</p></td></tr></table>';
        submit_button();
        echo '</form></div>';
    });
});
add_action('admin_post_gvc_reglages', function () {
    if (!current_user_can('manage_options')) wp_die('Accès refusé', 'Accès refusé', 403);
    check_admin_referer('gvc_reglages');
    update_option('groupevitos_candidatures', ['email' => sanitize_email(wp_unslash($_POST['email'] ?? '')) ?: 'contact@groupevitos.com', 'conservation_mois' => max(1, min(120, absint($_POST['conservation_mois'] ?? 24)))], false);
    wp_safe_redirect(admin_url('edit.php?post_type=candidature&page=gvc-reglages&maj=1'));
    exit;
});
