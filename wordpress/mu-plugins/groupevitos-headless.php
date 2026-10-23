<?php
/**
 * Plugin Name: Groupe Vitos — WordPress headless
 * Description: Reconstruit le site public (Astro, GitHub Actions) après chaque publication et redirige le front WordPress vers groupevitos.com.
 *
 * À copier dans wp-content/mu-plugins/ puis ajouter dans wp-config.php :
 *   define('GROUPEVITOS_GITHUB_REPO', 'proprietaire/groupevitos');
 *   define('GROUPEVITOS_GITHUB_TOKEN', '...'); // jeton GitHub fine-grained : ce dépôt seulement, Contents: Read and write
 */

if (!defined('ABSPATH')) exit;

const GROUPEVITOS_SITE = 'https://groupevitos.com';
const GROUPEVITOS_DELAY = 60; // secondes : regroupe les enregistrements rapprochés en une seule reconstruction

function groupevitos_schedule_rebuild() {
    if (defined('GROUPEVITOS_GITHUB_TOKEN') && !wp_next_scheduled('groupevitos_rebuild')) {
        wp_schedule_single_event(time() + GROUPEVITOS_DELAY, 'groupevitos_rebuild');
    }
}

// Publication, mise à jour, dépublication ou suppression d'un contenu public.
add_action('transition_post_status', function ($new, $old, $post) {
    if (wp_is_post_revision($post) || wp_is_post_autosave($post) || !is_post_type_viewable($post->post_type)) return;
    if ($new === 'publish' || $old === 'publish') groupevitos_schedule_rebuild();
}, 10, 3);
foreach (['deleted_post', 'edited_term', 'created_term', 'delete_term', 'wp_update_nav_menu', 'update_option_blogname', 'update_option_blogdescription'] as $hook) {
    add_action($hook, 'groupevitos_schedule_rebuild');
}

add_action('groupevitos_rebuild', 'groupevitos_trigger_rebuild');
function groupevitos_trigger_rebuild() {
    $response = wp_remote_post('https://api.github.com/repos/' . GROUPEVITOS_GITHUB_REPO . '/dispatches', [
        'timeout' => 15,
        'headers' => [
            'Authorization' => 'Bearer ' . GROUPEVITOS_GITHUB_TOKEN,
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'groupevitos-wordpress',
        ],
        'body' => wp_json_encode(['event_type' => 'wordpress-publish']),
    ]);
    $code = wp_remote_retrieve_response_code($response);
    $ok = $code === 204;
    update_option('groupevitos_last_rebuild', ['time' => time(), 'ok' => $ok], false);
    if (!$ok) error_log('groupevitos rebuild : ' . (is_wp_error($response) ? $response->get_error_message() : "HTTP $code " . wp_remote_retrieve_body($response)));
    return $ok;
}

// Bouton « Reconstruire le site » dans la barre d'administration.
add_action('admin_bar_menu', function ($bar) {
    if (!current_user_can('publish_posts') || !defined('GROUPEVITOS_GITHUB_TOKEN')) return;
    $last = get_option('groupevitos_last_rebuild');
    $bar->add_node([
        'id' => 'groupevitos-rebuild',
        'title' => 'Reconstruire le site' . ($last ? ($last['ok'] ? ' ✓' : ' ⚠') : ''),
        'href' => wp_nonce_url(admin_url('admin-post.php?action=groupevitos_rebuild'), 'groupevitos_rebuild'),
        'meta' => ['title' => $last ? 'Dernier déclenchement : ' . wp_date('d/m/Y H:i', $last['time']) . ($last['ok'] ? '' : ' (échec, voir le journal PHP)') : ''],
    ]);
}, 100);
add_action('admin_post_groupevitos_rebuild', function () {
    if (!current_user_can('publish_posts')) wp_die('Accès refusé', 403);
    check_admin_referer('groupevitos_rebuild');
    groupevitos_trigger_rebuild();
    wp_safe_redirect(wp_get_referer() ?: admin_url());
    exit;
});

// Le front WordPress n'est pas public : les visiteurs sont renvoyés vers le site Astro.
// L'administration, l'API REST, la connexion, le cron et les aperçus restent accessibles.
add_action('template_redirect', function () {
    if (is_preview() || is_user_logged_in() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST)) return;
    $path = wp_parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    wp_redirect(GROUPEVITOS_SITE . $path, 301);
    exit;
});
