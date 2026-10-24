<?php
/**
 * Plugin Name: Groupe Vitos — Back-office : accueil par profil et tâches
 * Description: Page d'accueil composée de blocs selon le profil (personnalisable par la Direction), liste « à traiter » et compteurs de menu.
 */

if (!defined('ABSPATH')) exit;

// ── Profil de l'utilisateur ───────────────────────────────────────────────────
function gvb_profil_utilisateur($user = null) {
    $u = $user ?: wp_get_current_user();
    foreach (array_keys(GVB_PROFILS) as $slug) if (in_array($slug, (array) $u->roles, true)) return $slug;
    return user_can($u, 'manage_options') ? 'gv_direction' : '';
}
function gvb_profil_libelle($user = null) {
    $p = gvb_profil_utilisateur($user); $u = $user ?: wp_get_current_user();
    return $p ? (GVB_PROFILS[$p] . (user_can($u, 'manage_options') && !in_array($p, (array) $u->roles, true) ? ' (administrateur)' : '')) : '';
}

// ── Tâches « à traiter » (comptages légers, mis en cache 2 minutes) ───────────
function gvb_taches() {
    $cle = 'gvb_taches_' . get_current_user_id();
    if (!defined('GVB_ESSAI') && ($c = get_transient($cle)) !== false) return $c;
    global $wpdb; $T = fn($n) => gvb_table($n); $pole = gvb_pole_utilisateur(); $annee = gvb_reglages()['annee']; $il = [];
    if ($pole === '__aucun__') return [];
    $pw = $pole !== '' ? $wpdb->prepare(' AND pole = %s', $pole) : '';
    $n = fn($sql) => (int) $wpdb->get_var($sql);
    if (gvb_can('admissions', 'modifier')) {
        $base = 'SELECT COUNT(*) FROM ' . $T('demandes') . ' WHERE annee = ' . $wpdb->prepare('%s', $annee) . $pw;
        $il['nouvelles'] = ['Nouvelles demandes à prendre en charge', $n($base . " AND statut = 'nouvelle'"), gvb_url('gv-admissions', ['statut' => 'nouvelle', 'annee' => $annee])];
        $il['attente'] = ['Demandes en attente depuis plus de 7 jours', $n($base . " AND statut IN ('nouvelle','etude') AND cree_le < " . $wpdb->prepare('%s', gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS))), gvb_url('gv-admissions', ['statut' => 'etude', 'annee' => $annee])];
        $il['incompletes'] = ['Dossiers incomplets à relancer', $n($base . " AND statut = 'incomplete'"), gvb_url('gv-admissions', ['statut' => 'incomplete', 'annee' => $annee])];
        $il['a_inscrire'] = ['Candidats acceptés à inscrire', $n($base . " AND statut = 'acceptee'"), gvb_url('gv-admissions', ['statut' => 'acceptee', 'annee' => $annee])];
        $il['doublons'] = ['Doublons probables à vérifier', $n($base . " AND doublon_de > 0 AND statut NOT IN ('refusee','abandonnee','inscrite')"), gvb_url('gv-admissions', ['annee' => $annee])];
    }
    if (gvb_can('planning', 'modifier')) $il['sans_appel'] = ['Séances passées sans appel ni annulation', $n('SELECT COUNT(*) FROM ' . $T('seances') . " WHERE statut = 'planifiee' AND date_seance < " . $wpdb->prepare('%s', gvb_aujourdhui()) . $pw), gvb_url('gv-planning')];
    if (gvb_can('pedagogie', 'modifier')) {
        $nc = 0; foreach ($wpdb->get_results('SELECT id, volume FROM ' . $T('cours') . ' WHERE actif = 1' . $pw) as $k) if (gvb_heures_affectees_cours($k->id, $annee) < $k->volume) $nc++;
        $il['non_couverts'] = ['Cours dont les heures ne sont pas toutes affectées', $nc, gvb_url('gv-programmes')];
    }
    if (gvb_can('finances', 'modifier')) { $imp = gvb_liste_impayes($pole); $il['impayes'] = ['Étudiants avec des échéances en retard', count($imp), gvb_url('gv-impayes')]; }
    if (gvb_can('espaces', 'modifier')) {
        $il['notifs_echec'] = ['Notifications en échec d’envoi', $n('SELECT COUNT(*) FROM ' . $T('notifs') . " WHERE statut = 'echec'"), gvb_url('gv-notifs', ['statut' => 'echec'])];
        if ($pole === '') $il['invitations'] = ['Invitations non activées depuis plus de 7 jours', $n('SELECT COUNT(*) FROM ' . $T('comptes') . " WHERE mdp = '' AND cree_le < " . $wpdb->prepare('%s', gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS))), gvb_url('gv-espaces')];
    }
    if (gvb_can('publications', 'creer')) $il['pub_echec'] = ['Publications Facebook en échec (7 jours)', $n('SELECT COUNT(*) FROM ' . $T('publications') . " WHERE statut = 'echec' AND cree_le > " . $wpdb->prepare('%s', gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS))), gvb_url('gv-publications')];
    if (!defined('GVB_ESSAI')) set_transient($cle, $il, 120);
    return $il;
}

