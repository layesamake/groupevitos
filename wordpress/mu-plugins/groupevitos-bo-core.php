<?php
/**
 * Plugin Name: Groupe Vitos — Back-office (socle)
 * Description: Schéma de données, profils et droits, journal, séquences, interface (menu GROUPE VITOS, identité, formulaires), page d'arrivée métier.
 *
 * Même architecture que le back-office OPENTOURS : contrôles côté serveur, aucune surface WordPress pour les équipes métier.
 */

if (!defined('ABSPATH')) exit;

const GVB_DB_VERSION = '1';
const GVB_ROLES_VERSION = '2';
const GVB_POLES = ['universite' => 'Université', 'lycee' => 'Lycée', 'itp' => 'ITP'];
const GVB_DOMAINES = ['admissions' => 'Admissions', 'inscriptions' => 'Inscriptions', 'contacts' => 'Contacts', 'candidatures' => 'Candidatures (CV)', 'parametres' => 'Réglages'];
const GVB_ACTIONS = ['voir' => 'Consulter', 'creer' => 'Créer', 'modifier' => 'Modifier', 'valider' => 'Décider', 'annuler' => 'Annuler', 'exporter' => 'Exporter'];
const GVB_PROFILS = ['gv_direction' => 'Direction', 'gv_scolarite' => 'Scolarité', 'gv_comptabilite' => 'Comptabilité', 'gv_responsable_pole' => 'Responsable de pôle'];
const GVB_STATUTS = ['nouvelle' => 'Nouvelle', 'etude' => 'En étude', 'incomplete' => 'Dossier incomplet', 'attente' => 'Liste d’attente', 'acceptee' => 'Acceptée', 'refusee' => 'Refusée', 'inscrite' => 'Inscrite', 'abandonnee' => 'Abandonnée'];
const GVB_COULEURS = ['nouvelle' => '#8A5A00', 'etude' => '#08065B', 'incomplete' => '#B42318', 'attente' => '#5A3E9B', 'acceptee' => '#007A2E', 'refusee' => '#9B1C1C', 'inscrite' => '#005F73', 'abandonnee' => '#6B7280', 'validee' => '#007A2E', 'annulee' => '#6B7280', 'en_cours' => '#08065B'];

function gvb_table($nom) {
    global $wpdb;
    return $wpdb->prefix . 'gv_' . $nom;
}
function gvb_now() { return current_time('mysql'); }
function gvb_terminer() { if (defined('GVB_ESSAI')) throw new Exception('FIN'); exit; }

// ── Droits par profil ─────────────────────────────────────────────────────────
function gvb_matrice() {
    $tout = 'voir,creer,modifier,valider,annuler,exporter';
    return [
        'gv_direction' => array_fill_keys(array_keys(GVB_DOMAINES), $tout),
        'gv_scolarite' => ['admissions' => 'voir,creer,modifier,exporter', 'inscriptions' => 'voir,creer,modifier,exporter', 'contacts' => 'voir,modifier,exporter'],
        'gv_comptabilite' => ['admissions' => 'voir', 'inscriptions' => 'voir,exporter'],
        'gv_responsable_pole' => ['admissions' => 'voir,creer,modifier,valider', 'inscriptions' => 'voir'],
    ];
}

function gvb_caps_profil($role) {
    $caps = ['read' => true, 'gv_acces' => true];
    foreach (gvb_matrice()[$role] ?? [] as $domaine => $actions) foreach (explode(',', $actions) as $a) $caps["gv_{$domaine}_{$a}"] = true;
    if ($role === 'gv_direction') {
        foreach (['list_users', 'create_users', 'edit_users', 'delete_users', 'promote_users', 'gv_site_publier'] as $cap) $caps[$cap] = true;
        foreach (['edit_candidatures', 'edit_others_candidatures', 'edit_published_candidatures', 'edit_private_candidatures', 'read_private_candidatures', 'delete_candidatures', 'delete_others_candidatures', 'delete_published_candidatures', 'delete_private_candidatures', 'publish_candidatures'] as $cap) $caps[$cap] = true;
    }
    return $caps;
}

