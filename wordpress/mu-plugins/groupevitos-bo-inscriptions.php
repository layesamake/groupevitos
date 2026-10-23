<?php
/**
 * Plugin Name: Groupe Vitos — Back-office : inscriptions, tableau de bord, réglages
 * Description: Conversion d'une demande acceptée en inscription (matricule unique), fiche imprimable, tableau de bord, réglages.
 */

if (!defined('ABSPATH')) exit;

function gvb_prefixe_pole($pole) { return ['universite' => 'UNI', 'lycee' => 'LYC', 'itp' => 'ITP'][$pole] ?? 'VIT'; }

function gvb_inscrire($id, $frais_inscription, $frais_scolarite, $derogation = '') {
    global $wpdb;
    $verrou = 'gvb_inscrire_' . (int) $id;
    $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', $verrou));
    try {
        $d = gvb_demande($id);
        if (!$d) return new WP_Error('introuvable', 'Demande introuvable.');
        if ($d->statut !== 'acceptee') return new WP_Error('statut', 'Seule une demande acceptée peut être inscrite.');
        if ($wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . gvb_table('inscriptions') . " WHERE demande_id = %d AND statut = 'validee'", $id))) return new WP_Error('deja', 'Ce candidat est déjà inscrit.');
        $manque = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . gvb_table('pieces') . ' WHERE demande_id = %d AND recue = 0', $id));
        if ($manque && $derogation === '') return new WP_Error('pieces', 'Des pièces manquent : indiquez le motif de la dérogation.');
        if ($frais_inscription < 0 || $frais_scolarite < 0) return new WP_Error('frais', 'Montants invalides.');
        $annee4 = substr(preg_replace('/\D/', '', $d->annee), 0, 4);
        $n = gvb_sequence('mat_' . $d->pole . '_' . $annee4);
        $matricule = gvb_prefixe_pole($d->pole) . '-' . $annee4 . '-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
        $now = gvb_now();
        $wpdb->insert(gvb_table('inscriptions'), ['demande_id' => $id, 'matricule' => $matricule, 'pole' => $d->pole, 'annee' => $d->annee, 'niveau' => $d->niveau, 'formation_id' => $d->formation_id,
            'frais' => wp_json_encode(['inscription' => (int) $frais_inscription, 'scolarite' => (int) $frais_scolarite]), 'derogation' => $derogation, 'statut' => 'validee', 'inscrit_le' => $now, 'inscrit_par' => get_current_user_id()]);
        $wpdb->update(gvb_table('demandes'), ['statut' => 'inscrite', 'modifie_le' => $now], ['id' => $id]);
        gvb_log($id, 'Inscription validée', $matricule . ($derogation ? ' — dérogation : ' . $derogation : ''));
        return $matricule;
    } finally {
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $verrou));
    }
}

add_action('admin_post_gvb_inscrire', function () {
    gvb_garde('inscriptions', 'creer', 'gvb_inscrire');
    $id = absint($_POST['id'] ?? 0);
    $r = gvb_inscrire($id, absint($_POST['frais_inscription'] ?? 0), absint($_POST['frais_scolarite'] ?? 0), mb_substr(sanitize_text_field(wp_unslash($_POST['derogation'] ?? '')), 0, 255));
    if (is_wp_error($r)) gvb_retour('gv-demande', ['id' => $id], null, $r);
    gvb_retour('gv-inscriptions', [], 'Inscription validée — matricule ' . $r . '.');
});

