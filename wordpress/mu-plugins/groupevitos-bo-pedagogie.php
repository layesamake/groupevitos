<?php
/**
 * Plugin Name: Groupe Vitos — Back-office : programmes et enseignants
 * Description: Cours (maquette par formation et niveau), enseignants, affectations avec volumes horaires, charges horaires (prévu, planifié, effectué).
 */

if (!defined('ABSPATH')) exit;

const GVB_ENS_STATUTS = ['permanent' => 'Permanent', 'vacataire' => 'Vacataire'];

// ── Accès aux données (avec périmètre de pôle) ────────────────────────────────
function gvb_ens_dans_perimetre($e) {
    $p = gvb_pole_utilisateur();
    if ($p === '') return true;
    if ($p === '__aucun__') return false;
    return $e->poles === '' || in_array($p, explode(',', $e->poles), true);
}
function gvb_enseignant($id) {
    global $wpdb;
    $e = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . gvb_table('enseignants') . ' WHERE id = %d', (int) $id));
    return $e && gvb_ens_dans_perimetre($e) ? $e : null;
}
function gvb_cours_ligne($id) {
    global $wpdb;
    $c = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . gvb_table('cours') . ' WHERE id = %d', (int) $id));
    return $c && gvb_pole_autorise($c->pole) ? $c : null;
}
function gvb_nom_ens($e) { return mb_strtoupper($e->nom) . ' ' . $e->prenom; }
function gvb_libelle_formation($id) { $f = gvb_formations(); return $id && isset($f[$id]) ? $f[$id] : ''; }
function gvb_heures_affectees_cours($cours_id, $annee, $sauf_aff = 0) {
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(heures),0) FROM ' . gvb_table('affectations') . ' WHERE cours_id = %d AND annee = %s AND id <> %d', $cours_id, $annee, $sauf_aff));
}
function gvb_heures_affectees_ens($ens_id, $annee, $sauf_aff = 0) {
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(heures),0) FROM ' . gvb_table('affectations') . ' WHERE enseignant_id = %d AND annee = %s AND id <> %d', $ens_id, $annee, $sauf_aff));
}
// Heures planifiées et effectuées (séances) d'un enseignant sur une année, calculées côté PHP
function gvb_heures_seances($ens_id, $annee) {
    global $wpdb; $plan = 0.0; $fait = 0.0;
    foreach ($wpdb->get_results($wpdb->prepare('SELECT debut, fin, statut FROM ' . gvb_table('seances') . ' WHERE enseignant_id = %d AND annee = %s', $ens_id, $annee)) as $s) {
        if (!in_array($s->statut, ['planifiee', 'tenue'], true)) continue;
        $h = max(0, (strtotime('1970-01-01 ' . $s->fin) - strtotime('1970-01-01 ' . $s->debut)) / 3600);
        $plan += $h; if ($s->statut === 'tenue') $fait += $h;
    }
    return ['planifiees' => round($plan, 2), 'effectuees' => round($fait, 2)];
}
function gvb_h($v) { return rtrim(rtrim(number_format((float) $v, 2, ',', ' '), '0'), ',') . ' h'; }
function gvb_poles_cases($coches = []) {
    $h = '<fieldset class="gvb-champ"><span>Pôles d’intervention (aucun coché = tous)</span><div class="gvb-actions">';
    foreach (GVB_POLES as $k => $l) $h .= '<label class="gvb-cases"><input type="checkbox" name="poles[]" value="' . esc_attr($k) . '"' . checked(in_array($k, $coches, true), true, false) . '> ' . esc_html($l) . '</label>';
    return $h . '</div></fieldset>';
}
function gvb_poles_post() {
    $p = isset($_POST['poles']) && is_array($_POST['poles']) ? array_values(array_intersect(array_map('sanitize_key', wp_unslash($_POST['poles'])), array_keys(GVB_POLES))) : [];
    return implode(',', $p);
}

