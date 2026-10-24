<?php
/**
 * Plugin Name: Groupe Vitos — Back-office : scolarité et finances
 * Description: Frais et échéanciers par inscription, paiements, reçus numérotés, impayés et relances, journal et exports.
 */

if (!defined('ABSPATH')) exit;

const GVB_MODES = ['especes' => 'Espèces', 'virement' => 'Virement', 'mobile' => 'Mobile money', 'cheque' => 'Chèque'];
const GVB_ETATS_CHARGE = ['paye' => 'Payé', 'partiel' => 'Partiel', 'retard' => 'En retard', 'avenir' => 'À venir'];

function gvb_fin_reglages() {
    return wp_parse_args(get_option('gvb_finances', []), ['tranches' => 3, 'delai' => 30, 'intervalle' => 30,
        'relance' => "Bonjour {prenom}, sauf erreur de notre part, un montant de {montant} reste à régler au Groupe VITO S. (échéance du {echeance}, matricule {matricule}). Merci de passer à la comptabilité ou de nous contacter pour régulariser. Cordialement, Groupe VITO S."]);
}
function gvb_aujourdhui() { return current_time('Y-m-d'); }
function gvb_montant($n) { return number_format((int) $n, 0, ',', ' ') . ' FCFA'; }

// ── Montant en lettres (reçus) ────────────────────────────────────────────────
function gvb_lettres($n) {
    $n = (int) $n;
    if ($n === 0) return 'zéro';
    $u = ['', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf', 'dix', 'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize', 'dix-sept', 'dix-huit', 'dix-neuf'];
    $d = [2 => 'vingt', 3 => 'trente', 4 => 'quarante', 5 => 'cinquante', 6 => 'soixante'];
    $c99 = function ($x, $final) use ($u, $d) {
        if ($x < 20) return $u[$x];
        $t = intdiv($x, 10); $r = $x % 10;
        if ($t <= 6) return $r === 0 ? $d[$t] : ($r === 1 ? $d[$t] . ' et un' : $d[$t] . '-' . $u[$r]);
        if ($t === 7) return $r === 1 ? 'soixante et onze' : 'soixante-' . $u[10 + $r];
        if ($t === 8) return $r === 0 ? ($final ? 'quatre-vingts' : 'quatre-vingt') : 'quatre-vingt-' . $u[$r];
        return 'quatre-vingt-' . $u[10 + $r];
    };
    $c999 = function ($x, $final) use ($u, $c99) {
        $c = intdiv($x, 100); $r = $x % 100; $s = '';
        if ($c > 0) $s = $c === 1 ? 'cent' : $u[$c] . ' cent' . ($r === 0 && $final ? 's' : '');
        if ($r > 0) $s .= ($s !== '' ? ' ' : '') . $c99($r, $final);
        return $s;
    };
    $m = intdiv($n, 1000000); $k = intdiv($n % 1000000, 1000); $r = $n % 1000; $p = [];
    if ($m > 0) $p[] = $c999($m, false) . ' million' . ($m > 1 ? 's' : '');
    if ($k > 0) $p[] = $k === 1 ? 'mille' : $c999($k, false) . ' mille';
    if ($r > 0) $p[] = $c999($r, true);
    return implode(' ', $p);
}

// ── Échéancier généré à l'inscription et à la réinscription ───────────────────
function gvb_generer_charges($ins_id) {
    global $wpdb;
    $i = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . gvb_table('inscriptions') . ' WHERE id = %d', (int) $ins_id));
    if (!$i || !$i->etudiant_id || $i->statut !== 'validee') return 0;
    $t = gvb_table('charges');
    if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE inscription_id = %d AND annule = 0", $i->id))) return 0;
    $fr = json_decode((string) $i->frais, true) ?: [];
    $fi = (int) ($fr['inscription'] ?? 0); $fs = (int) ($fr['scolarite'] ?? 0);
    $r = gvb_fin_reglages(); $n = max(1, min(10, (int) $r['tranches']));
    $base = strtotime(mysql2date('Y-m-d', $i->inscrit_le));
    $now = gvb_now(); $cree = 0;
    $ins = function ($libelle, $type, $montant, $echeance) use ($wpdb, $t, $i, $now, &$cree) {
        $wpdb->insert($t, ['etudiant_id' => $i->etudiant_id, 'inscription_id' => $i->id, 'libelle' => $libelle, 'type' => $type, 'montant' => $montant, 'echeance' => $echeance, 'cree_le' => $now]); $cree++;
    };
    if ($fi > 0) $ins('Frais d’inscription ' . $i->annee, 'inscription', $fi, gmdate('Y-m-d', $base));
    if ($fs > 0) {
        $part = intdiv($fs, $n);
        for ($k = 1; $k <= $n; $k++) {
            $montant = $k === $n ? $fs - $part * ($n - 1) : $part;
            $ins('Scolarité ' . $i->annee . ($n > 1 ? " — tranche $k/$n" : ''), 'scolarite', $montant, gmdate('Y-m-d', $base + ((int) $r['delai'] + ($k - 1) * (int) $r['intervalle']) * DAY_IN_SECONDS));
        }
    }
    if ($cree) gvb_log(0, 'Échéancier généré', $i->matricule . ' — ' . $i->annee . ' — ' . $cree . ' échéance(s)');
    return $cree;
}
add_action('gvb_inscription_creee', fn($ins_id) => gvb_generer_charges($ins_id), 20, 1);
add_action('gvb_reinscription_creee', fn($ins_id) => gvb_generer_charges($ins_id), 20, 1);
add_action('gvb_inscription_annulee', function ($ins, $motif) {
    global $wpdb;
    $wpdb->query($wpdb->prepare('UPDATE ' . gvb_table('charges') . " SET annule = 1, motif = %s WHERE inscription_id = %d AND annule = 0", 'Inscription annulée : ' . $motif, $ins->id));
}, 20, 2);

