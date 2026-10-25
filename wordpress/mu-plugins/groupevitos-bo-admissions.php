<?php
/**
 * Plugin Name: Groupe Vitos — Back-office : admissions
 * Description: Demandes d'admission (saisie agence + formulaire du site), pièces du dossier, décisions, détection de doublons, export.
 */

if (!defined('ABSPATH')) exit;

const GVB_PIECES = [
    'universite' => ['Acte de naissance ou extrait', 'Diplôme du baccalauréat (ou attestation)', 'Relevés de notes du baccalauréat', 'Photos d’identité', 'Pièce d’identité du candidat ou du tuteur'],
    'lycee' => ['Acte de naissance ou extrait', 'Bulletins de l’année précédente', 'Certificat de scolarité ou de radiation', 'Photos d’identité', 'Pièce d’identité du parent ou tuteur'],
    'itp' => ['Acte de naissance ou extrait', 'Dernier diplôme obtenu (ou attestation)', 'Photos d’identité', 'Pièce d’identité du candidat ou du tuteur'],
];
const GVB_TRANSITIONS = [
    'nouvelle' => ['etude', 'incomplete', 'attente', 'acceptee', 'refusee', 'abandonnee'],
    'etude' => ['incomplete', 'attente', 'acceptee', 'refusee', 'abandonnee'],
    'incomplete' => ['etude', 'attente', 'acceptee', 'refusee', 'abandonnee'],
    'attente' => ['etude', 'acceptee', 'refusee', 'abandonnee'],
    'acceptee' => ['attente', 'refusee', 'abandonnee'],
    'refusee' => ['etude'],
    'abandonnee' => ['etude'],
    'inscrite' => [],
];

function gvb_pieces_par_defaut($pole) { return apply_filters('gvb_pieces_par_defaut', GVB_PIECES[$pole] ?? GVB_PIECES['universite'], $pole); }

function gvb_demande($id) {
    global $wpdb;
    $d = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . gvb_table('demandes') . ' WHERE id = %d', (int) $id));
    return $d && gvb_pole_autorise($d->pole) ? $d : null;
}
function gvb_formations() {
    static $l = null;
    if ($l === null) { $l = []; foreach (get_posts(['post_type' => 'formation', 'post_status' => ['publish', 'draft', 'private'], 'numberposts' => 200, 'orderby' => 'title', 'order' => 'ASC']) as $p) $l[$p->ID] = wp_strip_all_tags($p->post_title); }
    return $l;
}
function gvb_annees() {
    $a = gvb_reglages()['annee'];
    global $wpdb;
    $autres = $wpdb->get_col('SELECT DISTINCT annee FROM ' . gvb_table('demandes') . " WHERE annee <> '' ORDER BY annee DESC");
    return array_values(array_unique(array_merge([$a], $autres)));
}
function gvb_clause_pole(&$where, &$args, $alias = '') {
    $p = gvb_pole_utilisateur();
    if ($p === '') return;
    $where[] = ($alias ? $alias . '.' : '') . 'pole = %s';
    $args[] = $p === '__aucun__' ? '' : $p;
}

// Doublons probables : même identité (nom, prénom, naissance) ou même e-mail / téléphone, sur le même pôle et la même année
function gvb_doublons($d, $exclure = 0) {
    global $wpdb;
    $t = gvb_table('demandes');
    $cond = ['(LOWER(nom) = LOWER(%s) AND LOWER(prenom) = LOWER(%s) AND ' . ($d['naissance'] ? 'naissance = %s' : "(naissance IS NULL AND '' = %s)") . ')']; $args = [$d['nom'], $d['prenom'], $d['naissance'] ?: ''];
    if ($d['email'] !== '') { $cond[] = 'LOWER(email) = LOWER(%s)'; $args[] = $d['email']; }
    if ($d['telephone'] !== '') { $cond[] = 'telephone = %s'; $args[] = $d['telephone']; }
    $sql = "SELECT id, code, nom, prenom, statut FROM $t WHERE pole = %s AND annee = %s AND id <> %d AND statut NOT IN ('refusee','abandonnee') AND (" . implode(' OR ', $cond) . ') LIMIT 5';
    return $wpdb->get_results($wpdb->prepare($sql, array_merge([$d['pole'], $d['annee'], (int) $exclure], $args)));
}