// ── Programmes (cours) ────────────────────────────────────────────────────────
function gvb_page_programmes() {
    gvb_exiger('pedagogie', 'voir');
    global $wpdb;
    $pole_u = gvb_pole_utilisateur();
    $pole = isset(GVB_POLES[$_GET['pole'] ?? '']) ? $_GET['pole'] : ($pole_u && $pole_u !== '__aucun__' ? $pole_u : '');
    $annee = sanitize_text_field(wp_unslash($_GET['annee'] ?? '')) ?: gvb_reglages()['annee'];
    $niveau = sanitize_text_field(wp_unslash($_GET['niveau'] ?? ''));
    $w = ['actif = 1']; $a = [];
    if ($pole !== '') { $w[] = 'pole = %s'; $a[] = $pole; }
    if ($niveau !== '') { $w[] = 'niveau = %s'; $a[] = $niveau; }
    gvb_clause_pole($w, $a);
    $sql = 'SELECT * FROM ' . gvb_table('cours') . ' WHERE ' . implode(' AND ', $w) . ' ORDER BY pole, formation_id, niveau, semestre, intitule';
    $cours = $a ? $wpdb->get_results($wpdb->prepare($sql, $a)) : $wpdb->get_results($sql);
    $ens = $wpdb->get_results('SELECT * FROM ' . gvb_table('enseignants') . ' WHERE actif = 1 ORDER BY nom, prenom');
    $creer = gvb_can('pedagogie', 'creer'); $modif = gvb_can('pedagogie', 'modifier');
    $niveaux = array_filter(array_unique($wpdb->get_col('SELECT niveau FROM ' . gvb_table('cours') . " WHERE niveau <> '' ORDER BY niveau")));
    gvb_debut('Programmes et cours', 'liste');
    echo '<form method="get" class="gvb-filtres"><input type="hidden" name="page" value="gv-programmes">' . gvb_liste('pole', 'Pôle', ['' => 'Tous'] + GVB_POLES, $pole) . gvb_liste('niveau', 'Niveau', ['' => 'Tous'] + array_combine($niveaux, $niveaux), $niveau) . gvb_champ('annee', 'Année des affectations', $annee) . gvb_bouton('Filtrer', false) . '</form>';
    $l = [];
    foreach ($cours as $c) {
        $affs = $wpdb->get_results($wpdb->prepare('SELECT a.*, e.nom, e.prenom FROM ' . gvb_table('affectations') . ' a JOIN ' . gvb_table('enseignants') . ' e ON e.id = a.enseignant_id WHERE a.cours_id = %d AND a.annee = %s ORDER BY e.nom', $c->id, $annee));
        $tot = array_sum(array_map(fn($x) => (int) $x->heures, $affs));
        $etat = $tot === 0 ? ['incomplete', 'Non affecté'] : ($tot < $c->volume ? ['etude', 'Partiel'] : ['validee', 'Complet']);
        $noms = '';
        foreach ($affs as $x) {
            $noms .= '<div>' . esc_html(mb_strtoupper($x->nom) . ' ' . $x->prenom) . ' — ' . (int) $x->heures . ' h';
            if ($modif) $noms .= ' ' . gvb_form('gvb_aff_retirer', 'gvb_aff_retirer', ['id' => $x->id], 'class="gvb-inline" onsubmit="return confirm(\'Retirer cette affectation ?\')"') . gvb_bouton('Retirer', false) . '</form>';
            $noms .= '</div>';
        }
        $act = '';
        if ($modif) {
            $opts = []; foreach ($ens as $e) if (gvb_ens_dans_perimetre($e) && ($e->poles === '' || in_array($c->pole, explode(',', $e->poles), true))) $opts[$e->id] = gvb_nom_ens($e);
            if ($opts) $act .= gvb_details('Affecter', gvb_form('gvb_affecter', 'gvb_affecter', ['cours' => $c->id, 'annee' => $annee]) . gvb_liste('enseignant', 'Enseignant', $opts) . gvb_champ('heures', 'Heures (reste ' . max(0, $c->volume - $tot) . ' h)', max(0, $c->volume - $tot), 'number', 'min="1" max="' . (int) $c->volume . '" step="1" required') . gvb_bouton('Affecter', false) . '</form>');
            $act .= ' ' . gvb_details('Modifier', gvb_form('gvb_cours_modifier', 'gvb_cours_modifier', ['id' => $c->id]) . gvb_champ('intitule', 'Intitulé', $c->intitule, 'text', 'required maxlength="190"') . gvb_champ('code', 'Code', $c->code) . gvb_champ('semestre', 'Semestre', $c->semestre) . gvb_champ('volume', 'Volume horaire', $c->volume, 'number', 'min="1" max="2000" required') . gvb_bouton('Enregistrer', false) . '</form>'
                . gvb_form('gvb_cours_desactiver', 'gvb_cours_desactiver', ['id' => $c->id], 'onsubmit="return confirm(\'Désactiver ce cours ?\')"') . gvb_bouton('Désactiver le cours', false) . '</form>');
        }
        $l[] = '<tr><td><strong>' . esc_html($c->intitule) . '</strong>' . ($c->code ? '<br><small>' . esc_html($c->code) . '</small>' : '') . '</td><td>' . esc_html(GVB_POLES[$c->pole] ?? '') . '<br><small>' . esc_html(gvb_libelle_formation($c->formation_id)) . '</small></td><td>' . esc_html($c->niveau ?: '—') . ($c->semestre ? ' · ' . esc_html($c->semestre) : '') . '</td><td class="d">' . (int) $c->volume . ' h</td><td>' . gvb_badge($etat[0], $etat[1]) . '<br><small>' . $tot . ' / ' . (int) $c->volume . ' h</small></td><td>' . $noms . '</td><td>' . $act . '</td></tr>';
    }
    echo '<div class="gvb-boite"><h2>' . gvb_icone('liste', 18) . 'Cours (' . count($cours) . ')</h2>' . gvb_tableau(['Cours', 'Pôle / formation', 'Niveau', 'Volume', 'Affectation ' . $annee, 'Enseignants', ''], $l, 'Aucun cours. Ajoutez le premier ci-dessous.') . '</div>';
    if ($creer) {
        $formations = [0 => '— (Lycée ou transversal)'] + gvb_formations();
        echo '<div class="gvb-boite"><h2>' . gvb_icone('plus', 18) . 'Nouveau cours</h2>' . gvb_form('gvb_cours_creer', 'gvb_cours_creer') . '<div class="gvb-champs">'
            . gvb_liste('pole', 'Pôle *', GVB_POLES, $pole ?: ($pole_u && $pole_u !== '__aucun__' ? $pole_u : 'universite')) . gvb_liste('formation_id', 'Formation', $formations) . gvb_champ('niveau', 'Niveau ou classe *', $niveau, 'text', 'required maxlength="40" placeholder="Licence 1, 10e année…"')
            . gvb_champ('semestre', 'Semestre', '', 'text', 'maxlength="10"') . gvb_champ('code', 'Code', '', 'text', 'maxlength="30"') . gvb_champ('intitule', 'Intitulé *', '', 'text', 'required maxlength="190"') . gvb_champ('volume', 'Volume horaire (heures) *', '', 'number', 'min="1" max="2000" step="1" required')
            . '</div>' . gvb_bouton('Ajouter le cours') . '</form></div>';
    }
    gvb_fin();
}