// ── Calcul du compte d'un étudiant (les paiements s'imputent par ordre d'échéance) ──
function gvb_compte($etudiant_id) {
    global $wpdb;
    $charges = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . gvb_table('charges') . ' WHERE etudiant_id = %d AND annule = 0 ORDER BY echeance, id', (int) $etudiant_id));
    $paye = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(montant),0) FROM ' . gvb_table('paiements') . ' WHERE etudiant_id = %d AND annule = 0', (int) $etudiant_id));
    $dispo = $paye; $du = 0; $retard = 0; $depuis = null; $auj = gvb_aujourdhui();
    foreach ($charges as $c) {
        $c->montant = (int) $c->montant; $c->alloue = min($dispo, $c->montant); $dispo -= $c->alloue; $c->reste = $c->montant - $c->alloue; $du += $c->montant;
        $c->etat = $c->reste === 0 ? 'paye' : ($c->echeance < $auj ? 'retard' : ($c->alloue > 0 ? 'partiel' : 'avenir'));
        if ($c->reste > 0 && $c->echeance < $auj) { $retard += $c->reste; if ($depuis === null) $depuis = $c->echeance; }
    }
    return ['charges' => $charges, 'paye' => $paye, 'du' => $du, 'solde' => $du - $paye, 'retard' => $retard, 'depuis' => $depuis, 'jours' => $depuis ? (int) floor((strtotime($auj) - strtotime($depuis)) / DAY_IN_SECONDS) : 0];
}