function gvb_synchroniser_roles() {
    foreach (GVB_PROFILS as $slug => $label) {
        $role = get_role($slug) ?: add_role($slug, $label, []);
        if (!$role) continue;
        $voulues = gvb_caps_profil($slug);
        foreach (array_keys($role->capabilities) as $cap) if ((str_starts_with($cap, 'gv_') || str_ends_with($cap, '_candidatures') || $cap === 'edit_candidatures') && !isset($voulues[$cap])) $role->remove_cap($cap);
        foreach ($voulues as $cap => $_) if (empty($role->capabilities[$cap])) $role->add_cap($cap);
    }
    if ($admin = get_role('administrator')) {
        foreach (GVB_DOMAINES as $d => $_) foreach (GVB_ACTIONS as $a => $__) if (empty($admin->capabilities["gv_{$d}_{$a}"])) $admin->add_cap("gv_{$d}_{$a}");
        foreach (['gv_acces', 'gv_site_publier'] as $cap) if (empty($admin->capabilities[$cap])) $admin->add_cap($cap);
    }
    update_option('gvb_roles_version', GVB_ROLES_VERSION, false);
}
add_action('init', function () {
    if (get_option('gvb_roles_version') !== GVB_ROLES_VERSION) gvb_synchroniser_roles();
}, 12);

function gvb_can($domaine, $action = 'voir') { return current_user_can("gv_{$domaine}_{$action}"); }
function gvb_exiger($domaine, $action = 'voir') {
    if (!is_user_logged_in() || !gvb_can($domaine, $action)) wp_die('Vous n’avez pas le droit d’effectuer cette opération.', 'Accès refusé', ['response' => 403, 'back_link' => false]);
}
function gvb_garde($domaine, $action, $nonce) {
    gvb_exiger($domaine, $action);
    check_admin_referer($nonce);
}

// Un responsable de pôle ne voit que son pôle (méta utilisateur gv_pole) ; '' = tous les pôles
function gvb_pole_utilisateur() {
    $u = wp_get_current_user();
    if (!in_array('gv_responsable_pole', (array) $u->roles, true) || current_user_can('manage_options')) return '';
    $pole = (string) get_user_meta($u->ID, 'gv_pole', true);
    return isset(GVB_POLES[$pole]) ? $pole : '__aucun__';
}
function gvb_pole_autorise($pole) {
    $p = gvb_pole_utilisateur();
    return $p === '' || $p === $pole;
}

// La Direction ne gère que des comptes métier, jamais un administrateur
add_filter('editable_roles', fn($roles) => current_user_can('manage_options') ? $roles : array_intersect_key($roles, GVB_PROFILS));
add_filter('map_meta_cap', function ($caps, $cap, $user_id, $args) {
    if (in_array($cap, ['edit_user', 'delete_user', 'promote_user', 'remove_user'], true) && !empty($args[0]) && !user_can($user_id, 'manage_options')) {
        $cible = get_userdata((int) $args[0]);
        if ($cible && (user_can($cible, 'manage_options') || !array_intersect(array_keys(GVB_PROFILS), (array) $cible->roles)) && !((int) $args[0] === (int) $user_id && $cap === 'edit_user')) return ['do_not_allow'];
    }
    return $caps;
}, 10, 4);

// Pôle d'un responsable : champ du profil utilisateur (modifiable par la Direction)
add_action('show_user_profile', 'gvb_champ_pole');
add_action('edit_user_profile', 'gvb_champ_pole');
function gvb_champ_pole($user) {
    if (!in_array('gv_responsable_pole', (array) $user->roles, true) || !current_user_can('edit_users')) return;
    echo '<h2>Groupe VITO S.</h2><table class="form-table"><tr><th><label for="gv_pole">Pôle géré</label></th><td><select name="gv_pole" id="gv_pole"><option value="">—</option>';
    foreach (GVB_POLES as $k => $l) echo '<option value="' . esc_attr($k) . '"' . selected(get_user_meta($user->ID, 'gv_pole', true), $k, false) . '>' . esc_html($l) . '</option>';
    echo '</select></td></tr></table>';
}
add_action('personal_options_update', 'gvb_sauver_pole');
add_action('edit_user_profile_update', 'gvb_sauver_pole');
function gvb_sauver_pole($id) {
    if (current_user_can('edit_user', $id) && isset($_POST['gv_pole'])) update_user_meta($id, 'gv_pole', isset(GVB_POLES[$_POST['gv_pole']]) ? $_POST['gv_pole'] : '');
}