add_action('admin_post_gvb_inscription_annuler', function () {
    gvb_garde('inscriptions', 'annuler', 'gvb_inscription_annuler');
    global $wpdb;
    $ins = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . gvb_table('inscriptions') . ' WHERE id = %d', absint($_POST['ins'] ?? 0)));
    $motif = mb_substr(sanitize_text_field(wp_unslash($_POST['motif'] ?? '')), 0, 255);
    if (!$ins || $ins->statut !== 'validee' || !gvb_pole_autorise($ins->pole)) gvb_retour('gv-inscriptions', [], null, 'Inscription introuvable.');
    if ($motif === '') gvb_retour('gv-inscriptions', [], null, 'Le motif d’annulation est obligatoire.');
    $wpdb->update(gvb_table('inscriptions'), ['statut' => 'annulee', 'motif_annulation' => $motif], ['id' => $ins->id]);
    $wpdb->update(gvb_table('demandes'), ['statut' => 'acceptee', 'modifie_le' => gvb_now()], ['id' => $ins->demande_id]);
    gvb_log($ins->demande_id, 'Inscription annulée', $ins->matricule . ' — ' . $motif);
    gvb_retour('gv-inscriptions', [], 'Inscription annulée ; la demande revient à « Acceptée ».');
});

function gvb_filtres_inscriptions() {
    return ['annee' => sanitize_text_field(wp_unslash($_GET['annee'] ?? gvb_reglages()['annee'])), 'pole' => isset(GVB_POLES[$_GET['pole'] ?? '']) ? $_GET['pole'] : '', 'q' => sanitize_text_field(wp_unslash($_GET['q'] ?? ''))];
}
function gvb_requete_inscriptions($f) {
    global $wpdb;
    $i = gvb_table('inscriptions'); $d = gvb_table('demandes'); $where = ['1=1']; $args = [];
    foreach (['annee', 'pole'] as $k) if ($f[$k] !== '') { $where[] = "i.$k = %s"; $args[] = $f[$k]; }
    if ($f['q'] !== '') { $like = '%' . $wpdb->esc_like($f['q']) . '%'; $where[] = '(d.nom LIKE %s OR d.prenom LIKE %s OR i.matricule LIKE %s)'; array_push($args, $like, $like, $like); }
    gvb_clause_pole($where, $args, 'i');
    $sql = "SELECT i.*, d.nom, d.prenom, d.code, d.telephone FROM $i i JOIN $d d ON d.id = i.demande_id WHERE " . implode(' AND ', $where) . ' ORDER BY i.id DESC LIMIT 500';
    return $args ? $wpdb->get_results($wpdb->prepare($sql, $args)) : $wpdb->get_results($sql);
}
function gvb_fcfa($n) { return number_format((float) $n, 0, ',', ' ') . ' FCFA'; }

function gvb_page_inscriptions() {
    gvb_exiger('inscriptions', 'voir');
    $f = gvb_filtres_inscriptions();
    $lignes = [];
    foreach (gvb_requete_inscriptions($f) as $x) {
        $fr = json_decode((string) $x->frais, true) ?: [];
        $act = '';
        if ($x->statut === 'validee') {
            $act .= '<a class="button" href="' . esc_url(gvb_url('gv-inscription', ['id' => $x->id])) . '">Fiche</a> ';
            if (gvb_can('inscriptions', 'annuler')) $act .= gvb_details('Annuler', gvb_form('gvb_inscription_annuler', 'gvb_inscription_annuler', ['ins' => $x->id]) . gvb_champ('motif', 'Motif', '', 'text', 'required maxlength="255"') . gvb_bouton('Annuler l’inscription') . '</form>');
        }
        $lignes[] = '<tr><td><strong>' . esc_html($x->matricule) . '</strong></td><td><a href="' . esc_url(gvb_url('gv-demande', ['id' => $x->demande_id])) . '">' . esc_html($x->prenom . ' ' . $x->nom) . '</a></td><td>' . esc_html(GVB_POLES[$x->pole] ?? '') . '</td><td>' . esc_html($x->niveau ?: '—') . '</td><td class="d">' . esc_html(gvb_fcfa(($fr['inscription'] ?? 0) + ($fr['scolarite'] ?? 0))) . '</td><td>' . gvb_badge($x->statut === 'validee' ? 'validee' : 'annulee', $x->statut === 'validee' ? 'Validée' : 'Annulée') . '</td><td>' . $act . '</td></tr>';
    }
    $actions = gvb_can('inscriptions', 'exporter') ? '<a class="button" href="' . esc_url(wp_nonce_url(add_query_arg(array_merge(['action' => 'gvb_export_inscriptions'], array_filter($f)), admin_url('admin-post.php')), 'gvb_export_inscriptions')) . '">Exporter (CSV)</a>' : '';
    gvb_debut('Inscriptions', 'inscription', $actions);
    echo '<form method="get" class="gvb-filtres"><input type="hidden" name="page" value="gv-inscriptions">' . gvb_liste('annee', 'Année', ['' => 'Toutes'] + array_combine(gvb_annees(), gvb_annees()), $f['annee']) . gvb_liste('pole', 'Pôle', ['' => 'Tous'] + GVB_POLES, $f['pole']) . gvb_champ('q', 'Recherche', $f['q'], 'search', 'placeholder="Nom ou matricule"') . gvb_bouton('Filtrer', false) . '</form>';
    echo '<p class="gvb-rappel">Les inscriptions se créent depuis une demande acceptée (Admissions).</p>';
    echo gvb_tableau(['Matricule', 'Étudiant', 'Pôle', 'Niveau', 'Frais prévus', 'Statut', 'Actions'], $lignes, 'Aucune inscription.');
    gvb_fin();
}