// ── Paiements ─────────────────────────────────────────────────────────────────
function gvb_enregistrer_paiement($etudiant_id, $montant, $mode, $date, $reference, $note, $jeton = '') {
    global $wpdb;
    $e = gvb_etudiant($etudiant_id);
    if (!$e) return new WP_Error('introuvable', 'Étudiant introuvable.');
    $montant = (int) $montant;
    if ($montant <= 0 || $montant > 500000000) return new WP_Error('montant', 'Montant invalide.');
    if (!isset(GVB_MODES[$mode])) return new WP_Error('mode', 'Mode de paiement invalide.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false || $date > gvb_aujourdhui()) return new WP_Error('date', 'Date de paiement invalide (pas dans le futur).');
    if ($mode !== 'especes' && $reference === '') return new WP_Error('reference', 'Indiquez la référence (numéro de virement, de chèque ou de transaction).');
    if ($jeton !== '') {
        if (get_transient('gvb_pay_' . md5($jeton))) return new WP_Error('doublon', 'Ce paiement vient déjà d’être enregistré.');
        set_transient('gvb_pay_' . md5($jeton), 1, HOUR_IN_SECONDS);
    }
    $annee = substr($date, 0, 4);
    $n = gvb_sequence('recu_' . $annee);
    $recu = 'REC-' . $annee . '-' . str_pad((string) $n, 5, '0', STR_PAD_LEFT);
    $wpdb->insert(gvb_table('paiements'), ['etudiant_id' => $e->id, 'montant' => $montant, 'mode' => $mode, 'reference' => $reference, 'date_paiement' => $date, 'recu' => $recu, 'note' => $note, 'saisi_par' => get_current_user_id(), 'cree_le' => gvb_now()]);
    $pid = (int) $wpdb->insert_id;
    gvb_log($e->demande_id, 'Paiement enregistré', $recu . ' — ' . gvb_montant($montant) . ' — ' . GVB_MODES[$mode]);
    do_action('gvb_paiement_enregistre', $pid, $e, $recu, $montant);
    return $pid;
}

// ── Écrans ────────────────────────────────────────────────────────────────────
function gvb_etat_badge($etat) {
    $c = ['paye' => '#007A2E', 'partiel' => '#8A5A00', 'retard' => '#B42318', 'avenir' => '#4B5578'][$etat] ?? '#4B5578';
    $i = ['paye' => 'check', 'partiel' => 'horloge', 'retard' => 'alerte', 'avenir' => 'horloge'][$etat] ?? 'dossier';
    return '<span class="gvb-badge" style="background:' . esc_attr($c) . '">' . gvb_icone($i, 12) . ' ' . esc_html(GVB_ETATS_CHARGE[$etat] ?? $etat) . '</span>';
}

function gvb_etudiants_avec_charges($pole_filtre = '') {
    global $wpdb;
    $where = ['EXISTS (SELECT 1 FROM ' . gvb_table('charges') . ' c WHERE c.etudiant_id = e.id AND c.annule = 0)']; $args = [];
    if ($pole_filtre !== '') { $where[] = 'e.pole = %s'; $args[] = $pole_filtre; }
    gvb_clause_pole($where, $args, 'e');
    $sql = 'SELECT e.* FROM ' . gvb_table('etudiants') . ' e WHERE ' . implode(' AND ', $where) . ' ORDER BY e.nom, e.prenom';
    return $args ? $wpdb->get_results($wpdb->prepare($sql, $args)) : $wpdb->get_results($sql);
}

function gvb_page_finances() {
    gvb_exiger('finances', 'voir');
    global $wpdb;
    $pole = isset(GVB_POLES[$_GET['pole'] ?? '']) ? $_GET['pole'] : '';
    $du = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['du'] ?? '') ? $_GET['du'] : gmdate('Y-m-01');
    $au = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['au'] ?? '') ? $_GET['au'] : gvb_aujourdhui();
    $mode = isset(GVB_MODES[$_GET['mode'] ?? '']) ? $_GET['mode'] : '';
    $tot = ['du' => 0, 'paye' => 0, 'retard' => 0, 'nb_retard' => 0]; $par_pole = [];
    foreach (gvb_etudiants_avec_charges($pole) as $e) {
        $c = gvb_compte($e->id);
        $tot['du'] += $c['du']; $tot['paye'] += min($c['paye'], $c['du']); $tot['retard'] += $c['retard']; if ($c['retard'] > 0) $tot['nb_retard']++;
        $p = &$par_pole[$e->pole]; $p['du'] = ($p['du'] ?? 0) + $c['du']; $p['paye'] = ($p['paye'] ?? 0) + min($c['paye'], $c['du']); $p['retard'] = ($p['retard'] ?? 0) + $c['retard']; unset($p);
    }
    $reste = max(0, $tot['du'] - $tot['paye']); $taux = $tot['du'] ? round(100 * $tot['paye'] / $tot['du']) : 0;
    $actions = gvb_can('finances', 'exporter') ? '<a class="button" href="' . esc_url(wp_nonce_url(add_query_arg(array_filter(['action' => 'gvb_export_paiements', 'pole' => $pole, 'du' => $du, 'au' => $au, 'mode' => $mode]), admin_url('admin-post.php')), 'gvb_export_paiements')) . '">Exporter le journal (CSV)</a>' : '';
    gvb_debut('Finances', 'carte', $actions);
    echo '<form method="get" class="gvb-filtres"><input type="hidden" name="page" value="gv-finances">' . gvb_liste('pole', 'Pôle', ['' => 'Tous'] + GVB_POLES, $pole) . gvb_champ('du', 'Journal du', $du, 'date') . gvb_champ('au', 'au', $au, 'date') . gvb_liste('mode', 'Mode', ['' => 'Tous'] + GVB_MODES, $mode) . gvb_bouton('Filtrer', false) . '</form>';
    echo '<div class="gvb-cartes">' . gvb_carte('Attendu', gvb_montant($tot['du']), 'Frais facturés', 'argent', '') . gvb_carte('Encaissé', gvb_montant($tot['paye']), $taux . ' % recouvré', 'check', 'ok') . gvb_carte('Reste à recouvrer', gvb_montant($reste), 'Échéances non soldées', 'horloge', '')
        . gvb_carte('En retard', gvb_montant($tot['retard']), $tot['nb_retard'] . ' étudiant(s)', 'alerte', $tot['retard'] ? 'attention' : '', gvb_url('gv-impayes', ['pole' => $pole])) . '</div>';
    $l = [];
    foreach ($par_pole as $slug => $p) $l[] = '<tr><td><strong>' . esc_html(GVB_POLES[$slug] ?? $slug) . '</strong></td><td class="d">' . esc_html(gvb_montant($p['du'])) . '</td><td class="d">' . esc_html(gvb_montant($p['paye'])) . '</td><td class="d">' . esc_html(gvb_montant(max(0, $p['du'] - $p['paye']))) . '</td><td class="d">' . esc_html(gvb_montant($p['retard'])) . '</td></tr>';
    echo '<div class="gvb-boite"><h2>' . gvb_icone('pole', 18) . 'Par pôle</h2>' . gvb_tableau(['Pôle', 'Attendu', 'Encaissé', 'Reste', 'En retard'], $l, 'Aucun échéancier pour le moment : il est créé à chaque inscription.') . '</div>';

    $w = ['p.date_paiement BETWEEN %s AND %s']; $a = [$du, $au];
    if ($mode !== '') { $w[] = 'p.mode = %s'; $a[] = $mode; }
    if ($pole !== '') { $w[] = 'e.pole = %s'; $a[] = $pole; }
    gvb_clause_pole($w, $a, 'e');
    $rows = $wpdb->get_results($wpdb->prepare('SELECT p.*, e.nom, e.prenom, e.matricule, e.pole FROM ' . gvb_table('paiements') . ' p JOIN ' . gvb_table('etudiants') . ' e ON e.id = p.etudiant_id WHERE ' . implode(' AND ', $w) . ' ORDER BY p.date_paiement DESC, p.id DESC LIMIT 500', $a));
    $l = []; $total = 0; $par_mode = [];
    foreach ($rows as $p) {
        if (!$p->annule) { $total += $p->montant; $par_mode[$p->mode] = ($par_mode[$p->mode] ?? 0) + $p->montant; }
        $l[] = '<tr' . ($p->annule ? ' style="opacity:.55;text-decoration:line-through"' : '') . '><td>' . esc_html(gvb_d($p->date_paiement)) . '</td><td><a href="' . esc_url(gvb_url('gv-recu', ['id' => $p->id])) . '">' . esc_html($p->recu) . '</a></td><td><a href="' . esc_url(gvb_url('gv-compte', ['id' => $p->etudiant_id])) . '">' . esc_html($p->matricule . ' · ' . mb_strtoupper($p->nom) . ' ' . $p->prenom) . '</a></td><td>' . esc_html(GVB_MODES[$p->mode] ?? $p->mode) . '</td><td>' . esc_html($p->reference ?: '—') . '</td><td class="d">' . esc_html(gvb_montant($p->montant)) . '</td></tr>';
    }
    $resume = ''; foreach ($par_mode as $m => $v) $resume .= esc_html(GVB_MODES[$m] ?? $m) . ' : <strong>' . esc_html(gvb_montant($v)) . '</strong> &nbsp; ';
    echo '<div class="gvb-boite"><h2>' . gvb_icone('journal', 18) . 'Journal des paiements</h2><p>Total encaissé sur la période : <strong>' . esc_html(gvb_montant($total)) . '</strong>' . ($resume ? ' — ' . $resume : '') . '</p>' . gvb_tableau(['Date', 'Reçu', 'Étudiant', 'Mode', 'Référence', 'Montant'], $l, 'Aucun paiement sur la période.') . '</div>';
    gvb_fin();
}