add_action('admin_post_gvb_cours_creer', function () {
    gvb_garde('pedagogie', 'creer', 'gvb_cours_creer');
    global $wpdb;
    $pole = sanitize_key($_POST['pole'] ?? ''); $fid = absint($_POST['formation_id'] ?? 0); $volume = absint($_POST['volume'] ?? 0);
    $intitule = mb_substr(sanitize_text_field(wp_unslash($_POST['intitule'] ?? '')), 0, 190); $niveau = mb_substr(sanitize_text_field(wp_unslash($_POST['niveau'] ?? '')), 0, 40);
    if (!isset(GVB_POLES[$pole]) || !gvb_pole_autorise($pole)) gvb_retour('gv-programmes', [], null, 'Pôle invalide ou hors de votre périmètre.');
    if ($intitule === '' || $niveau === '') gvb_retour('gv-programmes', [], null, 'L’intitulé et le niveau sont obligatoires.');
    if ($volume < 1 || $volume > 2000) gvb_retour('gv-programmes', [], null, 'Le volume horaire doit être compris entre 1 et 2000 heures.');
    if ($fid && !isset(gvb_formations()[$fid])) $fid = 0;
    if ($wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . gvb_table('cours') . ' WHERE pole = %s AND formation_id = %d AND niveau = %s AND intitule = %s AND actif = 1', $pole, $fid, $niveau, $intitule))) gvb_retour('gv-programmes', [], null, 'Ce cours existe déjà pour ce niveau.');
    $wpdb->insert(gvb_table('cours'), ['pole' => $pole, 'formation_id' => $fid, 'niveau' => $niveau, 'semestre' => mb_substr(sanitize_text_field(wp_unslash($_POST['semestre'] ?? '')), 0, 10), 'code' => mb_substr(sanitize_text_field(wp_unslash($_POST['code'] ?? '')), 0, 30), 'intitule' => $intitule, 'volume' => $volume, 'cree_le' => gvb_now()]);
    gvb_log(0, 'Cours créé', $intitule . ' — ' . $niveau);
    gvb_retour('gv-programmes', ['pole' => $pole], 'Cours ajouté.');
});
add_action('admin_post_gvb_cours_modifier', function () {
    gvb_garde('pedagogie', 'modifier', 'gvb_cours_modifier');
    global $wpdb; $c = gvb_cours_ligne(absint($_POST['id'] ?? 0));
    if (!$c) gvb_retour('gv-programmes', [], null, 'Cours introuvable.');
    $volume = absint($_POST['volume'] ?? 0); $intitule = mb_substr(sanitize_text_field(wp_unslash($_POST['intitule'] ?? '')), 0, 190);
    if ($intitule === '' || $volume < 1 || $volume > 2000) gvb_retour('gv-programmes', ['pole' => $c->pole], null, 'Intitulé et volume (1 à 2000 h) obligatoires.');
    $max = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(MAX(t),0) FROM (SELECT SUM(heures) t FROM ' . gvb_table('affectations') . ' WHERE cours_id = %d GROUP BY annee) x', $c->id));
    if ($volume < $max) gvb_retour('gv-programmes', ['pole' => $c->pole], null, 'Le volume ne peut pas être inférieur aux heures déjà affectées (' . $max . ' h).');
    $wpdb->update(gvb_table('cours'), ['intitule' => $intitule, 'volume' => $volume, 'code' => mb_substr(sanitize_text_field(wp_unslash($_POST['code'] ?? '')), 0, 30), 'semestre' => mb_substr(sanitize_text_field(wp_unslash($_POST['semestre'] ?? '')), 0, 10)], ['id' => $c->id]);
    gvb_log(0, 'Cours modifié', $intitule);
    gvb_retour('gv-programmes', ['pole' => $c->pole], 'Cours modifié.');
});
add_action('admin_post_gvb_cours_desactiver', function () {
    gvb_garde('pedagogie', 'modifier', 'gvb_cours_desactiver');
    global $wpdb; $c = gvb_cours_ligne(absint($_POST['id'] ?? 0));
    if (!$c) gvb_retour('gv-programmes', [], null, 'Cours introuvable.');
    if ((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . gvb_table('seances') . " WHERE cours_id = %d AND statut IN ('planifiee','tenue')", $c->id))) gvb_retour('gv-programmes', ['pole' => $c->pole], null, 'Ce cours a des séances planifiées ou tenues : annulez-les d’abord.');
    $wpdb->update(gvb_table('cours'), ['actif' => 0], ['id' => $c->id]);
    $wpdb->delete(gvb_table('affectations'), ['cours_id' => $c->id]);
    gvb_log(0, 'Cours désactivé', $c->intitule);
    gvb_retour('gv-programmes', ['pole' => $c->pole], 'Cours désactivé.');
});