// ── Schéma ────────────────────────────────────────────────────────────────────
add_action('init', function () {
    if (get_option('gvb_db_version') === GVB_DB_VERSION) return;
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $c = $wpdb->get_charset_collate();
    dbDelta('CREATE TABLE ' . gvb_table('demandes') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  code varchar(24) NOT NULL DEFAULT '',
  pole varchar(12) NOT NULL DEFAULT 'universite',
  annee varchar(12) NOT NULL DEFAULT '',
  niveau varchar(40) NOT NULL DEFAULT '',
  formation_id bigint(20) unsigned NOT NULL DEFAULT 0,
  serie varchar(60) NOT NULL DEFAULT '',
  nom varchar(120) NOT NULL DEFAULT '',
  prenom varchar(120) NOT NULL DEFAULT '',
  naissance date NULL,
  sexe varchar(1) NOT NULL DEFAULT '',
  nationalite varchar(60) NOT NULL DEFAULT '',
  telephone varchar(50) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  adresse varchar(255) NOT NULL DEFAULT '',
  parent_nom varchar(160) NOT NULL DEFAULT '',
  parent_telephone varchar(50) NOT NULL DEFAULT '',
  parent_email varchar(190) NOT NULL DEFAULT '',
  provenance varchar(190) NOT NULL DEFAULT '',
  message text NULL,
  statut varchar(14) NOT NULL DEFAULT 'nouvelle',
  motif varchar(255) NOT NULL DEFAULT '',
  source varchar(12) NOT NULL DEFAULT 'agence',
  responsable_id bigint(20) unsigned NOT NULL DEFAULT 0,
  doublon_de bigint(20) unsigned NOT NULL DEFAULT 0,
  decide_le datetime NULL,
  decide_par bigint(20) unsigned NOT NULL DEFAULT 0,
  cree_le datetime NOT NULL,
  modifie_le datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY pole_statut (pole,statut),
  KEY annee (annee),
  KEY code (code),
  KEY nom (nom)
) $c;");
    dbDelta('CREATE TABLE ' . gvb_table('pieces') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  demande_id bigint(20) unsigned NOT NULL,
  libelle varchar(255) NOT NULL DEFAULT '',
  recue tinyint(1) NOT NULL DEFAULT 0,
  recue_le datetime NULL,
  recue_par bigint(20) unsigned NOT NULL DEFAULT 0,
  note varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY demande_id (demande_id)
) $c;");
    dbDelta('CREATE TABLE ' . gvb_table('inscriptions') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  demande_id bigint(20) unsigned NOT NULL,
  matricule varchar(24) NOT NULL DEFAULT '',
  pole varchar(12) NOT NULL DEFAULT 'universite',
  annee varchar(12) NOT NULL DEFAULT '',
  niveau varchar(40) NOT NULL DEFAULT '',
  formation_id bigint(20) unsigned NOT NULL DEFAULT 0,
  frais longtext NULL,
  derogation varchar(255) NOT NULL DEFAULT '',
  statut varchar(10) NOT NULL DEFAULT 'validee',
  motif_annulation varchar(255) NOT NULL DEFAULT '',
  inscrit_le datetime NOT NULL,
  inscrit_par bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY demande_id (demande_id),
  KEY matricule (matricule),
  KEY pole_annee (pole,annee)
) $c;");
    dbDelta('CREATE TABLE ' . gvb_table('historique') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  demande_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  action varchar(190) NOT NULL DEFAULT '',
  details text NULL,
  cree_le datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY demande_id (demande_id)
) $c;");
    dbDelta('CREATE TABLE ' . gvb_table('sequences') . " (
  nom varchar(40) NOT NULL,
  valeur bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (nom)
) $c;");
    update_option('gvb_db_version', GVB_DB_VERSION, false);
}, 4);

function gvb_reglages() {
    return wp_parse_args(get_option('gvb_reglages', []), ['annee' => '2026–2027', 'email' => 'contact@groupevitos.com']);
}

function gvb_log($demande_id, $action, $details = '') {
    global $wpdb;
    $wpdb->insert(gvb_table('historique'), ['demande_id' => (int) $demande_id, 'user_id' => get_current_user_id(), 'action' => mb_substr($action, 0, 190), 'details' => $details, 'cree_le' => gvb_now()]);
}

