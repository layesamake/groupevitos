<?php
/**
 * Plugin Name: Groupe Vitos — Back-office : tutoriels par profil
 * Description: Page « Tutoriels » réservée aux gestionnaires de la plateforme : un tutoriel illustré par profil (contenu et captures dans groupevitos-bo-tutoriels/, générés par scripts/generer-tutoriels.mjs).
 */

if (!defined('ABSPATH')) exit;

const GVB_TUTO_DIR = __DIR__ . '/groupevitos-bo-tutoriels/';

// ── Contenu ───────────────────────────────────────────────────────────────────
function gvb_tuto_index() {
    static $index = null;
    if ($index === null) {
        $json = is_readable(GVB_TUTO_DIR . 'index.json') ? json_decode((string) file_get_contents(GVB_TUTO_DIR . 'index.json'), true) : [];
        $index = is_array($json) ? $json : [];
    }
    return $index;
}
function gvb_tuto_trouver($slug) {
    foreach (gvb_tuto_index() as $t) if ($t['slug'] === $slug) return $t;
    return null;
}
function gvb_tuto_image_url($nom) { return admin_url('admin-post.php?action=gvb_tuto_image&f=' . rawurlencode($nom)); }
function gvb_tuto_image_chemin($nom) {
    if (!preg_match('/^[0-9a-z-]+\.png$/', (string) $nom)) return '';
    $chemin = GVB_TUTO_DIR . 'img/' . $nom;
    return is_file($chemin) ? $chemin : '';
}
function gvb_tuto_html($tuto) {
    $fichier = GVB_TUTO_DIR . $tuto['fichier'] . '.html';
    if (!preg_match('/^[0-9a-z-]+$/', $tuto['fichier']) || !is_readable($fichier)) return '';
    return preg_replace_callback('/\{\{img:([0-9a-z-]+\.png)\}\}/', fn($m) => esc_url(gvb_tuto_image_url($m[1])), (string) file_get_contents($fichier));
}

// Les captures ne sont servies qu'aux gestionnaires connectés (jamais par lien direct)
add_action('admin_post_gvb_tuto_image', function () {
    if (!is_user_logged_in() || !current_user_can('gv_acces')) wp_die('Vous n’avez pas le droit d’effectuer cette opération.', 'Accès refusé', ['response' => 403, 'back_link' => false]);
    $chemin = gvb_tuto_image_chemin(isset($_GET['f']) ? sanitize_file_name(wp_unslash($_GET['f'])) : '');
    if (!$chemin) wp_die('Image introuvable.', 'Introuvable', ['response' => 404, 'back_link' => false]);
    if (defined('GVB_ESSAI')) return;
    nocache_headers();
    header('Content-Type: image/png'); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, max-age=86400'); header('Content-Length: ' . filesize($chemin));
    readfile($chemin); exit;
});

