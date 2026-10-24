<?php
/**
 * Plugin Name: Groupe Vitos — Back-office : emploi du temps, salles et présences
 * Description: Séances par cours et enseignant, détection des conflits (enseignant, salle, groupe), appel, absences et taux de présence.
 */

if (!defined('ABSPATH')) exit;

const GVB_SEANCE_STATUTS = ['planifiee' => 'Planifiée', 'tenue' => 'Tenue', 'annulee' => 'Annulée'];
const GVB_PRESENCES = ['present' => 'Présent', 'retard' => 'En retard', 'absent' => 'Absent', 'excuse' => 'Excusé'];
const GVB_JOURS = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];

function gvb_seuil_absences() { return (int) apply_filters('gvb_seuil_absences', 3); }
function gvb_seance($id) {
    global $wpdb;
    $s = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . gvb_table('seances') . ' WHERE id = %d', (int) $id));
    return $s && gvb_pole_autorise($s->pole) ? $s : null;
}
function gvb_salle($id) { global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . gvb_table('salles') . ' WHERE id = %d', (int) $id)); }
function gvb_hhmm($t) { return substr((string) $t, 0, 5); }
function gvb_duree_h($debut, $fin) { return max(0, (strtotime('1970-01-01 ' . $fin) - strtotime('1970-01-01 ' . $debut)) / 3600); }
function gvb_groupe_libelle($pole, $formation_id, $niveau) { return trim((GVB_POLES[$pole] ?? '') . ' · ' . $niveau . ($formation_id ? ' · ' . gvb_libelle_formation($formation_id) : '')); }
function gvb_etudiants_du_groupe($s) {
    global $wpdb;
    $w = ["statut = 'actif'", 'pole = %s', 'LOWER(niveau) = LOWER(%s)']; $a = [$s->pole, $s->niveau];
    if ($s->formation_id) { $w[] = 'formation_id = %d'; $a[] = $s->formation_id; }
    return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . gvb_table('etudiants') . ' WHERE ' . implode(' AND ', $w) . ' ORDER BY nom, prenom', $a));
}

// ── Conflits : enseignant, salle, groupe ──────────────────────────────────────
function gvb_conflits($date, $debut, $fin, $ens_id, $salle_id, $pole, $formation_id, $niveau, $sauf = 0) {
    global $wpdb; $msg = [];
    $rows = $wpdb->get_results($wpdb->prepare('SELECT s.*, c.intitule FROM ' . gvb_table('seances') . ' s JOIN ' . gvb_table('cours') . " c ON c.id = s.cours_id WHERE s.date_seance = %s AND s.statut IN ('planifiee','tenue') AND s.id <> %d AND s.debut < %s AND s.fin > %s", $date, (int) $sauf, $fin, $debut));
    foreach ($rows as $r) {
        $plage = gvb_hhmm($r->debut) . '–' . gvb_hhmm($r->fin) . ' (' . $r->intitule . ')';
        if ((int) $r->enseignant_id === (int) $ens_id) $msg[] = 'L’enseignant a déjà une séance ' . $plage . '.';
        if ($salle_id && (int) $r->salle_id === (int) $salle_id) $msg[] = 'La salle est déjà occupée ' . $plage . '.';
        if ($r->pole === $pole && (int) $r->formation_id === (int) $formation_id && mb_strtolower($r->niveau) === mb_strtolower($niveau)) $msg[] = 'Le groupe a déjà une séance ' . $plage . '.';
    }
    return array_values(array_unique($msg));
}
function gvb_heures_planifiees_aff($cours_id, $ens_id, $annee, $sauf = 0) {
    global $wpdb; $h = 0.0;
    foreach ($wpdb->get_results($wpdb->prepare('SELECT debut, fin FROM ' . gvb_table('seances') . " WHERE cours_id = %d AND enseignant_id = %d AND annee = %s AND statut IN ('planifiee','tenue') AND id <> %d", $cours_id, $ens_id, $annee, $sauf)) as $r) $h += gvb_duree_h($r->debut, $r->fin);
    return $h;
}
function gvb_valider_horaire($debut, $fin) {
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $debut) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $fin)) return 'Horaire invalide (format HH:MM).';
    if ($fin <= $debut) return 'L’heure de fin doit être après l’heure de début.';
    if ($debut < '06:00' || $fin > '22:00') return 'Les séances se tiennent entre 06:00 et 22:00.';
    $d = gvb_duree_h($debut . ':00', $fin . ':00');
    if ($d < 0.5 || $d > 6) return 'Une séance dure entre 30 minutes et 6 heures.';
    return null;
}