function gvb_page_compte() {
    gvb_exiger('finances', 'voir');
    global $wpdb;
    $e = gvb_etudiant(absint($_GET['id'] ?? 0));
    if (!$e) wp_die('Étudiant introuvable.', 'Introuvable', ['response' => 404, 'back_link' => false]);
    $c = gvb_compte($e->id);
    gvb_debut('Compte — ' . $e->prenom . ' ' . $e->nom, 'carte', '<a class="button" href="' . esc_url(gvb_url('gv-etudiant', ['id' => $e->id])) . '">← Fiche étudiant</a>');
    echo '<p>' . esc_html($e->matricule) . ' · ' . esc_html(GVB_POLES[$e->pole] ?? '') . ' · ' . esc_html($e->niveau) . ' · ' . gvb_etu_badge($e->statut) . '</p>';
    echo '<div class="gvb-cartes">' . gvb_carte('Total dû', gvb_montant($c['du']), '', 'argent') . gvb_carte('Payé', gvb_montant($c['paye']), '', 'check', 'ok')
        . gvb_carte($c['solde'] < 0 ? 'Crédit' : 'Reste à payer', gvb_montant(abs($c['solde'])), $c['solde'] < 0 ? 'Trop-perçu à imputer ou rembourser' : '', 'horloge') . gvb_carte('En retard', gvb_montant($c['retard']), $c['depuis'] ? 'depuis ' . $c['jours'] . ' jour(s)' : '', 'alerte', $c['retard'] ? 'attention' : '') . '</div>';
    $modif = gvb_can('finances', 'modifier'); $l = [];
    foreach ($c['charges'] as $ch) {
        $act = '';
        if ($modif) $act = gvb_details('Modifier', gvb_form('gvb_charge_modifier', 'gvb_charge_modifier', ['id' => $ch->id, 'e' => $e->id]) . gvb_champ('echeance', 'Échéance', $ch->echeance, 'date', 'required') . gvb_champ('montant', 'Montant (FCFA)', $ch->montant, 'number', 'min="1" step="1" required') . gvb_bouton('Enregistrer', false) . '</form>')
            . ' ' . gvb_details('Annuler', gvb_form('gvb_charge_annuler', 'gvb_charge_annuler', ['id' => $ch->id, 'e' => $e->id]) . gvb_champ('motif', 'Motif', '', 'text', 'required maxlength="255"') . gvb_bouton('Annuler cette échéance', false) . '</form>');
        $l[] = '<tr><td>' . esc_html($ch->libelle) . '</td><td>' . esc_html(gvb_d($ch->echeance)) . '</td><td class="d">' . esc_html(gvb_montant($ch->montant)) . '</td><td class="d">' . esc_html(gvb_montant($ch->alloue)) . '</td><td class="d">' . esc_html(gvb_montant($ch->reste)) . '</td><td>' . gvb_etat_badge($ch->etat) . '</td><td>' . $act . '</td></tr>';
    }
    echo '<div class="gvb-boite"><h2>' . gvb_icone('liste', 18) . 'Échéancier</h2>' . gvb_tableau(['Libellé', 'Échéance', 'Montant', 'Payé', 'Reste', 'État', ''], $l, 'Aucune échéance. Elle est créée à l’inscription.');
    if ($modif) echo gvb_details('Ajouter une ligne (frais exceptionnel, ajustement)', gvb_form('gvb_charge_ajouter', 'gvb_charge_ajouter', ['e' => $e->id]) . '<div class="gvb-champs">' . gvb_champ('libelle', 'Libellé', '', 'text', 'required maxlength="160"') . gvb_champ('montant', 'Montant (FCFA)', '', 'number', 'min="1" step="1" required') . gvb_champ('echeance', 'Échéance', gvb_aujourdhui(), 'date', 'required') . '</div>' . gvb_bouton('Ajouter', false) . '</form>');
    echo '</div>';

    echo '<div class="gvb-grille"><div>';
    if (gvb_can('finances', 'creer') && in_array($e->statut, ['actif', 'suspendu'], true)) {
        echo '<div class="gvb-boite"><h2>' . gvb_icone('plus', 18) . 'Enregistrer un paiement</h2>' . gvb_form('gvb_paiement_saisir', 'gvb_paiement_saisir', ['id' => $e->id, 'jeton' => wp_generate_password(20, false)]) . '<div class="gvb-champs">'
            . gvb_champ('montant', 'Montant (FCFA) *', '', 'number', 'min="1" step="1" required') . gvb_liste('mode', 'Mode *', GVB_MODES, 'especes') . gvb_champ('date', 'Date du paiement *', gvb_aujourdhui(), 'date', 'required max="' . esc_attr(gvb_aujourdhui()) . '"')
            . gvb_champ('reference', 'Référence (obligatoire hors espèces)', '', 'text', 'maxlength="120"') . '</div>' . gvb_champ('note', 'Note (facultatif)', '', 'text', 'maxlength="255"') . gvb_bouton('Enregistrer et créer le reçu') . '</form></div>';
    }
    echo '</div><div>';
    $ps = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . gvb_table('paiements') . ' WHERE etudiant_id = %d ORDER BY date_paiement DESC, id DESC', $e->id)); $l = [];
    foreach ($ps as $p) {
        $act = '<a class="button" href="' . esc_url(gvb_url('gv-recu', ['id' => $p->id])) . '">Reçu</a>';
        if (!$p->annule && gvb_can('finances', 'annuler')) $act .= ' ' . gvb_details('Annuler', gvb_form('gvb_paiement_annuler', 'gvb_paiement_annuler', ['id' => $p->id, 'e' => $e->id]) . gvb_champ('motif', 'Motif', '', 'text', 'required maxlength="255"') . gvb_bouton('Annuler le paiement', false) . '</form>');
        $l[] = '<tr' . ($p->annule ? ' style="opacity:.55;text-decoration:line-through"' : '') . '><td>' . esc_html(gvb_d($p->date_paiement)) . '</td><td>' . esc_html($p->recu) . '</td><td>' . esc_html(GVB_MODES[$p->mode] ?? $p->mode) . '</td><td class="d">' . esc_html(gvb_montant($p->montant)) . '</td><td>' . $act . '</td></tr>';
    }
    echo '<div class="gvb-boite"><h2>' . gvb_icone('journal', 18) . 'Paiements</h2>' . gvb_tableau(['Date', 'Reçu', 'Mode', 'Montant', ''], $l, 'Aucun paiement.') . '</div></div></div>';
    gvb_fin();
}