function gvb_nettoyer_demande($src) {
    $s = fn($k, $max = 190) => mb_substr(sanitize_text_field(wp_unslash($src[$k] ?? '')), 0, $max);
    $naissance = $s('naissance', 10);
    if ($naissance !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $naissance)) $naissance = '';
    $pole = $s('pole', 12);
    return [
        'pole' => isset(GVB_POLES[$pole]) ? $pole : '', 'annee' => $s('annee', 12) ?: gvb_reglages()['annee'], 'niveau' => $s('niveau', 40), 'formation_id' => absint($src['formation_id'] ?? 0), 'serie' => $s('serie', 60),
        'nom' => $s('nom', 120), 'prenom' => $s('prenom', 120), 'naissance' => $naissance, 'sexe' => in_array($s('sexe', 1), ['F', 'M'], true) ? $s('sexe', 1) : '', 'nationalite' => $s('nationalite', 60),
        'telephone' => $s('telephone', 50), 'email' => sanitize_email(wp_unslash($src['email'] ?? '')), 'adresse' => $s('adresse', 255),
        'parent_nom' => $s('parent_nom', 160), 'parent_telephone' => $s('parent_telephone', 50), 'parent_email' => sanitize_email(wp_unslash($src['parent_email'] ?? '')),
        'provenance' => $s('provenance', 190), 'message' => mb_substr(sanitize_textarea_field(wp_unslash($src['message'] ?? '')), 0, 2000),
    ];
}
function gvb_valider_demande($d) {
    if ($d['pole'] === '') return 'Choisissez un pôle.';
    if ($d['nom'] === '' || $d['prenom'] === '') return 'Le nom et le prénom sont obligatoires.';
    if ($d['telephone'] === '' && $d['email'] === '' && $d['parent_telephone'] === '') return 'Indiquez au moins un moyen de contact (téléphone ou e-mail).';
    if (!empty($_POST['email']) && $d['email'] === '') return 'Adresse e-mail invalide.';
    if ($d['naissance'] !== '' && (strtotime($d['naissance']) === false || $d['naissance'] > gmdate('Y-m-d'))) return 'Date de naissance invalide.';
    return null;
}

function gvb_creer_demande($d, $source) {
    global $wpdb;
    $now = gvb_now();
    $ok = $wpdb->insert(gvb_table('demandes'), $d + ['statut' => 'nouvelle', 'source' => $source, 'cree_le' => $now, 'modifie_le' => $now]);
    if (!$ok) return 0;
    $id = (int) $wpdb->insert_id;
    $code = 'ADM-' . substr(preg_replace('/\D/', '', $d['annee']), 0, 4) . '-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    $wpdb->update(gvb_table('demandes'), ['code' => $code], ['id' => $id]);
    foreach (gvb_pieces_par_defaut($d['pole']) as $libelle) $wpdb->insert(gvb_table('pieces'), ['demande_id' => $id, 'libelle' => $libelle]);
    gvb_log($id, $source === 'site' ? 'Demande reçue depuis le site' : 'Demande saisie');
    return $id;
}