// Crée une séance (ou une série hebdomadaire) ; retourne ['creees' => n, 'ignorees' => [date => raison], 'alertes' => []] ou WP_Error
function gvb_creer_seances($cours_id, $ens_id, $salle_id, $date, $debut, $fin, $semaines = 1) {
    global $wpdb;
    $c = gvb_cours_ligne($cours_id); $e = gvb_enseignant($ens_id);
    if (!$c || !$c->actif) return new WP_Error('cours', 'Cours introuvable ou désactivé.');
    if (!$e || !$e->actif) return new WP_Error('ens', 'Enseignant introuvable ou inactif.');
    if ($err = gvb_valider_horaire($debut, $fin)) return new WP_Error('horaire', $err);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) return new WP_Error('date', 'Date invalide.');
    $semaines = max(1, min(20, (int) $semaines));
    $salle = $salle_id ? gvb_salle($salle_id) : null;
    if ($salle_id && (!$salle || !$salle->actif)) return new WP_Error('salle', 'Salle introuvable ou inactive.');
    $annee = gvb_reglages()['annee'];
    $aff = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . gvb_table('affectations') . ' WHERE cours_id = %d AND enseignant_id = %d AND annee = %s', $c->id, $e->id, $annee));
    if (!$aff) return new WP_Error('aff', 'Cet enseignant n’est pas affecté à ce cours pour ' . $annee . ' (Programmes → Affecter).');
    $res = ['creees' => 0, 'ignorees' => [], 'alertes' => []];
    $effectif = $salle && $salle->capacite ? count(gvb_etudiants_du_groupe((object) ['pole' => $c->pole, 'niveau' => $c->niveau, 'formation_id' => $c->formation_id])) : 0;
    if ($salle && $salle->capacite && $effectif > $salle->capacite) $res['alertes'][] = 'La salle (' . $salle->capacite . ' places) est plus petite que l’effectif du groupe (' . $effectif . ').';
    $wpdb->get_var("SELECT GET_LOCK('gvb_edt', 10)");
    try {
        $deja = gvb_heures_planifiees_aff($c->id, $e->id, $annee);
        $h = gvb_duree_h($debut . ':00', $fin . ':00');
        for ($i = 0; $i < $semaines; $i++) {
            $d = gmdate('Y-m-d', strtotime($date) + 7 * $i * DAY_IN_SECONDS);
            $conf = gvb_conflits($d, $debut . ':00', $fin . ':00', $e->id, $salle_id, $c->pole, $c->formation_id, $c->niveau);
            if ($conf) { $res['ignorees'][$d] = implode(' ', $conf); continue; }
            if ($deja + $h > $aff->heures + 0.001) { $res['ignorees'][$d] = 'Les heures affectées (' . $aff->heures . ' h) sont épuisées.'; continue; }
            $wpdb->insert(gvb_table('seances'), ['cours_id' => $c->id, 'enseignant_id' => $e->id, 'salle_id' => (int) $salle_id, 'annee' => $annee, 'pole' => $c->pole, 'formation_id' => $c->formation_id, 'niveau' => $c->niveau, 'date_seance' => $d, 'debut' => $debut . ':00', 'fin' => $fin . ':00', 'cree_par' => get_current_user_id(), 'cree_le' => gvb_now()]);
            $res['creees']++; $deja += $h;
        }
    } finally { $wpdb->get_var("SELECT RELEASE_LOCK('gvb_edt')"); }
    if ($res['creees']) gvb_log(0, 'Séance(s) planifiée(s)', $c->intitule . ' — ' . gvb_nom_ens($e) . ' — ' . $res['creees'] . ' séance(s) dès le ' . $date);
    return $res;
}

