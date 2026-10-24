<?php
/**
 * Plugin Name: Groupe Vitos — Back-office : étudiants et élèves
 * Description: Fiche de l'étudiant créée à l'inscription, matricule permanent, statut, réinscription annuelle, liste et export.
 */

if (!defined('ABSPATH')) exit;

const GVB_ETU_STATUTS = ['actif' => 'Actif', 'suspendu' => 'Suspendu', 'diplome' => 'Diplômé', 'abandon' => 'Abandon', 'transfere' => 'Transféré', 'inactif' => 'Inactif'];
const GVB_ETU_ICONES = ['actif' => 'check', 'suspendu' => 'horloge', 'diplome' => 'etoile', 'abandon' => 'croix', 'transfere' => 'liste', 'inactif' => 'croix'];
const GVB_ETU_COULEURS = ['actif' => '#007A2E', 'suspendu' => '#B42318', 'diplome' => '#005F73', 'abandon' => '#6B7280', 'transfere' => '#5A3E9B', 'inactif' => '#6B7280'];

function gvb_etu_badge($statut) {
    return '<span class="gvb-badge" style="background:' . esc_attr(GVB_ETU_COULEURS[$statut] ?? '#4B5578') . '">' . gvb_icone(GVB_ETU_ICONES[$statut] ?? 'dossier', 12) . ' ' . esc_html(GVB_ETU_STATUTS[$statut] ?? $statut) . '</span>';
}
function gvb_etudiant($id) {
    global $wpdb;
    $e = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . gvb_table('etudiants') . ' WHERE id = %d', (int) $id));
    return $e && gvb_pole_autorise($e->pole) ? $e : null;
}
function gvb_nb_etudiants_actifs() {
    global $wpdb; $where = ["statut = 'actif'"]; $args = [];
    gvb_clause_pole($where, $args);
    $sql = 'SELECT COUNT(*) FROM ' . gvb_table('etudiants') . ' WHERE ' . implode(' AND ', $where);
    return (int) ($args ? $wpdb->get_var($wpdb->prepare($sql, $args)) : $wpdb->get_var($sql));
}

// ── Création automatique à l'inscription ──────────────────────────────────────
add_filter('gvb_matricule_existant', function ($m, $demande_id) {
    global $wpdb;
    return (string) $wpdb->get_var($wpdb->prepare('SELECT matricule FROM ' . gvb_table('etudiants') . ' WHERE demande_id = %d ORDER BY id LIMIT 1', (int) $demande_id));
}, 10, 2);

add_action('gvb_inscription_creee', function ($ins_id, $d, $matricule) {
    global $wpdb;
    $t = gvb_table('etudiants'); $now = gvb_now();
    $e = $wpdb->get_row($wpdb->prepare("SELECT id FROM $t WHERE matricule = %s", $matricule));
    if ($e) {
        $wpdb->update($t, ['statut' => 'actif', 'motif' => '', 'pole' => $d->pole, 'annee' => $d->annee, 'niveau' => $d->niveau, 'formation_id' => $d->formation_id, 'modifie_le' => $now], ['id' => $e->id]);
        $eid = (int) $e->id;
    } else {
        $wpdb->insert($t, ['matricule' => $matricule, 'demande_id' => $d->id, 'pole' => $d->pole, 'annee' => $d->annee, 'niveau' => $d->niveau, 'formation_id' => $d->formation_id,
            'nom' => $d->nom, 'prenom' => $d->prenom, 'naissance' => $d->naissance ?: null, 'sexe' => $d->sexe, 'nationalite' => $d->nationalite, 'telephone' => $d->telephone, 'email' => $d->email, 'adresse' => $d->adresse,
            'parent_nom' => $d->parent_nom, 'parent_telephone' => $d->parent_telephone, 'parent_email' => $d->parent_email, 'statut' => 'actif', 'cree_le' => $now, 'modifie_le' => $now]);
        $eid = (int) $wpdb->insert_id;
    }
    $wpdb->update(gvb_table('inscriptions'), ['etudiant_id' => $eid], ['id' => $ins_id]);
    gvb_log($d->id, 'Fiche étudiant créée', $matricule);
}, 10, 3);

