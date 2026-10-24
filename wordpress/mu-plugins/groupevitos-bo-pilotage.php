<?php
/**
 * Plugin Name: Groupe Vitos — Back-office : pilotage de la Direction
 * Description: Indicateurs de direction (admissions, effectifs, finances, présences, réalisation des cours, espaces en ligne), alertes « à traiter », export et impression.
 */

if (!defined('ABSPATH')) exit;

function gvb_pil_pole_effectif($pole_filtre) {
    $u = gvb_pole_utilisateur();
    if ($u === '__aucun__') return '__aucun__';
    return $u !== '' ? $u : $pole_filtre;
}
function gvb_pil_where($colonne, $pole) { global $wpdb; return $pole === '' ? '' : $wpdb->prepare(" AND $colonne = %s", $pole); }
function gvb_pct($a, $b) { return $b > 0 ? (int) round(100 * $a / $b) : 0; }

// ── Collecte ──────────────────────────────────────────────────────────────────
function gvb_pilotage_donnees($annee, $pole_filtre = '') {
    global $wpdb;
    $pole = gvb_pil_pole_effectif($pole_filtre);
    $vide = ['annee' => $annee, 'pole' => $pole, 'admissions' => null, 'effectifs' => null, 'evolution' => null, 'finances' => null, 'pedagogie' => null, 'espaces' => null, 'communication' => null, 'alertes' => []];
    if ($pole === '__aucun__') return $vide;
    $d = $vide; $auj = gvb_aujourdhui(); $il_y_a_30 = gmdate('Y-m-d', time() - 30 * DAY_IN_SECONDS);
    $T = fn($n) => gvb_table($n);

    // Admissions et entonnoir
    if (gvb_can('admissions', 'voir')) {
        $rows = $wpdb->get_results($wpdb->prepare('SELECT statut, source, cree_le, decide_le, pole FROM ' . $T('demandes') . ' WHERE annee = %s' . gvb_pil_where('pole', $pole), $annee));
        $a = ['total' => count($rows), 'par_statut' => array_fill_keys(array_keys(GVB_STATUTS), 0), 'site' => 0, 'agence' => 0, 'delai_jours' => null, 'par_pole' => []]; $delais = [];
        foreach ($rows as $r) {
            $a['par_statut'][$r->statut] = ($a['par_statut'][$r->statut] ?? 0) + 1; $a[$r->source === 'site' ? 'site' : 'agence']++;
            $a['par_pole'][$r->pole] = ($a['par_pole'][$r->pole] ?? 0) + 1;
            if ($r->decide_le) $delais[] = max(0, (strtotime($r->decide_le) - strtotime($r->cree_le)) / DAY_IN_SECONDS);
        }
        $a['acceptees'] = $a['par_statut']['acceptee'] + $a['par_statut']['inscrite']; $a['inscrites'] = $a['par_statut']['inscrite'];
        $a['taux_acceptation'] = gvb_pct($a['acceptees'], $a['total']); $a['taux_conversion'] = gvb_pct($a['inscrites'], $a['total']);
        $a['delai_jours'] = $delais ? round(array_sum($delais) / count($delais), 1) : null;
        $d['admissions'] = $a;
    }
    // Effectifs
    if (gvb_can('etudiants', 'voir')) {
        $rows = $wpdb->get_results('SELECT pole, niveau, annee FROM ' . $T('etudiants') . " WHERE statut = 'actif'" . gvb_pil_where('pole', $pole));
        $e = ['actifs' => count($rows), 'par_pole' => [], 'par_niveau' => []];
        foreach ($rows as $r) { $e['par_pole'][$r->pole] = ($e['par_pole'][$r->pole] ?? 0) + 1; $k = (GVB_POLES[$r->pole] ?? $r->pole) . ' · ' . ($r->niveau ?: 'non renseigné'); $e['par_niveau'][$k] = ($e['par_niveau'][$k] ?? 0) + 1; }
        arsort($e['par_niveau']); $e['par_niveau'] = array_slice($e['par_niveau'], 0, 12, true);
        $e['statuts'] = []; foreach ($wpdb->get_results('SELECT statut, COUNT(*) n FROM ' . $T('etudiants') . ' WHERE 1=1' . gvb_pil_where('pole', $pole) . ' GROUP BY statut') as $r) $e['statuts'][$r->statut] = (int) $r->n;
        $d['effectifs'] = $e;
        // Évolution sur les années
        $ev = [];
        foreach ($wpdb->get_results('SELECT annee, COUNT(*) n FROM ' . $T('inscriptions') . " WHERE statut = 'validee'" . gvb_pil_where('pole', $pole) . ' GROUP BY annee ORDER BY annee') as $r) $ev[$r->annee]['inscriptions'] = (int) $r->n;
        foreach ($wpdb->get_results('SELECT annee, COUNT(*) n FROM ' . $T('demandes') . ' WHERE 1=1' . gvb_pil_where('pole', $pole) . ' GROUP BY annee ORDER BY annee') as $r) $ev[$r->annee]['demandes'] = (int) $r->n;
        ksort($ev); $d['evolution'] = array_slice($ev, -8, 8, true);
    }
    // Finances
    if (gvb_can('finances', 'voir')) {
        $f = ['attendu' => 0, 'encaisse' => 0, 'retard' => 0, 'nb_retard' => 0, 'vieillissement' => ['0–30 j' => 0, '31–60 j' => 0, '61–90 j' => 0, '+ de 90 j' => 0], 'mensuel' => [], 'attendu_annee' => 0, 'par_mode' => []];
        foreach (gvb_etudiants_avec_charges($pole) as $e) {
            $c = gvb_compte($e->id); $f['attendu'] += $c['du']; $f['encaisse'] += min($c['paye'], $c['du']);
            if ($c['retard'] > 0 && !in_array($e->statut, ['inactif', 'abandon'], true)) { $f['retard'] += $c['retard']; $f['nb_retard']++; $j = $c['jours']; $f['vieillissement'][$j <= 30 ? '0–30 j' : ($j <= 60 ? '31–60 j' : ($j <= 90 ? '61–90 j' : '+ de 90 j'))] += $c['retard']; }
        }
        $f['recouvrement'] = gvb_pct($f['encaisse'], $f['attendu']);
        $f['attendu_annee'] = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(c.montant),0) FROM ' . $T('charges') . ' c JOIN ' . $T('inscriptions') . ' i ON i.id = c.inscription_id WHERE c.annule = 0 AND i.annee = %s' . gvb_pil_where('i.pole', $pole), $annee));
        for ($i = 11; $i >= 0; $i--) $f['mensuel'][gmdate('Y-m', strtotime(gmdate('Y-m-15') . " -$i months"))] = 0;
        foreach ($wpdb->get_results($wpdb->prepare('SELECT p.date_paiement, p.montant, p.mode FROM ' . $T('paiements') . ' p JOIN ' . $T('etudiants') . ' e ON e.id = p.etudiant_id WHERE p.annule = 0 AND p.date_paiement >= %s' . gvb_pil_where('e.pole', $pole), gmdate('Y-m-01', strtotime(gmdate('Y-m-15') . ' -11 months')))) as $p) {
            $m = substr($p->date_paiement, 0, 7); if (isset($f['mensuel'][$m])) $f['mensuel'][$m] += (int) $p->montant; $f['par_mode'][$p->mode] = ($f['par_mode'][$p->mode] ?? 0) + (int) $p->montant;
        }
        $d['finances'] = $f;
    }
    // Pédagogie : séances, présences, heures, couverture
    if (gvb_can('planning', 'voir')) {
        $p = ['tenues' => 0, 'annulees' => 0, 'a_regulariser' => 0, 'a_venir' => 0, 'presence_taux' => null, 'presence_total' => 0, 'absences' => 0, 'heures_affectees' => 0, 'heures_effectuees' => 0.0, 'cours' => 0, 'cours_couverts' => 0, 'par_pole' => []];
        foreach ($wpdb->get_results($wpdb->prepare('SELECT statut, date_seance, debut, fin, pole FROM ' . $T('seances') . ' WHERE annee = %s' . gvb_pil_where('pole', $pole), $annee)) as $s) {
            $h = gvb_duree_h($s->debut, $s->fin);
            if ($s->statut === 'tenue') { $p['heures_effectuees'] += $h; if ($s->date_seance >= $il_y_a_30) $p['tenues']++; }
            elseif ($s->statut === 'annulee') { if ($s->date_seance >= $il_y_a_30 && $s->date_seance <= $auj) $p['annulees']++; }
            elseif ($s->date_seance < $auj) $p['a_regulariser']++;
            else $p['a_venir']++;
        }
        $pr = ['present' => 0, 'retard' => 0, 'absent' => 0, 'excuse' => 0];
        foreach ($wpdb->get_results($wpdb->prepare('SELECT p.statut, e.pole FROM ' . $T('presences') . ' p JOIN ' . $T('seances') . ' s ON s.id = p.seance_id JOIN ' . $T('etudiants') . " e ON e.id = p.etudiant_id WHERE s.statut = 'tenue' AND s.date_seance >= %s" . gvb_pil_where('e.pole', $pole), $il_y_a_30)) as $r) { $pr[$r->statut] = ($pr[$r->statut] ?? 0) + 1; $p['par_pole'][$r->pole][$r->statut === 'absent' ? 'absent' : 'ok'] = ($p['par_pole'][$r->pole][$r->statut === 'absent' ? 'absent' : 'ok'] ?? 0) + 1; }
        $p['presence_total'] = array_sum($pr); $p['absences'] = $pr['absent']; $p['presence_taux'] = $p['presence_total'] ? gvb_pct($p['presence_total'] - $pr['absent'], $p['presence_total']) : null;
        $p['heures_affectees'] = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(a.heures),0) FROM ' . $T('affectations') . ' a JOIN ' . $T('cours') . ' k ON k.id = a.cours_id WHERE a.annee = %s' . gvb_pil_where('k.pole', $pole), $annee));
        foreach ($wpdb->get_results('SELECT k.id, k.volume FROM ' . $T('cours') . ' k WHERE k.actif = 1' . gvb_pil_where('k.pole', $pole)) as $k) { $p['cours']++; if (gvb_heures_affectees_cours($k->id, $annee) >= $k->volume) $p['cours_couverts']++; }
        $p['taux_realisation'] = gvb_pct($p['tenues'], $p['tenues'] + $p['a_regulariser']);
        $d['pedagogie'] = $p;
    }
    // Espaces en ligne et notifications
    if (gvb_can('espaces', 'voir') && $pole === '') {
        $c = ['total' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $T('comptes')), 'actives' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $T('comptes') . " WHERE mdp <> ''"), 'connectes_30j' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $T('comptes') . ' WHERE derniere_connexion >= %s', gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS))),
            'notifs_envoyees' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $T('notifs') . " WHERE statut = 'envoye' AND envoye_le >= %s", gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS))), 'notifs_echec' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $T('notifs') . " WHERE statut = 'echec'")];
        $c['taux_activation'] = gvb_pct($c['actives'], $c['total']);
        $d['espaces'] = $c;
        $d['communication'] = ['contacts_30j' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $T('contacts') . ' WHERE cree_le >= %s', gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS))), 'telechargements' => (int) $wpdb->get_var('SELECT COALESCE(SUM(telechargements),0) FROM ' . $T('contacts')),
            'publications_30j' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $T('publications') . " WHERE statut = 'publiee' AND cree_le >= %s", gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS)))];
    }
    // À traiter
    $al = [];
    if ($d['admissions'] !== null) {
        $n = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $T('demandes') . " WHERE statut IN ('nouvelle','etude') AND cree_le < %s" . gvb_pil_where('pole', $pole), gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS)));
        if ($n) $al[] = ['Demandes d’admission en attente depuis plus de 7 jours', $n, gvb_url('gv-admissions', ['statut' => 'etude'])];
        $n = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $T('demandes') . " WHERE statut = 'acceptee'" . gvb_pil_where('pole', $pole)));
        if ($n) $al[] = ['Candidats acceptés, pas encore inscrits', $n, gvb_url('gv-admissions', ['statut' => 'acceptee'])];
    }
    if ($d['finances'] !== null && $d['finances']['vieillissement']['+ de 90 j'] + $d['finances']['vieillissement']['61–90 j'] > 0) $al[] = ['Impayés de plus de 60 jours', $d['finances']['vieillissement']['+ de 90 j'] + $d['finances']['vieillissement']['61–90 j'], gvb_url('gv-impayes'), true];
    if ($d['pedagogie'] !== null) {
        if ($d['pedagogie']['a_regulariser']) $al[] = ['Séances passées sans appel ni annulation', $d['pedagogie']['a_regulariser'], gvb_url('gv-planning')];
        $nc = $d['pedagogie']['cours'] - $d['pedagogie']['cours_couverts']; if ($nc > 0) $al[] = ['Cours dont les heures ne sont pas toutes affectées', $nc, gvb_url('gv-programmes')];
    }
    if ($d['espaces'] !== null) {
        if ($d['espaces']['notifs_echec']) $al[] = ['Notifications en échec d’envoi', $d['espaces']['notifs_echec'], gvb_url('gv-notifs', ['statut' => 'echec'])];
        $inv = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $T('comptes') . " WHERE mdp = '' AND cree_le < %s", gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS)));
        if ($inv) $al[] = ['Invitations non activées depuis plus de 7 jours', $inv, gvb_url('gv-espaces')];
    }
    $d['alertes'] = $al;
    return $d;
}