function gvb_page_recu() {
    gvb_exiger('finances', 'voir');
    global $wpdb;
    $p = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . gvb_table('paiements') . ' WHERE id = %d', absint($_GET['id'] ?? 0)));
    $e = $p ? gvb_etudiant($p->etudiant_id) : null;
    if (!$p || !$e) wp_die('Reçu introuvable.', 'Introuvable', ['response' => 404, 'back_link' => false]);
    $c = gvb_compte($e->id); $u = get_userdata($p->saisi_par);
    gvb_debut('Reçu ' . $p->recu, 'carte', '<a class="button" href="' . esc_url(gvb_url('gv-compte', ['id' => $e->id])) . '">← Compte</a><button type="button" class="button button-primary" onclick="window.print()">' . gvb_icone('print', 16) . 'Imprimer</button>');
    echo '<div class="gvb-boite gvb-recu">' . ($p->annule ? '<div class="gvb-alerte"><strong>REÇU ANNULÉ</strong> — ' . esc_html($p->motif) . '</div>' : '')
        . '<h2 style="border:0">GROUPE VITO S. — Reçu de paiement</h2><p><strong>N° ' . esc_html($p->recu) . '</strong> · Date : ' . esc_html(gvb_d($p->date_paiement)) . '</p>'
        . '<p>Reçu de : <strong>' . esc_html(mb_strtoupper($e->nom) . ' ' . $e->prenom) . '</strong> — matricule ' . esc_html($e->matricule) . ' — ' . esc_html(GVB_POLES[$e->pole] ?? '') . ', ' . esc_html($e->niveau) . '</p>'
        . '<p>La somme de : <strong>' . esc_html(gvb_montant($p->montant)) . '</strong> (' . esc_html(gvb_lettres($p->montant)) . ' francs CFA)</p>'
        . '<p>Mode : ' . esc_html(GVB_MODES[$p->mode] ?? $p->mode) . ($p->reference ? ' — réf. ' . esc_html($p->reference) : '') . '</p>'
        . '<p>Solde restant à ce jour : <strong>' . esc_html(gvb_montant(max(0, $c['solde']))) . '</strong></p>'
        . '<p class="gvb-rappel">Reçu établi par ' . esc_html($u ? $u->display_name : '—') . ' le ' . esc_html(gvb_dt($p->cree_le)) . '. Signature et cachet :</p><p style="height:70px"></p></div>';
    echo '<style media="print">#adminmenumain,#wpadminbar,#wpfooter,.gvb-entete-actions{display:none!important}#wpcontent{margin-left:0!important}</style>';
    gvb_fin();
}