add_action('gvb_inscription_annulee', function ($ins, $motif) {
    global $wpdb;
    if (!$ins->etudiant_id) return;
    $reste = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . gvb_table('inscriptions') . " WHERE etudiant_id = %d AND statut = 'validee' AND id <> %d", $ins->etudiant_id, $ins->id));
    if (!$reste) $wpdb->update(gvb_table('etudiants'), ['statut' => 'inactif', 'motif' => 'Inscription annulée : ' . $motif, 'modifie_le' => gvb_now()], ['id' => $ins->etudiant_id]);
}, 10, 2);

// ── Réinscription d'une année à l'autre ───────────────────────────────────────
function gvb_reinscrire($etudiant_id, $annee, $niveau, $frais_inscription, $frais_scolarite) {
    global $wpdb;
    $verrou = 'gvb_reinscrire_' . (int) $etudiant_id;
    $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', $verrou));
    try {
        $e = gvb_etudiant($etudiant_id);
        if (!$e) return new WP_Error('introuvable', 'Étudiant introuvable.');
        if ($e->statut !== 'actif') return new WP_Error('statut', 'Seul un étudiant actif peut être réinscrit.');
        if (!preg_match('/^\d{4}[–-]\d{4}$/u', $annee)) return new WP_Error('annee', 'Année invalide (ex. 2027–2028).');
        if ($niveau === '') return new WP_Error('niveau', 'Indiquez le niveau ou la classe.');
        if ($frais_inscription < 0 || $frais_scolarite < 0) return new WP_Error('frais', 'Montants invalides.');
        $deja = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . gvb_table('inscriptions') . " WHERE etudiant_id = %d AND annee = %s AND statut = 'validee'", $e->id, $annee));
        if ($deja) return new WP_Error('deja', 'Cet étudiant est déjà inscrit pour ' . $annee . '.');
        $now = gvb_now();
        $wpdb->insert(gvb_table('inscriptions'), ['demande_id' => $e->demande_id, 'etudiant_id' => $e->id, 'matricule' => $e->matricule, 'pole' => $e->pole, 'annee' => $annee, 'niveau' => $niveau, 'formation_id' => $e->formation_id,
            'frais' => wp_json_encode(['inscription' => (int) $frais_inscription, 'scolarite' => (int) $frais_scolarite]), 'derogation' => '', 'statut' => 'validee', 'inscrit_le' => $now, 'inscrit_par' => get_current_user_id()]);
        $ins_id = (int) $wpdb->insert_id;
        $wpdb->update(gvb_table('etudiants'), ['annee' => $annee, 'niveau' => $niveau, 'modifie_le' => $now], ['id' => $e->id]);
        do_action('gvb_reinscription_creee', $ins_id);
        gvb_log($e->demande_id, 'Réinscription', $e->matricule . ' — ' . $annee . ' — ' . $niveau);
        return true;
    } finally {
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $verrou));
    }
}

// ── Liste ─────────────────────────────────────────────────────────────────────
function gvb_filtres_etudiants() {
    return ['annee' => sanitize_text_field(wp_unslash($_GET['annee'] ?? '')), 'pole' => isset(GVB_POLES[$_GET['pole'] ?? '']) ? $_GET['pole'] : '', 'statut' => isset(GVB_ETU_STATUTS[$_GET['statut'] ?? '']) ? $_GET['statut'] : '', 'q' => sanitize_text_field(wp_unslash($_GET['q'] ?? ''))];
}
function gvb_requete_etudiants($f, $limite = 0) {
    global $wpdb;
    $where = ['1=1']; $args = [];
    foreach (['annee', 'pole', 'statut'] as $k) if ($f[$k] !== '') { $where[] = "$k = %s"; $args[] = $f[$k]; }
    if ($f['q'] !== '') { $like = '%' . $wpdb->esc_like($f['q']) . '%'; $where[] = '(nom LIKE %s OR prenom LIKE %s OR matricule LIKE %s OR telephone LIKE %s OR email LIKE %s)'; array_push($args, $like, $like, $like, $like, $like); }
    gvb_clause_pole($where, $args);
    $sql = 'SELECT * FROM ' . gvb_table('etudiants') . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY nom, prenom' . ($limite ? ' LIMIT ' . (int) $limite : '');
    return $args ? $wpdb->get_results($wpdb->prepare($sql, $args)) : $wpdb->get_results($sql);
}