// ── Emploi du temps (vue hebdomadaire) ────────────────────────────────────────
function gvb_lundi($date) { $t = strtotime($date ?: gvb_aujourdhui()); return gmdate('Y-m-d', $t - ((int) gmdate('N', $t) - 1) * DAY_IN_SECONDS); }
function gvb_filtres_planning() {
    return ['sem' => gvb_lundi(preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['sem'] ?? '') ? $_GET['sem'] : ''), 'pole' => isset(GVB_POLES[$_GET['pole'] ?? '']) ? $_GET['pole'] : '', 'niveau' => sanitize_text_field(wp_unslash($_GET['niveau'] ?? '')), 'ens' => absint($_GET['ens'] ?? 0), 'salle' => absint($_GET['salle'] ?? 0)];
}
function gvb_requete_seances($f, $du, $au) {
    global $wpdb;
    $w = ['s.date_seance BETWEEN %s AND %s']; $a = [$du, $au];
    if ($f['pole'] !== '') { $w[] = 's.pole = %s'; $a[] = $f['pole']; }
    if ($f['niveau'] !== '') { $w[] = 'LOWER(s.niveau) = LOWER(%s)'; $a[] = $f['niveau']; }
    if ($f['ens']) { $w[] = 's.enseignant_id = %d'; $a[] = $f['ens']; }
    if ($f['salle']) { $w[] = 's.salle_id = %d'; $a[] = $f['salle']; }
    gvb_clause_pole($w, $a, 's');
    return $wpdb->get_results($wpdb->prepare('SELECT s.*, c.intitule, e.nom AS e_nom, e.prenom AS e_prenom, r.nom AS salle_nom FROM ' . gvb_table('seances') . ' s JOIN ' . gvb_table('cours') . ' c ON c.id = s.cours_id JOIN ' . gvb_table('enseignants') . ' e ON e.id = s.enseignant_id LEFT JOIN ' . gvb_table('salles') . ' r ON r.id = s.salle_id WHERE ' . implode(' AND ', $w) . ' ORDER BY s.date_seance, s.debut', $a));
}
function gvb_page_planning() {
    gvb_exiger('planning', 'voir');
    global $wpdb;
    $f = gvb_filtres_planning(); $du = $f['sem']; $au = gmdate('Y-m-d', strtotime($du) + 6 * DAY_IN_SECONDS);
    $seances = gvb_requete_seances($f, $du, $au); $par_jour = [];
    foreach ($seances as $s) $par_jour[$s->date_seance][] = $s;
    $modif = gvb_can('planning', 'modifier'); $auj = gvb_aujourdhui();
    $base = array_filter(['page' => 'gv-planning', 'pole' => $f['pole'], 'niveau' => $f['niveau'], 'ens' => $f['ens'], 'salle' => $f['salle']]);
    $nav = '<a class="button" href="' . esc_url(add_query_arg($base + ['sem' => gmdate('Y-m-d', strtotime($du) - 7 * DAY_IN_SECONDS)], admin_url('admin.php'))) . '">← Semaine précédente</a> <a class="button" href="' . esc_url(add_query_arg($base + ['sem' => gvb_lundi($auj)], admin_url('admin.php'))) . '">Cette semaine</a> <a class="button" href="' . esc_url(add_query_arg($base + ['sem' => gmdate('Y-m-d', strtotime($du) + 7 * DAY_IN_SECONDS)], admin_url('admin.php'))) . '">Semaine suivante →</a>'
        . (gvb_can('planning', 'exporter') ? ' <a class="button" href="' . esc_url(wp_nonce_url(add_query_arg($base + ['sem' => $du, 'action' => 'gvb_export_planning'], admin_url('admin-post.php')), 'gvb_export_planning')) . '">Exporter (CSV)</a>' : '') . ' <button type="button" class="button" onclick="window.print()">' . gvb_icone('print', 16) . 'Imprimer</button>';
    gvb_debut('Emploi du temps', 'horloge', $nav);
    $enseignants = [0 => 'Tous']; foreach ($wpdb->get_results('SELECT * FROM ' . gvb_table('enseignants') . ' WHERE actif = 1 ORDER BY nom') as $e) if (gvb_ens_dans_perimetre($e)) $enseignants[$e->id] = gvb_nom_ens($e);
    $salles = [0 => 'Toutes']; foreach ($wpdb->get_results('SELECT * FROM ' . gvb_table('salles') . ' WHERE actif = 1 ORDER BY nom') as $r) $salles[$r->id] = $r->nom;
    $niveaux = array_filter(array_unique($wpdb->get_col('SELECT niveau FROM ' . gvb_table('cours') . " WHERE niveau <> '' AND actif = 1 ORDER BY niveau")));
    echo '<form method="get" class="gvb-filtres"><input type="hidden" name="page" value="gv-planning">' . gvb_champ('sem', 'Semaine du', $du, 'date') . gvb_liste('pole', 'Pôle', ['' => 'Tous'] + GVB_POLES, $f['pole']) . gvb_liste('niveau', 'Niveau', ['' => 'Tous'] + array_combine($niveaux, $niveaux), $f['niveau']) . gvb_liste('ens', 'Enseignant', $enseignants, $f['ens']) . gvb_liste('salle', 'Salle', $salles, $f['salle']) . gvb_bouton('Filtrer', false) . '</form>';
    echo '<p class="gvb-rappel">Semaine du ' . esc_html(gvb_d($du)) . ' au ' . esc_html(gvb_d($au)) . ' — ' . count($seances) . ' séance(s).</p>';
    for ($i = 0; $i < 7; $i++) {
        $jour = gmdate('Y-m-d', strtotime($du) + $i * DAY_IN_SECONDS);
        if ($i >= 5 && empty($par_jour[$jour])) continue;
        $l = [];
        foreach ($par_jour[$jour] ?? [] as $s) {
            $act = '';
            if ($s->statut !== 'annulee' && $jour <= $auj && gvb_can('planning', 'modifier')) $act .= '<a class="button button-primary" href="' . esc_url(gvb_url('gv-seance', ['id' => $s->id])) . '">' . ($s->statut === 'tenue' ? 'Voir l’appel' : 'Faire l’appel') . '</a> ';
            if ($modif && $s->statut === 'planifiee') {
                $act .= gvb_details('Reporter', gvb_form('gvb_seance_reporter', 'gvb_seance_reporter', ['id' => $s->id]) . gvb_champ('date', 'Nouvelle date', $s->date_seance, 'date', 'required') . gvb_champ('debut', 'Début', gvb_hhmm($s->debut), 'time', 'required') . gvb_champ('fin', 'Fin', gvb_hhmm($s->fin), 'time', 'required') . gvb_bouton('Reporter', false) . '</form>')
                    . ' ' . gvb_details('Annuler', gvb_form('gvb_seance_annuler', 'gvb_seance_annuler', ['id' => $s->id]) . gvb_champ('motif', 'Motif', '', 'text', 'required maxlength="255"') . gvb_bouton('Annuler la séance', false) . '</form>');
            }
            $l[] = '<tr' . ($s->statut === 'annulee' ? ' style="opacity:.5;text-decoration:line-through"' : '') . '><td><strong>' . esc_html(gvb_hhmm($s->debut) . '–' . gvb_hhmm($s->fin)) . '</strong></td><td>' . esc_html($s->intitule) . '</td><td>' . esc_html(gvb_groupe_libelle($s->pole, $s->formation_id, $s->niveau)) . '</td><td>' . esc_html(strtoupper_fr($s->e_nom) . ' ' . $s->e_prenom) . '</td><td>' . esc_html($s->salle_nom ?: '—') . '</td><td>' . gvb_badge($s->statut === 'tenue' ? 'validee' : ($s->statut === 'annulee' ? 'annulee' : 'etude'), GVB_SEANCE_STATUTS[$s->statut] ?? $s->statut) . ($s->motif ? '<br><small>' . esc_html($s->motif) . '</small>' : '') . '</td><td class="gvb-noprint">' . $act . '</td></tr>';
        }
        echo '<div class="gvb-boite"><h2>' . esc_html(GVB_JOURS[$i] . ' ' . gvb_d($jour)) . ($jour === $auj ? ' · aujourd’hui' : '') . '</h2>' . gvb_tableau(['Horaire', 'Cours', 'Groupe', 'Enseignant', 'Salle', 'État', ''], $l, 'Aucune séance.') . '</div>';
    }
    if (gvb_can('planning', 'creer')) {
        $cours = []; foreach ($wpdb->get_results('SELECT * FROM ' . gvb_table('cours') . ' WHERE actif = 1 ORDER BY pole, niveau, intitule') as $c) if (gvb_pole_autorise($c->pole)) $cours[$c->id] = gvb_groupe_libelle($c->pole, $c->formation_id, $c->niveau) . ' — ' . $c->intitule;
        $ens = $enseignants; unset($ens[0]); $sal = [0 => '— Aucune —'] + array_slice($salles, 1, null, true);
        echo '<div class="gvb-boite gvb-noprint"><h2>' . gvb_icone('plus', 18) . 'Planifier une séance</h2>';
        echo $cours && $ens ? gvb_form('gvb_seance_creer', 'gvb_seance_creer') . '<div class="gvb-champs">' . gvb_liste('cours', 'Cours *', $cours) . gvb_liste('enseignant', 'Enseignant * (affecté au cours)', $ens) . gvb_liste('salle', 'Salle', $sal) . gvb_champ('date', 'Date *', $f['sem'], 'date', 'required') . gvb_champ('debut', 'Début *', '08:00', 'time', 'required') . gvb_champ('fin', 'Fin *', '10:00', 'time', 'required') . gvb_champ('semaines', 'Répéter chaque semaine (1 à 20)', 1, 'number', 'min="1" max="20"') . '</div>' . gvb_bouton('Planifier') . '</form>'
            : '<p class="gvb-rappel">Créez d’abord des cours (Programmes) et des enseignants, puis affectez-les.</p>';
        echo '</div>';
    }
    echo '<style media="print">#adminmenumain,#wpadminbar,#wpfooter,.gvb-entete-actions,.gvb-filtres,.gvb-noprint{display:none!important}#wpcontent{margin-left:0!important}</style>';
    gvb_fin();
}
function strtoupper_fr($s) { return mb_strtoupper((string) $s); }