// ── Formulaire public (site Astro) ────────────────────────────────────────────
add_action('rest_api_init', function () {
    register_rest_route('groupevitos/v1', '/admissions', ['methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'gvb_recevoir']);
});
function gvb_recevoir(WP_REST_Request $r) {
    $p = $r->get_params();
    if (!empty($p['site_web'])) return ['ok' => true];
    $ip = md5(gvb_ip_visiteur());
    $cle = 'gvb_rl_' . $ip; $n = (int) get_transient($cle);
    if ($n >= 5) return new WP_Error('trop_de_demandes', 'Trop de demandes depuis cette adresse. Réessayez dans une heure.', ['status' => 429]);
    if (empty($p['consentement'])) return new WP_Error('consentement', 'Veuillez accepter le traitement de vos données.', ['status' => 422]);
    $d = gvb_nettoyer_demande($p);
    $_POST['email'] = $p['email'] ?? '';
    if ($err = gvb_valider_demande($d)) return new WP_Error('invalide', $err, ['status' => 422]);
    $formations = gvb_formations();
    if ($d['formation_id'] && !isset($formations[$d['formation_id']])) $d['formation_id'] = 0;
    $d['annee'] = gvb_reglages()['annee'];
    set_transient($cle, $n + 1, HOUR_IN_SECONDS);
    $id = gvb_creer_demande($d, 'site');
    if (!$id) return new WP_Error('erreur', 'Enregistrement impossible, réessayez.', ['status' => 500]);
    $code = gvb_demande($id)->code ?? '';
    $doublons = gvb_doublons($d, $id);
    if ($doublons) { global $wpdb; $wpdb->update(gvb_table('demandes'), ['doublon_de' => (int) $doublons[0]->id], ['id' => $id]); }
    gvb_mail(gvb_reglages()['email'], 'Nouvelle demande d’admission ' . $code, "Pôle : " . GVB_POLES[$d['pole']] . "\nCandidat : {$d['prenom']} {$d['nom']}\nContact : {$d['telephone']} {$d['email']}\n\nÀ traiter : " . gvb_url('gv-demande', ['id' => $id]));
    if ($d['email']) gvb_mail($d['email'], 'Votre demande d’admission au Groupe VITO S.', "Bonjour {$d['prenom']},\n\nNous avons bien reçu votre demande ($code). Notre service de scolarité vous contactera pour la suite du dossier.\n\nGroupe VITO S.");
    return ['ok' => true, 'code' => $code];
}

// ── Liste ─────────────────────────────────────────────────────────────────────
function gvb_filtres_demandes() {
    return [
        'annee' => sanitize_text_field(wp_unslash($_GET['annee'] ?? '')), 'pole' => isset(GVB_POLES[$_GET['pole'] ?? '']) ? $_GET['pole'] : '', 'statut' => isset(GVB_STATUTS[$_GET['statut'] ?? '']) ? $_GET['statut'] : '',
        'q' => sanitize_text_field(wp_unslash($_GET['q'] ?? '')),
    ];
}
function gvb_requete_demandes($f, $limite = 0) {
    global $wpdb;
    $t = gvb_table('demandes'); $where = ['1=1']; $args = [];
    foreach (['annee', 'pole', 'statut'] as $k) if ($f[$k] !== '') { $where[] = "$k = %s"; $args[] = $f[$k]; }
    if ($f['q'] !== '') { $like = '%' . $wpdb->esc_like($f['q']) . '%'; $where[] = '(nom LIKE %s OR prenom LIKE %s OR code LIKE %s OR telephone LIKE %s OR email LIKE %s)'; array_push($args, $like, $like, $like, $like, $like); }
    gvb_clause_pole($where, $args);
    $sql = "SELECT * FROM $t WHERE " . implode(' AND ', $where) . ' ORDER BY id DESC' . ($limite ? ' LIMIT ' . (int) $limite : '');
    return $args ? $wpdb->get_results($wpdb->prepare($sql, $args)) : $wpdb->get_results($sql);
}

function gvb_page_admissions() {
    gvb_exiger('admissions', 'voir');
    $f = gvb_filtres_demandes();
    $lignes = [];
    foreach (gvb_requete_demandes($f, 300) as $d) {
        $lignes[] = '<tr><td><a href="' . esc_url(gvb_url('gv-demande', ['id' => $d->id])) . '"><strong>' . esc_html($d->code) . '</strong></a></td><td>' . esc_html($d->prenom . ' ' . $d->nom) . ($d->doublon_de ? ' <span class="gvb-ko" title="Doublon probable">⚠</span>' : '') . '</td><td>' . esc_html(GVB_POLES[$d->pole] ?? $d->pole) . '</td><td>' . esc_html($d->niveau ?: '—') . '</td><td>' . esc_html($d->annee) . '</td><td>' . gvb_badge($d->statut) . '</td><td>' . esc_html(gvb_d($d->cree_le)) . '</td></tr>';
    }
    $actions = '';
    if (gvb_can('admissions', 'creer')) $actions .= '<a class="button button-primary" href="' . esc_url(gvb_url('gv-demande')) . '">' . gvb_icone('plus', 16) . 'Nouvelle demande</a>';
    if (gvb_can('admissions', 'exporter')) $actions .= '<a class="button" href="' . esc_url(wp_nonce_url(add_query_arg(array_merge(['action' => 'gvb_export_demandes'], array_filter($f)), admin_url('admin-post.php')), 'gvb_export_demandes')) . '">Exporter (CSV)</a>';
    gvb_debut('Admissions', 'admission', $actions);
    echo '<form method="get" class="gvb-filtres"><input type="hidden" name="page" value="gv-admissions">'
        . gvb_liste('annee', 'Année', ['' => 'Toutes'] + array_combine(gvb_annees(), gvb_annees()), $f['annee']) . gvb_liste('pole', 'Pôle', ['' => 'Tous'] + GVB_POLES, $f['pole']) . gvb_liste('statut', 'Statut', ['' => 'Tous'] + GVB_STATUTS, $f['statut'])
        . gvb_champ('q', 'Recherche', $f['q'], 'search', 'placeholder="Nom, code, téléphone…"') . gvb_bouton('Filtrer', false) . '</form>';
    echo gvb_tableau(['Code', 'Candidat', 'Pôle', 'Niveau', 'Année', 'Statut', 'Reçue le'], $lignes, 'Aucune demande ne correspond.');
    gvb_fin();
}

// ── Fiche / saisie ────────────────────────────────────────────────────────────
function gvb_champs_demande($d) {
    $g = fn($k) => $d->$k ?? '';
    $formations = [0 => '—'] + gvb_formations();
    $h = '<div class="gvb-champs">' . gvb_liste('pole', 'Pôle *', GVB_POLES, $g('pole') ?: (gvb_pole_utilisateur() ?: 'universite')) . gvb_champ('annee', 'Année académique', $g('annee') ?: gvb_reglages()['annee']) . gvb_champ('niveau', 'Niveau demandé', $g('niveau'), 'text', 'placeholder="Licence 1, 10e année…"')
        . gvb_liste('formation_id', 'Formation (Université / ITP)', $formations, $g('formation_id')) . gvb_champ('serie', 'Série / filière du Lycée', $g('serie'))
        . gvb_champ('nom', 'Nom *', $g('nom'), 'text', 'required') . gvb_champ('prenom', 'Prénom *', $g('prenom'), 'text', 'required') . gvb_champ('naissance', 'Date de naissance', $g('naissance'), 'date')
        . gvb_liste('sexe', 'Sexe', ['' => '—', 'F' => 'Féminin', 'M' => 'Masculin'], $g('sexe')) . gvb_champ('nationalite', 'Nationalité', $g('nationalite')) . gvb_champ('telephone', 'Téléphone / WhatsApp', $g('telephone'), 'tel') . gvb_champ('email', 'E-mail', $g('email'), 'email')
        . gvb_champ('adresse', 'Adresse', $g('adresse')) . gvb_champ('parent_nom', 'Parent ou tuteur', $g('parent_nom')) . gvb_champ('parent_telephone', 'Téléphone du parent', $g('parent_telephone'), 'tel') . gvb_champ('parent_email', 'E-mail du parent', $g('parent_email'), 'email')
        . gvb_champ('provenance', 'Établissement d’origine', $g('provenance')) . '</div>' . gvb_zone('message', 'Observations', $g('message'));
    return $h;
}

function gvb_page_demande() {
    $id = absint($_GET['id'] ?? 0);
    if (!$id) { gvb_exiger('admissions', 'creer'); gvb_page_demande_nouvelle(); return; }
    gvb_exiger('admissions', 'voir');
    global $wpdb;
    $d = gvb_demande($id);
    if (!$d) wp_die('Demande introuvable.', 'Introuvable', ['response' => 404, 'back_link' => false]);
    gvb_debut($d->code . ' — ' . $d->prenom . ' ' . $d->nom, 'admission', '<a class="button" href="' . esc_url(gvb_url('gv-admissions')) . '">← Admissions</a>');
    echo '<p>' . gvb_badge($d->statut) . ' <span class="gvb-rappel">' . esc_html(GVB_POLES[$d->pole] ?? '') . ' · ' . esc_html($d->annee) . ' · ' . ($d->source === 'site' ? 'reçue depuis le site' : 'saisie à l’agence') . '</span></p>';
    if ($d->motif && in_array($d->statut, ['refusee', 'abandonnee', 'incomplete', 'attente'], true)) echo '<div class="gvb-alerte">Motif : ' . esc_html($d->motif) . '</div>';
    $dbl = gvb_doublons((array) $d, $d->id);
    if ($dbl) { echo '<div class="gvb-alerte"><strong>Doublon probable :</strong> '; foreach ($dbl as $x) echo '<a href="' . esc_url(gvb_url('gv-demande', ['id' => $x->id])) . '">' . esc_html($x->code . ' ' . $x->prenom . ' ' . $x->nom) . '</a> '; echo '</div>'; }

    $pieces = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . gvb_table('pieces') . ' WHERE demande_id = %d ORDER BY id', $id));
    $manque = array_filter($pieces, fn($p) => !$p->recue);
    echo '<div class="gvb-grille"><div>';
    echo '<div class="gvb-boite"><h2>' . gvb_icone('utilisateurs', 18) . 'Candidat</h2>';
    if (gvb_can('admissions', 'modifier') && !in_array($d->statut, ['inscrite'], true)) {
        echo gvb_form('gvb_demande_sauver', 'gvb_demande_sauver', ['id' => $id]) . gvb_champs_demande($d) . gvb_bouton('Enregistrer') . '</form>';
    } else {
        foreach (['niveau' => 'Niveau', 'nom' => 'Nom', 'prenom' => 'Prénom', 'naissance' => 'Naissance', 'telephone' => 'Téléphone', 'email' => 'E-mail', 'parent_nom' => 'Parent', 'parent_telephone' => 'Tél. parent', 'provenance' => 'Origine'] as $k => $l) echo '<p><strong>' . esc_html($l) . ' :</strong> ' . esc_html((string) ($d->$k ?: '—')) . '</p>';
    }
    echo '</div></div><div>';

    echo '<div class="gvb-boite"><h2>' . gvb_icone('dossier', 18) . 'Pièces du dossier (' . (count($pieces) - count($manque)) . '/' . count($pieces) . ')</h2><ul class="gvb-pieces">';
    foreach ($pieces as $p) {
        echo '<li><span class="' . ($p->recue ? 'gvb-ok' : 'gvb-ko') . '">' . (gvb_icone($p->recue ? 'check' : 'croix', 14) . ($p->recue ? ' Reçue' : ' Manquante')) . '</span> ' . esc_html($p->libelle) . ($p->recue ? ' <small class="gvb-rappel">' . esc_html(gvb_d($p->recue_le)) . '</small>' : '');
        if (gvb_can('admissions', 'modifier') && $d->statut !== 'inscrite') echo gvb_form('gvb_piece_basculer', 'gvb_piece_' . $p->id, ['id' => $id, 'piece' => $p->id], 'class="gvb-inline"') . gvb_bouton($p->recue ? 'Marquer manquante' : 'Marquer reçue', false) . '</form>';
        echo '</li>';
    }
    echo '</ul>';
    if (gvb_can('admissions', 'modifier') && $d->statut !== 'inscrite') echo gvb_form('gvb_piece_ajouter', 'gvb_piece_ajouter', ['id' => $id], 'class="gvb-inline"') . gvb_champ('libelle', 'Ajouter une pièce', '', 'text', 'required maxlength="255"') . gvb_bouton('Ajouter', false) . '</form>';
    echo '</div>';

    $suivants = array_filter(GVB_TRANSITIONS[$d->statut] ?? [], fn($s) => $s !== 'acceptee' || true);
    if (gvb_can('admissions', 'valider') && $suivants) {
        echo '<div class="gvb-boite"><h2>' . gvb_icone('check', 18) . 'Décision</h2>' . gvb_form('gvb_demande_statut', 'gvb_demande_statut', ['id' => $id]) . gvb_liste('statut', 'Nouveau statut', array_combine($suivants, array_map(fn($s) => GVB_STATUTS[$s], $suivants)))
            . gvb_champ('motif', 'Motif (obligatoire pour un refus, un abandon ou une liste d’attente)', '', 'text', 'maxlength="255"') . gvb_bouton('Appliquer') . '</form></div>';
    }
    if ($d->statut === 'acceptee' && gvb_can('inscriptions', 'creer')) {
        echo '<div class="gvb-boite"><h2>' . gvb_icone('inscription', 18) . 'Inscription</h2>';
        if ($manque) echo '<div class="gvb-alerte">' . count($manque) . ' pièce(s) manquante(s). Une dérogation motivée est nécessaire pour inscrire.</div>';
        echo gvb_form('gvb_inscrire', 'gvb_inscrire', ['id' => $id]) . '<div class="gvb-champs">' . gvb_champ('frais_inscription', 'Frais d’inscription (FCFA)', '', 'number', 'min="0" step="1" required') . gvb_champ('frais_scolarite', 'Scolarité annuelle (FCFA)', '', 'number', 'min="0" step="1" required') . '</div>'
            . ($manque ? gvb_champ('derogation', 'Dérogation (motif)', '', 'text', 'maxlength="255" required') : '') . gvb_bouton('Inscrire le candidat') . '</form></div>';
    }
    echo '</div></div>';

    $h = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . gvb_table('historique') . ' WHERE demande_id = %d ORDER BY id DESC LIMIT 100', $id));
    echo '<div class="gvb-boite"><h2>' . gvb_icone('journal', 18) . 'Historique</h2><ul class="gvb-journal">';
    foreach ($h as $x) { $u = get_userdata($x->user_id); echo '<li><strong>' . esc_html($x->action) . '</strong> — ' . esc_html($u ? $u->display_name : 'Site public') . ' <small class="gvb-rappel">' . esc_html(gvb_dt($x->cree_le)) . '</small>' . ($x->details ? '<br><small>' . esc_html($x->details) . '</small>' : '') . '</li>'; }
    echo '</ul></div>';
    gvb_fin();
}