function gvb_page_etudiants() {
    gvb_exiger('etudiants', 'voir');
    $f = gvb_filtres_etudiants(); $lignes = [];
    foreach (gvb_requete_etudiants($f, 500) as $e) {
        $lignes[] = '<tr><td><a href="' . esc_url(gvb_url('gv-etudiant', ['id' => $e->id])) . '"><strong>' . esc_html($e->matricule) . '</strong></a></td><td>' . esc_html(mb_strtoupper($e->nom) . ' ' . $e->prenom) . '</td><td>' . esc_html(GVB_POLES[$e->pole] ?? '') . '</td><td>' . esc_html($e->niveau ?: '—') . '</td><td>' . esc_html($e->annee) . '</td><td>' . gvb_etu_badge($e->statut) . '</td><td>' . esc_html($e->telephone ?: ($e->parent_telephone ?: '—')) . '</td></tr>';
    }
    $actions = gvb_can('etudiants', 'exporter') ? '<a class="button" href="' . esc_url(wp_nonce_url(add_query_arg(array_merge(['action' => 'gvb_export_etudiants'], array_filter($f)), admin_url('admin-post.php')), 'gvb_export_etudiants')) . '">Exporter (CSV)</a>' : '';
    gvb_debut('Étudiants et élèves', 'pole', $actions);
    echo '<form method="get" class="gvb-filtres"><input type="hidden" name="page" value="gv-etudiants">' . gvb_liste('annee', 'Année', ['' => 'Toutes'] + array_combine(gvb_annees(), gvb_annees()), $f['annee']) . gvb_liste('pole', 'Pôle', ['' => 'Tous'] + GVB_POLES, $f['pole']) . gvb_liste('statut', 'Statut', ['' => 'Tous'] + GVB_ETU_STATUTS, $f['statut']) . gvb_champ('q', 'Recherche', $f['q'], 'search', 'placeholder="Nom, matricule, téléphone…"') . gvb_bouton('Filtrer', false) . '</form>';
    echo '<p class="gvb-rappel">La fiche d’un étudiant est créée automatiquement à son inscription (Admissions → Inscrire le candidat).</p>';
    echo gvb_tableau(['Matricule', 'Étudiant', 'Pôle', 'Niveau', 'Année', 'Statut', 'Contact'], $lignes, 'Aucun étudiant ne correspond.');
    gvb_fin();
}