// Numérotation sans trou ni doublon : verrou, lecture, incrément
function gvb_sequence($nom) {
    global $wpdb;
    $verrou = 'gvb_seq_' . $nom;
    $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', $verrou));
    try {
        $t = gvb_table('sequences');
        $v = $wpdb->get_var($wpdb->prepare("SELECT valeur FROM $t WHERE nom = %s", $nom));
        if ($v === null) { $wpdb->insert($t, ['nom' => $nom, 'valeur' => 1]); return 1; }
        $wpdb->update($t, ['valeur' => (int) $v + 1], ['nom' => $nom]);
        return (int) $v + 1;
    } finally {
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $verrou));
    }
}

// Jamais d'e-mail réel depuis un poste de développement ou de recette
function gvb_mail($a, $sujet, $corps) {
    if (defined('GVB_ESSAI')) { $GLOBALS['gvb_mails'][] = [$a, $sujet]; return true; }
    $hote = (string) wp_parse_url(home_url(), PHP_URL_HOST);
    if ($hote === 'localhost' || str_starts_with($hote, '127.') || str_ends_with($hote, '.test')) return false;
    return wp_mail($a, $sujet, $corps);
}

// ── Outils d'affichage ────────────────────────────────────────────────────────
function gvb_url($page, $args = []) {
    return add_query_arg(array_map('rawurlencode', array_merge(['page' => $page], array_filter($args, fn($v) => $v !== null))), admin_url('admin.php'));
}
function gvb_redirect($page, $args = []) { wp_safe_redirect(gvb_url($page, $args)); exit; }
function gvb_retour($page, $args, $ok = null, $erreur = null) {
    if ($ok) $args['gv_msg'] = $ok;
    if ($erreur) $args['gv_err'] = is_wp_error($erreur) ? $erreur->get_error_message() : $erreur;
    gvb_redirect($page, $args);
}
function gvb_dt($v) { return $v ? mysql2date('d/m/Y H:i', $v) : '—'; }
function gvb_d($v) { return $v ? mysql2date('d/m/Y', $v) : '—'; }

