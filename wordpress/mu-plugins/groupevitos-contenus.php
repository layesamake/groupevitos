<?php
/**
 * Plugin Name: Groupe Vitos — Référentiel des contenus
 * Description: Types de contenus du référentiel (tarifs, dossiers d'admission, coordonnées, partenaires, promotions), familles de formations et identité du groupe, exposés dans l'API REST pour le site Astro.
 *
 * Source des données : docs/GroupeVITOS_donnees_structurees_site.md. Les contenus publiés (statut « Publié ») sont repris par le site ;
 * les brouillons et contenus privés restent dans l'administration (données à valider, anciennes coordonnées, promotions).
 */

if (!defined('ABSPATH')) exit;

const GV_POLES = ['universite' => 'Université VITO S.', 'lycee' => 'Lycée VITO S.', 'itp' => 'ITP VITO S.', 'secondaire' => 'Lycée et ITP'];

// Types de contenus : clé => [libellé pluriel, libellé singulier, icône, position, champs, prise en charge de l'image]
// Champs : clé => [libellé, type (text|number|select|html), options ou aide]
function gv_types() {
    return [
        'tarif' => ['Tarifs', 'Tarif', 'dashicons-money-alt', 6, [
            'pole' => ['Pôle', 'select', GV_POLES],
            'periode' => ['Période ou année scolaire', 'text', 'Ex. : 2026–2027. Laisser vide si le barème n’est pas millésimé.'],
            'statut' => ['Statut du barème', 'select', ['en_vigueur' => 'En vigueur', 'a_actualiser' => 'À actualiser avant affichage comme tarif en vigueur']],
            'type' => ['Nature', 'select', ['scolarite' => 'Frais de scolarité', 'complement' => 'Frais complémentaire (tenue, carte, masse de garantie…)']],
            'inscription' => ['Inscription (F CFA)', 'number', ''],
            'scolarite' => ['Scolarité (F CFA)', 'number', 'Barème universitaire : total annuel = inscription + scolarité.'],
            'total' => ['Total annuel ou montant (F CFA)', 'number', 'Secondaire : total des scolarités, inscription comprise.'],
            'decembre' => ['Échéance de décembre (F CFA)', 'number', ''],
            'mars' => ['Échéance de mars (F CFA)', 'number', ''],
            'note' => ['Précision', 'text', ''],
        ], false],
        'dossier' => ['Dossiers d’admission', 'Dossier d’admission', 'dashicons-media-document', 7, [
            'pole' => ['Pôle', 'select', ['universite' => 'Université VITO S.', 'lycee' => 'Lycée VITO S.', 'itp' => 'ITP VITO S.']],
            'annee' => ['Année ou rentrée', 'text', 'Ex. : rentrée 2026–2027'],
            'pieces' => ['Pièces à fournir (une liste)', 'html', ''],
            'note' => ['À savoir', 'html', 'Ex. : conditions d’accès non détaillées, à confirmer avec l’admission.'],
        ], false],
        'coordonnee' => ['Coordonnées', 'Coordonnée', 'dashicons-phone', 8, [
            'type' => ['Type', 'select', ['telephone' => 'Téléphone', 'email' => 'E-mail', 'reseau' => 'Réseau social', 'adresse' => 'Adresse', 'paiement' => 'Moyen de paiement (ne pas afficher comme contact)']],
            'valeur' => ['Valeur', 'text', 'Numéro avec indicatif, adresse e-mail ou lien.'],
            'poles' => ['Pôles concernés', 'text', 'tous, ou une liste : universite, lycee, itp'],
            'usage' => ['Usage affiché', 'text', 'Ex. : Admissions, Institut, Annexe'],
            'note' => ['Note interne', 'text', 'Source et validation. Les anciennes coordonnées restent en brouillon ou en privé.'],
        ], false],
        'partenaire' => ['Partenaires', 'Partenaire', 'dashicons-groups', 9, [
            'sigle' => ['Sigle', 'text', ''],
            'note' => ['Précision', 'text', 'Statut du partenariat et dénomination à confirmer.'],
        ], true],
        'promotion' => ['Promotions (registre)', 'Promotion', 'dashicons-tag', 10, [
            'taux' => ['Réduction (%)', 'number', ''],
            'periode' => ['Période ou condition visible', 'text', ''],
            'perimetre' => ['Périmètre', 'text', ''],
            'statut' => ['Statut', 'select', ['expiree' => 'Échéance passée', 'rattachee' => 'Rattachée à une année passée', 'historique' => 'Campagne historique', 'non_precisee' => 'Validité non précisée']],
            'source' => ['Source', 'text', ''],
        ], false],
    ];
}