// ── Page ──────────────────────────────────────────────────────────────────────
function gvb_tuto_style() {
    return '<style>
.gvt{display:grid;grid-template-columns:240px minmax(0,1fr);gap:22px;align-items:start}.gvt-nav{position:sticky;top:42px;background:#fff;border:1px solid var(--ligne);border-radius:12px;padding:12px 8px}
.gvt-nav strong{display:block;padding:2px 10px 8px;color:var(--ink);font-size:13px}.gvt-nav a{display:block;padding:6px 10px;border-radius:8px;text-decoration:none;font-size:13px;color:var(--muted)}.gvt-nav a:hover{background:var(--fond);color:var(--ink)}
.gvt-doc{background:#fff;border:1px solid var(--ligne);border-top:4px solid #FFC23D;border-radius:12px;padding:8px 28px 24px;font-size:15px;line-height:1.6;max-width:960px}
.gvt-doc h2{margin:30px 0 10px;padding-bottom:6px;border-bottom:3px solid #FFC23D;font-size:21px;color:var(--ink);scroll-margin-top:50px}.gvt-doc h3{margin:20px 0 6px;font-size:16px;color:var(--ink);scroll-margin-top:50px}.gvt-doc h4{margin:14px 0 4px;font-size:14px;color:var(--ink)}
.gvt-doc hr{border:0;border-top:1px solid var(--ligne);margin:18px 0}.gvt-doc ul,.gvt-doc ol{margin:6px 0 10px 22px}.gvt-doc ul{list-style:disc}.gvt-doc ol{list-style:decimal}.gvt-doc li{margin:3px 0}.gvt-doc code{background:var(--fond);padding:1px 5px;border-radius:4px}
.gvt-doc blockquote{margin:12px 0;padding:8px 14px;background:#FFF8E1;border-left:4px solid #FFC23D;border-radius:6px}.gvt-doc blockquote p{margin:3px 0}
.gvt-fig{margin:14px 0 18px;text-align:center}.gvt-fig img{max-width:100%;height:auto;border:1px solid var(--ligne);border-radius:8px;box-shadow:0 2px 10px rgba(8,6,91,.08);background:#fff}.gvt-fig figcaption{margin-top:5px;font-size:12.5px;color:var(--muted);font-style:italic}
.gvt-tableau{overflow-x:auto;margin:10px 0}.gvt-doc table{border-collapse:collapse;width:100%;font-size:14px}.gvt-doc th{background:var(--ink);color:#fff;text-align:left;padding:7px 10px}.gvt-doc td{padding:7px 10px;border-bottom:1px solid var(--ligne);vertical-align:top}.gvt-doc tr:nth-child(even) td{background:#F8F9FC}
.gvt-case{display:inline-block;width:13px;height:13px;border:2px solid var(--ink);border-radius:3px;margin-right:7px;vertical-align:-2px}.gvt-doc li:has(.gvt-case){list-style:none;margin-left:-18px}
.gvt-pn{display:flex;justify-content:space-between;gap:12px;margin-top:22px;flex-wrap:wrap}.gvt-pn a{padding:8px 14px;border:1px solid var(--ligne);border-radius:8px;text-decoration:none;background:#fff;font-weight:600}
.gvt-etiquette{display:inline-block;margin-left:6px;padding:1px 9px;border-radius:999px;background:#007A2E;color:#fff;font-size:11px;font-weight:700;vertical-align:2px}.gvt-groupe{margin:22px 0 4px;color:var(--ink);font-size:15px}
@media(max-width:900px){.gvt{grid-template-columns:1fr}.gvt-nav{position:static}.gvt-doc{padding:6px 16px 18px}}
@media print{.gvt{display:block}.gvt-nav,.gvt-pn,.gvb-entete-actions,#adminmenumain,#wpadminbar{display:none!important}.gvt-doc{border:0;max-width:none;padding:0}.gvt-fig{break-inside:avoid}#wpcontent{margin-left:0!important}}
</style>';
}
function gvb_tuto_carte($t, $profil) {
    $mien = $t['profil'] !== '' && $t['profil'] === $profil;
    return '<a class="gvb-carte" href="' . esc_url(gvb_url('gv-tutoriels', ['t' => $t['slug']])) . '"><span class="gvb-carte-icone">' . gvb_icone($t['profil'] !== '' ? 'etude' : 'utilisateurs', 18) . '</span>'
        . '<span class="gvb-carte-titre">' . esc_html($t['public']) . ($mien ? '<span class="gvt-etiquette">Votre profil</span>' : '') . '</span><small>' . esc_html($t['resume']) . '</small><small>Environ ' . (int) $t['minutes'] . ' min de lecture</small></a>';
}
function gvb_page_tutoriels() {
    if (!is_user_logged_in() || !current_user_can('gv_acces')) wp_die('Vous n’avez pas le droit d’effectuer cette opération.', 'Accès refusé', ['response' => 403, 'back_link' => false]);
    $slug = isset($_GET['t']) ? sanitize_key(wp_unslash($_GET['t'])) : '';
    $tuto = $slug ? gvb_tuto_trouver($slug) : null;
    echo gvb_tuto_style();
    if (!$tuto) {
        $profil = gvb_profil_utilisateur();
        $liste = gvb_tuto_index();
        usort($liste, fn($a, $b) => (($b['profil'] === $profil && $profil !== '') <=> ($a['profil'] === $profil && $profil !== '')));
        gvb_debut('Tutoriels', 'etude');
        if ($slug !== '') echo '<div class="notice notice-error"><p>Ce tutoriel n’existe pas.</p></div>';
        echo '<p class="gvb-rappel">Des tutoriels pas à pas, avec captures d’écran, pour chaque profil de la plateforme. Commencez par celui de votre profil ; les autres vous aident à comprendre le travail de vos collègues. Les captures utilisent des données fictives.</p>';
        $perso = array_filter($liste, fn($t) => $t['profil'] !== ''); $espaces = array_filter($liste, fn($t) => $t['profil'] === '');
        echo '<h2 class="gvt-groupe">Tutoriels du personnel</h2><div class="gvb-cartes">';
        foreach ($perso as $t) echo gvb_tuto_carte($t, $profil);
        echo '</div><h2 class="gvt-groupe">Espaces en ligne (ce que voient les familles, étudiants et enseignants)</h2><div class="gvb-cartes">';
        foreach ($espaces as $t) echo gvb_tuto_carte($t, $profil);
        echo '</div>';
        if (!$liste) echo '<p class="gvb-vide">Les tutoriels ne sont pas encore installés.</p>';
        gvb_fin();
        return;
    }
    $index = gvb_tuto_index(); $pos = array_search($tuto['slug'], array_column($index, 'slug'), true);
    $prec = $index[$pos - 1] ?? null; $suiv = $index[$pos + 1] ?? null;
    gvb_debut('Tutoriel — ' . $tuto['public'], 'etude', '<a class="button" href="' . esc_url(gvb_url('gv-tutoriels')) . '">← Tous les tutoriels</a><button type="button" class="button button-primary" onclick="window.print()">' . gvb_icone('print', 16) . 'Imprimer</button>');
    echo '<div class="gvt"><nav class="gvt-nav" aria-label="Plan du tutoriel"><strong>Dans ce tutoriel</strong>';
    foreach ($tuto['sections'] as $s) echo '<a href="#' . esc_attr($s['id']) . '">' . esc_html($s['titre']) . '</a>';
    echo '</nav><article class="gvt-doc">' . gvb_tuto_html($tuto) . '<div class="gvt-pn">'
        . ($prec ? '<a href="' . esc_url(gvb_url('gv-tutoriels', ['t' => $prec['slug']])) . '">← ' . esc_html($prec['public']) . '</a>' : '<span></span>')
        . ($suiv ? '<a href="' . esc_url(gvb_url('gv-tutoriels', ['t' => $suiv['slug']])) . '">' . esc_html($suiv['public']) . ' →</a>' : '<span></span>') . '</div></article></div>';
    gvb_fin();
}
