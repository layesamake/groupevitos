<?php
/**
 * Plugin Name: Groupe Vitos — Back-office : recherche globale, « + Nouveau » et raccourcis
 * Description: Recherche dans la barre du haut (étudiants, demandes, enseignants, comptes, reçus, contacts, cours), actions rapides par profil, écrans épinglés et récents.
 */

if (!defined('ABSPATH')) exit;

const GVB_RECH_PAR_GROUPE = 8;
const GVB_EPINGLES_MAX = 8;
const GVB_RECENTS_MAX = 8;

// ── Recherche ─────────────────────────────────────────────────────────────────
function gvb_chiffres($s) { return preg_replace('/\D/', '', (string) $s); }
function gvb_rech_tel($col, $chiffres) { return "REPLACE(REPLACE(REPLACE(REPLACE($col, ' ', ''), '-', ''), '+', ''), '.', '') LIKE '%" . esc_sql($chiffres) . "%'"; }

function gvb_rechercher($q, $pour = '') {
    global $wpdb;
    $q = trim(mb_substr(wp_strip_all_tags((string) $q), 0, 80));
    if (mb_strlen($q) < 2) return [];
    $like = '%' . $wpdb->esc_like($q) . '%'; $ch = gvb_chiffres($q); $tel = strlen($ch) >= 6 && strlen($ch) >= 0.6 * mb_strlen(str_replace([' ', '+', '-', '.'], '', $q));
    $pole = gvb_pole_utilisateur(); if ($pole === '__aucun__') return [];
    $L = GVB_RECH_PAR_GROUPE + 1; $out = [];
    $groupe = function ($cle, $titre, $icone, $items) use (&$out) { if ($items) $out[$cle] = ['titre' => $titre, 'icone' => $icone, 'items' => array_slice($items, 0, GVB_RECH_PAR_GROUPE), 'plus' => count($items) > GVB_RECH_PAR_GROUPE]; };
    $pw = fn($alias = '') => $pole !== '' ? $wpdb->prepare(' AND ' . ($alias ? "$alias." : '') . 'pole = %s', $pole) : '';

    if (gvb_can('etudiants', 'voir')) {
        $cond = '(nom LIKE %s OR prenom LIKE %s OR matricule LIKE %s OR email LIKE %s OR parent_nom LIKE %s' . ($tel ? ' OR ' . gvb_rech_tel('telephone', $ch) . ' OR ' . gvb_rech_tel('parent_telephone', $ch) : '') . ')';
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . gvb_table('etudiants') . " WHERE $cond" . $pw() . " ORDER BY nom, prenom LIMIT $L", $like, $like, $like, $like, $like));
        $groupe('etudiants', 'Étudiants et élèves', 'pole', array_map(fn($e) => [mb_strtoupper($e->nom) . ' ' . $e->prenom, $e->matricule . ' · ' . (GVB_POLES[$e->pole] ?? '') . ' · ' . $e->niveau . ' · ' . (GVB_ETU_STATUTS[$e->statut] ?? $e->statut), $pour === 'paiement' && gvb_can('finances', 'voir') ? gvb_url('gv-compte', ['id' => $e->id]) : gvb_url('gv-etudiant', ['id' => $e->id])], $rows));
    }
    if (gvb_can('admissions', 'voir')) {
        $cond = '(nom LIKE %s OR prenom LIKE %s OR code LIKE %s OR email LIKE %s OR parent_nom LIKE %s' . ($tel ? ' OR ' . gvb_rech_tel('telephone', $ch) . ' OR ' . gvb_rech_tel('parent_telephone', $ch) : '') . ')';
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . gvb_table('demandes') . " WHERE $cond" . $pw() . " ORDER BY id DESC LIMIT $L", $like, $like, $like, $like, $like));
        $groupe('demandes', 'Demandes d’admission', 'admission', array_map(fn($d) => [$d->prenom . ' ' . mb_strtoupper($d->nom), $d->code . ' · ' . (GVB_POLES[$d->pole] ?? '') . ' · ' . $d->annee . ' · ' . (GVB_STATUTS[$d->statut] ?? $d->statut), gvb_url('gv-demande', ['id' => $d->id])], $rows));
    }
    if (gvb_can('pedagogie', 'voir')) {
        $cond = '(nom LIKE %s OR prenom LIKE %s OR email LIKE %s OR specialites LIKE %s' . ($tel ? ' OR ' . gvb_rech_tel('telephone', $ch) : '') . ')';
        $rows = array_filter($wpdb->get_results($wpdb->prepare('SELECT * FROM ' . gvb_table('enseignants') . " WHERE $cond ORDER BY nom LIMIT $L", $like, $like, $like, $like)), 'gvb_ens_dans_perimetre');
        $groupe('enseignants', 'Enseignants', 'cv', array_map(fn($e) => [gvb_nom_ens($e), (GVB_ENS_STATUTS[$e->statut] ?? '') . ' · ' . ($e->specialites ?: 'spécialités non renseignées') . ($e->actif ? '' : ' · inactif'), gvb_url('gv-enseignant', ['id' => $e->id])], array_values($rows)));
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . gvb_table('cours') . ' WHERE actif = 1 AND (intitule LIKE %s OR code LIKE %s)' . $pw() . " ORDER BY intitule LIMIT $L", $like, $like));
        $groupe('cours', 'Cours', 'liste', array_map(fn($c) => [$c->intitule, (GVB_POLES[$c->pole] ?? '') . ' · ' . $c->niveau . ' · ' . $c->volume . ' h', gvb_url('gv-programmes', ['pole' => $c->pole])], $rows));
    }
    if (gvb_can('finances', 'voir')) {
        $rows = $wpdb->get_results($wpdb->prepare('SELECT p.*, e.nom, e.prenom, e.matricule, e.pole FROM ' . gvb_table('paiements') . ' p JOIN ' . gvb_table('etudiants') . ' e ON e.id = p.etudiant_id WHERE (p.recu LIKE %s OR p.reference LIKE %s)' . $pw('e') . " ORDER BY p.id DESC LIMIT $L", $like, $like));
        $groupe('paiements', 'Reçus et paiements', 'argent', array_map(fn($p) => [$p->recu . ($p->annule ? ' (annulé)' : ''), gvb_montant($p->montant) . ' · ' . $p->prenom . ' ' . mb_strtoupper($p->nom) . ' · ' . gvb_d($p->date_paiement) . ($p->reference ? ' · réf. ' . $p->reference : ''), gvb_url('gv-recu', ['id' => $p->id])], $rows));
    }
    if (gvb_can('espaces', 'voir') && $pole === '') {
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . gvb_table('comptes') . " WHERE email LIKE %s OR nom LIKE %s ORDER BY id DESC LIMIT $L", $like, $like));
        $groupe('comptes', 'Comptes des espaces en ligne', 'utilisateurs', array_map(fn($c) => [$c->email, (GVB_TYPES_COMPTE[$c->type] ?? '') . ' · ' . $c->nom . ($c->actif ? '' : ' · désactivé'), gvb_url('gv-espaces', ['q' => $c->email])], $rows));
    }
    if (gvb_can('contacts', 'voir') && $pole === '') {
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . gvb_table('contacts') . " WHERE statut <> 'supprime' AND (email LIKE %s OR nom LIKE %s" . ($tel ? ' OR ' . gvb_rech_tel('whatsapp', $ch) : '') . ") ORDER BY id DESC LIMIT $L", $like, $like));
        $groupe('contacts', 'Contacts de la plaquette', 'utilisateurs', array_map(fn($c) => [$c->nom ?: ($c->email ?: $c->whatsapp), ($c->email ?: $c->whatsapp) . ' · ' . gvb_dt($c->cree_le), gvb_url('gv-contacts', ['q' => $c->email ?: $c->whatsapp])], $rows));
    }
    return $out;
}