// ── Affichage ─────────────────────────────────────────────────────────────────
function gvb_barres($donnees, $formatage = null, $couleur = '#08065B') {
    if (!$donnees) return '<p class="gvb-vide">Pas encore de données.</p>';
    $max = max(1, max(array_map('floatval', $donnees))); $h = '<div class="gvb-barres" role="list">';
    foreach ($donnees as $k => $v) { $w = max(1, (int) round(100 * $v / $max)); $h .= '<div class="gvb-barre" role="listitem"><span class="gvb-barre-lib">' . esc_html((string) $k) . '</span><span class="gvb-barre-piste"><span class="gvb-barre-val" style="width:' . $w . '%;background:' . esc_attr($couleur) . '"></span></span><strong>' . esc_html($formatage ? $formatage($v) : (string) $v) . '</strong></div>'; }
    return $h . '</div>';
}
function gvb_filtres_pilotage() { return ['annee' => sanitize_text_field(wp_unslash($_GET['annee'] ?? '')) ?: gvb_reglages()['annee'], 'pole' => isset(GVB_POLES[$_GET['pole'] ?? '']) ? $_GET['pole'] : '']; }

function gvb_page_pilotage() {
    gvb_exiger('pilotage', 'voir');
    $f = gvb_filtres_pilotage(); $d = gvb_pilotage_donnees($f['annee'], $f['pole']);
    $actions = (gvb_can('pilotage', 'exporter') ? '<a class="button" href="' . esc_url(wp_nonce_url(add_query_arg(array_filter(['action' => 'gvb_export_pilotage', 'annee' => $f['annee'], 'pole' => $f['pole']]), admin_url('admin-post.php')), 'gvb_export_pilotage')) . '">Exporter (CSV)</a> ' : '') . '<button type="button" class="button" onclick="window.print()">' . gvb_icone('print', 16) . 'Imprimer</button>';
    gvb_debut('Pilotage', 'rapport', $actions);
    $pole_u = gvb_pole_utilisateur();
    echo '<form method="get" class="gvb-filtres"><input type="hidden" name="page" value="gv-pilotage">' . gvb_liste('annee', 'Année', array_combine(gvb_annees(), gvb_annees()), $f['annee']) . ($pole_u === '' ? gvb_liste('pole', 'Pôle', ['' => 'Tous'] + GVB_POLES, $f['pole']) : '<p class="gvb-rappel">Pôle : ' . esc_html(GVB_POLES[$pole_u] ?? 'aucun') . '</p>') . gvb_bouton('Afficher', false) . '</form>';
    if ($d['pole'] === '__aucun__') { echo '<div class="gvb-boite"><p>Aucun pôle ne vous est attribué : contactez la Direction.</p></div>'; gvb_fin(); return; }
    echo '<p class="gvb-rappel">Année ' . esc_html($d['annee']) . ' · ' . esc_html($d['pole'] ? GVB_POLES[$d['pole']] : 'Tous les pôles') . ' · données au ' . esc_html(gvb_d(gvb_aujourdhui())) . '</p>';

    if ($d['alertes']) {
        echo '<div class="gvb-boite"><h2>' . gvb_icone('alerte', 18) . 'À traiter</h2><ul class="gvb-journal">';
        foreach ($d['alertes'] as $al) echo '<li><a href="' . esc_url($al[2]) . '"><strong>' . esc_html(!empty($al[3]) ? gvb_montant($al[1]) : (string) $al[1]) . '</strong> — ' . esc_html($al[0]) . '</a></li>';
        echo '</ul></div>';
    } else echo '<div class="gvb-boite"><p class="gvb-ok">Rien d’urgent à traiter.</p></div>';

    if ($a = $d['admissions']) {
        echo '<div class="gvb-boite"><h2>' . gvb_icone('admission', 18) . 'Admissions</h2><div class="gvb-cartes">' . gvb_carte('Demandes', $a['total'], $a['site'] . ' via le site, ' . $a['agence'] . ' à l’agence', 'admission') . gvb_carte('Acceptées', $a['acceptees'], $a['taux_acceptation'] . ' % des demandes', 'check') . gvb_carte('Inscrites', $a['inscrites'], $a['taux_conversion'] . ' % des demandes', 'inscription', 'ok')
            . gvb_carte('Délai de décision', $a['delai_jours'] === null ? '—' : $a['delai_jours'] . ' j', 'Moyenne demande → décision', 'horloge') . '</div>'
            . gvb_barres(array_filter(array_combine(array_map(fn($k) => GVB_STATUTS[$k] ?? $k, array_keys($a['par_statut'])), $a['par_statut'])), null) . '</div>';
    }
    if ($e = $d['effectifs']) {
        echo '<div class="gvb-grille"><div class="gvb-boite"><h2>' . gvb_icone('pole', 18) . 'Effectifs actifs : ' . (int) $e['actifs'] . '</h2>' . gvb_barres(array_combine(array_map(fn($k) => GVB_POLES[$k] ?? $k, array_keys($e['par_pole'])), $e['par_pole']), null, '#1F4FFF') . '<h3>Par niveau</h3>' . gvb_barres($e['par_niveau'], null, '#FFC23D') . '</div>';
        $ev = []; foreach ($d['evolution'] ?? [] as $an => $v) $ev[$an . ' — inscrits'] = $v['inscriptions'] ?? 0;
        echo '<div class="gvb-boite"><h2>' . gvb_icone('rapport', 18) . 'Évolution des inscriptions</h2>' . gvb_barres($ev, null, '#007A2E') . '</div></div>';
    }
    if ($f_ = $d['finances']) {
        echo '<div class="gvb-boite"><h2>' . gvb_icone('argent', 18) . 'Finances</h2><div class="gvb-cartes">' . gvb_carte('Attendu', gvb_montant($f_['attendu']), 'Toutes années confondues', 'argent') . gvb_carte('Encaissé', gvb_montant($f_['encaisse']), $f_['recouvrement'] . ' % recouvré', 'check', 'ok') . gvb_carte('En retard', gvb_montant($f_['retard']), $f_['nb_retard'] . ' étudiant(s)', 'alerte', $f_['retard'] ? 'attention' : '') . gvb_carte('Facturé pour ' . $d['annee'], gvb_montant($f_['attendu_annee']), '', 'carte') . '</div>'
            . '<div class="gvb-grille"><div><h3>Encaissements des 12 derniers mois</h3>' . gvb_barres($f_['mensuel'], 'gvb_montant', '#007A2E') . '</div><div><h3>Âge des impayés</h3>' . gvb_barres($f_['vieillissement'], 'gvb_montant', '#B42318') . '</div></div></div>';
    }
    if ($p = $d['pedagogie']) {
        echo '<div class="gvb-boite"><h2>' . gvb_icone('horloge', 18) . 'Vie pédagogique (30 derniers jours)</h2><div class="gvb-cartes">' . gvb_carte('Présence', $p['presence_taux'] === null ? '—' : $p['presence_taux'] . ' %', $p['absences'] . ' absence(s) sur ' . $p['presence_total'] . ' appel(s)', 'check', $p['presence_taux'] !== null && $p['presence_taux'] < 80 ? 'attention' : 'ok')
            . gvb_carte('Réalisation des séances', $p['taux_realisation'] . ' %', $p['tenues'] . ' tenues · ' . $p['a_regulariser'] . ' à régulariser · ' . $p['annulees'] . ' annulées', 'liste', $p['a_regulariser'] ? 'attention' : '') . gvb_carte('Heures effectuées', gvb_h($p['heures_effectuees']), 'sur ' . $p['heures_affectees'] . ' h affectées', 'horloge') . gvb_carte('Cours couverts', $p['cours_couverts'] . ' / ' . $p['cours'], 'heures toutes affectées', 'liste') . '</div></div>';
    }
    if ($c = $d['espaces']) {
        $co = $d['communication'];
        echo '<div class="gvb-boite"><h2>' . gvb_icone('utilisateurs', 18) . 'Espaces en ligne et communication</h2><div class="gvb-cartes">' . gvb_carte('Comptes actifs', $c['actives'] . ' / ' . $c['total'], $c['taux_activation'] . ' % ont créé leur mot de passe', 'utilisateurs') . gvb_carte('Connectés (30 j)', $c['connectes_30j'], '', 'check') . gvb_carte('Notifications envoyées (30 j)', $c['notifs_envoyees'], $c['notifs_echec'] . ' en échec', 'alerte', $c['notifs_echec'] ? 'attention' : '')
            . gvb_carte('Contacts plaquette (30 j)', $co['contacts_30j'], $co['telechargements'] . ' téléchargement(s) au total', 'cv') . gvb_carte('Publications Facebook (30 j)', $co['publications_30j'], '', 'rapport') . '</div></div>';
    }
    echo '<style media="print">#adminmenumain,#wpadminbar,#wpfooter,.gvb-entete-actions,.gvb-filtres{display:none!important}#wpcontent{margin-left:0!important}</style>';
    echo '<style>.gvb-barres{display:grid;gap:6px}.gvb-barre{display:grid;grid-template-columns:minmax(110px,190px) 1fr auto;gap:10px;align-items:center;font-size:13px}.gvb-barre-piste{background:#EEF1F8;border-radius:6px;height:14px;overflow:hidden}.gvb-barre-val{display:block;height:100%;border-radius:6px}.gvb-barre strong{min-width:60px;text-align:right}</style>';
    gvb_fin();
}