add_action('admin_post_gvb_seance_creer', function () {
    gvb_garde('planning', 'creer', 'gvb_seance_creer');
    $date = sanitize_text_field(wp_unslash($_POST['date'] ?? ''));
    $r = gvb_creer_seances(absint($_POST['cours'] ?? 0), absint($_POST['enseignant'] ?? 0), absint($_POST['salle'] ?? 0), $date, sanitize_text_field(wp_unslash($_POST['debut'] ?? '')), sanitize_text_field(wp_unslash($_POST['fin'] ?? '')), absint($_POST['semaines'] ?? 1));
    $args = ['sem' => gvb_lundi($date ?: '')];
    if (is_wp_error($r)) gvb_retour('gv-planning', $args, null, $r);
    if (!$r['creees']) gvb_retour('gv-planning', $args, null, 'Aucune séance créée : ' . implode(' | ', array_map(fn($d, $m) => gvb_d($d) . ' : ' . $m, array_keys($r['ignorees']), $r['ignorees'])));
    $msg = $r['creees'] . ' séance(s) planifiée(s).';
    if ($r['ignorees']) $msg .= ' Ignorées : ' . implode(' | ', array_map(fn($d, $m) => gvb_d($d) . ' (' . $m . ')', array_keys($r['ignorees']), $r['ignorees']));
    if ($r['alertes']) $msg .= ' Attention : ' . implode(' ', $r['alertes']);
    gvb_retour('gv-planning', $args, $msg);
});
add_action('admin_post_gvb_seance_annuler', function () {
    gvb_garde('planning', 'modifier', 'gvb_seance_annuler');
    global $wpdb; $s = gvb_seance(absint($_POST['id'] ?? 0)); $motif = mb_substr(sanitize_text_field(wp_unslash($_POST['motif'] ?? '')), 0, 255);
    if (!$s || $s->statut !== 'planifiee') gvb_retour('gv-planning', [], null, 'Seule une séance planifiée peut être annulée.');
    if ($motif === '') gvb_retour('gv-planning', ['sem' => gvb_lundi($s->date_seance)], null, 'Le motif est obligatoire.');
    $wpdb->update(gvb_table('seances'), ['statut' => 'annulee', 'motif' => $motif], ['id' => $s->id]);
    do_action('gvb_seance_annulee', $s, $motif);
    gvb_log(0, 'Séance annulée', $s->date_seance . ' ' . gvb_hhmm($s->debut) . ' — ' . $motif);
    gvb_retour('gv-planning', ['sem' => gvb_lundi($s->date_seance)], 'Séance annulée.');
});
add_action('admin_post_gvb_seance_reporter', function () {
    gvb_garde('planning', 'modifier', 'gvb_seance_reporter');
    global $wpdb; $s = gvb_seance(absint($_POST['id'] ?? 0));
    $date = sanitize_text_field(wp_unslash($_POST['date'] ?? '')); $debut = sanitize_text_field(wp_unslash($_POST['debut'] ?? '')); $fin = sanitize_text_field(wp_unslash($_POST['fin'] ?? ''));
    if (!$s || $s->statut !== 'planifiee') gvb_retour('gv-planning', [], null, 'Seule une séance planifiée peut être reportée.');
    $retour = ['sem' => gvb_lundi($s->date_seance)];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) gvb_retour('gv-planning', $retour, null, 'Date invalide.');
    if ($err = gvb_valider_horaire($debut, $fin)) gvb_retour('gv-planning', $retour, null, $err);
    $wpdb->get_var("SELECT GET_LOCK('gvb_edt', 10)");
    try {
        $conf = gvb_conflits($date, $debut . ':00', $fin . ':00', $s->enseignant_id, $s->salle_id, $s->pole, $s->formation_id, $s->niveau, $s->id);
        if ($conf) gvb_retour('gv-planning', $retour, null, 'Report impossible : ' . implode(' ', $conf));
        $aff = $wpdb->get_row($wpdb->prepare('SELECT heures FROM ' . gvb_table('affectations') . ' WHERE cours_id = %d AND enseignant_id = %d AND annee = %s', $s->cours_id, $s->enseignant_id, $s->annee));
        if ($aff && gvb_heures_planifiees_aff($s->cours_id, $s->enseignant_id, $s->annee, $s->id) + gvb_duree_h($debut . ':00', $fin . ':00') > $aff->heures + 0.001) gvb_retour('gv-planning', $retour, null, 'Les heures affectées (' . $aff->heures . ' h) seraient dépassées.');
        $wpdb->update(gvb_table('seances'), ['date_seance' => $date, 'debut' => $debut . ':00', 'fin' => $fin . ':00'], ['id' => $s->id]);
    } finally { $wpdb->get_var("SELECT RELEASE_LOCK('gvb_edt')"); }
    do_action('gvb_seance_reportee', $s, $date, $debut, $fin);
    gvb_log(0, 'Séance reportée', $s->date_seance . ' ' . gvb_hhmm($s->debut) . ' → ' . $date . ' ' . $debut);
    gvb_retour('gv-planning', ['sem' => gvb_lundi($date)], 'Séance reportée.');
});
add_action('admin_post_gvb_export_planning', function () {
    gvb_garde('planning', 'exporter', 'gvb_export_planning');
    $f = gvb_filtres_planning(); $neutre = fn($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v;
    nocache_headers(); header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="emploi-du-temps-' . $f['sem'] . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF"); fputcsv($o, ['Date', 'Début', 'Fin', 'Cours', 'Groupe', 'Enseignant', 'Salle', 'État'], ';');
    foreach (gvb_requete_seances($f, $f['sem'], gmdate('Y-m-d', strtotime($f['sem']) + 6 * DAY_IN_SECONDS)) as $s) fputcsv($o, array_map($neutre, [$s->date_seance, gvb_hhmm($s->debut), gvb_hhmm($s->fin), $s->intitule, gvb_groupe_libelle($s->pole, $s->formation_id, $s->niveau), $s->e_nom . ' ' . $s->e_prenom, $s->salle_nom, GVB_SEANCE_STATUTS[$s->statut] ?? $s->statut]), ';');
    gvb_log(0, 'Export de l’emploi du temps', $f['sem']);
    gvb_terminer();
});