const GVB_ICONES = [
    'plus' => 'M12 5v14M5 12h14', 'tableau' => 'M4 4h7v7H4zM13 4h7v4h-7zM13 10h7v10h-7zM4 13h7v7H4z', 'admission' => 'M7 3h8l4 4v14H7zM15 3v4h4M10 12h6M10 16h6',
    'inscription' => 'M4 20V8l8-5 8 5v12M9 20v-6h6v6', 'cv' => 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8M4 21c0-4 4-6 8-6s8 2 8 6', 'reglage' => 'M4 8h10M18 8h2M4 16h2M10 16h10M14 5v6M6 13v6',
    'print' => 'M7 8V3h10v5M5 8h14v8H5zM7 14h10v7H7z', 'utilisateurs' => 'M9 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6M3 20c0-3 3-5 6-5s6 2 6 5M17 11a2.5 2.5 0 1 0 0-5M17 15c2.5 0 4 1.5 4 4',
    'pole' => 'M2 8l10-5 10 5-10 5zM6 10v5c3 2.5 9 2.5 12 0v-5', 'alerte' => 'M12 3l10 18H2zM12 10v5M12 18h.01', 'rapport' => 'M5 20V10M12 20V4M19 20v-7', 'check' => 'M5 12.5l4.5 4.5L19 7', 'croix' => 'M6 6l12 12M18 6L6 18', 'horloge' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18M12 7v5l3 2', 'dossier' => 'M3 6h6l2 2h10v11H3z', 'etoile' => 'M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z', 'liste' => 'M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01', 'journal' => 'M6 3h12v18H6zM9 8h6M9 12h6M9 16h4',
];
function gvb_icone($nom, $taille = 18) {
    return '<svg class="gvb-i" width="' . (int) $taille . '" height="' . (int) $taille . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="' . esc_attr(GVB_ICONES[$nom] ?? GVB_ICONES['admission']) . '"/></svg>';
}
const GVB_ICONES_STATUT = ['nouvelle' => 'etoile', 'etude' => 'horloge', 'incomplete' => 'alerte', 'attente' => 'liste', 'acceptee' => 'check', 'refusee' => 'croix', 'inscrite' => 'inscription', 'abandonnee' => 'croix', 'validee' => 'check', 'annulee' => 'croix'];
function gvb_badge($cle, $label = null) {
    return '<span class="gvb-badge" style="background:' . esc_attr(GVB_COULEURS[$cle] ?? '#4B5578') . '">' . gvb_icone(GVB_ICONES_STATUT[$cle] ?? 'dossier', 12) . ' ' . esc_html($label ?? (GVB_STATUTS[$cle] ?? $cle)) . '</span>';
}
function gvb_carte($titre, $valeur, $note = '', $icone = '', $etat = '', $lien = '') {
    $tag = $lien ? 'a' : 'div';
    return '<' . $tag . ' class="gvb-carte ' . esc_attr($etat) . '"' . ($lien ? ' href="' . esc_url($lien) . '"' : '') . '>' . ($icone ? '<span class="gvb-carte-icone">' . gvb_icone($icone, 22) . '</span>' : '')
        . '<span class="gvb-carte-titre">' . esc_html($titre) . '</span><strong>' . esc_html((string) $valeur) . '</strong>' . ($note ? '<small>' . esc_html($note) . '</small>' : '') . '</' . $tag . '>';
}
function gvb_form($action, $nonce, $caches = [], $attrs = '') {
    $h = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" ' . $attrs . '><input type="hidden" name="action" value="' . esc_attr($action) . '">' . wp_nonce_field($nonce, '_wpnonce', true, false);
    foreach ($caches as $k => $v) $h .= '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr((string) $v) . '">';
    return $h;
}
function gvb_champ($name, $label, $valeur = '', $type = 'text', $attrs = '') {
    return '<label class="gvb-champ"><span>' . esc_html($label) . '</span><input type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr((string) $valeur) . '" ' . $attrs . '></label>';
}
function gvb_liste($name, $label, $options, $valeur = '', $attrs = '') {
    $h = '<label class="gvb-champ"><span>' . esc_html($label) . '</span><select name="' . esc_attr($name) . '" ' . $attrs . '>';
    foreach ($options as $k => $texte) $h .= '<option value="' . esc_attr((string) $k) . '"' . selected((string) $valeur, (string) $k, false) . '>' . esc_html((string) $texte) . '</option>';
    return $h . '</select></label>';
}
function gvb_zone($name, $label, $valeur = '', $lignes = 3) {
    return '<label class="gvb-champ"><span>' . esc_html($label) . '</span><textarea name="' . esc_attr($name) . '" rows="' . (int) $lignes . '">' . esc_textarea((string) $valeur) . '</textarea></label>';
}
function gvb_bouton($label, $primaire = true, $attrs = '') {
    return '<button type="submit" class="button' . ($primaire ? ' button-primary' : '') . '" ' . $attrs . '>' . esc_html($label) . '</button>';
}
function gvb_details($titre, $contenu) {
    return '<details class="gvb-details"><summary>' . esc_html($titre) . '</summary><div class="gvb-details-corps">' . $contenu . '</div></details>';
}
function gvb_tableau($entetes, $lignes, $vide = 'Rien à afficher.') {
    if (!$lignes) return '<p class="gvb-vide">' . esc_html($vide) . '</p>';
    return '<div class="gvb-defile"><table class="widefat striped gvb-tableau"><thead><tr>' . implode('', array_map(fn($e) => '<th scope="col">' . esc_html($e) . '</th>', $entetes)) . '</tr></thead><tbody>' . implode('', $lignes) . '</tbody></table></div>';
}
function gvb_notice() {
    foreach (['gv_msg' => 'notice-success', 'gv_err' => 'notice-error'] as $cle => $classe) if (!empty($_GET[$cle])) echo '<div class="notice ' . $classe . '" role="' . ($classe === 'notice-error' ? 'alert' : 'status') . '"><p>' . esc_html(wp_unslash($_GET[$cle])) . '</p></div>';
}
function gvb_debut($titre, $icone = '', $actions = '') {
    echo '<div class="wrap gvb"><header class="gvb-entete"><h1>' . ($icone ? '<span class="gvb-titre-icone">' . gvb_icone($icone, 26) . '</span>' : '') . esc_html($titre) . '</h1><div class="gvb-entete-actions">' . $actions . '</div></header>';
    gvb_notice();
}
function gvb_fin() { echo '</div>'; }

// ── Menu GROUPE VITOS ─────────────────────────────────────────────────────────
const GVB_PAGES_CACHEES = ['gv-demande' => ['Demande d’admission', 'gv_admissions_voir', 'gvb_page_demande'], 'gv-inscription' => ['Inscription', 'gv_inscriptions_voir', 'gvb_page_inscription']];