function gvb_lignes_pilotage($d) {
    $l = [];
    if ($a = $d['admissions']) { $l[] = ['Admissions', 'Demandes', $a['total']]; $l[] = ['Admissions', 'Acceptées', $a['acceptees']]; $l[] = ['Admissions', 'Inscrites', $a['inscrites']]; $l[] = ['Admissions', 'Taux d’acceptation (%)', $a['taux_acceptation']]; $l[] = ['Admissions', 'Taux de conversion (%)', $a['taux_conversion']]; $l[] = ['Admissions', 'Délai moyen de décision (jours)', $a['delai_jours'] ?? '']; }
    if ($e = $d['effectifs']) { $l[] = ['Effectifs', 'Étudiants actifs', $e['actifs']]; foreach ($e['par_pole'] as $k => $v) $l[] = ['Effectifs', 'Actifs — ' . (GVB_POLES[$k] ?? $k), $v]; foreach ($d['evolution'] ?? [] as $an => $v) $l[] = ['Évolution', 'Inscriptions ' . $an, $v['inscriptions'] ?? 0]; }
    if ($f = $d['finances']) { $l[] = ['Finances', 'Attendu', $f['attendu']]; $l[] = ['Finances', 'Encaissé', $f['encaisse']]; $l[] = ['Finances', 'Recouvrement (%)', $f['recouvrement']]; $l[] = ['Finances', 'En retard', $f['retard']]; $l[] = ['Finances', 'Étudiants en retard', $f['nb_retard']]; foreach ($f['vieillissement'] as $k => $v) $l[] = ['Finances', 'Impayés ' . $k, $v]; foreach ($f['mensuel'] as $k => $v) $l[] = ['Encaissements', $k, $v]; }
    if ($p = $d['pedagogie']) { $l[] = ['Pédagogie', 'Taux de présence 30 j (%)', $p['presence_taux'] ?? '']; $l[] = ['Pédagogie', 'Séances tenues 30 j', $p['tenues']]; $l[] = ['Pédagogie', 'Séances à régulariser', $p['a_regulariser']]; $l[] = ['Pédagogie', 'Heures effectuées', $p['heures_effectuees']]; $l[] = ['Pédagogie', 'Heures affectées', $p['heures_affectees']]; $l[] = ['Pédagogie', 'Cours couverts', $p['cours_couverts'] . '/' . $p['cours']]; }
    if ($c = $d['espaces']) { $l[] = ['Espaces', 'Comptes actifs', $c['actives'] . '/' . $c['total']]; $l[] = ['Espaces', 'Connectés 30 j', $c['connectes_30j']]; $l[] = ['Espaces', 'Notifications envoyées 30 j', $c['notifs_envoyees']]; }
    return $l;
}
add_action('admin_post_gvb_export_pilotage', function () {
    gvb_garde('pilotage', 'exporter', 'gvb_export_pilotage');
    $f = gvb_filtres_pilotage(); $d = gvb_pilotage_donnees($f['annee'], $f['pole']);
    $neutre = fn($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v;
    nocache_headers(); header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="pilotage-' . gmdate('Ymd') . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF"); fputcsv($o, ['Rubrique', 'Indicateur', 'Valeur', 'Année', 'Pôle'], ';');
    foreach (gvb_lignes_pilotage($d) as [$r, $i, $v]) fputcsv($o, array_map($neutre, [$r, $i, $v, $f['annee'], $d['pole'] ? (GVB_POLES[$d['pole']] ?? '') : 'Tous']), ';');
    gvb_log(0, 'Export du pilotage', $f['annee']);
    gvb_terminer();
});