function gvb_page_demande_nouvelle() {
    gvb_debut('Nouvelle demande d’admission', 'plus', '<a class="button" href="' . esc_url(gvb_url('gv-admissions')) . '">← Admissions</a>');
    echo '<div class="gvb-boite">' . gvb_form('gvb_demande_creer', 'gvb_demande_creer') . gvb_champs_demande(null) . gvb_bouton('Créer la demande') . '</form></div>';
    gvb_fin();
}

// ── Actions ───────────────────────────────────────────────────────────────────
add_action('admin_post_gvb_demande_creer', function () {
    gvb_garde('admissions', 'creer', 'gvb_demande_creer');
    $d = gvb_nettoyer_demande($_POST);
    if (!gvb_pole_autorise($d['pole'])) gvb_retour('gv-demande', [], null, 'Ce pôle n’est pas dans votre périmètre.');
    if ($err = gvb_valider_demande($d)) gvb_retour('gv-demande', [], null, $err);
    $id = gvb_creer_demande($d, 'agence');
    if (!$id) gvb_retour('gv-demande', [], null, 'Enregistrement impossible.');
    if ($dbl = gvb_doublons($d, $id)) { global $wpdb; $wpdb->update(gvb_table('demandes'), ['doublon_de' => (int) $dbl[0]->id], ['id' => $id]); }
    gvb_retour('gv-demande', ['id' => $id], 'Demande créée.');
});