const GV_IDENTITE_DEFAUT = [
    'marque' => 'Groupe VITO S.', 'etablissement' => 'Institut VITO S.', 'sigle' => 'IVS',
    'devise' => 'Ensemble, bâtissons les talents de demain !',
    'signatures' => "Travail – Réussite – Succès\nVITO S. pour tous, tous pour VITO S.",
    'reconnaissance' => 'Établissement privé d’enseignement supérieur agréé par l’État (arrêté n° 09-1834/MESRS-SG).',
    'adresse' => 'Kalaban Coura, rue 30, immeuble VITO S., Bamako, Mali',
    'reperes' => "2009|Création de l’Institut, arrêté n° 09-1834/MESRS-SG du 23 juillet 2009\n2012|Structuration de l’offre de formation\n2013|Schéma directeur de l’offre\n|Ouverture d’une annexe à Sikasso",
    'presentation' => 'Le Groupe VITO S. accompagne les apprenants dans l’enseignement secondaire, la formation technique et professionnelle et l’enseignement supérieur. À Bamako, l’Institut VITO S. développe une pédagogie associant connaissances, ateliers pratiques et préparation au monde professionnel. Son offre universitaire présente des parcours en gestion, finance, droit, marketing, logistique, communication, numérique et développement.',
];

function gv_identite() {
    return wp_parse_args(get_option('groupevitos_identite', []), GV_IDENTITE_DEFAUT);
}

add_action('init', function () {
    foreach (gv_types() as $type => [$pluriel, $singulier, $icone, $position, $champs, $vignette]) {
        register_post_type($type, [
            'label' => $pluriel,
            'labels' => ['singular_name' => $singulier, 'add_new_item' => 'Ajouter : ' . mb_strtolower($singulier), 'edit_item' => 'Modifier : ' . mb_strtolower($singulier)],
            'public' => false, 'publicly_queryable' => false, 'show_ui' => true, 'show_in_menu' => true, 'show_in_rest' => true, 'rest_base' => $type . 's',
            'menu_icon' => $icone, 'menu_position' => $position, 'has_archive' => false,
            'supports' => array_filter(['title', 'page-attributes', 'custom-fields', $vignette ? 'thumbnail' : null]),
        ]);
        foreach ($champs as $cle => [$label, $genre]) {
            register_post_meta($type, $cle, [
                'type' => $genre === 'number' ? 'integer' : 'string', 'single' => true, 'default' => $genre === 'number' ? 0 : '', 'description' => $label, 'show_in_rest' => true,
                'sanitize_callback' => $genre === 'number' ? 'absint' : ($genre === 'html' ? 'wp_kses_post' : 'sanitize_text_field'),
                'auth_callback' => fn() => current_user_can('edit_posts'),
            ]);
        }
    }
    register_taxonomy('famille_formation', 'formation', [
        'label' => 'Familles de formations', 'labels' => ['singular_name' => 'Famille', 'add_new_item' => 'Ajouter une famille'],
        'hierarchical' => true, 'public' => false, 'show_ui' => true, 'show_in_rest' => true, 'rest_base' => 'familles-formations', 'show_admin_column' => true,
    ]);
}, 11);

foreach (array_keys(gv_types()) as $gv_type) {
    add_action("add_meta_boxes_$gv_type", function () use ($gv_type) {
        add_meta_box("gv-$gv_type", 'Détails', function ($post) use ($gv_type) {
            wp_nonce_field("gv_$gv_type", "gv_{$gv_type}_nonce");
            foreach (gv_types()[$gv_type][4] as $cle => [$label, $genre, $aide]) {
                $valeur = get_post_meta($post->ID, $cle, true);
                echo '<p><label for="gvc-' . esc_attr($cle) . '"><strong>' . esc_html($label) . '</strong></label><br>';
                if ($genre === 'select') {
                    echo '<select id="gvc-' . esc_attr($cle) . '" name="gv[' . esc_attr($cle) . ']">';
                    foreach ($aide as $option => $texte) echo '<option value="' . esc_attr($option) . '"' . selected($valeur, $option, false) . '>' . esc_html($texte) . '</option>';
                    echo '</select>';
                } elseif ($genre === 'html') {
                    wp_editor($valeur, 'gvc-' . $cle, ['textarea_name' => "gv[$cle]", 'textarea_rows' => 6, 'media_buttons' => false]);
                } else {
                    echo '<input type="' . ($genre === 'number' ? 'number" min="0' : 'text') . '" class="widefat" id="gvc-' . esc_attr($cle) . '" name="gv[' . esc_attr($cle) . ']" value="' . esc_attr($valeur) . '">';
                }
                if ($aide && $genre !== 'select') echo '<br><span class="description">' . esc_html($aide) . '</span>';
                echo '</p>';
            }
            echo '<p class="description">Statut « Publié » : affiché sur le site. « Brouillon » ou « Privé » : conservé dans l’administration, non affiché.</p>';
        }, $gv_type, 'normal', 'high');
    });
    add_action("save_post_$gv_type", function ($post_id) use ($gv_type) {
        if (!isset($_POST["gv_{$gv_type}_nonce"]) || !wp_verify_nonce($_POST["gv_{$gv_type}_nonce"], "gv_$gv_type") || wp_is_post_autosave($post_id) || !current_user_can('edit_post', $post_id)) return;
        foreach (gv_types()[$gv_type][4] as $cle => [$label, $genre]) {
            if (!isset($_POST['gv'][$cle])) continue;
            $v = wp_unslash($_POST['gv'][$cle]);
            update_post_meta($post_id, $cle, $genre === 'number' ? absint($v) : ($genre === 'html' ? wp_kses_post($v) : sanitize_text_field($v)));
        }
    });
}