// ── Écran de résultats ────────────────────────────────────────────────────────
function gvb_page_recherche() {
    if (!current_user_can('gv_acces')) wp_die('Accès refusé.', 'Accès refusé', ['response' => 403, 'back_link' => false]);
    $q = trim((string) wp_unslash($_GET['q'] ?? '')); $pour = ($_GET['pour'] ?? '') === 'paiement' ? 'paiement' : '';
    $res = gvb_rechercher($q, $pour);
    if ($res && preg_match('/^((UNI|LYC|ITP)-\d{4}-\d{4}|ADM-\d{4}-\d{5}|REC-\d{4}-\d{5})$/i', $q)) {
        $tous = []; foreach ($res as $g) foreach ($g['items'] as $it) $tous[] = $it;
        if (count($tous) === 1) { wp_safe_redirect($tous[0][2]); gvb_terminer(); }
    }
    gvb_debut($pour === 'paiement' ? 'Choisir l’étudiant à encaisser' : 'Recherche', 'plus');
    echo '<form method="get" class="gvb-filtres"><input type="hidden" name="page" value="gv-recherche">' . ($pour ? '<input type="hidden" name="pour" value="paiement">' : '') . gvb_champ('q', 'Nom, matricule, code, téléphone, e-mail, n° de reçu…', $q, 'search', 'autofocus minlength="2" maxlength="80"') . gvb_bouton('Rechercher', false) . '</form>';
    if (mb_strlen($q) < 2) echo '<p class="gvb-rappel">Saisissez au moins 2 caractères. Exemples : <code>UNI-2026-0001</code>, <code>Diarra</code>, <code>70 11 22 33</code>, <code>REC-2026-00012</code>.</p>';
    elseif (!$res) echo '<div class="gvb-boite"><p>Aucun résultat pour « ' . esc_html($q) . ' » dans les rubriques auxquelles votre profil donne accès.</p></div>';
    foreach ($res as $g) {
        $l = ''; foreach ($g['items'] as [$lib, $det, $url]) $l .= '<li><a href="' . esc_url($url) . '"><strong>' . esc_html($lib) . '</strong></a><br><small class="gvb-rappel">' . esc_html($det) . '</small></li>';
        echo '<div class="gvb-boite"><h2>' . gvb_icone($g['icone'], 18) . esc_html($g['titre']) . '</h2><ul class="gvb-journal">' . $l . '</ul>' . ($g['plus'] ? '<p class="gvb-rappel">D’autres résultats existent : précisez votre recherche.</p>' : '') . '</div>';
    }
    gvb_fin();
}