// ── Impayés et relances ───────────────────────────────────────────────────────
function gvb_liste_impayes($pole = '') {
    global $wpdb; $out = [];
    foreach (gvb_etudiants_avec_charges($pole) as $e) {
        if (in_array($e->statut, ['inactif', 'abandon'], true)) continue;
        $c = gvb_compte($e->id);
        if ($c['retard'] <= 0) continue;
        $e->compte = $c;
        $e->derniere = $wpdb->get_var($wpdb->prepare('SELECT MAX(envoyee_le) FROM ' . gvb_table('relances') . ' WHERE etudiant_id = %d', $e->id));
        $out[] = $e;
    }
    usort($out, fn($a, $b) => strcmp((string) $a->compte['depuis'], (string) $b->compte['depuis']));
    return $out;
}
function gvb_message_relance($e) {
    $c = $e->compte ?? gvb_compte($e->id);
    return strtr(gvb_fin_reglages()['relance'], ['{prenom}' => $e->prenom, '{nom}' => $e->nom, '{montant}' => gvb_montant($c['retard']), '{echeance}' => gvb_d($c['depuis']), '{matricule}' => $e->matricule]);
}
function gvb_page_impayes() {
    gvb_exiger('finances', 'voir');
    $pole = isset(GVB_POLES[$_GET['pole'] ?? '']) ? $_GET['pole'] : '';
    $l = []; $total = 0;
    foreach (gvb_liste_impayes($pole) as $e) {
        $c = $e->compte; $total += $c['retard'];
        $tel = preg_replace('/\D/', '', $e->telephone ?: $e->parent_telephone);
        $act = '';
        if (gvb_can('finances', 'modifier')) {
            if ($e->email || $e->parent_email) $act .= gvb_form('gvb_relancer', 'gvb_relancer', ['id' => $e->id, 'canal' => 'email'], 'class="gvb-inline"') . gvb_bouton('Relancer par e-mail', false) . '</form> ';
            if ($tel) $act .= '<a class="button" target="_blank" rel="noopener" href="' . esc_url('https://wa.me/' . $tel . '?text=' . rawurlencode(gvb_message_relance($e))) . '">WhatsApp</a> ' . gvb_form('gvb_relancer', 'gvb_relancer', ['id' => $e->id, 'canal' => 'whatsapp'], 'class="gvb-inline"') . gvb_bouton('Noter envoyé', false) . '</form> ';
        }
        $l[] = '<tr><td><a href="' . esc_url(gvb_url('gv-compte', ['id' => $e->id])) . '"><strong>' . esc_html($e->matricule) . '</strong></a><br>' . esc_html(mb_strtoupper($e->nom) . ' ' . $e->prenom) . '</td><td>' . esc_html(GVB_POLES[$e->pole] ?? '') . '</td><td class="d"><strong>' . esc_html(gvb_montant($c['retard'])) . '</strong></td><td>' . esc_html(gvb_d($c['depuis'])) . '<br><small class="gvb-ko">' . (int) $c['jours'] . ' jour(s)</small></td><td>' . ($e->derniere ? esc_html(gvb_dt($e->derniere)) : '—') . '</td><td>' . $act . '</td></tr>';
    }
    $actions = gvb_can('finances', 'exporter') ? '<a class="button" href="' . esc_url(wp_nonce_url(add_query_arg(array_filter(['action' => 'gvb_export_impayes', 'pole' => $pole]), admin_url('admin-post.php')), 'gvb_export_impayes')) . '">Exporter (CSV)</a>' : '';
    gvb_debut('Impayés et relances', 'alerte', $actions);
    echo '<form method="get" class="gvb-filtres"><input type="hidden" name="page" value="gv-impayes">' . gvb_liste('pole', 'Pôle', ['' => 'Tous'] + GVB_POLES, $pole) . gvb_bouton('Filtrer', false) . '</form>';
    echo '<p>Total en retard : <strong>' . esc_html(gvb_montant($total)) . '</strong> · ' . count($l) . ' étudiant(s). Une relance par e-mail ne peut être envoyée qu’une fois tous les 7 jours par étudiant.</p>';
    echo gvb_tableau(['Étudiant', 'Pôle', 'En retard', 'Depuis', 'Dernière relance', 'Actions'], $l, 'Aucun impayé : toutes les échéances échues sont soldées.');
    gvb_fin();
}