// ── Salles ────────────────────────────────────────────────────────────────────
function gvb_page_salles() {
    gvb_exiger('planning', 'voir');
    global $wpdb; $l = [];
    foreach ($wpdb->get_results('SELECT * FROM ' . gvb_table('salles') . ' ORDER BY actif DESC, nom') as $r) {
        $act = gvb_can('planning', 'modifier') ? gvb_details('Modifier', gvb_form('gvb_salle_sauver', 'gvb_salle_sauver', ['id' => $r->id]) . gvb_champ('nom', 'Nom', $r->nom, 'text', 'required maxlength="80"') . gvb_liste('pole', 'Pôle', ['' => 'Tous'] + GVB_POLES, $r->pole) . gvb_champ('capacite', 'Capacité (places)', $r->capacite, 'number', 'min="0" max="2000"') . '<label class="gvb-cases"><input type="checkbox" name="actif" value="1"' . checked((int) $r->actif, 1, false) . '> Salle utilisable</label>' . gvb_bouton('Enregistrer', false) . '</form>') : '';
        $l[] = '<tr' . ($r->actif ? '' : ' style="opacity:.55"') . '><td><strong>' . esc_html($r->nom) . '</strong></td><td>' . esc_html(GVB_POLES[$r->pole] ?? 'Tous') . '</td><td class="d">' . (int) $r->capacite . '</td><td>' . ($r->actif ? 'Active' : 'Inactive') . '</td><td>' . $act . '</td></tr>';
    }
    gvb_debut('Salles', 'inscription');
    echo gvb_tableau(['Salle', 'Pôle', 'Capacité', 'État', ''], $l, 'Aucune salle. Ajoutez la première ci-dessous.');
    if (gvb_can('planning', 'creer')) echo '<div class="gvb-boite"><h2>' . gvb_icone('plus', 18) . 'Nouvelle salle</h2>' . gvb_form('gvb_salle_sauver', 'gvb_salle_sauver', ['id' => 0]) . '<div class="gvb-champs">' . gvb_champ('nom', 'Nom *', '', 'text', 'required maxlength="80"') . gvb_liste('pole', 'Pôle', ['' => 'Tous'] + GVB_POLES) . gvb_champ('capacite', 'Capacité (places)', '', 'number', 'min="0" max="2000"') . '</div><input type="hidden" name="actif" value="1">' . gvb_bouton('Ajouter la salle') . '</form></div>';
    gvb_fin();
}
add_action('admin_post_gvb_salle_sauver', function () {
    $id = absint($_POST['id'] ?? 0);
    gvb_garde('planning', $id ? 'modifier' : 'creer', 'gvb_salle_sauver');
    global $wpdb; $nom = mb_substr(sanitize_text_field(wp_unslash($_POST['nom'] ?? '')), 0, 80); $pole = isset(GVB_POLES[$_POST['pole'] ?? '']) ? $_POST['pole'] : '';
    if ($nom === '') gvb_retour('gv-salles', [], null, 'Le nom est obligatoire.');
    if ($wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . gvb_table('salles') . ' WHERE LOWER(nom) = LOWER(%s) AND id <> %d', $nom, $id))) gvb_retour('gv-salles', [], null, 'Une salle porte déjà ce nom.');
    $d = ['nom' => $nom, 'pole' => $pole, 'capacite' => min(2000, absint($_POST['capacite'] ?? 0)), 'actif' => empty($_POST['actif']) ? 0 : 1];
    if ($id) {
        if (!$d['actif'] && $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . gvb_table('seances') . " WHERE salle_id = %d AND statut = 'planifiee' AND date_seance >= %s", $id, gvb_aujourdhui()))) gvb_retour('gv-salles', [], null, 'Cette salle a des séances à venir : déplacez-les avant de la désactiver.');
        $wpdb->update(gvb_table('salles'), $d, ['id' => $id]);
    } else $wpdb->insert(gvb_table('salles'), $d);
    gvb_log(0, $id ? 'Salle modifiée' : 'Salle créée', $nom);
    gvb_retour('gv-salles', [], $id ? 'Salle enregistrée.' : 'Salle ajoutée.');
});