add_action('admin_menu', function () {
    add_menu_page('GROUPE VITOS', 'GROUPE VITOS', 'gv_acces', 'gv', 'gvb_page_tableau', 'dashicons-welcome-learn-more', 2);
    foreach ([
        ['Tableau de bord', 'gv', 'gv_acces', 'gvb_page_tableau', 'tableau'], ['Admissions', 'gv-admissions', 'gv_admissions_voir', 'gvb_page_admissions', 'admission'],
        ['Inscriptions', 'gv-inscriptions', 'gv_inscriptions_voir', 'gvb_page_inscriptions', 'inscription'], ['Contacts', 'gv-contacts', 'gv_contacts_voir', 'gvb_page_contacts', 'utilisateurs'], ['Réglages', 'gv-reglages', 'gv_parametres_voir', 'gvb_page_reglages', 'reglage'],
    ] as [$titre, $slug, $cap, $cb, $icone]) add_submenu_page('gv', $titre, '<span class="gvb-mi">' . gvb_icone($icone, 16) . '</span>' . esc_html($titre), $cap, $slug, $cb);
    add_submenu_page('gv', 'Candidatures (CV)', '<span class="gvb-mi">' . gvb_icone('cv', 16) . '</span>Candidatures (CV)', 'edit_candidatures', 'edit.php?post_type=candidature');
    add_submenu_page('gv', 'Utilisateurs', '<span class="gvb-mi">' . gvb_icone('utilisateurs', 16) . '</span>Utilisateurs', 'list_users', 'users.php');
    foreach (GVB_PAGES_CACHEES as $slug => [$titre, $cap, $cb]) add_submenu_page('gv', $titre, $titre, $cap, $slug, $cb);
});
add_action('admin_menu', function () {
    global $submenu;
    foreach ($submenu['gv'] ?? [] as $i => $item) if (isset(GVB_PAGES_CACHEES[$item[2]])) $submenu['gv'][$i][4] = 'gvb-cache';
}, 9999);
// Les équipes métier ne voient aucun écran WordPress
add_action('admin_menu', function () {
    if (current_user_can('manage_options')) return;
    global $menu;
    foreach ($menu as $item) if (!empty($item[2]) && !in_array($item[2], ['gv', 'profile.php', 'edit.php?post_type=candidature'], true) && !str_starts_with((string) ($item[4] ?? ''), 'wp-menu-separator')) remove_menu_page($item[2]);
}, 9998);

add_action('admin_init', function () {
    global $pagenow;
    if ($pagenow === 'index.php' && !wp_doing_ajax() && is_user_logged_in()) { wp_safe_redirect(current_user_can('gv_acces') ? gvb_url('gv') : admin_url('profile.php')); exit; }
});
add_filter('login_redirect', fn($url, $d, $user) => $user instanceof WP_User && $user->has_cap('gv_acces') ? gvb_url('gv') : $url, 10, 3);

// ── Identité ──────────────────────────────────────────────────────────────────
add_action('admin_bar_menu', function ($bar) {
    foreach (['wp-logo', 'comments', 'new-content', 'customize', 'search'] as $n) $bar->remove_node($n);
    if (!current_user_can('manage_options')) $bar->remove_node('updates');
    if ($bar->get_node('site-name')) $bar->add_node(['id' => 'site-name', 'title' => 'Groupe VITO S.', 'href' => 'https://groupevitos.com', 'meta' => ['target' => '_blank']]);
}, 999);
add_filter('admin_footer_text', fn() => 'Groupe VITO S. — espace de gestion');
add_filter('update_footer', '__return_empty_string', 99);
add_filter('admin_title', fn($a, $t) => ($t ?: 'Espace de gestion') . ' — Groupe VITO S.', 10, 2);
add_filter('screen_options_show_screen', fn($v) => current_user_can('manage_options') ? $v : false);
add_filter('login_headerurl', fn() => 'https://groupevitos.com');
add_filter('login_headertext', fn() => 'Groupe VITO S.');
add_filter('login_title', fn() => 'Connexion — Groupe VITO S.');
add_filter('login_message', fn($m) => $m . '<p class="gvb-login-titre">Espace de gestion</p>');
add_action('login_enqueue_scripts', function () {
    echo '<style>body.login{background:#08065B!important;color:#fff}body.login #login h1 a,body.login h1.wp-login-logo a{background-image:url(https://groupevitos.com/logo-institut-vitos.webp)!important;background-size:contain!important;background-repeat:no-repeat!important;background-position:center!important;width:300px!important;height:76px!important;background-color:#fff!important;border-radius:10px;padding:6px;box-sizing:content-box;text-indent:-9999px;overflow:hidden}'
        . 'body.login form{background:#fff;border:0;border-radius:14px;box-shadow:0 14px 40px rgba(0,0,0,.35)}body.login label{color:#08065B;font-weight:600}body.login .button-primary{background:#007A2E!important;border-color:#007A2E!important;border-radius:10px;min-height:42px;font-weight:700;box-shadow:none}'
        . 'body.login #nav a,body.login #backtoblog a{color:#E1E7FA!important;text-decoration:underline}body.login .message,body.login .success,body.login #login_error{color:#111827!important;background:#fff!important;border-left:4px solid #007A2E;border-radius:6px}.gvb-login-titre{text-align:center;color:#E1E7FA;margin:0 0 14px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;font-size:13px}.language-switcher{display:none}</style>';
});