// ── Actions ───────────────────────────────────────────────────────────────────
add_action('admin_post_gvb_paiement_saisir', function () {
    gvb_garde('finances', 'creer', 'gvb_paiement_saisir');
    $id = absint($_POST['id'] ?? 0);
    $r = gvb_enregistrer_paiement($id, absint($_POST['montant'] ?? 0), sanitize_key($_POST['mode'] ?? ''), sanitize_text_field(wp_unslash($_POST['date'] ?? '')), mb_substr(sanitize_text_field(wp_unslash($_POST['reference'] ?? '')), 0, 120), mb_substr(sanitize_text_field(wp_unslash($_POST['note'] ?? '')), 0, 255), sanitize_text_field(wp_unslash($_POST['jeton'] ?? '')));
    if (is_wp_error($r)) gvb_retour('gv-compte', ['id' => $id], null, $r);
    gvb_retour('gv-recu', ['id' => $r], 'Paiement enregistré.');
});
add_action('admin_post_gvb_paiement_annuler', function () {
    gvb_garde('finances', 'annuler', 'gvb_paiement_annuler');
    global $wpdb; $id = absint($_POST['id'] ?? 0); $eid = absint($_POST['e'] ?? 0);
    $p = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . gvb_table('paiements') . ' WHERE id = %d', $id)); $e = $p ? gvb_etudiant($p->etudiant_id) : null;
    $motif = mb_substr(sanitize_text_field(wp_unslash($_POST['motif'] ?? '')), 0, 255);
    if (!$p || !$e || $p->annule) gvb_retour('gv-finances', [], null, 'Paiement introuvable ou déjà annulé.');
    if ($motif === '') gvb_retour('gv-compte', ['id' => $e->id], null, 'Le motif est obligatoire.');
    $wpdb->update(gvb_table('paiements'), ['annule' => 1, 'motif' => $motif], ['id' => $id]);
    gvb_log($e->demande_id, 'Paiement annulé', $p->recu . ' — ' . $motif);
    gvb_retour('gv-compte', ['id' => $e->id], 'Paiement annulé : le reçu est conservé et marqué annulé.');
});
add_action('admin_post_gvb_charge_ajouter', function () {
    gvb_garde('finances', 'modifier', 'gvb_charge_ajouter');
    global $wpdb; $e = gvb_etudiant(absint($_POST['e'] ?? 0));
    $libelle = mb_substr(sanitize_text_field(wp_unslash($_POST['libelle'] ?? '')), 0, 160); $montant = absint($_POST['montant'] ?? 0); $ech = sanitize_text_field(wp_unslash($_POST['echeance'] ?? ''));
    if (!$e) gvb_retour('gv-finances', [], null, 'Étudiant introuvable.');
    if ($libelle === '' || $montant <= 0 || $montant > 500000000 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ech)) gvb_retour('gv-compte', ['id' => $e->id], null, 'Libellé, montant et échéance valides sont obligatoires.');
    $wpdb->insert(gvb_table('charges'), ['etudiant_id' => $e->id, 'inscription_id' => 0, 'libelle' => $libelle, 'type' => 'autre', 'montant' => $montant, 'echeance' => $ech, 'cree_le' => gvb_now()]);
    gvb_log($e->demande_id, 'Échéance ajoutée', $libelle . ' — ' . gvb_montant($montant));
    gvb_retour('gv-compte', ['id' => $e->id], 'Ligne ajoutée.');
});
add_action('admin_post_gvb_charge_modifier', function () {
    gvb_garde('finances', 'modifier', 'gvb_charge_modifier');
    global $wpdb; $ch = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . gvb_table('charges') . ' WHERE id = %d AND annule = 0', absint($_POST['id'] ?? 0))); $e = $ch ? gvb_etudiant($ch->etudiant_id) : null;
    $montant = absint($_POST['montant'] ?? 0); $ech = sanitize_text_field(wp_unslash($_POST['echeance'] ?? ''));
    if (!$ch || !$e) gvb_retour('gv-finances', [], null, 'Échéance introuvable.');
    if ($montant <= 0 || $montant > 500000000 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ech)) gvb_retour('gv-compte', ['id' => $e->id], null, 'Montant ou date invalide.');
    $wpdb->update(gvb_table('charges'), ['montant' => $montant, 'echeance' => $ech], ['id' => $ch->id]);
    gvb_log($e->demande_id, 'Échéance modifiée', $ch->libelle . ' : ' . gvb_montant($ch->montant) . ' → ' . gvb_montant($montant) . ', ' . $ch->echeance . ' → ' . $ech);
    gvb_retour('gv-compte', ['id' => $e->id], 'Échéance modifiée.');
});
add_action('admin_post_gvb_charge_annuler', function () {
    gvb_garde('finances', 'modifier', 'gvb_charge_annuler');
    global $wpdb; $ch = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . gvb_table('charges') . ' WHERE id = %d AND annule = 0', absint($_POST['id'] ?? 0))); $e = $ch ? gvb_etudiant($ch->etudiant_id) : null;
    $motif = mb_substr(sanitize_text_field(wp_unslash($_POST['motif'] ?? '')), 0, 255);
    if (!$ch || !$e) gvb_retour('gv-finances', [], null, 'Échéance introuvable.');
    if ($motif === '') gvb_retour('gv-compte', ['id' => $e->id], null, 'Le motif est obligatoire.');
    $wpdb->update(gvb_table('charges'), ['annule' => 1, 'motif' => $motif], ['id' => $ch->id]);
    gvb_log($e->demande_id, 'Échéance annulée', $ch->libelle . ' — ' . $motif);
    gvb_retour('gv-compte', ['id' => $e->id], 'Échéance annulée.');
});
add_action('admin_post_gvb_relancer', function () {
    gvb_garde('finances', 'modifier', 'gvb_relancer');
    global $wpdb; $e = gvb_etudiant(absint($_POST['id'] ?? 0)); $canal = ($_POST['canal'] ?? '') === 'whatsapp' ? 'whatsapp' : 'email';
    if (!$e) gvb_retour('gv-impayes', [], null, 'Étudiant introuvable.');
    $e->compte = gvb_compte($e->id);
    if ($e->compte['retard'] <= 0) gvb_retour('gv-impayes', [], null, 'Aucun retard pour cet étudiant.');
    if ($wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . gvb_table('relances') . ' WHERE etudiant_id = %d AND canal = %s AND envoyee_le > %s', $e->id, $canal, gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS)))) gvb_retour('gv-impayes', [], null, 'Une relance a déjà été envoyée il y a moins de 7 jours.');
    if ($canal === 'email') {
        $a = $e->email ?: $e->parent_email;
        if (!is_email($a)) gvb_retour('gv-impayes', [], null, 'Aucune adresse e-mail pour cet étudiant ou son parent.');
        if (!gvb_mail($a, 'Rappel d’échéance — Groupe VITO S.', gvb_message_relance($e))) gvb_retour('gv-impayes', [], null, 'L’e-mail n’a pas pu être envoyé (voir Réglages → envoi des e-mails).');
    }
    $wpdb->insert(gvb_table('relances'), ['etudiant_id' => $e->id, 'canal' => $canal, 'montant' => $e->compte['retard'], 'envoyee_le' => gvb_now(), 'par' => get_current_user_id()]);
    gvb_log($e->demande_id, 'Relance (' . $canal . ')', $e->matricule . ' — ' . gvb_montant($e->compte['retard']));
    gvb_retour('gv-impayes', [], $canal === 'email' ? 'Relance envoyée par e-mail.' : 'Relance WhatsApp notée.');
});
add_action('admin_post_gvb_export_paiements', function () {
    gvb_garde('finances', 'exporter', 'gvb_export_paiements');
    global $wpdb;
    $du = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['du'] ?? '') ? $_GET['du'] : gmdate('Y-m-01'); $au = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['au'] ?? '') ? $_GET['au'] : gvb_aujourdhui();
    $w = ['p.date_paiement BETWEEN %s AND %s']; $a = [$du, $au];
    if (isset(GVB_MODES[$_GET['mode'] ?? ''])) { $w[] = 'p.mode = %s'; $a[] = $_GET['mode']; }
    if (isset(GVB_POLES[$_GET['pole'] ?? ''])) { $w[] = 'e.pole = %s'; $a[] = $_GET['pole']; }
    gvb_clause_pole($w, $a, 'e');
    $neutre = fn($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v;
    nocache_headers(); header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="paiements-' . gmdate('Ymd') . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF"); fputcsv($o, ['Date', 'Reçu', 'Matricule', 'Nom', 'Prénom', 'Pôle', 'Mode', 'Référence', 'Montant', 'Annulé', 'Motif'], ';');
    foreach ($wpdb->get_results($wpdb->prepare('SELECT p.*, e.nom, e.prenom, e.matricule, e.pole FROM ' . gvb_table('paiements') . ' p JOIN ' . gvb_table('etudiants') . ' e ON e.id = p.etudiant_id WHERE ' . implode(' AND ', $w) . ' ORDER BY p.date_paiement, p.id', $a)) as $p)
        fputcsv($o, array_map($neutre, [$p->date_paiement, $p->recu, $p->matricule, $p->nom, $p->prenom, GVB_POLES[$p->pole] ?? $p->pole, GVB_MODES[$p->mode] ?? $p->mode, $p->reference, $p->montant, $p->annule ? 'oui' : 'non', $p->motif]), ';');
    gvb_log(0, 'Export du journal des paiements', $du . ' → ' . $au);
    gvb_terminer();
});
add_action('admin_post_gvb_export_impayes', function () {
    gvb_garde('finances', 'exporter', 'gvb_export_impayes');
    $neutre = fn($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v;
    nocache_headers(); header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="impayes-' . gmdate('Ymd') . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF"); fputcsv($o, ['Matricule', 'Nom', 'Prénom', 'Pôle', 'Téléphone', 'E-mail', 'En retard (FCFA)', 'Depuis', 'Jours'], ';');
    foreach (gvb_liste_impayes(isset(GVB_POLES[$_GET['pole'] ?? '']) ? $_GET['pole'] : '') as $e) fputcsv($o, array_map($neutre, [$e->matricule, $e->nom, $e->prenom, GVB_POLES[$e->pole] ?? $e->pole, $e->telephone ?: $e->parent_telephone, $e->email ?: $e->parent_email, $e->compte['retard'], $e->compte['depuis'], $e->compte['jours']]), ';');
    gvb_log(0, 'Export des impayés');
    gvb_terminer();
});