// ── Actions rapides (« + Nouveau » et raccourcis) ─────────────────────────────
function gvb_actions_rapides($user = null) {
    $u = $user ?: wp_get_current_user(); $c = fn($cap) => user_can($u, $cap); $a = [];
    if ($c('gv_admissions_creer')) $a[] = ['demande', 'Nouvelle demande d’admission', gvb_url('gv-demande')];
    if ($c('gv_finances_creer')) $a[] = ['paiement', 'Enregistrer un paiement', gvb_url('gv-recherche', ['pour' => 'paiement'])];
    if ($c('gv_planning_creer')) $a[] = ['seance', 'Planifier une séance', gvb_url('gv-planning')];
    if ($c('gv_pedagogie_creer')) { $a[] = ['enseignant', 'Nouvel enseignant', gvb_url('gv-enseignants')]; $a[] = ['cours', 'Nouveau cours', gvb_url('gv-programmes')]; }
    if ($c('gv_planning_creer')) $a[] = ['salle', 'Nouvelle salle', gvb_url('gv-salles')];
    if ($c('gv_espaces_creer')) { $a[] = ['annonce', 'Nouvelle annonce', gvb_url('gv-annonces')]; $a[] = ['invitation', 'Inviter un parent ou un enseignant', gvb_url('gv-espaces')]; }
    if ($c('gv_publications_creer')) $a[] = ['publication', 'Nouvelle publication Facebook', gvb_url('gv-publications')];
    if ($c('edit_posts')) $a[] = ['article', 'Nouvel article', admin_url('post-new.php')];
    return $a;
}