add_action('admin_post_gvb_demande_sauver', function () {
    gvb_garde('admissions', 'modifier', 'gvb_demande_sauver');
    global $wpdb;
    $id = absint($_POST['id'] ?? 0); $ancien = gvb_demande($id);
    if (!$ancien || $ancien->statut === 'inscrite') gvb_retour('gv-admissions', [], null, 'Demande introuvable ou déjà inscrite.');
    $d = gvb_nettoyer_demande($_POST);
    if (!gvb_pole_autorise($d['pole'])) gvb_retour('gv-demande', ['id' => $id], null, 'Ce pôle n’est pas dans votre périmètre.');
    if ($err = gvb_valider_demande($d)) gvb_retour('gv-demande', ['id' => $id], null, $err);
    $diff = [];
    foreach ($d as $k => $v) if ((string) ($ancien->$k ?? '') !== (string) $v) $diff[] = $k;
    $wpdb->update(gvb_table('demandes'), $d + ['naissance' => $d['naissance'] ?: null, 'modifie_le' => gvb_now()], ['id' => $id]);
    if ($d['pole'] !== $ancien->pole) { /* les pièces suivent le pôle uniquement si aucune n'est reçue */
        if (!(int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . gvb_table('pieces') . ' WHERE demande_id = %d AND recue = 1', $id))) {
            $wpdb->delete(gvb_table('pieces'), ['demande_id' => $id]);
            foreach (gvb_pieces_par_defaut($d['pole']) as $l) $wpdb->insert(gvb_table('pieces'), ['demande_id' => $id, 'libelle' => $l]);
        }
    }
    if ($diff) gvb_log($id, 'Fiche modifiée', implode(', ', $diff));
    gvb_retour('gv-demande', ['id' => $id], 'Fiche enregistrée.');
});