function gvb_page_inscription() {
    gvb_exiger('inscriptions', 'voir');
    global $wpdb;
    $x = $wpdb->get_row($wpdb->prepare('SELECT i.matricule, i.annee AS iannee, i.niveau AS iniveau, i.pole AS ipole, i.frais, i.derogation, i.statut AS istatut, i.inscrit_le, d.nom, d.prenom, d.naissance, d.telephone, d.parent_nom, d.parent_telephone FROM ' . gvb_table('inscriptions') . ' i JOIN ' . gvb_table('demandes') . ' d ON d.id = i.demande_id WHERE i.id = %d', absint($_GET['id'] ?? 0)));
    if (!$x || !gvb_pole_autorise($x->ipole)) wp_die('Inscription introuvable.', 'Introuvable', ['response' => 404, 'back_link' => false]);
    $fr = json_decode((string) $x->frais, true) ?: [];
    gvb_debut('Fiche d’inscription ' . $x->matricule, 'inscription', '<a class="button" href="' . esc_url(gvb_url('gv-inscriptions')) . '">← Inscriptions</a><button type="button" class="button button-primary" onclick="window.print()">' . gvb_icone('print', 16) . 'Imprimer</button>');
    echo '<div class="gvb-boite"><h2>' . esc_html(strtoupper($x->nom) . ' ' . $x->prenom) . '</h2><p><strong>Matricule :</strong> ' . esc_html($x->matricule) . ' · <strong>Pôle :</strong> ' . esc_html(GVB_POLES[$x->ipole] ?? '') . ' · <strong>Année :</strong> ' . esc_html($x->iannee) . ' · <strong>Niveau :</strong> ' . esc_html($x->iniveau ?: '—') . '</p>'
        . '<p><strong>Né(e) le :</strong> ' . esc_html(gvb_d($x->naissance)) . ' · <strong>Téléphone :</strong> ' . esc_html($x->telephone ?: '—') . ' · <strong>Parent :</strong> ' . esc_html($x->parent_nom ?: '—') . ' ' . esc_html($x->parent_telephone) . '</p>'
        . '<p><strong>Frais d’inscription :</strong> ' . esc_html(gvb_fcfa($fr['inscription'] ?? 0)) . ' · <strong>Scolarité annuelle :</strong> ' . esc_html(gvb_fcfa($fr['scolarite'] ?? 0)) . '</p>'
        . ($x->derogation ? '<p class="gvb-alerte">Dérogation pièces : ' . esc_html($x->derogation) . '</p>' : '') . '<p class="gvb-rappel">Inscrit le ' . esc_html(gvb_dt($x->inscrit_le)) . ' · Statut : ' . esc_html($x->istatut) . '</p></div>';
    echo '<style media="print">#adminmenumain,#wpadminbar,#wpfooter,.gvb-entete-actions{display:none!important}#wpcontent{margin-left:0!important}</style>';
    gvb_fin();
}