// ── Écrans épinglés et récents ────────────────────────────────────────────────
function gvb_pages_epinglables($user = null) {
    $out = [];
    foreach (gvb_menu_visible($user ?: wp_get_current_user()) as $s) foreach ($s['items'] as $i) if ($i[1] !== 'gv' && !in_array($i[1], GVB_EXTERNES, true)) $out[$i[1]] = $i[0];
    return $out;
}
function gvb_epingles($uid = 0) {
    $e = get_user_meta($uid ?: get_current_user_id(), 'gvb_epingles', true); $ok = gvb_pages_epinglables($uid ? get_userdata($uid) : null);
    return array_values(array_filter(is_array($e) ? $e : [], fn($s) => isset($ok[$s])));
}
function gvb_noter_recent($titre) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || !is_user_logged_in()) return;
    $p = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
    if ($p === '' || !str_starts_with($p, 'gv-') || in_array($p, ['gv-recherche'], true)) return;
    $args = []; foreach (['id', 'sem', 'e'] as $k) if (isset($_GET[$k]) && preg_match('/^[\w-]{1,20}$/', (string) $_GET[$k])) $args[$k] = (string) $_GET[$k];
    $url = gvb_url($p, $args); $uid = get_current_user_id();
    $r = get_user_meta($uid, 'gvb_recents', true); $r = is_array($r) ? $r : [];
    $r = array_values(array_filter($r, fn($x) => ($x[0] ?? '') !== $url));
    array_unshift($r, [$url, mb_substr($titre, 0, 80)]);
    update_user_meta($uid, 'gvb_recents', array_slice($r, 0, GVB_RECENTS_MAX));
}
add_filter('gvb_debut_actions', function ($actions, $titre) {
    gvb_noter_recent($titre);
    $p = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
    $pages = gvb_pages_epinglables();
    if ($p === '' || !isset($pages[$p])) return $actions;
    $epingle = in_array($p, gvb_epingles(), true);
    return $actions . gvb_form('gvb_epingler', 'gvb_epingler', ['page' => $p], 'class="gvb-inline"') . '<button type="submit" class="button" title="' . esc_attr($epingle ? 'Retirer des raccourcis' : 'Ajouter aux raccourcis') . '" aria-pressed="' . ($epingle ? 'true' : 'false') . '">' . ($epingle ? '★ Épinglé' : '☆ Épingler') . '</button></form>';
}, 10, 2);
add_action('admin_post_gvb_epingler', function () {
    check_admin_referer('gvb_epingler');
    if (!current_user_can('gv_acces')) wp_die('Accès refusé.', 'Accès refusé', ['response' => 403, 'back_link' => false]);
    $p = sanitize_key($_POST['page'] ?? ''); $pages = gvb_pages_epinglables(); $uid = get_current_user_id();
    if (!isset($pages[$p])) gvb_retour('gv', [], null, 'Cet écran ne peut pas être épinglé.');
    $e = gvb_epingles();
    if (in_array($p, $e, true)) { $e = array_values(array_diff($e, [$p])); $msg = 'Raccourci retiré.'; }
    else { if (count($e) >= GVB_EPINGLES_MAX) gvb_retour($p, [], null, 'Vous pouvez épingler ' . GVB_EPINGLES_MAX . ' écrans au maximum : retirez-en un d’abord.'); $e[] = $p; $msg = 'Écran épinglé dans vos raccourcis.'; }
    update_user_meta($uid, 'gvb_epingles', $e);
    gvb_retour($p, [], $msg);
});
function gvb_bloc_epingles() {
    $pages = gvb_pages_epinglables(); $h = '';
    foreach (gvb_epingles() as $s) $h .= '<a class="button" href="' . esc_url(gvb_url($s)) . '">★ ' . esc_html($pages[$s]) . '</a> ';
    $r = get_user_meta(get_current_user_id(), 'gvb_recents', true); $l = '';
    foreach (is_array($r) ? $r : [] as [$url, $titre]) $l .= '<li><a href="' . esc_url($url) . '">' . esc_html($titre) . '</a></li>';
    return ($h ? '<p class="gvb-actions">' . $h . '</p>' : '<p class="gvb-rappel">Aucun écran épinglé : utilisez le bouton « ☆ Épingler » en haut de n’importe quel écran.</p>') . ($l ? '<h3>Consultés récemment</h3><ul class="gvb-journal">' . $l . '</ul>' : '');
}

// ── Barre d'administration : recherche, + Nouveau, ★ Raccourcis ───────────────
add_action('admin_bar_menu', function ($bar) {
    if (!(is_admin() || defined('GVB_ESSAI')) || !current_user_can('gv_acces')) return;
    $bar->add_node(['id' => 'gvb-recherche', 'title' => '<form action="' . esc_url(admin_url('admin.php')) . '" method="get" role="search"><input type="hidden" name="page" value="gv-recherche"><input type="search" name="q" minlength="2" maxlength="80" placeholder="Rechercher : nom, matricule, téléphone…" aria-label="Recherche globale"></form>', 'meta' => ['class' => 'gvb-recherche']]);
    $acts = gvb_actions_rapides();
    if ($acts) { $bar->add_node(['id' => 'gvb-nouveau', 'title' => '+ Nouveau', 'href' => false]); foreach ($acts as [$id, $lib, $url]) $bar->add_node(['id' => 'gvb-nouveau-' . $id, 'parent' => 'gvb-nouveau', 'title' => esc_html($lib), 'href' => $url]); }
    $pages = gvb_pages_epinglables(); $ep = gvb_epingles();
    if ($ep) { $bar->add_node(['id' => 'gvb-favoris', 'title' => '★ Raccourcis', 'href' => false]); foreach ($ep as $s) $bar->add_node(['id' => 'gvb-fav-' . $s, 'parent' => 'gvb-favoris', 'title' => esc_html($pages[$s]), 'href' => gvb_url($s)]); }
}, 100);
add_action('admin_head', function () {
    echo '<style>#wpadminbar .gvb-recherche{padding:0}#wpadminbar .gvb-recherche form{margin:0;padding:3px 6px}#wpadminbar .gvb-recherche input[type=search]{width:260px;height:26px;min-height:0;border-radius:6px;border:0;padding:2px 10px;background:#fff;color:#111827}#wpadminbar #wp-admin-bar-gvb-nouveau>.ab-item{background:#FFC23D;color:#08065B!important;font-weight:700}@media(max-width:782px){#wpadminbar .gvb-recherche input[type=search]{width:130px}}</style>';
});