// ── Catalogue de blocs ────────────────────────────────────────────────────────
// id => [titre, icône, droit requis (domaine, action), fonction d'affichage]
function gvb_blocs_catalogue() {
    return [
        'taches' => ['À traiter', 'alerte', ['gv_acces'], 'gvb_bloc_taches'],
        'raccourcis' => ['Raccourcis', 'plus', ['gv_acces'], 'gvb_bloc_raccourcis'],
        'pilotage_resume' => ['Indicateurs clés', 'rapport', ['pilotage', 'voir'], 'gvb_bloc_pilotage'],
        'admissions' => ['Admissions', 'admission', ['admissions', 'voir'], 'gvb_bloc_admissions'],
        'inscrits' => ['Inscrits et étudiants', 'pole', ['admissions', 'voir'], 'gvb_bloc_inscrits'],
        'recentes' => ['Dernières demandes', 'liste', ['admissions', 'voir'], 'gvb_bloc_recentes'],
        'finances_jour' => ['Finances', 'argent', ['finances', 'voir'], 'gvb_bloc_finances'],
        'pedagogie_semaine' => ['Aujourd’hui en classe', 'horloge', ['planning', 'voir'], 'gvb_bloc_pedagogie'],
        'communication' => ['Communication', 'rapport', ['publications', 'voir'], 'gvb_bloc_communication'],
        'espaces' => ['Espaces en ligne', 'utilisateurs', ['espaces', 'voir'], 'gvb_bloc_espaces'],
        'annonces_recentes' => ['Dernières annonces', 'journal', ['espaces', 'voir'], 'gvb_bloc_annonces'],
        'epingles' => ['Mes raccourcis', 'etoile', ['gv_acces'], 'gvb_bloc_epingles'],
    ];
}
function gvb_bloc_autorise($id) {
    $cat = gvb_blocs_catalogue();
    if (!isset($cat[$id])) return false;
    $r = $cat[$id][2];
    return count($r) === 1 ? current_user_can($r[0]) : gvb_can($r[0], $r[1]);
}
function gvb_bloc_autorise_pour($id, $user) {
    $cat = gvb_blocs_catalogue();
    if (!isset($cat[$id])) return false;
    $r = $cat[$id][2];
    return user_can($user, count($r) === 1 ? $r[0] : "gv_{$r[0]}_{$r[1]}");
}
function gvb_accueil_defauts($profil) {
    $d = [
        'gv_direction' => ['taches', 'pilotage_resume', 'finances_jour', 'inscrits', 'communication', 'epingles'],
        'gv_scolarite' => ['taches', 'admissions', 'inscrits', 'pedagogie_semaine', 'raccourcis', 'epingles'],
        'gv_comptabilite' => ['taches', 'finances_jour', 'raccourcis', 'epingles'],
        'gv_responsable_pole' => ['taches', 'admissions', 'pedagogie_semaine', 'inscrits', 'epingles'],
        'gv_communication' => ['communication', 'taches', 'annonces_recentes', 'raccourcis', 'epingles'],
    ];
    return $d[$profil] ?? ['taches'];
}
function gvb_accueil_config($profil) {
    $o = get_option('gvb_accueil', []);
    $liste = isset($o[$profil]) && is_array($o[$profil]) ? $o[$profil] : gvb_accueil_defauts($profil);
    return array_values(array_unique(array_filter($liste, fn($b) => isset(gvb_blocs_catalogue()[$b]))));
}