add_action('admin_post_gvb_export_inscriptions', function () {
    gvb_garde('inscriptions', 'exporter', 'gvb_export_inscriptions');
    $neutre = fn($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v;
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="inscriptions-' . gmdate('Ymd') . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['Matricule', 'Pôle', 'Année', 'Niveau', 'Nom', 'Prénom', 'Téléphone', 'Frais inscription', 'Scolarité', 'Statut', 'Inscrit le'], ';');
    foreach (gvb_requete_inscriptions(gvb_filtres_inscriptions()) as $x) { $fr = json_decode((string) $x->frais, true) ?: []; fputcsv($o, array_map($neutre, [$x->matricule, GVB_POLES[$x->pole] ?? $x->pole, $x->annee, $x->niveau, $x->nom, $x->prenom, $x->telephone, $fr['inscription'] ?? 0, $fr['scolarite'] ?? 0, $x->statut, $x->inscrit_le]), ';'); }
    gvb_log(0, 'Export des inscriptions');
    gvb_terminer();
});

// ── Tableau de bord ───────────────────────────────────────────────────────────
function gvb_indicateurs() {
    global $wpdb;
    $d = gvb_table('demandes'); $i = gvb_table('inscriptions'); $annee = gvb_reglages()['annee'];
    $w = ['annee = %s']; $a = [$annee]; gvb_clause_pole($w, $a);
    $par = $wpdb->get_results($wpdb->prepare("SELECT pole, statut, COUNT(*) n FROM $d WHERE " . implode(' AND ', $w) . ' GROUP BY pole, statut', $a));
    $r = ['annee' => $annee, 'statuts' => array_fill_keys(array_keys(GVB_STATUTS), 0), 'poles' => []];
    foreach ($par as $x) { $r['statuts'][$x->statut] = ($r['statuts'][$x->statut] ?? 0) + (int) $x->n; $r['poles'][$x->pole][$x->statut] = (int) $x->n; }
    $w2 = ['i.annee = %s', "i.statut = 'validee'"]; $a2 = [$annee]; gvb_clause_pole($w2, $a2, 'i');
    $r['inscrits'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $i i WHERE " . implode(' AND ', $w2), $a2));
    $w3 = ['annee = %s', "statut IN ('nouvelle','etude')", 'cree_le < %s']; $a3 = [$annee, gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS)]; gvb_clause_pole($w3, $a3);
    $r['en_retard'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $d WHERE " . implode(' AND ', $w3), $a3));
    $w4 = ['annee = %s', 'doublon_de > 0', "statut NOT IN ('refusee','abandonnee','inscrite')"]; $a4 = [$annee]; gvb_clause_pole($w4, $a4);
    $r['doublons'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $d WHERE " . implode(' AND ', $w4), $a4));
    return $r;
}

function gvb_page_tableau() {
    if (!current_user_can('gv_acces')) wp_die('Accès refusé.', 'Accès refusé', ['response' => 403, 'back_link' => false]);
    $u = wp_get_current_user();
    gvb_debut('Bonjour ' . $u->display_name, 'tableau');
    if (!gvb_can('admissions', 'voir')) { echo '<div class="gvb-boite"><p>Aucun module ne vous est encore attribué. Contactez la Direction.</p></div>'; gvb_fin(); return; }
    $k = gvb_indicateurs(); $s = $k['statuts'];
    $pole = gvb_pole_utilisateur();
    echo '<p class="gvb-rappel">Année ' . esc_html($k['annee']) . ($pole && $pole !== '__aucun__' ? ' · pôle ' . esc_html(GVB_POLES[$pole]) : '') . '</p><div class="gvb-cartes">'
        . gvb_carte('Nouvelles demandes', $s['nouvelle'], 'À prendre en charge', 'admission', $s['nouvelle'] ? 'attention' : '', gvb_url('gv-admissions', ['statut' => 'nouvelle', 'annee' => $k['annee']]))
        . gvb_carte('En étude', $s['etude'], $k['en_retard'] . ' en attente depuis plus de 7 jours', 'rapport', $k['en_retard'] ? 'attention' : '', gvb_url('gv-admissions', ['statut' => 'etude', 'annee' => $k['annee']]))
        . gvb_carte('Dossiers incomplets', $s['incomplete'], 'Pièces à relancer', 'alerte', $s['incomplete'] ? 'attention' : '', gvb_url('gv-admissions', ['statut' => 'incomplete', 'annee' => $k['annee']]))
        . gvb_carte('Acceptées à inscrire', $s['acceptee'], 'Prêtes pour l’inscription', 'inscription', '', gvb_url('gv-admissions', ['statut' => 'acceptee', 'annee' => $k['annee']]))
        . gvb_carte('Inscrits', $k['inscrits'], 'Année ' . $k['annee'], 'pole', 'ok', gvb_url('gv-inscriptions', ['annee' => $k['annee']]))
        . gvb_carte('Doublons probables', $k['doublons'], 'À vérifier', 'utilisateurs', $k['doublons'] ? 'attention' : '') . '</div>';
    $lignes = [];
    foreach (GVB_POLES as $slug => $nom) { if ($pole !== '' && $pole !== $slug) continue; $p = $k['poles'][$slug] ?? []; $lignes[] = '<tr><td><strong>' . esc_html($nom) . '</strong></td><td>' . array_sum($p) . '</td><td>' . ($p['acceptee'] ?? 0) . '</td><td>' . ($p['inscrite'] ?? 0) . '</td><td>' . ($p['refusee'] ?? 0) . '</td></tr>'; }
    echo '<div class="gvb-boite"><h2>Répartition par pôle</h2>' . gvb_tableau(['Pôle', 'Demandes', 'Acceptées', 'Inscrites', 'Refusées'], $lignes) . '</div>';
    $recent = [];
    foreach (gvb_requete_demandes(['annee' => '', 'pole' => '', 'statut' => '', 'q' => ''], 8) as $d) $recent[] = '<tr><td><a href="' . esc_url(gvb_url('gv-demande', ['id' => $d->id])) . '">' . esc_html($d->code) . '</a></td><td>' . esc_html($d->prenom . ' ' . $d->nom) . '</td><td>' . esc_html(GVB_POLES[$d->pole] ?? '') . '</td><td>' . gvb_badge($d->statut) . '</td></tr>';
    echo '<div class="gvb-boite"><h2>Dernières demandes</h2>' . gvb_tableau(['Code', 'Candidat', 'Pôle', 'Statut'], $recent, 'Aucune demande pour le moment.') . '</div>';
    gvb_fin();
}

// ── Réglages ──────────────────────────────────────────────────────────────────
function gvb_page_reglages() {
    gvb_exiger('parametres', 'voir');
    $r = gvb_reglages();
    gvb_debut('Réglages', 'reglage');
    echo '<div class="gvb-boite" style="max-width:640px"><h2>Admissions</h2>' . gvb_form('gvb_reglages_sauver', 'gvb_reglages_sauver') . gvb_champ('annee', 'Année académique en cours', $r['annee'], 'text', 'required pattern="[0-9]{4}[–-][0-9]{4}"')
        . gvb_champ('email', 'E-mail d’alerte des nouvelles demandes', $r['email'], 'email', 'required') . (gvb_can('parametres', 'modifier') ? gvb_bouton('Enregistrer') : '') . '</form></div>';
    gvb_fin();
}
add_action('admin_post_gvb_reglages_sauver', function () {
    gvb_garde('parametres', 'modifier', 'gvb_reglages_sauver');
    $annee = sanitize_text_field(wp_unslash($_POST['annee'] ?? '')); $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
    if (!preg_match('/^\d{4}[–-]\d{4}$/u', $annee) || !is_email($email)) gvb_retour('gv-reglages', [], null, 'Année (ex. 2026–2027) ou e-mail invalide.');
    update_option('gvb_reglages', ['annee' => $annee, 'email' => $email], false);
    gvb_log(0, 'Réglages modifiés', "année $annee");
    gvb_retour('gv-reglages', [], 'Réglages enregistrés.');
});