add_action('admin_head', function () {
    if ($e = get_current_screen()) $e->remove_help_tabs();
    echo '<style>
#adminmenuback,#adminmenuwrap,#adminmenu{background:#08065B}#adminmenu a{color:#E1E7FA}#adminmenu li.menu-top:hover,#adminmenu li.opensub>a.menu-top{background:#1B1A7A;color:#fff}
#adminmenu .wp-has-current-submenu .wp-submenu,#adminmenu .wp-submenu{background:#1B1A7A}#adminmenu .wp-submenu li.current a{box-shadow:inset 4px 0 0 #FFC23D}#adminmenu .gvb-mi svg{color:#FFC23D}#adminmenu li.current a.menu-top,#adminmenu li.wp-has-current-submenu a.wp-has-current-submenu,#adminmenu .wp-submenu li.current a{background:#1F4FFF;color:#fff;font-weight:600}
#adminmenu .wp-submenu a{color:#C9D3F5}#adminmenu .wp-submenu a:hover{color:#fff;background:#2A2995}#adminmenu li.gvb-cache,#adminmenu li:has(> a[href$="page=gv-demande"]),#adminmenu li:has(> a[href$="page=gv-inscription"]){display:none}
#adminmenu .gvb-mi{display:inline-flex;vertical-align:-3px;margin-right:8px}#wpadminbar{background:#08065B}#wpadminbar .ab-item,#wpadminbar a.ab-item{color:#E1E7FA}#adminmenu #menu-posts::before{content:"Maintenance et contenus du site";display:block;padding:14px 12px 6px;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#9FB0EA}
.gvb{max-width:1280px;--ink:#08065B;--blue:#1F4FFF;--vert:#007A2E;--ligne:#D6DCEB;--fond:#F2F4F9;--muted:#4A5578;color:#111827}
.gvb-entete{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin:18px 0 14px}.gvb-entete h1{display:flex;align-items:center;gap:10px;margin:0;padding:0;font-size:26px;color:var(--ink);font-weight:800}
.gvb-titre-icone{display:inline-flex;align-items:center;justify-content:center;width:44px;height:44px;border-radius:12px;background:var(--ink);color:#FFC23D;box-shadow:0 3px 0 #FFC23D}.gvb-entete-actions{display:flex;flex-wrap:wrap;gap:8px}
.gvb .button{display:inline-flex;align-items:center;gap:6px;min-height:36px;border-radius:8px}.gvb .button-primary{background:var(--vert);border-color:var(--vert)}.gvb .button-primary:hover,.gvb .button-primary:focus{background:#00662A;border-color:#00662A}
.gvb a:focus-visible,.gvb .button:focus-visible,.gvb input:focus-visible,.gvb select:focus-visible,.gvb textarea:focus-visible,.gvb summary:focus-visible{outline:3px solid var(--blue);outline-offset:2px}.gvb a{color:var(--blue)}.gvb h2{font-size:18px;color:var(--ink);margin:0 0 12px;padding-bottom:8px;border-bottom:3px solid #FFC23D;display:flex;align-items:center;gap:8px}.gvb h2::before{content:"";width:10px;height:10px;border-radius:3px;background:#FFC23D;flex:none}.gvb h3{margin:14px 0 6px}
.gvb-cartes{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:12px;margin:12px 0 20px}.gvb-carte{position:relative;display:grid;gap:2px;padding:14px 14px 14px 58px;background:#fff;border:1px solid var(--ligne);border-radius:12px;text-decoration:none;color:#111827;min-height:76px}
a.gvb-carte:hover{border-color:var(--blue);box-shadow:0 4px 14px rgba(31,79,255,.14)}.gvb-carte strong{font-size:24px;line-height:1.15;color:var(--ink)}.gvb-carte small{color:var(--muted);font-size:12px}.gvb-carte-titre{font-size:13px;font-weight:600;color:var(--muted)}
.gvb-carte-icone{position:absolute;left:12px;top:14px;display:inline-flex;width:34px;height:34px;align-items:center;justify-content:center;border-radius:10px;background:#FFE9A8;color:var(--ink)}.gvb-carte.attention{border-left:5px solid #B42318}.gvb-carte.attention .gvb-carte-icone{background:#FDECEA;color:#B42318}.gvb-carte.ok{border-left:5px solid var(--vert)}
.gvb-boite{background:#fff;border:1px solid var(--ligne);border-radius:12px;padding:16px 18px;margin-bottom:16px}.gvb-grille{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:16px;align-items:start}
.gvb-onglets{display:flex;flex-wrap:wrap;gap:4px;border-bottom:2px solid var(--ligne);margin:6px 0 16px}.gvb-onglets a{padding:9px 14px;text-decoration:none;color:var(--muted);font-weight:600;border-radius:8px 8px 0 0}.gvb-onglets a.actif{color:#fff;background:var(--ink);box-shadow:inset 0 -3px 0 #FFC23D}
.gvb-filtres{display:flex;flex-wrap:wrap;gap:10px;align-items:end;margin:8px 0 14px}.gvb-champ{display:grid;gap:3px;font-size:13px;margin-bottom:8px}.gvb-champ span{font-weight:600;color:var(--ink)}
.gvb-champ input,.gvb-champ select,.gvb-champ textarea{min-height:38px;width:100%;max-width:100%;border:1px solid #8A94B3;border-radius:8px;padding:6px 10px;background:#fff}.gvb-champs{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:4px 14px}
.gvb-cases{display:flex;align-items:center;gap:8px;margin:6px 0}.gvb-badge .gvb-i{vertical-align:-2px}.gvb-badge{display:inline-block;padding:2px 10px;border-radius:999px;color:#fff;font-size:12px;font-weight:600;white-space:nowrap}.gvb-defile{overflow-x:auto}.gvb-tableau th{color:var(--ink);border-bottom:2px solid #FFC23D}.gvb-tableau td.d{text-align:right;white-space:nowrap}
.gvb-vide,.gvb-rappel{color:var(--muted)}.gvb-boite{border-top:4px solid #FFC23D}.gvb-rappel{font-size:13px}.gvb-alerte{border-left:5px solid #B42318;background:#FDECEA;padding:8px 12px;border-radius:6px;margin:8px 0}.gvb-ok{color:#007A2E;font-weight:700}.gvb-ko{color:#B42318;font-weight:700}
.gvb-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:start}.gvb-inline{display:inline-flex;flex-wrap:wrap;gap:6px;align-items:end;margin:0}.gvb-details{border:1px solid var(--ligne);border-radius:8px;padding:2px 10px;background:var(--fond)}.gvb-details summary{cursor:pointer;padding:7px 0;font-weight:600;color:var(--blue)}.gvb-details-corps{padding:6px 0 10px;min-width:260px}
.gvb-journal{list-style:none;margin:0;padding:0}.gvb-journal li{padding:7px 0;border-bottom:1px solid var(--ligne)}.gvb-pieces{list-style:none;margin:0 0 10px;padding:0}.gvb-pieces li{display:flex;flex-wrap:wrap;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid var(--ligne)}
@media(max-width:900px){.gvb-grille{grid-template-columns:1fr}.gvb-entete h1{font-size:22px}.gvb-champs{grid-template-columns:1fr}.gvb-cartes{grid-template-columns:repeat(auto-fill,minmax(160px,1fr))}}
</style>';
});