// ── Affectations ──────────────────────────────────────────────────────────────
function gvb_affecter($cours_id, $ens_id, $annee, $heures) {
    global $wpdb;
    $c = gvb_cours_ligne($cours_id); $e = gvb_enseignant($ens_id);
    if (!$c || !$e) return new WP_Error('introuvable', 'Cours ou enseignant introuvable.');
    if (!$c->actif) return new WP_Error('actif', 'Ce cours est désactivé.');
    if (!$e->actif) return new WP_Error('actif', 'Cet enseignant n’est plus actif.');
    if ($e->poles !== '' && !in_array($c->pole, explode(',', $e->poles), true)) return new WP_Error('pole', 'Cet enseignant n’intervient pas dans le pôle ' . (GVB_POLES[$c->pole] ?? '') . '.');
    if (!preg_match('/^\d{4}[–-]\d{4}$/u', $annee)) return new WP_Error('annee', 'Année invalide.');
    $heures = (int) $heures;
    if ($heures < 1) return new WP_Error('heures', 'Le nombre d’heures doit être positif.');
    $verrou = 'gvb_aff_' . (int) $cours_id;
    $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', $verrou));
    try {
        if ($wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . gvb_table('affectations') . ' WHERE cours_id = %d AND enseignant_id = %d AND annee = %s', $c->id, $e->id, $annee))) return new WP_Error('doublon', 'Cet enseignant est déjà affecté à ce cours pour ' . $annee . '.');
        $reste = $c->volume - gvb_heures_affectees_cours($c->id, $annee);
        if ($heures > $reste) return new WP_Error('volume', 'Dépasse le volume du cours : il reste ' . max(0, $reste) . ' h à affecter.');
        if ($e->plafond > 0 && gvb_heures_affectees_ens($e->id, $annee) + $heures > $e->plafond) return new WP_Error('plafond', 'Dépasse le plafond annuel de l’enseignant (' . $e->plafond . ' h).');
        $wpdb->insert(gvb_table('affectations'), ['cours_id' => $c->id, 'enseignant_id' => $e->id, 'annee' => $annee, 'heures' => $heures, 'cree_le' => gvb_now()]);
        gvb_log(0, 'Affectation', gvb_nom_ens($e) . ' → ' . $c->intitule . ' (' . $heures . ' h, ' . $annee . ')');
        return (int) $wpdb->insert_id;
    } finally {
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $verrou));
    }
}
add_action('admin_post_gvb_affecter', function () {
    gvb_garde('pedagogie', 'modifier', 'gvb_affecter');
    $c = absint($_POST['cours'] ?? 0); $e = absint($_POST['enseignant'] ?? 0);
    $r = gvb_affecter($c, $e, sanitize_text_field(wp_unslash($_POST['annee'] ?? '')), absint($_POST['heures'] ?? 0));
    $retour = !empty($_POST['retour_ens']) ? ['gv-enseignant', ['id' => $e]] : ['gv-programmes', ['pole' => (gvb_cours_ligne($c)->pole ?? '')]];
    if (is_wp_error($r)) gvb_retour($retour[0], $retour[1], null, $r);
    gvb_retour($retour[0], $retour[1], 'Affectation enregistrée.');
});
add_action('admin_post_gvb_aff_retirer', function () {
    gvb_garde('pedagogie', 'modifier', 'gvb_aff_retirer');
    global $wpdb;
    $a = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . gvb_table('affectations') . ' WHERE id = %d', absint($_POST['id'] ?? 0)));
    $c = $a ? gvb_cours_ligne($a->cours_id) : null; $e = $a ? gvb_enseignant($a->enseignant_id) : null;
    if (!$a || !$c || !$e) gvb_retour('gv-programmes', [], null, 'Affectation introuvable.');
    if ((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . gvb_table('seances') . " WHERE cours_id = %d AND enseignant_id = %d AND annee = %s AND statut IN ('planifiee','tenue')", $a->cours_id, $a->enseignant_id, $a->annee))) gvb_retour('gv-programmes', ['pole' => $c->pole], null, 'Des séances sont planifiées ou tenues pour cette affectation : annulez-les d’abord.');
    $wpdb->delete(gvb_table('affectations'), ['id' => $a->id]);
    gvb_log(0, 'Affectation retirée', gvb_nom_ens($e) . ' ← ' . $c->intitule . ' (' . $a->annee . ')');
    gvb_retour('gv-programmes', ['pole' => $c->pole], 'Affectation retirée.');
});