add_action('admin_post_gvb_piece_basculer', function () {
    $piece = absint($_POST['piece'] ?? 0);
    gvb_garde('admissions', 'modifier', 'gvb_piece_' . $piece);
    global $wpdb; $id = absint($_POST['id'] ?? 0);
    $d = gvb_demande($id); $p = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . gvb_table('pieces') . ' WHERE id = %d AND demande_id = %d', $piece, $id));
    if (!$d || !$p || $d->statut === 'inscrite') gvb_retour('gv-admissions', [], null, 'Pièce introuvable.');
    $recue = $p->recue ? 0 : 1;
    $wpdb->update(gvb_table('pieces'), ['recue' => $recue, 'recue_le' => $recue ? gvb_now() : null, 'recue_par' => $recue ? get_current_user_id() : 0], ['id' => $piece]);
    gvb_log($id, ($recue ? 'Pièce reçue' : 'Pièce marquée manquante'), $p->libelle);
    gvb_retour('gv-demande', ['id' => $id], 'Pièce mise à jour.');
});

add_action('admin_post_gvb_piece_ajouter', function () {
    gvb_garde('admissions', 'modifier', 'gvb_piece_ajouter');
    global $wpdb; $id = absint($_POST['id'] ?? 0); $d = gvb_demande($id);
    $l = mb_substr(sanitize_text_field(wp_unslash($_POST['libelle'] ?? '')), 0, 255);
    if (!$d || $d->statut === 'inscrite' || $l === '') gvb_retour('gv-admissions', [], null, 'Demande introuvable ou libellé vide.');
    $wpdb->insert(gvb_table('pieces'), ['demande_id' => $id, 'libelle' => $l]);
    gvb_log($id, 'Pièce ajoutée à la liste', $l);
    gvb_retour('gv-demande', ['id' => $id], 'Pièce ajoutée.');
});