// ── Appel ─────────────────────────────────────────────────────────────────────
function gvb_enregistrer_appel($seance_id, array $statuts, array $notes = []) {
    global $wpdb;
    $s = gvb_seance($seance_id);
    if (!$s) return new WP_Error('introuvable', 'Séance introuvable.');
    if ($s->statut === 'annulee') return new WP_Error('annulee', 'Cette séance est annulée.');
    if ($s->date_seance > gvb_aujourdhui()) return new WP_Error('futur', 'On ne peut pas faire l’appel d’une séance à venir.');
    $ids = array_map(fn($e) => (int) $e->id, gvb_etudiants_du_groupe($s));
    $t = gvb_table('presences'); $now = gvb_now(); $n = 0;
    foreach ($statuts as $eid => $st) {
        $eid = (int) $eid;
        if (!in_array($eid, $ids, true)) return new WP_Error('groupe', 'Un étudiant ne fait pas partie de ce groupe.');
        if (!isset(GVB_PRESENCES[$st])) return new WP_Error('statut', 'Statut de présence invalide.');
        $note = mb_substr(sanitize_text_field((string) ($notes[$eid] ?? '')), 0, 120);
        $existe = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE seance_id = %d AND etudiant_id = %d", $s->id, $eid));
        if ($existe) $wpdb->update($t, ['statut' => $st, 'note' => $note, 'saisi_par' => get_current_user_id(), 'saisi_le' => $now], ['id' => $existe]);
        else $wpdb->insert($t, ['seance_id' => $s->id, 'etudiant_id' => $eid, 'statut' => $st, 'note' => $note, 'saisi_par' => get_current_user_id(), 'saisi_le' => $now]);
        $n++;
    }
    $wpdb->update(gvb_table('seances'), ['statut' => 'tenue'], ['id' => $s->id]);
    gvb_log(0, 'Appel enregistré', $s->date_seance . ' ' . gvb_hhmm($s->debut) . ' — ' . $n . ' étudiant(s)');
    do_action('gvb_appel_enregistre', $s, $statuts);
    return $n;
}
function gvb_page_seance() {
    gvb_exiger('planning', 'voir');
    global $wpdb;
    $s = gvb_seance(absint($_GET['id'] ?? 0));
    if (!$s) wp_die('Séance introuvable.', 'Introuvable', ['response' => 404, 'back_link' => false]);
    $c = $wpdb->get_row($wpdb->prepare('SELECT intitule FROM ' . gvb_table('cours') . ' WHERE id = %d', $s->cours_id)); $e = $wpdb->get_row($wpdb->prepare('SELECT nom, prenom FROM ' . gvb_table('enseignants') . ' WHERE id = %d', $s->enseignant_id));
    gvb_debut('Appel — ' . ($c->intitule ?? ''), 'check', '<a class="button" href="' . esc_url(gvb_url('gv-planning', ['sem' => gvb_lundi($s->date_seance)])) . '">← Emploi du temps</a>');
    echo '<p>' . esc_html(gvb_d($s->date_seance) . ' · ' . gvb_hhmm($s->debut) . '–' . gvb_hhmm($s->fin) . ' · ' . gvb_groupe_libelle($s->pole, $s->formation_id, $s->niveau) . ' · ' . ($e ? mb_strtoupper($e->nom) . ' ' . $e->prenom : '')) . ' ' . gvb_badge($s->statut === 'tenue' ? 'validee' : ($s->statut === 'annulee' ? 'annulee' : 'etude'), GVB_SEANCE_STATUTS[$s->statut]) . '</p>';
    $etus = gvb_etudiants_du_groupe($s); $existant = [];
    foreach ($wpdb->get_results($wpdb->prepare('SELECT * FROM ' . gvb_table('presences') . ' WHERE seance_id = %d', $s->id)) as $p) $existant[$p->etudiant_id] = $p;
    if ($s->statut === 'annulee') echo '<div class="gvb-alerte">Séance annulée : ' . esc_html($s->motif) . '</div>';
    elseif (!$etus) echo '<div class="gvb-boite"><p>Aucun étudiant actif dans ce groupe (même pôle, niveau et formation).</p>' . (gvb_can('planning', 'modifier') && $s->statut === 'planifiee' && $s->date_seance <= gvb_aujourdhui() ? gvb_form('gvb_seance_tenue', 'gvb_seance_tenue', ['id' => $s->id]) . gvb_bouton('Marquer la séance comme tenue') . '</form>' : '') . '</div>';
    else {
        $edit = gvb_can('planning', 'modifier') && $s->date_seance <= gvb_aujourdhui();
        echo '<div class="gvb-boite">' . ($edit ? gvb_form('gvb_appel_sauver', 'gvb_appel_sauver', ['id' => $s->id]) : '') . '<div class="gvb-defile"><table class="widefat striped gvb-tableau"><thead><tr><th>Étudiant</th>';
        foreach (GVB_PRESENCES as $l) echo '<th>' . esc_html($l) . '</th>'; echo '<th>Note</th></tr></thead><tbody>';
        foreach ($etus as $et) {
            $cur = $existant[$et->id]->statut ?? 'present';
            echo '<tr><td><strong>' . esc_html(mb_strtoupper($et->nom) . ' ' . $et->prenom) . '</strong><br><small>' . esc_html($et->matricule) . '</small></td>';
            foreach (GVB_PRESENCES as $k => $l) echo '<td><input type="radio" name="statut[' . (int) $et->id . ']" value="' . esc_attr($k) . '"' . checked($cur === $k, true, false) . ($edit ? '' : ' disabled') . ' aria-label="' . esc_attr($l . ' — ' . $et->nom) . '"></td>';
            echo '<td>' . ($edit ? '<input type="text" name="note[' . (int) $et->id . ']" value="' . esc_attr($existant[$et->id]->note ?? '') . '" maxlength="120">' : esc_html($existant[$et->id]->note ?? '')) . '</td></tr>';
        }
        echo '</tbody></table></div>' . ($edit ? gvb_bouton('Enregistrer l’appel') . '</form>' : '<p class="gvb-rappel">Consultation seule.</p>') . '</div>';
    }
    gvb_fin();
}
add_action('admin_post_gvb_appel_sauver', function () {
    gvb_garde('planning', 'modifier', 'gvb_appel_sauver');
    $id = absint($_POST['id'] ?? 0);
    $st = isset($_POST['statut']) && is_array($_POST['statut']) ? array_map('sanitize_key', wp_unslash($_POST['statut'])) : [];
    $notes = isset($_POST['note']) && is_array($_POST['note']) ? wp_unslash($_POST['note']) : [];
    $r = gvb_enregistrer_appel($id, $st, $notes);
    if (is_wp_error($r)) gvb_retour('gv-seance', ['id' => $id], null, $r);
    gvb_retour('gv-seance', ['id' => $id], 'Appel enregistré (' . $r . ' étudiant(s)).');
});
add_action('admin_post_gvb_seance_tenue', function () {
    gvb_garde('planning', 'modifier', 'gvb_seance_tenue');
    global $wpdb; $s = gvb_seance(absint($_POST['id'] ?? 0));
    if (!$s || $s->statut !== 'planifiee' || $s->date_seance > gvb_aujourdhui()) gvb_retour('gv-planning', [], null, 'Séance introuvable, déjà traitée ou à venir.');
    $wpdb->update(gvb_table('seances'), ['statut' => 'tenue'], ['id' => $s->id]);
    gvb_log(0, 'Séance marquée tenue', $s->date_seance . ' ' . gvb_hhmm($s->debut));
    gvb_retour('gv-seance', ['id' => $s->id], 'Séance marquée comme tenue.');
});