// ── Enseignants ───────────────────────────────────────────────────────────────
function gvb_page_enseignants() {
    gvb_exiger('pedagogie', 'voir');
    global $wpdb;
    $statut = isset(GVB_ENS_STATUTS[$_GET['statut'] ?? '']) ? $_GET['statut'] : ''; $q = sanitize_text_field(wp_unslash($_GET['q'] ?? '')); $voir_inactifs = !empty($_GET['inactifs']);
    $annee = gvb_reglages()['annee']; $w = ['1=1']; $a = [];
    if (!$voir_inactifs) $w[] = 'actif = 1';
    if ($statut !== '') { $w[] = 'statut = %s'; $a[] = $statut; }
    if ($q !== '') { $like = '%' . $wpdb->esc_like($q) . '%'; $w[] = '(nom LIKE %s OR prenom LIKE %s OR specialites LIKE %s)'; array_push($a, $like, $like, $like); }
    $sql = 'SELECT * FROM ' . gvb_table('enseignants') . ' WHERE ' . implode(' AND ', $w) . ' ORDER BY nom, prenom';
    $rows = array_filter($a ? $wpdb->get_results($wpdb->prepare($sql, $a)) : $wpdb->get_results($sql), 'gvb_ens_dans_perimetre');
    $l = [];
    foreach ($rows as $e) {
        $nb = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . gvb_table('affectations') . ' WHERE enseignant_id = %d AND annee = %s', $e->id, $annee));
        $l[] = '<tr' . ($e->actif ? '' : ' style="opacity:.55"') . '><td><a href="' . esc_url(gvb_url('gv-enseignant', ['id' => $e->id])) . '"><strong>' . esc_html(gvb_nom_ens($e)) . '</strong></a></td><td>' . esc_html(GVB_ENS_STATUTS[$e->statut] ?? $e->statut) . '</td><td>' . esc_html($e->poles ? implode(', ', array_map(fn($p) => GVB_POLES[$p] ?? $p, explode(',', $e->poles))) : 'Tous') . '</td><td>' . esc_html($e->specialites ?: '—') . '</td><td>' . $nb . ' cours · ' . gvb_heures_affectees_ens($e->id, $annee) . ' h</td><td>' . esc_html($e->telephone ?: '—') . '</td></tr>';
    }
    $actions = gvb_can('pedagogie', 'exporter') ? '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=gvb_export_enseignants'), 'gvb_export_enseignants')) . '">Exporter (CSV)</a>' : '';
    gvb_debut('Enseignants', 'cv', $actions);
    echo '<form method="get" class="gvb-filtres"><input type="hidden" name="page" value="gv-enseignants">' . gvb_liste('statut', 'Statut', ['' => 'Tous'] + GVB_ENS_STATUTS, $statut) . gvb_champ('q', 'Recherche', $q, 'search', 'placeholder="Nom, spécialité…"') . '<label class="gvb-cases"><input type="checkbox" name="inactifs" value="1"' . checked($voir_inactifs, true, false) . '> Inclure les inactifs</label>' . gvb_bouton('Filtrer', false) . '</form>';
    echo gvb_tableau(['Enseignant', 'Statut', 'Pôles', 'Spécialités', 'Charge ' . $annee, 'Téléphone'], $l, 'Aucun enseignant.');
    if (gvb_can('pedagogie', 'creer')) {
        $cands = [];
        if (current_user_can('edit_candidatures')) foreach (get_posts(['post_type' => 'candidature', 'post_status' => 'private', 'numberposts' => 100, 'meta_key' => 'gvc_statut', 'meta_value' => 'retenue']) as $p) $cands[$p->ID] = get_post_meta($p->ID, 'gvc_nom', true) . ' ' . get_post_meta($p->ID, 'gvc_prenom', true) . ' — ' . get_post_meta($p->ID, 'gvc_discipline', true);
        echo '<div class="gvb-boite"><h2>' . gvb_icone('plus', 18) . 'Nouvel enseignant</h2>' . gvb_form('gvb_ens_creer', 'gvb_ens_creer')
            . ($cands ? gvb_liste('candidature', 'Reprendre une candidature retenue (facultatif)', [0 => '—'] + $cands) . '<p class="gvb-rappel">Les champs ci-dessous restent à compléter ; ceux laissés vides sont remplis depuis la candidature.</p>' : '')
            . '<div class="gvb-champs">' . gvb_champ('nom', 'Nom', '', 'text', 'maxlength="120"') . gvb_champ('prenom', 'Prénom', '', 'text', 'maxlength="120"') . gvb_champ('telephone', 'Téléphone', '', 'tel') . gvb_champ('email', 'E-mail', '', 'email')
            . gvb_liste('statut', 'Statut', GVB_ENS_STATUTS, 'vacataire') . gvb_champ('specialites', 'Spécialités', '', 'text', 'maxlength="255"') . '</div>' . gvb_poles_cases() . gvb_bouton('Créer l’enseignant') . '</form></div>';
    }
    gvb_fin();
}