function gvb_bloc_taches() {
    $l = '';
    foreach (gvb_taches() as $t) if ($t[1] > 0) $l .= '<li><a href="' . esc_url($t[2]) . '"><strong>' . (int) $t[1] . '</strong> — ' . esc_html($t[0]) . '</a></li>';
    return $l ? '<ul class="gvb-journal">' . $l . '</ul>' : '<p class="gvb-ok">Rien d’urgent : tout est à jour.</p>';
}
function gvb_bloc_raccourcis() {
    $b = [];
    if (gvb_can('admissions', 'creer')) $b[] = ['Nouvelle demande d’admission', gvb_url('gv-demande'), true];
    if (gvb_can('finances', 'creer')) $b[] = ['Enregistrer un paiement', gvb_url('gv-finances'), true];
    if (gvb_can('finances', 'voir')) $b[] = ['Impayés à relancer', gvb_url('gv-impayes'), false];
    if (gvb_can('planning', 'creer')) $b[] = ['Planifier une séance', gvb_url('gv-planning'), false];
    if (gvb_can('publications', 'creer')) $b[] = ['Nouvelle publication Facebook', gvb_url('gv-publications'), true];
    if (current_user_can('edit_posts')) $b[] = ['Nouvel article', admin_url('post-new.php'), false];
    if (gvb_can('espaces', 'creer')) $b[] = ['Nouvelle annonce', gvb_url('gv-annonces'), false];
    if (gvb_can('etudiants', 'voir')) $b[] = ['Chercher un étudiant', gvb_url('gv-etudiants'), false];
    $h = ''; foreach ($b as [$t, $u, $p]) $h .= '<a class="button' . ($p ? ' button-primary' : '') . '" href="' . esc_url($u) . '">' . esc_html($t) . '</a> ';
    return $h ? '<p class="gvb-actions">' . $h . '</p>' : '<p class="gvb-rappel">Aucun raccourci pour votre profil.</p>';
}
function gvb_bloc_pilotage() {
    $cle = 'gvb_pil_resume_' . md5(gvb_pole_utilisateur());
    $d = defined('GVB_ESSAI') ? false : get_transient($cle);
    if ($d === false) { $d = gvb_pilotage_donnees(gvb_reglages()['annee']); if (!defined('GVB_ESSAI')) set_transient($cle, $d, 600); }
    $h = '<div class="gvb-cartes">';
    if ($d['finances']) $h .= gvb_carte('Recouvrement', $d['finances']['recouvrement'] . ' %', gvb_montant($d['finances']['encaisse']) . ' encaissés', 'argent', 'ok', gvb_url('gv-finances')) . gvb_carte('Impayés', gvb_montant($d['finances']['retard']), $d['finances']['nb_retard'] . ' étudiant(s)', 'alerte', $d['finances']['retard'] ? 'attention' : '', gvb_url('gv-impayes'));
    if ($d['effectifs']) $h .= gvb_carte('Étudiants actifs', $d['effectifs']['actifs'], 'Tous pôles', 'pole', '', gvb_url('gv-etudiants'));
    if ($d['admissions']) $h .= gvb_carte('Conversion des demandes', $d['admissions']['taux_conversion'] . ' %', $d['admissions']['inscrites'] . ' inscrites sur ' . $d['admissions']['total'], 'inscription');
    if ($d['pedagogie'] && $d['pedagogie']['presence_taux'] !== null) $h .= gvb_carte('Présence (30 j)', $d['pedagogie']['presence_taux'] . ' %', $d['pedagogie']['absences'] . ' absence(s)', 'check', $d['pedagogie']['presence_taux'] < 80 ? 'attention' : 'ok', gvb_url('gv-absences'));
    return $h . '</div>' . (gvb_can('pilotage', 'voir') ? '<p><a href="' . esc_url(gvb_url('gv-pilotage')) . '">Ouvrir le pilotage complet →</a></p>' : '');
}
function gvb_bloc_admissions() {
    $k = gvb_indicateurs(); $s = $k['statuts']; $a = $k['annee'];
    return '<div class="gvb-cartes">' . gvb_carte('Nouvelles demandes', $s['nouvelle'], 'À prendre en charge', 'admission', $s['nouvelle'] ? 'attention' : '', gvb_url('gv-admissions', ['statut' => 'nouvelle', 'annee' => $a]))
        . gvb_carte('En étude', $s['etude'], $k['en_retard'] . ' en attente depuis plus de 7 jours', 'rapport', $k['en_retard'] ? 'attention' : '', gvb_url('gv-admissions', ['statut' => 'etude', 'annee' => $a]))
        . gvb_carte('Dossiers incomplets', $s['incomplete'], 'Pièces à relancer', 'alerte', $s['incomplete'] ? 'attention' : '', gvb_url('gv-admissions', ['statut' => 'incomplete', 'annee' => $a]))
        . gvb_carte('Acceptées à inscrire', $s['acceptee'], 'Prêtes pour l’inscription', 'inscription', '', gvb_url('gv-admissions', ['statut' => 'acceptee', 'annee' => $a])) . '</div>';
}
function gvb_bloc_inscrits() {
    $k = gvb_indicateurs();
    return '<div class="gvb-cartes">' . gvb_carte('Inscrits', $k['inscrits'], 'Année ' . $k['annee'], 'pole', 'ok', gvb_url('gv-inscriptions', ['annee' => $k['annee']]))
        . (gvb_can('etudiants', 'voir') ? gvb_carte('Étudiants actifs', gvb_nb_etudiants_actifs(), 'Tous niveaux', 'carte', '', gvb_url('gv-etudiants', ['statut' => 'actif'])) : '')
        . gvb_carte('Doublons probables', $k['doublons'], 'À vérifier', 'utilisateurs', $k['doublons'] ? 'attention' : '') . '</div>';
}
function gvb_bloc_recentes() {
    $l = [];
    foreach (gvb_requete_demandes(['annee' => '', 'pole' => '', 'statut' => '', 'q' => ''], 6) as $d) $l[] = '<tr><td><a href="' . esc_url(gvb_url('gv-demande', ['id' => $d->id])) . '">' . esc_html($d->code) . '</a></td><td>' . esc_html($d->prenom . ' ' . $d->nom) . '</td><td>' . esc_html(GVB_POLES[$d->pole] ?? '') . '</td><td>' . gvb_badge($d->statut) . '</td></tr>';
    return gvb_tableau(['Code', 'Candidat', 'Pôle', 'Statut'], $l, 'Aucune demande pour le moment.');
}
function gvb_bloc_finances() {
    global $wpdb; $pole = gvb_pole_utilisateur();
    $w = ['p.annule = 0']; $a = []; gvb_clause_pole($w, $a, 'e');
    $somme = function ($debut, $fin) use ($wpdb, $w, $a) { $w2 = array_merge($w, ['p.date_paiement BETWEEN %s AND %s']); return (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(p.montant),0) FROM ' . gvb_table('paiements') . ' p JOIN ' . gvb_table('etudiants') . ' e ON e.id = p.etudiant_id WHERE ' . implode(' AND ', $w2), array_merge($a, [$debut, $fin]))); };
    $auj = gvb_aujourdhui(); $sem = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(c.montant),0) FROM ' . gvb_table('charges') . ' c JOIN ' . gvb_table('etudiants') . ' e ON e.id = c.etudiant_id WHERE c.annule = 0 AND c.echeance BETWEEN %s AND %s' . ($pole !== '' ? $wpdb->prepare(' AND e.pole = %s', $pole) : ''), $auj, gmdate('Y-m-d', time() + 7 * DAY_IN_SECONDS)));
    $imp = gvb_liste_impayes($pole); $ret = array_sum(array_map(fn($e) => $e->compte['retard'], $imp));
    return '<div class="gvb-cartes">' . gvb_carte('Encaissé aujourd’hui', gvb_montant($somme($auj, $auj)), 'Paiements du jour', 'argent', 'ok', gvb_url('gv-finances')) . gvb_carte('Encaissé ce mois', gvb_montant($somme(gmdate('Y-m-01'), $auj)), 'Depuis le 1er', 'check', '', gvb_url('gv-finances'))
        . gvb_carte('Échéances (7 jours)', gvb_montant($sem), 'Montants à recevoir', 'horloge') . gvb_carte('Impayés', gvb_montant($ret), count($imp) . ' étudiant(s) en retard', 'alerte', $ret ? 'attention' : '', gvb_url('gv-impayes')) . '</div>';
}
function gvb_bloc_pedagogie() {
    global $wpdb; $pole = gvb_pole_utilisateur(); $auj = gvb_aujourdhui();
    $w = ['s.date_seance = %s']; $a = [$auj]; gvb_clause_pole($w, $a, 's');
    $rows = $wpdb->get_results($wpdb->prepare('SELECT s.*, c.intitule, e.nom AS e_nom FROM ' . gvb_table('seances') . ' s JOIN ' . gvb_table('cours') . ' c ON c.id = s.cours_id JOIN ' . gvb_table('enseignants') . ' e ON e.id = s.enseignant_id WHERE ' . implode(' AND ', $w) . ' ORDER BY s.debut LIMIT 12', $a));
    $l = array_map(fn($s) => '<tr' . ($s->statut === 'annulee' ? ' style="opacity:.5;text-decoration:line-through"' : '') . '><td><strong>' . esc_html(gvb_hhmm($s->debut) . '–' . gvb_hhmm($s->fin)) . '</strong></td><td>' . esc_html($s->intitule) . '</td><td>' . esc_html(gvb_groupe_libelle($s->pole, $s->formation_id, $s->niveau)) . '</td><td>' . esc_html(mb_strtoupper($s->e_nom)) . '</td><td>' . ($s->statut === 'planifiee' && gvb_can('planning', 'modifier') ? '<a class="button" href="' . esc_url(gvb_url('gv-seance', ['id' => $s->id])) . '">Appel</a>' : esc_html(GVB_SEANCE_STATUTS[$s->statut] ?? '')) . '</td></tr>', $rows);
    return gvb_tableau(['Horaire', 'Cours', 'Groupe', 'Enseignant', ''], $l, 'Aucune séance aujourd’hui.');
}
function gvb_bloc_communication() {
    global $wpdb; $t = gvb_table('publications'); $h = '<div class="gvb-cartes">';
    $h .= gvb_carte('Publications programmées', (int) $wpdb->get_var("SELECT COUNT(*) FROM $t WHERE statut = 'programmee'"), 'À venir sur Facebook', 'horloge', '', gvb_url('gv-publications'))
        . gvb_carte('Publiées (30 jours)', (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE statut = 'publiee' AND cree_le > %s", gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS))), 'Sur la page Facebook', 'rapport', 'ok', gvb_url('gv-publications'))
        . gvb_carte('Échecs (7 jours)', (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE statut = 'echec' AND cree_le > %s", gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS))), 'À reprendre', 'alerte', '', gvb_url('gv-publications'))
        . gvb_carte('Actualités en ligne', (int) wp_count_posts('post')->publish, 'Articles publiés sur le site', 'journal', '', admin_url('edit.php'));
    if (gvb_can('contacts', 'voir')) $h .= gvb_carte('Contacts plaquette (7 jours)', (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . gvb_table('contacts') . ' WHERE cree_le > %s', gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS))), 'Visiteurs ayant demandé la plaquette', 'utilisateurs', '', gvb_url('gv-contacts'));
    return $h . '</div>';
}
function gvb_bloc_espaces() {
    global $wpdb; $c = gvb_table('comptes'); $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $c"); $actifs = (int) $wpdb->get_var("SELECT COUNT(*) FROM $c WHERE mdp <> ''");
    return '<div class="gvb-cartes">' . gvb_carte('Comptes actifs', $actifs . ' / ' . $total, gvb_pct($actifs, $total) . ' % ont créé leur mot de passe', 'utilisateurs', '', gvb_url('gv-espaces')) . gvb_carte('Invitations en attente', $total - $actifs, 'Comptes sans mot de passe', 'horloge', '', gvb_url('gv-espaces'))
        . gvb_carte('Notifications en échec', (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . gvb_table('notifs') . " WHERE statut = 'echec'"), 'À réessayer', 'alerte', '', gvb_url('gv-notifs', ['statut' => 'echec'])) . '</div>';
}
function gvb_bloc_annonces() {
    global $wpdb; $l = '';
    foreach ($wpdb->get_results('SELECT * FROM ' . gvb_table('annonces') . ' WHERE archive = 0 ORDER BY id DESC LIMIT 3') as $a) $l .= '<li><strong>' . esc_html($a->titre) . '</strong> <small class="gvb-rappel">' . esc_html(gvb_d($a->publie_le)) . ' · ' . esc_html(GVB_AUDIENCES[$a->audience] ?? '') . '</small></li>';
    return ($l ? '<ul class="gvb-journal">' . $l . '</ul>' : '<p class="gvb-vide">Aucune annonce publiée.</p>') . '<p><a href="' . esc_url(gvb_url('gv-annonces')) . '">Toutes les annonces →</a></p>';
}

// ── Page d'accueil ────────────────────────────────────────────────────────────
function gvb_page_tableau() {
    if (!current_user_can('gv_acces')) wp_die('Accès refusé.', 'Accès refusé', ['response' => 403, 'back_link' => false]);
    $u = wp_get_current_user(); $profil = gvb_profil_utilisateur($u); $pole = gvb_pole_utilisateur(); $cat = gvb_blocs_catalogue();
    gvb_debut('Bonjour ' . $u->display_name, 'tableau');
    echo '<p class="gvb-rappel">' . esc_html(gvb_profil_libelle($u) ?: 'Profil non défini') . ($pole && $pole !== '__aucun__' ? ' · pôle ' . esc_html(GVB_POLES[$pole]) : '') . ' · année ' . esc_html(gvb_reglages()['annee']) . '</p>';
    $affiches = 0;
    foreach (gvb_accueil_config($profil) as $id) {
        if (!gvb_bloc_autorise($id)) continue;
        [$titre, $icone, , $fn] = $cat[$id];
        echo '<div class="gvb-boite gvb-bloc" data-bloc="' . esc_attr($id) . '"><h2>' . gvb_icone($icone, 18) . esc_html($titre) . '</h2>' . call_user_func($fn) . '</div>'; $affiches++;
    }
    if (!$affiches) echo '<div class="gvb-boite"><p>Aucun bloc n’est configuré pour votre profil, ou aucun module ne vous est attribué. Contactez la Direction.</p></div>';
    gvb_fin();
}

// ── Personnalisation par la Direction (Réglages) ──────────────────────────────
add_action('gvb_reglages_apres', function () {
    if (!gvb_can('parametres', 'modifier')) return;
    $cat = gvb_blocs_catalogue();
    echo '<div class="gvb-boite" style="max-width:900px"><h2>' . gvb_icone('tableau', 18) . 'Accueil par profil</h2><p class="gvb-rappel">Choisissez les blocs affichés sur la page d’accueil de chaque profil et leur ordre. Un bloc n’apparaît que si le profil a le droit d’en voir les données : cocher un bloc ne donne aucun droit supplémentaire.</p>';
    foreach (GVB_PROFILS as $slug => $label) {
        $actuels = gvb_accueil_config($slug); $role = get_role($slug);
        echo gvb_details($label, gvb_form('gvb_accueil_sauver', 'gvb_accueil_sauver', ['profil' => $slug]) . '<table class="widefat striped"><thead><tr><th>Afficher</th><th>Bloc</th><th>Ordre</th></tr></thead><tbody>' . (function () use ($cat, $actuels, $role) {
            $h = ''; $ordre = 1;
            $liste = array_merge($actuels, array_diff(array_keys($cat), $actuels));
            foreach ($liste as $id) {
                $r = $cat[$id][2]; $cap = count($r) === 1 ? $r[0] : "gv_{$r[0]}_{$r[1]}"; $a = $role && !empty($role->capabilities[$cap]);
                $h .= '<tr' . ($a ? '' : ' style="opacity:.5"') . '><td><input type="checkbox" name="blocs[' . esc_attr($id) . ']" value="1"' . checked(in_array($id, $actuels, true), true, false) . ($a ? '' : ' disabled') . '></td><td>' . esc_html($cat[$id][0]) . ($a ? '' : ' <small>(droit non attribué à ce profil)</small>') . '</td><td><input type="number" name="ordre[' . esc_attr($id) . ']" value="' . (in_array($id, $actuels, true) ? $ordre++ : 99) . '" min="1" max="99" style="width:70px"></td></tr>';
            }
            return $h;
        })() . '</tbody></table>' . gvb_bouton('Enregistrer cet accueil') . '</form>' . gvb_form('gvb_accueil_defaut', 'gvb_accueil_defaut', ['profil' => $slug], 'style="margin-top:6px"') . gvb_bouton('Rétablir l’accueil par défaut', false) . '</form>');
    }
    echo '</div>';
});
add_action('admin_post_gvb_accueil_sauver', function () {
    gvb_garde('parametres', 'modifier', 'gvb_accueil_sauver');
    $profil = sanitize_key($_POST['profil'] ?? '');
    if (!isset(GVB_PROFILS[$profil])) gvb_retour('gv-reglages', [], null, 'Profil inconnu.');
    $cat = gvb_blocs_catalogue(); $role = get_role($profil); $choisis = [];
    foreach ((array) ($_POST['blocs'] ?? []) as $id => $_) {
        $id = sanitize_key($id); if (!isset($cat[$id])) continue;
        $r = $cat[$id][2]; $cap = count($r) === 1 ? $r[0] : "gv_{$r[0]}_{$r[1]}";
        if ($role && !empty($role->capabilities[$cap])) $choisis[$id] = absint($_POST['ordre'][$id] ?? 99);
    }
    asort($choisis); $o = get_option('gvb_accueil', []); $o[$profil] = array_keys($choisis); update_option('gvb_accueil', $o, false);
    gvb_log(0, 'Accueil personnalisé', GVB_PROFILS[$profil] . ' : ' . implode(', ', array_keys($choisis)));
    gvb_retour('gv-reglages', [], 'Accueil « ' . GVB_PROFILS[$profil] . ' » enregistré.');
});
add_action('admin_post_gvb_accueil_defaut', function () {
    gvb_garde('parametres', 'modifier', 'gvb_accueil_defaut');
    $profil = sanitize_key($_POST['profil'] ?? '');
    if (!isset(GVB_PROFILS[$profil])) gvb_retour('gv-reglages', [], null, 'Profil inconnu.');
    $o = get_option('gvb_accueil', []); unset($o[$profil]); update_option('gvb_accueil', $o, false);
    gvb_log(0, 'Accueil rétabli par défaut', GVB_PROFILS[$profil]);
    gvb_retour('gv-reglages', [], 'Accueil « ' . GVB_PROFILS[$profil] . ' » rétabli par défaut.');
});