add_action('admin_post_gvb_demande_statut', function () {
    gvb_garde('admissions', 'valider', 'gvb_demande_statut');
    global $wpdb; $id = absint($_POST['id'] ?? 0); $d = gvb_demande($id);
    $nouveau = sanitize_key($_POST['statut'] ?? ''); $motif = mb_substr(sanitize_text_field(wp_unslash($_POST['motif'] ?? '')), 0, 255);
    if (!$d) gvb_retour('gv-admissions', [], null, 'Demande introuvable.');
    if (!in_array($nouveau, GVB_TRANSITIONS[$d->statut] ?? [], true)) gvb_retour('gv-demande', ['id' => $id], null, 'Ce changement de statut n’est pas permis.');
    if (in_array($nouveau, ['refusee', 'abandonnee', 'attente'], true) && $motif === '') gvb_retour('gv-demande', ['id' => $id], null, 'Un motif est obligatoire.');
    $wpdb->update(gvb_table('demandes'), ['statut' => $nouveau, 'motif' => $motif, 'decide_le' => gvb_now(), 'decide_par' => get_current_user_id(), 'modifie_le' => gvb_now()], ['id' => $id]);
    gvb_log($id, 'Statut : ' . GVB_STATUTS[$d->statut] . ' → ' . GVB_STATUTS[$nouveau], $motif);
    if (in_array($nouveau, ['acceptee', 'refusee'], true) && $d->email) {
        gvb_mail($d->email, $nouveau === 'acceptee' ? 'Votre admission au Groupe VITO S.' : 'Réponse à votre demande d’admission', $nouveau === 'acceptee'
            ? "Bonjour {$d->prenom},\n\nVotre demande ({$d->code}) est acceptée. Le service de scolarité vous contactera pour finaliser l’inscription.\n\nGroupe VITO S." : "Bonjour {$d->prenom},\n\nAprès étude, nous ne pouvons pas donner suite à votre demande ({$d->code}). Pour toute question, contactez la scolarité.\n\nGroupe VITO S.");
    }
    gvb_retour('gv-demande', ['id' => $id], 'Statut mis à jour.');
});

add_action('admin_post_gvb_export_demandes', function () {
    gvb_garde('admissions', 'exporter', 'gvb_export_demandes');
    $f = gvb_filtres_demandes();
    $neutre = fn($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v;
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="admissions-' . gmdate('Ymd') . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['Code', 'Pôle', 'Année', 'Niveau', 'Nom', 'Prénom', 'Naissance', 'Téléphone', 'E-mail', 'Parent', 'Statut', 'Motif', 'Reçue le'], ';');
    foreach (gvb_requete_demandes($f) as $d) fputcsv($o, array_map($neutre, [$d->code, GVB_POLES[$d->pole] ?? $d->pole, $d->annee, $d->niveau, $d->nom, $d->prenom, $d->naissance, $d->telephone, $d->email, $d->parent_nom, GVB_STATUTS[$d->statut] ?? $d->statut, $d->motif, $d->cree_le]), ';');
    gvb_log(0, 'Export des admissions', count(array_filter($f)) . ' filtre(s)');
    gvb_terminer();
});