add_action('admin_post_gvb_ens_creer', function () {
    gvb_garde('pedagogie', 'creer', 'gvb_ens_creer');
    global $wpdb; $cand = absint($_POST['candidature'] ?? 0);
    $d = ['nom' => '', 'prenom' => '', 'telephone' => '', 'email' => '', 'specialites' => ''];
    foreach ($d as $k => $_) $d[$k] = mb_substr(sanitize_text_field(wp_unslash($_POST[$k] ?? '')), 0, $k === 'specialites' ? 255 : 120);
    $d['email'] = sanitize_email(wp_unslash($_POST['email'] ?? ''));
    $poles = gvb_poles_post();
    if ($cand && current_user_can('edit_candidatures')) {
        $p = get_post($cand);
        if ($p && $p->post_type === 'candidature') {
            $m = fn($k) => (string) get_post_meta($cand, 'gvc_' . $k, true);
            foreach (['nom', 'prenom', 'telephone', 'email'] as $k) if ($d[$k] === '') $d[$k] = $m($k);
            if ($d['specialites'] === '') $d['specialites'] = mb_substr($m('discipline'), 0, 255);
            if ($poles === '' && isset(GVB_POLES[$m('pole')])) $poles = $m('pole');
        } else $cand = 0;
    } else $cand = 0;
    if ($d['nom'] === '' || $d['prenom'] === '') gvb_retour('gv-enseignants', [], null, 'Le nom et le prénom sont obligatoires.');
    if (!empty($_POST['email']) && $d['email'] === '') gvb_retour('gv-enseignants', [], null, 'Adresse e-mail invalide.');
    $statut = isset(GVB_ENS_STATUTS[$_POST['statut'] ?? '']) ? $_POST['statut'] : 'vacataire';
    if ($d['email'] !== '' && $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . gvb_table('enseignants') . ' WHERE LOWER(email) = LOWER(%s)', $d['email']))) gvb_retour('gv-enseignants', [], null, 'Un enseignant avec cette adresse e-mail existe déjà.');
    if (!gvb_ens_dans_perimetre((object) ['poles' => $poles])) gvb_retour('gv-enseignants', [], null, 'Ce pôle n’est pas dans votre périmètre.');
    $now = gvb_now();
    $wpdb->insert(gvb_table('enseignants'), $d + ['statut' => $statut, 'poles' => $poles, 'candidature_id' => $cand, 'cree_le' => $now, 'modifie_le' => $now]);
    $id = (int) $wpdb->insert_id;
    if ($cand) update_post_meta($cand, 'gvc_statut', 'archivee');
    gvb_log(0, 'Enseignant créé', $d['prenom'] . ' ' . $d['nom'] . ($cand ? ' (depuis une candidature)' : ''));
    gvb_retour('gv-enseignant', ['id' => $id], 'Enseignant créé.');
});