// ── Fiche ─────────────────────────────────────────────────────────────────────
function gvb_page_etudiant() {
    gvb_exiger('etudiants', 'voir');
    global $wpdb;
    $e = gvb_etudiant(absint($_GET['id'] ?? 0));
    if (!$e) wp_die('Étudiant introuvable.', 'Introuvable', ['response' => 404, 'back_link' => false]);
    gvb_debut($e->matricule . ' — ' . $e->prenom . ' ' . $e->nom, 'pole', '<a class="button" href="' . esc_url(gvb_url('gv-etudiants')) . '">← Étudiants</a>');
    echo '<p>' . gvb_etu_badge($e->statut) . ' <span class="gvb-rappel">' . esc_html(GVB_POLES[$e->pole] ?? '') . ' · ' . esc_html($e->annee) . ' · ' . esc_html($e->niveau ?: 'niveau non renseigné') . '</span></p>';
    if ($e->motif && $e->statut !== 'actif') echo '<div class="gvb-alerte">Motif : ' . esc_html($e->motif) . '</div>';
    echo '<div class="gvb-grille"><div><div class="gvb-boite"><h2>' . gvb_icone('utilisateurs', 18) . 'Identité et contacts</h2>';
    if (gvb_can('etudiants', 'modifier')) {
        echo gvb_form('gvb_etudiant_sauver', 'gvb_etudiant_sauver', ['id' => $e->id]) . '<div class="gvb-champs">'
            . gvb_champ('nom', 'Nom *', $e->nom, 'text', 'required') . gvb_champ('prenom', 'Prénom *', $e->prenom, 'text', 'required') . gvb_champ('naissance', 'Date de naissance', $e->naissance, 'date')
            . gvb_liste('sexe', 'Sexe', ['' => '—', 'F' => 'Féminin', 'M' => 'Masculin'], $e->sexe) . gvb_champ('nationalite', 'Nationalité', $e->nationalite) . gvb_champ('telephone', 'Téléphone', $e->telephone, 'tel') . gvb_champ('email', 'E-mail', $e->email, 'email') . gvb_champ('adresse', 'Adresse', $e->adresse)
            . gvb_champ('parent_nom', 'Parent ou tuteur', $e->parent_nom) . gvb_champ('parent_telephone', 'Téléphone du parent', $e->parent_telephone, 'tel') . gvb_champ('parent_email', 'E-mail du parent', $e->parent_email, 'email') . '</div>' . gvb_bouton('Enregistrer') . '</form>';
    } else {
        foreach (['naissance' => 'Naissance', 'telephone' => 'Téléphone', 'email' => 'E-mail', 'adresse' => 'Adresse', 'parent_nom' => 'Parent', 'parent_telephone' => 'Tél. parent'] as $k => $l) echo '<p><strong>' . esc_html($l) . ' :</strong> ' . esc_html((string) ($e->$k ?: '—')) . '</p>';
    }
    echo '</div></div><div>';

    $ins = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . gvb_table('inscriptions') . ' WHERE etudiant_id = %d ORDER BY id DESC', $e->id));
    $l = [];
    foreach ($ins as $i) { $fr = json_decode((string) $i->frais, true) ?: []; $l[] = '<tr><td>' . esc_html($i->annee) . '</td><td>' . esc_html($i->niveau ?: '—') . '</td><td class="d">' . esc_html(gvb_fcfa(($fr['inscription'] ?? 0) + ($fr['scolarite'] ?? 0))) . '</td><td>' . gvb_badge($i->statut === 'validee' ? 'validee' : 'annulee', $i->statut === 'validee' ? 'Validée' : 'Annulée') . '</td></tr>'; }
    echo '<div class="gvb-boite"><h2>' . gvb_icone('journal', 18) . 'Parcours</h2>' . gvb_tableau(['Année', 'Niveau', 'Frais prévus', 'État'], $l, 'Aucune inscription.') . '<p><a href="' . esc_url(gvb_url('gv-demande', ['id' => $e->demande_id])) . '">Voir le dossier d’admission →</a>' . (gvb_can('finances', 'voir') ? ' &nbsp;·&nbsp; <a href="' . esc_url(gvb_url('gv-compte', ['id' => $e->id])) . '">Compte financier →</a>' : '') . '</p></div>';

    if ($e->statut === 'actif' && gvb_can('inscriptions', 'creer')) {
        $a = gvb_reglages()['annee'];
        echo '<div class="gvb-boite"><h2>' . gvb_icone('inscription', 18) . 'Réinscription</h2>' . gvb_form('gvb_reinscrire', 'gvb_reinscrire', ['id' => $e->id]) . '<div class="gvb-champs">' . gvb_champ('annee', 'Année académique', $a, 'text', 'required pattern="[0-9]{4}[–-][0-9]{4}"') . gvb_champ('niveau', 'Niveau ou classe', $e->niveau, 'text', 'required')
            . gvb_champ('frais_inscription', 'Frais d’inscription (FCFA)', '', 'number', 'min="0" step="1" required') . gvb_champ('frais_scolarite', 'Scolarité annuelle (FCFA)', '', 'number', 'min="0" step="1" required') . '</div>' . gvb_bouton('Réinscrire') . '</form></div>';
    }
    if (gvb_can('etudiants', 'modifier') && $e->statut !== 'inactif') {
        echo '<div class="gvb-boite"><h2>' . gvb_icone('check', 18) . 'Statut</h2>' . gvb_form('gvb_etudiant_statut', 'gvb_etudiant_statut', ['id' => $e->id]) . gvb_liste('statut', 'Nouveau statut', array_diff_key(GVB_ETU_STATUTS, ['inactif' => 1]), $e->statut)
            . gvb_champ('motif', 'Motif (obligatoire sauf pour « Actif »)', '', 'text', 'maxlength="255"') . gvb_bouton('Appliquer') . '</form></div>';
    }
    echo '</div></div>';
    do_action('gvb_fiche_etudiant_apres', $e);
    gvb_fin();
}