// ── Absences et taux de présence ──────────────────────────────────────────────
function gvb_stats_presence($f) {
    global $wpdb;
    $w = ["s.statut = 'tenue'", 's.date_seance BETWEEN %s AND %s']; $a = [$f['du'], $f['au']];
    if ($f['pole'] !== '') { $w[] = 'e.pole = %s'; $a[] = $f['pole']; }
    if ($f['niveau'] !== '') { $w[] = 'LOWER(e.niveau) = LOWER(%s)'; $a[] = $f['niveau']; }
    if (!empty($f['etudiant'])) { $w[] = 'e.id = %d'; $a[] = $f['etudiant']; }
    gvb_clause_pole($w, $a, 'e');
    $rows = $wpdb->get_results($wpdb->prepare('SELECT p.statut AS pst, e.id, e.matricule, e.nom, e.prenom, e.pole, e.niveau FROM ' . gvb_table('presences') . ' p JOIN ' . gvb_table('seances') . ' s ON s.id = p.seance_id JOIN ' . gvb_table('etudiants') . ' e ON e.id = p.etudiant_id WHERE ' . implode(' AND ', $w), $a));
    $out = [];
    foreach ($rows as $r) {
        $o = &$out[$r->id];
        if (!isset($o)) $o = ['e' => $r, 'total' => 0, 'present' => 0, 'retard' => 0, 'absent' => 0, 'excuse' => 0];
        $o['total']++; $o[$r->pst]++; unset($o);
    }
    foreach ($out as &$o) $o['taux'] = $o['total'] ? (int) round(100 * ($o['present'] + $o['retard'] + $o['excuse']) / $o['total']) : 0;
    unset($o);
    uasort($out, fn($x, $y) => [$y['absent'], $x['taux']] <=> [$x['absent'], $y['taux']]);
    return $out;
}
function gvb_filtres_absences() {
    $d = fn($k, $def) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET[$k] ?? '') ? $_GET[$k] : $def;
    return ['du' => $d('du', gmdate('Y-m-01')), 'au' => $d('au', gvb_aujourdhui()), 'pole' => isset(GVB_POLES[$_GET['pole'] ?? '']) ? $_GET['pole'] : '', 'niveau' => sanitize_text_field(wp_unslash($_GET['niveau'] ?? ''))];
}
function gvb_page_absences() {
    gvb_exiger('planning', 'voir');
    global $wpdb; $f = gvb_filtres_absences(); $l = []; $seuil = gvb_seuil_absences();
    foreach (gvb_stats_presence($f) as $o) {
        $e = $o['e']; $alerte = $o['absent'] >= $seuil;
        $l[] = '<tr><td><a href="' . esc_url(gvb_url('gv-etudiant', ['id' => $e->id])) . '"><strong>' . esc_html($e->matricule) . '</strong></a><br>' . esc_html(mb_strtoupper($e->nom) . ' ' . $e->prenom) . '</td><td>' . esc_html(GVB_POLES[$e->pole] ?? '') . ' · ' . esc_html($e->niveau) . '</td><td class="d">' . $o['total'] . '</td><td class="d">' . $o['absent'] . ($alerte ? ' <span class="gvb-ko">⚠</span>' : '') . '</td><td class="d">' . $o['retard'] . '</td><td class="d">' . $o['excuse'] . '</td><td class="d"><strong>' . $o['taux'] . ' %</strong></td></tr>';
    }
    $niveaux = array_filter(array_unique($wpdb->get_col('SELECT niveau FROM ' . gvb_table('etudiants') . " WHERE niveau <> '' ORDER BY niveau")));
    $actions = gvb_can('planning', 'exporter') ? '<a class="button" href="' . esc_url(wp_nonce_url(add_query_arg(array_filter(['action' => 'gvb_export_absences'] + $f), admin_url('admin-post.php')), 'gvb_export_absences')) . '">Exporter (CSV)</a>' : '';
    gvb_debut('Présences et absences', 'alerte', $actions);
    echo '<form method="get" class="gvb-filtres"><input type="hidden" name="page" value="gv-absences">' . gvb_champ('du', 'Du', $f['du'], 'date') . gvb_champ('au', 'Au', $f['au'], 'date') . gvb_liste('pole', 'Pôle', ['' => 'Tous'] + GVB_POLES, $f['pole']) . gvb_liste('niveau', 'Niveau', ['' => 'Tous'] + array_combine($niveaux, $niveaux), $f['niveau']) . gvb_bouton('Filtrer', false) . '</form>';
    echo '<p class="gvb-rappel">Seules les séances tenues avec appel comptent. Le taux de présence compte les présents, retards et absences excusées. ⚠ : au moins ' . $seuil . ' absences non excusées sur la période.</p>';
    echo gvb_tableau(['Étudiant', 'Groupe', 'Séances', 'Absences', 'Retards', 'Excusées', 'Taux de présence'], $l, 'Aucun appel enregistré sur la période.');
    gvb_fin();
}
add_action('admin_post_gvb_export_absences', function () {
    gvb_garde('planning', 'exporter', 'gvb_export_absences');
    $f = gvb_filtres_absences(); $neutre = fn($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v;
    nocache_headers(); header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="absences-' . gmdate('Ymd') . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF"); fputcsv($o, ['Matricule', 'Nom', 'Prénom', 'Pôle', 'Niveau', 'Séances', 'Absences', 'Retards', 'Excusées', 'Taux de présence (%)'], ';');
    foreach (gvb_stats_presence($f) as $s) fputcsv($o, array_map($neutre, [$s['e']->matricule, $s['e']->nom, $s['e']->prenom, GVB_POLES[$s['e']->pole] ?? '', $s['e']->niveau, $s['total'], $s['absent'], $s['retard'], $s['excuse'], $s['taux']]), ';');
    gvb_log(0, 'Export des absences', $f['du'] . ' → ' . $f['au']);
    gvb_terminer();
});
// Bloc « présence » sur la fiche étudiant
add_action('gvb_fiche_etudiant_apres', function ($e) {
    if (!gvb_can('planning', 'voir')) return;
    global $wpdb;
    $st = gvb_stats_presence(['du' => '2000-01-01', 'au' => gvb_aujourdhui(), 'pole' => '', 'niveau' => '', 'etudiant' => $e->id]); $o = $st[$e->id] ?? null;
    $abs = $wpdb->get_results($wpdb->prepare('SELECT s.date_seance, s.debut, c.intitule FROM ' . gvb_table('presences') . ' p JOIN ' . gvb_table('seances') . ' s ON s.id = p.seance_id JOIN ' . gvb_table('cours') . " c ON c.id = s.cours_id WHERE p.etudiant_id = %d AND p.statut = 'absent' ORDER BY s.date_seance DESC LIMIT 5", $e->id));
    $l = array_map(fn($a) => '<li>' . esc_html(gvb_d($a->date_seance) . ' ' . gvb_hhmm($a->debut) . ' — ' . $a->intitule) . '</li>', $abs);
    echo '<div class="gvb-boite"><h2>' . gvb_icone('horloge', 18) . 'Présence</h2>' . ($o ? '<p><strong>' . $o['taux'] . ' %</strong> de présence sur ' . $o['total'] . ' séance(s) · ' . $o['absent'] . ' absence(s) · ' . $o['retard'] . ' retard(s) · ' . $o['excuse'] . ' excusée(s)</p>' . ($l ? '<p class="gvb-rappel">Dernières absences :</p><ul>' . implode('', $l) . '</ul>' : '') : '<p class="gvb-rappel">Aucun appel enregistré.</p>') . '</div>';
});