function gvb_page_enseignant() {
    gvb_exiger('pedagogie', 'voir');
    global $wpdb;
    $e = gvb_enseignant(absint($_GET['id'] ?? 0));
    if (!$e) wp_die('Enseignant introuvable.', 'Introuvable', ['response' => 404, 'back_link' => false]);
    $annee = sanitize_text_field(wp_unslash($_GET['annee'] ?? '')) ?: gvb_reglages()['annee'];
    gvb_debut(gvb_nom_ens($e), 'cv', '<a class="button" href="' . esc_url(gvb_url('gv-enseignants')) . '">← Enseignants</a>');
    $h = gvb_heures_seances($e->id, $annee); $aff = gvb_heures_affectees_ens($e->id, $annee);
    echo '<p>' . ($e->actif ? gvb_badge('validee', 'Actif') : gvb_badge('annulee', 'Inactif')) . ' <span class="gvb-rappel">' . esc_html(GVB_ENS_STATUTS[$e->statut] ?? '') . '</span></p>';
    echo '<div class="gvb-cartes">' . gvb_carte('Heures affectées', gvb_h($aff), $e->plafond ? 'Plafond ' . $e->plafond . ' h' : 'Sans plafond', 'liste') . gvb_carte('Heures planifiées', gvb_h($h['planifiees']), $annee, 'horloge') . gvb_carte('Heures effectuées', gvb_h($h['effectuees']), 'Séances tenues', 'check', 'ok') . '</div>';
    echo '<div class="gvb-grille"><div class="gvb-boite"><h2>' . gvb_icone('utilisateurs', 18) . 'Fiche</h2>';
    if (gvb_can('pedagogie', 'modifier')) {
        echo gvb_form('gvb_ens_sauver', 'gvb_ens_sauver', ['id' => $e->id]) . '<div class="gvb-champs">' . gvb_champ('nom', 'Nom *', $e->nom, 'text', 'required') . gvb_champ('prenom', 'Prénom *', $e->prenom, 'text', 'required') . gvb_champ('telephone', 'Téléphone', $e->telephone, 'tel') . gvb_champ('email', 'E-mail', $e->email, 'email')
            . gvb_liste('statut', 'Statut', GVB_ENS_STATUTS, $e->statut) . gvb_champ('specialites', 'Spécialités', $e->specialites) . gvb_champ('plafond', 'Plafond annuel (h, 0 = aucun)', $e->plafond, 'number', 'min="0" max="3000"')
            . (gvb_can('pedagogie', 'valider') ? gvb_champ('taux', 'Taux horaire (FCFA/h)', $e->taux, 'number', 'min="0" step="1"') : '') . '</div>' . gvb_poles_cases($e->poles === '' ? [] : explode(',', $e->poles))
            . '<label class="gvb-cases"><input type="checkbox" name="actif" value="1"' . checked((int) $e->actif, 1, false) . '> Enseignant actif</label>' . gvb_bouton('Enregistrer') . '</form>';
    } else {
        foreach (['telephone' => 'Téléphone', 'email' => 'E-mail', 'specialites' => 'Spécialités'] as $k => $lab) echo '<p><strong>' . esc_html($lab) . ' :</strong> ' . esc_html($e->$k ?: '—') . '</p>';
        if (gvb_can('pedagogie', 'valider')) echo '<p><strong>Taux horaire :</strong> ' . esc_html(gvb_montant($e->taux)) . '/h</p>';
    }
    echo '</div><div>';
    $affs = $wpdb->get_results($wpdb->prepare('SELECT a.*, c.intitule, c.niveau, c.pole, c.volume FROM ' . gvb_table('affectations') . ' a JOIN ' . gvb_table('cours') . ' c ON c.id = a.cours_id WHERE a.enseignant_id = %d AND a.annee = %s ORDER BY c.pole, c.niveau, c.intitule', $e->id, $annee));
    $l = []; foreach ($affs as $x) $l[] = '<tr><td>' . esc_html($x->intitule) . '</td><td>' . esc_html(GVB_POLES[$x->pole] ?? '') . ' · ' . esc_html($x->niveau) . '</td><td class="d">' . (int) $x->heures . ' h</td></tr>';
    echo '<div class="gvb-boite"><h2>' . gvb_icone('liste', 18) . 'Affectations ' . esc_html($annee) . '</h2>' . gvb_tableau(['Cours', 'Niveau', 'Heures'], $l, 'Aucune affectation cette année.');
    if (gvb_can('pedagogie', 'modifier') && $e->actif) {
        $opts = []; foreach ($wpdb->get_results('SELECT * FROM ' . gvb_table('cours') . ' WHERE actif = 1 ORDER BY pole, niveau, intitule') as $c) if (gvb_pole_autorise($c->pole) && ($e->poles === '' || in_array($c->pole, explode(',', $e->poles), true))) $opts[$c->id] = (GVB_POLES[$c->pole] ?? '') . ' · ' . $c->niveau . ' · ' . $c->intitule . ' (' . $c->volume . ' h)';
        if ($opts) echo gvb_details('Affecter un cours', gvb_form('gvb_affecter', 'gvb_affecter', ['enseignant' => $e->id, 'annee' => $annee, 'retour_ens' => 1]) . gvb_liste('cours', 'Cours', $opts) . gvb_champ('heures', 'Heures', '', 'number', 'min="1" step="1" required') . gvb_bouton('Affecter', false) . '</form>');
    }
    echo '</div></div></div>';
    do_action('gvb_fiche_enseignant_apres', $e);
    gvb_fin();
}
add_action('admin_post_gvb_ens_sauver', function () {
    gvb_garde('pedagogie', 'modifier', 'gvb_ens_sauver');
    global $wpdb; $e = gvb_enseignant(absint($_POST['id'] ?? 0));
    if (!$e) gvb_retour('gv-enseignants', [], null, 'Enseignant introuvable.');
    $nom = mb_substr(sanitize_text_field(wp_unslash($_POST['nom'] ?? '')), 0, 120); $prenom = mb_substr(sanitize_text_field(wp_unslash($_POST['prenom'] ?? '')), 0, 120);
    $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
    if ($nom === '' || $prenom === '') gvb_retour('gv-enseignant', ['id' => $e->id], null, 'Le nom et le prénom sont obligatoires.');
    if (!empty($_POST['email']) && $email === '') gvb_retour('gv-enseignant', ['id' => $e->id], null, 'Adresse e-mail invalide.');
    $poles = gvb_poles_post(); $actif = empty($_POST['actif']) ? 0 : 1;
    $maj = ['nom' => $nom, 'prenom' => $prenom, 'telephone' => mb_substr(sanitize_text_field(wp_unslash($_POST['telephone'] ?? '')), 0, 50), 'email' => $email, 'statut' => isset(GVB_ENS_STATUTS[$_POST['statut'] ?? '']) ? $_POST['statut'] : $e->statut,
        'specialites' => mb_substr(sanitize_text_field(wp_unslash($_POST['specialites'] ?? '')), 0, 255), 'plafond' => min(3000, absint($_POST['plafond'] ?? 0)), 'poles' => $poles, 'actif' => $actif, 'modifie_le' => gvb_now()];
    if (gvb_can('pedagogie', 'valider') && isset($_POST['taux'])) $maj['taux'] = min(100000000, absint($_POST['taux']));
    if (!$actif && (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . gvb_table('seances') . " WHERE enseignant_id = %d AND statut = 'planifiee' AND date_seance >= %s", $e->id, gvb_aujourdhui()))) gvb_retour('gv-enseignant', ['id' => $e->id], null, 'Cet enseignant a des séances à venir : réassignez ou annulez-les avant de le désactiver.');
    $wpdb->update(gvb_table('enseignants'), $maj, ['id' => $e->id]);
    gvb_log(0, 'Enseignant modifié', $prenom . ' ' . $nom);
    gvb_retour('gv-enseignant', ['id' => $e->id], 'Fiche enregistrée.');
});