// ── Actions ───────────────────────────────────────────────────────────────────
add_action('admin_post_gvb_etudiant_sauver', function () {
    gvb_garde('etudiants', 'modifier', 'gvb_etudiant_sauver');
    global $wpdb; $id = absint($_POST['id'] ?? 0); $e = gvb_etudiant($id);
    if (!$e) gvb_retour('gv-etudiants', [], null, 'Étudiant introuvable.');
    $d = gvb_nettoyer_demande($_POST + ['pole' => $e->pole]);
    if ($d['nom'] === '' || $d['prenom'] === '') gvb_retour('gv-etudiant', ['id' => $id], null, 'Le nom et le prénom sont obligatoires.');
    if (!empty($_POST['email']) && $d['email'] === '') gvb_retour('gv-etudiant', ['id' => $id], null, 'Adresse e-mail invalide.');
    if ($d['naissance'] !== '' && (strtotime($d['naissance']) === false || $d['naissance'] > gmdate('Y-m-d'))) gvb_retour('gv-etudiant', ['id' => $id], null, 'Date de naissance invalide.');
    $champs = ['nom', 'prenom', 'naissance', 'sexe', 'nationalite', 'telephone', 'email', 'adresse', 'parent_nom', 'parent_telephone', 'parent_email'];
    $maj = array_intersect_key($d, array_flip($champs)); $diff = [];
    foreach ($maj as $k => $v) if ((string) ($e->$k ?? '') !== (string) $v) $diff[] = $k;
    $maj['naissance'] = $maj['naissance'] ?: null; $maj['modifie_le'] = gvb_now();
    $wpdb->update(gvb_table('etudiants'), $maj, ['id' => $id]);
    if ($diff) gvb_log($e->demande_id, 'Fiche étudiant modifiée', implode(', ', $diff));
    gvb_retour('gv-etudiant', ['id' => $id], 'Fiche enregistrée.');
});

add_action('admin_post_gvb_etudiant_statut', function () {
    gvb_garde('etudiants', 'modifier', 'gvb_etudiant_statut');
    global $wpdb; $id = absint($_POST['id'] ?? 0); $e = gvb_etudiant($id);
    $nouveau = sanitize_key($_POST['statut'] ?? ''); $motif = mb_substr(sanitize_text_field(wp_unslash($_POST['motif'] ?? '')), 0, 255);
    if (!$e || $e->statut === 'inactif') gvb_retour('gv-etudiants', [], null, 'Étudiant introuvable ou inactif.');
    if (!isset(GVB_ETU_STATUTS[$nouveau]) || $nouveau === 'inactif') gvb_retour('gv-etudiant', ['id' => $id], null, 'Statut invalide.');
    if ($nouveau !== 'actif' && $motif === '') gvb_retour('gv-etudiant', ['id' => $id], null, 'Un motif est obligatoire.');
    if ($nouveau === $e->statut) gvb_retour('gv-etudiant', ['id' => $id], null, 'Le statut est déjà « ' . GVB_ETU_STATUTS[$nouveau] . ' ».');
    $wpdb->update(gvb_table('etudiants'), ['statut' => $nouveau, 'motif' => $nouveau === 'actif' ? '' : $motif, 'modifie_le' => gvb_now()], ['id' => $id]);
    gvb_log($e->demande_id, 'Statut étudiant : ' . GVB_ETU_STATUTS[$e->statut] . ' → ' . GVB_ETU_STATUTS[$nouveau], $motif);
    gvb_retour('gv-etudiant', ['id' => $id], 'Statut mis à jour.');
});

add_action('admin_post_gvb_reinscrire', function () {
    gvb_garde('inscriptions', 'creer', 'gvb_reinscrire');
    $id = absint($_POST['id'] ?? 0);
    $r = gvb_reinscrire($id, sanitize_text_field(wp_unslash($_POST['annee'] ?? '')), mb_substr(sanitize_text_field(wp_unslash($_POST['niveau'] ?? '')), 0, 40), absint($_POST['frais_inscription'] ?? 0), absint($_POST['frais_scolarite'] ?? 0));
    if (is_wp_error($r)) gvb_retour('gv-etudiant', ['id' => $id], null, $r);
    gvb_retour('gv-etudiant', ['id' => $id], 'Réinscription enregistrée.');
});

add_action('admin_post_gvb_export_etudiants', function () {
    gvb_garde('etudiants', 'exporter', 'gvb_export_etudiants');
    $neutre = fn($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v;
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="etudiants-' . gmdate('Ymd') . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['Matricule', 'Pôle', 'Année', 'Niveau', 'Nom', 'Prénom', 'Naissance', 'Sexe', 'Téléphone', 'E-mail', 'Parent', 'Tél. parent', 'Statut'], ';');
    foreach (gvb_requete_etudiants(gvb_filtres_etudiants()) as $e) fputcsv($o, array_map($neutre, [$e->matricule, GVB_POLES[$e->pole] ?? $e->pole, $e->annee, $e->niveau, $e->nom, $e->prenom, $e->naissance, $e->sexe, $e->telephone, $e->email, $e->parent_nom, $e->parent_telephone, GVB_ETU_STATUTS[$e->statut] ?? $e->statut]), ';');
    gvb_log(0, 'Export des étudiants');
    gvb_terminer();
});