// ── Identité du groupe : Réglages → Groupe VITOS ; lecture publique (hors données internes) pour le site ──
add_action('admin_menu', function () {
    add_options_page('Identité du groupe', 'Groupe VITOS', 'manage_options', 'groupevitos-identite', function () {
        $i = gv_identite();
        $champs = ['marque' => ['Marque ombrelle', 'text'], 'etablissement' => ['Établissement', 'text'], 'sigle' => ['Sigle', 'text'], 'devise' => ['Devise', 'text'], 'signatures' => ['Autres signatures (une par ligne)', 'textarea'],
            'reconnaissance' => ['Reconnaissance affichée', 'textarea'], 'adresse' => ['Adresse principale', 'text'], 'reperes' => ['Repères historiques (une ligne « année|texte »)', 'textarea'], 'presentation' => ['Texte de présentation', 'textarea']];
        echo '<div class="wrap"><h1>Identité du groupe</h1><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('gv_identite');
        echo '<input type="hidden" name="action" value="gv_identite"><table class="form-table">';
        foreach ($champs as $cle => [$label, $genre]) {
            echo '<tr><th><label for="gvi-' . esc_attr($cle) . '">' . esc_html($label) . '</label></th><td>';
            echo $genre === 'text' ? '<input class="large-text" id="gvi-' . esc_attr($cle) . '" name="gv[' . esc_attr($cle) . ']" value="' . esc_attr($i[$cle]) . '">' : '<textarea class="large-text" rows="4" id="gvi-' . esc_attr($cle) . '" name="gv[' . esc_attr($cle) . ']">' . esc_textarea($i[$cle]) . '</textarea>';
            echo '</td></tr>';
        }
        echo '</table>';
        submit_button();
        echo '</form></div>';
    });
});
add_action('admin_post_gv_identite', function () {
    if (!current_user_can('manage_options')) wp_die('Accès refusé', 403);
    check_admin_referer('gv_identite');
    $nouveau = [];
    foreach (array_keys(GV_IDENTITE_DEFAUT) as $cle) $nouveau[$cle] = sanitize_textarea_field(wp_unslash($_POST['gv'][$cle] ?? ''));
    update_option('groupevitos_identite', $nouveau, false);
    do_action('groupevitos_identite_modifiee');
    wp_safe_redirect(admin_url('options-general.php?page=groupevitos-identite&maj=1'));
    exit;
});

add_action('rest_api_init', function () {
    register_rest_route('groupevitos/v1', '/identite', [
        ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => fn() => gv_identite()],
        ['methods' => 'POST', 'permission_callback' => fn() => current_user_can('manage_options'), 'callback' => function (WP_REST_Request $r) {
            $nouveau = gv_identite();
            foreach (array_keys(GV_IDENTITE_DEFAUT) as $cle) if (is_string($r->get_param($cle))) $nouveau[$cle] = sanitize_textarea_field($r->get_param($cle));
            update_option('groupevitos_identite', $nouveau, false);
            return $nouveau;
        }],
    ]);
});

// Les comptes ne sont pas listés publiquement par l'API REST
add_filter('rest_endpoints', function ($routes) {
    if (!is_user_logged_in()) foreach (array_keys($routes) as $route) if (str_starts_with($route, '/wp/v2/users')) unset($routes[$route]);
    return $routes;
});