// ── Charges horaires ──────────────────────────────────────────────────────────
function gvb_charges_lignes($annee, $pole = '') {
    global $wpdb; $out = [];
    foreach ($wpdb->get_results('SELECT * FROM ' . gvb_table('enseignants') . ' WHERE actif = 1 ORDER BY nom, prenom') as $e) {
        if (!gvb_ens_dans_perimetre($e) || ($pole !== '' && $e->poles !== '' && !in_array($pole, explode(',', $e->poles), true))) continue;
        $aff = gvb_heures_affectees_ens($e->id, $annee); $h = gvb_heures_seances($e->id, $annee);
        if ($aff === 0 && $h['planifiees'] == 0) continue;
        $out[] = ['e' => $e, 'aff' => $aff, 'plan' => $h['planifiees'], 'fait' => $h['effectuees'], 'montant' => (int) round($h['effectuees'] * $e->taux)];
    }
    return $out;
}
function gvb_page_charges() {
    gvb_exiger('pedagogie', 'voir');
    $annee = sanitize_text_field(wp_unslash($_GET['annee'] ?? '')) ?: gvb_reglages()['annee']; $pole = isset(GVB_POLES[$_GET['pole'] ?? '']) ? $_GET['pole'] : '';
    $voir_taux = gvb_can('pedagogie', 'valider'); $l = []; $t = ['aff' => 0, 'plan' => 0, 'fait' => 0, 'montant' => 0];
    foreach (gvb_charges_lignes($annee, $pole) as $r) {
        $e = $r['e']; foreach (['aff', 'plan', 'fait', 'montant'] as $k) $t[$k] += $r[$k];
        $alerte = $e->plafond > 0 && $r['aff'] > $e->plafond ? ' <span class="gvb-ko">Plafond dépassé</span>' : '';
        $l[] = '<tr><td><a href="' . esc_url(gvb_url('gv-enseignant', ['id' => $e->id, 'annee' => $annee])) . '"><strong>' . esc_html(gvb_nom_ens($e)) . '</strong></a>' . $alerte . '</td><td>' . esc_html(GVB_ENS_STATUTS[$e->statut] ?? '') . '</td><td class="d">' . esc_html(gvb_h($r['aff'])) . '</td><td class="d">' . esc_html(gvb_h($r['plan'])) . '</td><td class="d">' . esc_html(gvb_h($r['fait'])) . '</td>' . ($voir_taux ? '<td class="d">' . esc_html(gvb_montant($r['montant'])) . '</td>' : '') . '</tr>';
    }
    $actions = gvb_can('pedagogie', 'exporter') ? '<a class="button" href="' . esc_url(wp_nonce_url(add_query_arg(array_filter(['action' => 'gvb_export_charges', 'annee' => $annee, 'pole' => $pole]), admin_url('admin-post.php')), 'gvb_export_charges')) . '">Exporter (CSV)</a>' : '';
    gvb_debut('Charges horaires', 'horloge', $actions);
    echo '<form method="get" class="gvb-filtres"><input type="hidden" name="page" value="gv-charges">' . gvb_champ('annee', 'Année', $annee) . gvb_liste('pole', 'Pôle', ['' => 'Tous'] + GVB_POLES, $pole) . gvb_bouton('Filtrer', false) . '</form>';
    echo '<div class="gvb-cartes">' . gvb_carte('Heures affectées', gvb_h($t['aff']), count($l) . ' enseignant(s)', 'liste') . gvb_carte('Planifiées', gvb_h($t['plan']), 'Emploi du temps', 'horloge') . gvb_carte('Effectuées', gvb_h($t['fait']), 'Séances tenues', 'check', 'ok') . ($voir_taux ? gvb_carte('À rémunérer', gvb_montant($t['montant']), 'Heures effectuées × taux', 'argent') : '') . '</div>';
    echo gvb_tableau(array_merge(['Enseignant', 'Statut', 'Affectées', 'Planifiées', 'Effectuées'], $voir_taux ? ['À rémunérer'] : []), $l, 'Aucune charge pour cette année.');
    gvb_fin();
}
add_action('admin_post_gvb_export_charges', function () {
    gvb_garde('pedagogie', 'exporter', 'gvb_export_charges');
    $annee = sanitize_text_field(wp_unslash($_GET['annee'] ?? '')) ?: gvb_reglages()['annee']; $voir_taux = gvb_can('pedagogie', 'valider');
    $neutre = fn($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v;
    nocache_headers(); header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="charges-horaires-' . gmdate('Ymd') . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF"); fputcsv($o, array_merge(['Année', 'Nom', 'Prénom', 'Statut', 'Heures affectées', 'Heures planifiées', 'Heures effectuées'], $voir_taux ? ['Taux horaire', 'À rémunérer'] : []), ';');
    foreach (gvb_charges_lignes($annee, isset(GVB_POLES[$_GET['pole'] ?? '']) ? $_GET['pole'] : '') as $r) fputcsv($o, array_map($neutre, array_merge([$annee, $r['e']->nom, $r['e']->prenom, GVB_ENS_STATUTS[$r['e']->statut] ?? '', $r['aff'], $r['plan'], $r['fait']], $voir_taux ? [$r['e']->taux, $r['montant']] : [])), ';');
    gvb_log(0, 'Export des charges horaires', $annee);
    gvb_terminer();
});
add_action('admin_post_gvb_export_enseignants', function () {
    gvb_garde('pedagogie', 'exporter', 'gvb_export_enseignants');
    global $wpdb; $neutre = fn($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v;
    nocache_headers(); header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="enseignants-' . gmdate('Ymd') . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF"); fputcsv($o, ['Nom', 'Prénom', 'Statut', 'Pôles', 'Spécialités', 'Téléphone', 'E-mail', 'Actif'], ';');
    foreach ($wpdb->get_results('SELECT * FROM ' . gvb_table('enseignants') . ' ORDER BY nom, prenom') as $e) if (gvb_ens_dans_perimetre($e)) fputcsv($o, array_map($neutre, [$e->nom, $e->prenom, GVB_ENS_STATUTS[$e->statut] ?? '', $e->poles ?: 'tous', $e->specialites, $e->telephone, $e->email, $e->actif ? 'oui' : 'non']), ';');
    gvb_log(0, 'Export des enseignants');
    gvb_terminer();
});
