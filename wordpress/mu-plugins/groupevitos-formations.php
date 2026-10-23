<?php
/**
 * Plugin Name: Groupe Vitos — Formations
 * Description: Type de contenu « Formations » et ses champs (niveau, durée, débouchés…), exposés dans l'API REST pour le site Astro.
 */

if (!defined('ABSPATH')) exit;

// Champs d'une formation : clé => [libellé, type de saisie]
const GROUPEVITOS_FORMATION_FIELDS = [
    'reference' => ['Référence du catalogue (01 à 17)', 'text'],
    'sigle' => ['Sigle (ex. MSS, MPD, DPR)', 'text'],
    'diplomes' => ['Diplômes rattachés (DUT, Licence, Master) — à renseigner après validation de l’établissement', 'text'],
    'variantes' => ['Autres intitulés relevés sur d’anciens supports', 'text'],
    'validation' => ['Fiche : « catalogue » (intitulé seul) ou « validee » (détails confirmés)', 'text'],
    'niveau' => ['Niveau', 'text'],
    'duree' => ['Durée', 'text'],
    'parcours' => ['Parcours (niveaux, durées, objectifs)', 'html'],
    'admission' => ['Conditions d’admission', 'html'],
    'finalite' => ['Finalité de la formation', 'html'],
    'enseignements' => ['Enseignements principaux', 'html'],
    'competences' => ['Compétences visées', 'html'],
    'methodes' => ['Méthodes pédagogiques', 'html'],
    'debouches' => ['Débouchés', 'html'],
    'carriere' => ['Poursuite d’études et carrière', 'html'],
];

add_action('init', function () {
    register_post_type('formation', [
        'label' => 'Formations',
        'labels' => ['singular_name' => 'Formation', 'add_new_item' => 'Ajouter une formation', 'edit_item' => 'Modifier la formation'],
        'public' => true,
        'show_in_rest' => true,
        'rest_base' => 'formations',
        'menu_icon' => 'dashicons-welcome-learn-more',
        'menu_position' => 5,
        'has_archive' => false,
        'rewrite' => ['slug' => 'formations'],
        'supports' => ['title', 'editor', 'excerpt', 'thumbnail', 'page-attributes', 'custom-fields'],
    ]);
    foreach (GROUPEVITOS_FORMATION_FIELDS as $key => [$label, $type]) {
        register_post_meta('formation', $key, [
            'type' => 'string',
            'single' => true,
            'default' => '',
            'description' => $label,
            'show_in_rest' => true,
            'sanitize_callback' => $type === 'html' ? 'wp_kses_post' : 'sanitize_text_field',
            'auth_callback' => fn() => current_user_can('edit_posts'),
        ]);
    }
});

add_action('add_meta_boxes_formation', function () {
    add_meta_box('groupevitos-formation', 'Détails de la formation', function ($post) {
        wp_nonce_field('groupevitos_formation', 'groupevitos_formation_nonce');
        foreach (GROUPEVITOS_FORMATION_FIELDS as $key => [$label, $type]) {
            $value = get_post_meta($post->ID, $key, true);
            echo '<p><label for="gv-' . esc_attr($key) . '"><strong>' . esc_html($label) . '</strong></label></p>';
            if ($type === 'text') {
                echo '<input type="text" class="widefat" id="gv-' . esc_attr($key) . '" name="groupevitos[' . esc_attr($key) . ']" value="' . esc_attr($value) . '" />';
            } else {
                wp_editor($value, 'gv-' . $key, ['textarea_name' => "groupevitos[$key]", 'textarea_rows' => 6, 'media_buttons' => false]);
            }
        }
    }, 'formation', 'normal', 'high');
});

add_action('save_post_formation', function ($post_id) {
    if (!isset($_POST['groupevitos_formation_nonce']) || !wp_verify_nonce($_POST['groupevitos_formation_nonce'], 'groupevitos_formation')) return;
    if (wp_is_post_autosave($post_id) || !current_user_can('edit_post', $post_id)) return;
    foreach (GROUPEVITOS_FORMATION_FIELDS as $key => [$label, $type]) {
        if (!isset($_POST['groupevitos'][$key])) continue;
        $value = wp_unslash($_POST['groupevitos'][$key]);
        update_post_meta($post_id, $key, $type === 'html' ? wp_kses_post($value) : sanitize_text_field($value));
    }
});