// ── Réglages (Direction) ──────────────────────────────────────────────────────
add_action('gvb_reglages_apres', function () {
    if (!gvb_can('parametres', 'modifier')) return;
    $r = gvb_fin_reglages();
    echo '<div class="gvb-boite" style="max-width:640px"><h2>' . gvb_icone('argent', 18) . 'Échéancier et relances</h2>' . gvb_form('gvb_fin_sauver', 'gvb_fin_sauver') . '<div class="gvb-champs">'
        . gvb_champ('tranches', 'Tranches de scolarité (1 à 10)', $r['tranches'], 'number', 'min="1" max="10" required') . gvb_champ('delai', 'Jours avant la 1re tranche', $r['delai'], 'number', 'min="0" max="365" required') . gvb_champ('intervalle', 'Jours entre deux tranches', $r['intervalle'], 'number', 'min="1" max="365" required') . '</div>'
        . gvb_zone('relance', 'Message de relance ({prenom} {nom} {montant} {echeance} {matricule})', $r['relance'], 5) . '<p class="gvb-rappel">Ces réglages s’appliquent aux prochaines inscriptions ; les échéanciers existants ne changent pas.</p>' . gvb_bouton('Enregistrer') . '</form></div>';
});
add_action('admin_post_gvb_fin_sauver', function () {
    gvb_garde('parametres', 'modifier', 'gvb_fin_sauver');
    $m = trim(mb_substr(sanitize_textarea_field(wp_unslash($_POST['relance'] ?? '')), 0, 1000));
    if ($m === '') gvb_retour('gv-reglages', [], null, 'Le message de relance ne peut pas être vide.');
    update_option('gvb_finances', ['tranches' => max(1, min(10, absint($_POST['tranches'] ?? 3))), 'delai' => min(365, absint($_POST['delai'] ?? 30)), 'intervalle' => max(1, min(365, absint($_POST['intervalle'] ?? 30))), 'relance' => $m], false);
    gvb_log(0, 'Réglages des finances modifiés');
    gvb_retour('gv-reglages', [], 'Réglages enregistrés.');
});
