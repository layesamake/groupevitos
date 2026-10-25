<?php
/**
 * Plugin Name: Groupe Vitos — Back-office : préférences, résumés par e-mail, aide contextuelle, guide de première connexion
 * Description: Chaque utilisateur règle ses résumés (quotidien ou hebdomadaire), l'aide des écrans et revoit le guide de son profil. La Direction fixe les valeurs par défaut.
 */

if (!defined('ABSPATH')) exit;

const GVB_FREQUENCES = ['aucun' => 'Aucun résumé', 'quotidien' => 'Chaque matin (jours ouvrés)', 'hebdo' => 'Chaque lundi'];

// ── Préférences et valeurs par défaut ─────────────────────────────────────────
function gvb_resumes_reglages() { return wp_parse_args(get_option('gvb_resumes', []), ['actif' => 1, 'defauts' => []]); }
function gvb_resume_frequence($uid) {
    $u = get_userdata($uid); if (!$u) return 'aucun';
    $m = get_user_meta($uid, 'gvb_resume', true);
    if (isset(GVB_FREQUENCES[$m])) return $m;
    $d = gvb_resumes_reglages()['defauts'][gvb_profil_utilisateur($u)] ?? 'aucun';
    return isset(GVB_FREQUENCES[$d]) ? $d : 'aucun';
}

// ── Construction et envoi des résumés ─────────────────────────────────────────
function gvb_resume_construire($user, $force = false, $hebdo = false) {
    $prev = get_current_user_id(); wp_set_current_user($user->ID);
    try {
        $taches = array_filter(gvb_taches(), fn($t) => $t[1] > 0); $lignes = [];
        foreach ($taches as $t) $lignes[] = ' • ' . $t[1] . ' — ' . $t[0] . "\n   " . $t[2];
        $kpi = [];
        if ($hebdo && gvb_can('pilotage', 'voir')) {
            $d = gvb_pilotage_donnees(gvb_reglages()['annee']);
            if ($d['finances']) { $kpi[] = ' • Recouvrement : ' . $d['finances']['recouvrement'] . ' % (' . gvb_montant($d['finances']['encaisse']) . ' encaissés sur ' . gvb_montant($d['finances']['attendu']) . ')'; $kpi[] = ' • Impayés : ' . gvb_montant($d['finances']['retard']) . ' (' . $d['finances']['nb_retard'] . ' étudiant(s))'; }
            if ($d['effectifs']) $kpi[] = ' • Étudiants actifs : ' . $d['effectifs']['actifs'];
            if ($d['admissions']) $kpi[] = ' • Demandes : ' . $d['admissions']['total'] . ' dont ' . $d['admissions']['inscrites'] . ' inscrites (' . $d['admissions']['taux_conversion'] . ' %)';
            if ($d['pedagogie'] && $d['pedagogie']['presence_taux'] !== null) $kpi[] = ' • Présence (30 jours) : ' . $d['pedagogie']['presence_taux'] . ' %';
        }
        if (!$lignes && !$kpi && !$force) return null;
        $pole = gvb_pole_utilisateur();
        $corps = 'Bonjour ' . ($user->first_name ?: $user->display_name) . ",\n\nVoici votre résumé du " . gvb_d(gvb_aujourdhui()) . ' — ' . (gvb_profil_libelle($user) ?: 'Groupe VITO S.') . ($pole && $pole !== '__aucun__' ? ' · pôle ' . GVB_POLES[$pole] : '') . ".\n\n";
        $corps .= $lignes ? "À TRAITER\n" . implode("\n", $lignes) . "\n\n" : "Rien d'urgent à traiter : tout est à jour.\n\n";
        if ($kpi) $corps .= "INDICATEURS DE LA SEMAINE\n" . implode("\n", $kpi) . "\n\n";
        $corps .= 'Ouvrir votre accueil : ' . gvb_url('gv');
        return [($hebdo ? 'Votre résumé de la semaine' : 'Votre résumé du jour') . ' — Groupe VITO S.', $corps];
    } finally { wp_set_current_user($prev); }
}
function gvb_resumes_envoyer($date = null) {
    if (!gvb_resumes_reglages()['actif']) return 0;
    $date = $date ?: gvb_aujourdhui(); $jour = (int) gmdate('N', strtotime($date)); $n = 0;
    if ($jour > 5) return 0;
    foreach (get_users(['fields' => 'ID']) as $uid) {
        $u = get_userdata($uid);
        if (!$u || !user_can($u, 'gv_acces') || !is_email($u->user_email)) continue;
        $f = gvb_resume_frequence($uid);
        if ($f === 'aucun' || ($f === 'hebdo' && $jour !== 1) || get_user_meta($uid, 'gvb_resume_dernier', true) === $date) continue;
        $r = gvb_resume_construire($u, false, $f === 'hebdo');
        update_user_meta($uid, 'gvb_resume_dernier', $date);
        if (!$r) continue;
        if (gvb_notif_ajouter('resume:' . $date . ':' . $uid, $u->user_email, $r[0], $r[1], 'resume')) $n++;
    }
    if ($n) gvb_notifs_traiter(40);
    return $n;
}
add_action('init', function () { if (!wp_next_scheduled('gvb_resumes_cron')) wp_schedule_event(time() + 600, 'hourly', 'gvb_resumes_cron'); }, 20);
add_action('gvb_resumes_cron', function () { if ((int) current_time('G') >= 7) gvb_resumes_envoyer(); });

// ── Aide contextuelle ─────────────────────────────────────────────────────────
function gvb_aide_textes() {
    return [
        'gv' => ['Votre accueil regroupe les chiffres et les tâches de votre profil. Chaque nombre est un lien vers la liste concernée. Utilisez « ☆ Épingler » sur un écran pour le retrouver ici.', ['gv_direction' => 'Vous pouvez choisir les blocs affichés pour chaque profil dans Administration → Réglages → Accueil par profil.']],
        'gv-pilotage' => ['Indicateurs de direction : admissions, effectifs, finances, présence, espaces en ligne. Filtrez par année et par pôle, exportez ou imprimez pour vos réunions.', []],
        'gv-admissions' => ['Toutes les demandes d’admission, reçues du site ou saisies à l’agence. Ouvrez un dossier pour contrôler les pièces, décider et inscrire.', ['gv_scolarite' => 'Vous saisissez les demandes et suivez les pièces ; la décision revient aux responsables de pôle et à la Direction.', 'gv_responsable_pole' => 'Vous décidez (accepter, refuser, liste d’attente) pour les demandes de votre pôle.']],
        'gv-demande' => ['Dossier d’un candidat : identité, pièces reçues, décision et historique. Une demande acceptée peut être inscrite (frais, matricule).', []],
        'gv-inscriptions' => ['Liste des inscriptions de l’année. L’annulation (avec motif) est réservée à la Direction ; elle rend l’étudiant inactif.', []],
        'gv-inscription' => ['Fiche d’inscription imprimable.', []],
        'gv-etudiants' => ['Les étudiants et élèves inscrits, avec leur matricule permanent. La fiche donne le parcours, la présence, le compte financier et les accès à l’espace en ligne.', []],
        'gv-etudiant' => ['Fiche d’un étudiant : coordonnées, statut, parcours, réinscription, présence, compte financier et accès famille.', []],
        'gv-absences' => ['Taux de présence et absences par étudiant sur une période. Le symbole ⚠ signale au moins 3 absences non excusées.', []],
        'gv-programmes' => ['Les cours (maquette par pôle, formation et niveau) et leurs enseignants. La barre « Affectation » montre si toutes les heures du cours sont couvertes.', ['gv_responsable_pole' => 'Vous affectez les enseignants aux cours de votre pôle.']],
        'gv-enseignants' => ['Les enseignants permanents et vacataires. Vous pouvez créer un enseignant à partir d’une candidature retenue (Direction).', []],
        'gv-enseignant' => ['Fiche d’un enseignant : affectations, heures planifiées et effectuées, accès à l’espace enseignant.', []],
        'gv-charges' => ['Heures affectées, planifiées et effectuées par enseignant, et montant à rémunérer pour la Direction et la Comptabilité.', []],
        'gv-planning' => ['Emploi du temps par semaine. Planifiez une séance (ou une série hebdomadaire) : les conflits d’enseignant, de salle et de groupe sont détectés. Faites l’appel depuis la séance.', []],
        'gv-seance' => ['Appel d’une séance : présent, en retard, absent ou excusé. Enregistrer l’appel marque la séance comme tenue et prévient les parents des absences.', []],
        'gv-salles' => ['Les salles et leur capacité. Une alerte apparaît si un groupe dépasse la capacité.', []],
        'gv-finances' => ['Vue d’ensemble : attendu, encaissé, reste, retards, et journal des paiements avec export.', ['gv_comptabilite' => 'Pour encaisser : « + Nouveau » → Enregistrer un paiement, puis choisissez l’étudiant.']],
        'gv-compte' => ['Compte financier d’un étudiant : échéancier, paiements imputés par ordre d’échéance, reçus. Seule la Direction peut annuler un paiement.', []],
        'gv-recu' => ['Reçu numéroté, imprimable. Un reçu annulé reste conservé et marqué comme tel.', []],
        'gv-impayes' => ['Étudiants avec des échéances échues non réglées. Relancez par e-mail (une fois par semaine au plus) ou par WhatsApp, puis notez la relance.', []],
        'gv-publications' => ['Publiez ou programmez un message, des photos et un lien sur la page Facebook. Vous pouvez partir d’une formation ou d’une actualité du site.', []],
        'gv-annonces' => ['Annonces visibles dans l’espace en ligne des familles ou des enseignants, avec envoi facultatif par e-mail. Ciblez par pôle et par niveau.', []],
        'gv-notifs' => ['File d’envoi des notifications par e-mail (absences, séances, reçus, annonces). Réessayez les échecs ; le plafond est de 250 envois par jour.', []],
        'gv-contacts' => ['Visiteurs ayant demandé la plaquette par e-mail ou WhatsApp. Pour WhatsApp, envoyez le message prérempli puis notez-le comme envoyé.', []],
        'gv-espaces' => ['Comptes des familles, étudiants et enseignants. Les accès se créent sur invitation : la personne reçoit un lien pour choisir son mot de passe.', []],
        'gv-reglages' => ['Réglages de l’établissement : année en cours, e-mails, Brevo, Facebook, échéancier, notifications, résumés et accueil par profil.', []],
        'gv-recherche' => ['Cherchez un nom, un matricule, un code de demande, un téléphone, un e-mail ou un numéro de reçu : les résultats se limitent à ce que votre profil peut voir.', []],
        'gv-messages' => ['Messages envoyés depuis le formulaire de la page Contact du site. Ouvrez un message pour le lire, répondre depuis votre messagerie, puis le marquer comme traité ou indésirable.', []],
        'gv-message' => ['Détail d’un message : coordonnées, objet et texte. Aucune réponse automatique n’est envoyée au visiteur.', []],
        'gv-tutoriels' => ['Tutoriels pas à pas, avec captures d’écran, pour chaque profil. Ouvrez celui de votre profil pour apprendre les tâches courantes ; vous pouvez l’imprimer.', []],
        'gv-preferences' => ['Vos réglages personnels : résumés par e-mail, aide et guide de bienvenue.', []],
    ];
}
add_action('gvb_debut_apres', function ($titre) {
    $p = isset($_GET['page']) ? sanitize_key($_GET['page']) : ''; $txt = gvb_aide_textes();
    if (!isset($txt[$p]) || get_user_meta(get_current_user_id(), 'gvb_aide_off', true)) return;
    [$base, $profils] = $txt[$p]; $profil = gvb_profil_utilisateur(); $vues = get_user_meta(get_current_user_id(), 'gvb_aide_vue', true); $vues = is_array($vues) ? $vues : [];
    $premiere = !in_array($p, $vues, true); if ($premiere) update_user_meta(get_current_user_id(), 'gvb_aide_vue', array_slice(array_merge($vues, [$p]), -60));
    echo '<details class="gvb-aide"' . ($premiere ? ' open' : '') . '><summary>' . gvb_icone('etoile', 14) . ' À quoi sert cet écran ?</summary><div class="gvb-aide-corps"><p>' . esc_html($base) . '</p>' . (!empty($profils[$profil]) ? '<p><strong>Pour votre profil :</strong> ' . esc_html($profils[$profil]) . '</p>' : '') . '<p class="gvb-rappel"><a href="' . esc_url(gvb_url('gv-preferences')) . '">Masquer l’aide ou revoir le guide de bienvenue</a></p></div></details>';
});

// ── Guide de première connexion ───────────────────────────────────────────────
function gvb_guide_etapes($profil) {
    $g = [
        'gv_direction' => [['Pilotage', 'Suivez admissions, effectifs, finances et présence, avec les alertes « à traiter ».', 'gv-pilotage'], ['Réglages', 'Année en cours, e-mails, échéancier, notifications et accueil de chaque profil.', 'gv-reglages'], ['Utilisateurs', 'Créez les comptes de vos équipes et attribuez-leur un profil.', 'users.php'], ['Recherche et raccourcis', 'Tapez un nom ou un matricule dans la barre du haut ; épinglez vos écrans favoris.', 'gv-recherche']],
        'gv_scolarite' => [['Admissions', 'Traitez les nouvelles demandes et relancez les dossiers incomplets.', 'gv-admissions'], ['Inscriptions et étudiants', 'Inscrivez les candidats acceptés ; la fiche étudiante se crée seule.', 'gv-etudiants'], ['Emploi du temps et appel', 'Planifiez les séances et faites l’appel : les parents sont prévenus des absences.', 'gv-planning'], ['Recherche et « + Nouveau »', 'Retrouvez un dossier en tapant un nom ou un matricule dans la barre du haut.', 'gv-recherche']],
        'gv_comptabilite' => [['Finances', 'Suivez l’encaissé, les échéances de la semaine et les impayés.', 'gv-finances'], ['Enregistrer un paiement', '« + Nouveau » → Enregistrer un paiement, puis choisissez l’étudiant : le reçu est créé automatiquement.', 'gv-recherche'], ['Impayés et relances', 'Relancez par e-mail ou WhatsApp les étudiants en retard.', 'gv-impayes']],
        'gv_responsable_pole' => [['Admissions de votre pôle', 'Décidez des demandes : accepter, refuser ou liste d’attente.', 'gv-admissions'], ['Programmes', 'Affectez les enseignants aux cours de votre pôle.', 'gv-programmes'], ['Emploi du temps', 'Contrôlez le planning et les appels de votre pôle.', 'gv-planning']],
        'gv_communication' => [['Publications Facebook', 'Rédigez, programmez et suivez vos publications ; partez d’une formation ou d’une actualité.', 'gv-publications'], ['Actualités du site', 'Rédigez les articles depuis le menu « Actualités ».', 'edit.php'], ['Annonces', 'Informez les familles ou les enseignants dans leur espace en ligne et par e-mail.', 'gv-annonces']],
    ];
    return $g[$profil] ?? [];
}
add_action('gvb_accueil_avant', function ($profil) {
    $uid = get_current_user_id(); if (get_user_meta($uid, 'gvb_guide_vu', true)) return;
    $e = gvb_guide_etapes($profil); if (!$e) return;
    $l = ''; foreach ($e as $i => [$t, $d, $slug]) $l .= '<li><strong>' . esc_html($t) . '</strong> — ' . esc_html($d) . ' <a href="' . esc_url(str_ends_with($slug, '.php') ? admin_url($slug) : gvb_url($slug)) . '">Ouvrir →</a></li>';
    echo '<div class="gvb-boite gvb-guide"><h2>' . gvb_icone('etoile', 18) . 'Bienvenue, voici l’essentiel pour votre profil</h2><ol>' . $l . '</ol>' . gvb_form('gvb_guide_ok', 'gvb_guide_ok', [], 'class="gvb-inline"') . gvb_bouton('J’ai compris, masquer ce guide') . '</form><p class="gvb-rappel">Vous pouvez le revoir à tout moment dans « Mes préférences ».</p></div>';
});
add_action('admin_post_gvb_guide_ok', function () {
    check_admin_referer('gvb_guide_ok');
    if (!current_user_can('gv_acces')) wp_die('Accès refusé.', 'Accès refusé', ['response' => 403, 'back_link' => false]);
    update_user_meta(get_current_user_id(), 'gvb_guide_vu', 1);
    gvb_retour('gv', [], 'Guide masqué. Il reste disponible dans « Mes préférences ».');
});

// ── Page « Mes préférences » ──────────────────────────────────────────────────
function gvb_page_preferences() {
    if (!current_user_can('gv_acces')) wp_die('Accès refusé.', 'Accès refusé', ['response' => 403, 'back_link' => false]);
    $uid = get_current_user_id(); $f = gvb_resume_frequence($uid); $actif = gvb_resumes_reglages()['actif'];
    gvb_debut('Mes préférences', 'reglage');
    echo '<div class="gvb-boite" style="max-width:720px"><h2>' . gvb_icone('alerte', 18) . 'Résumé par e-mail</h2>';
    if (!$actif) echo '<p class="gvb-rappel">Les résumés sont désactivés par la Direction.</p>';
    echo gvb_form('gvb_prefs_sauver', 'gvb_prefs_sauver') . gvb_liste('frequence', 'Recevoir sur ' . esc_html(wp_get_current_user()->user_email), GVB_FREQUENCES, $f) . '<p class="gvb-rappel">Le résumé liste ce qui est « à traiter » pour votre profil (et, le lundi, les indicateurs de la semaine pour ceux qui ont accès au pilotage). Il ne contient ni nom d’étudiant ni montant individuel.</p>'
        . '<label class="gvb-cases"><input type="checkbox" name="aide_off" value="1"' . checked((bool) get_user_meta($uid, 'gvb_aide_off', true), true, false) . '> Masquer l’aide « À quoi sert cet écran ? »</label>' . gvb_bouton('Enregistrer') . '</form>'
        . gvb_form('gvb_prefs_action', 'gvb_prefs_action', ['op' => 'tester'], 'style="margin-top:10px"') . gvb_bouton('M’envoyer un résumé maintenant', false) . '</form>'
        . gvb_form('gvb_prefs_action', 'gvb_prefs_action', ['op' => 'guide'], 'style="margin-top:6px"') . gvb_bouton('Revoir le guide de bienvenue', false) . '</form>'
        . gvb_form('gvb_prefs_action', 'gvb_prefs_action', ['op' => 'epingles'], 'style="margin-top:6px"') . gvb_bouton('Effacer mes raccourcis et écrans récents', false) . '</form></div>';
    gvb_fin();
}
add_action('admin_post_gvb_prefs_sauver', function () {
    check_admin_referer('gvb_prefs_sauver');
    if (!current_user_can('gv_acces')) wp_die('Accès refusé.', 'Accès refusé', ['response' => 403, 'back_link' => false]);
    $uid = get_current_user_id(); $f = sanitize_key($_POST['frequence'] ?? '');
    if (!isset(GVB_FREQUENCES[$f])) gvb_retour('gv-preferences', [], null, 'Fréquence invalide.');
    update_user_meta($uid, 'gvb_resume', $f); update_user_meta($uid, 'gvb_aide_off', empty($_POST['aide_off']) ? 0 : 1);
    gvb_retour('gv-preferences', [], 'Préférences enregistrées.');
});
add_action('admin_post_gvb_prefs_action', function () {
    check_admin_referer('gvb_prefs_action');
    if (!current_user_can('gv_acces')) wp_die('Accès refusé.', 'Accès refusé', ['response' => 403, 'back_link' => false]);
    $uid = get_current_user_id(); $op = sanitize_key($_POST['op'] ?? ''); $u = wp_get_current_user();
    if ($op === 'guide') { delete_user_meta($uid, 'gvb_guide_vu'); delete_user_meta($uid, 'gvb_aide_vue'); gvb_retour('gv', [], 'Le guide de bienvenue est de retour sur votre accueil.'); }
    if ($op === 'epingles') { delete_user_meta($uid, 'gvb_epingles'); delete_user_meta($uid, 'gvb_recents'); gvb_retour('gv-preferences', [], 'Raccourcis et récents effacés.'); }
    if ($op === 'tester') {
        if (!is_email($u->user_email)) gvb_retour('gv-preferences', [], null, 'Votre compte n’a pas d’adresse e-mail valide.');
        $r = gvb_resume_construire($u, true, gvb_resume_frequence($uid) === 'hebdo');
        gvb_notif_ajouter('resume-test:' . $uid . ':' . time(), $u->user_email, $r[0], $r[1], 'resume'); gvb_notifs_traiter(5);
        gvb_retour('gv-preferences', [], 'Résumé envoyé à ' . $u->user_email . '.');
    }
    gvb_retour('gv-preferences', [], null, 'Action inconnue.');
});
add_action('admin_bar_menu', function ($bar) { if ((is_admin() || defined('GVB_ESSAI')) && current_user_can('gv_acces')) $bar->add_node(['id' => 'gvb-prefs', 'parent' => 'user-actions', 'title' => 'Mes préférences', 'href' => gvb_url('gv-preferences')]); }, 100);

// ── Réglages de la Direction ──────────────────────────────────────────────────
add_action('gvb_reglages_apres', function () {
    if (!gvb_can('parametres', 'modifier')) return;
    $r = gvb_resumes_reglages();
    $l = ''; foreach (GVB_PROFILS as $slug => $label) $l .= gvb_liste('defaut[' . $slug . ']', $label, GVB_FREQUENCES, $r['defauts'][$slug] ?? 'aucun');
    echo '<div class="gvb-boite" style="max-width:640px"><h2>' . gvb_icone('alerte', 18) . 'Résumés par e-mail</h2>' . gvb_form('gvb_resumes_sauver', 'gvb_resumes_sauver')
        . '<label class="gvb-cases"><input type="checkbox" name="actif" value="1"' . checked($r['actif'], 1, false) . '> Activer les résumés par e-mail</label><p class="gvb-rappel">Fréquence par défaut de chaque profil. Chaque utilisateur peut la modifier dans « Mes préférences » (menu de votre nom, en haut à droite).</p><div class="gvb-champs">' . $l . '</div>' . gvb_bouton('Enregistrer') . '</form></div>';
});
add_action('admin_post_gvb_resumes_sauver', function () {
    gvb_garde('parametres', 'modifier', 'gvb_resumes_sauver');
    $d = []; foreach (GVB_PROFILS as $slug => $_) { $v = sanitize_key($_POST['defaut'][$slug] ?? 'aucun'); $d[$slug] = isset(GVB_FREQUENCES[$v]) ? $v : 'aucun'; }
    update_option('gvb_resumes', ['actif' => empty($_POST['actif']) ? 0 : 1, 'defauts' => $d], false);
    gvb_log(0, 'Résumés par e-mail : réglages modifiés');
    gvb_retour('gv-reglages', [], 'Réglages des résumés enregistrés.');
});
add_action('admin_head', function () {
    echo '<style>.gvb-aide{margin:0 0 14px;border:1px solid #D6DCEB;border-left:5px solid #FFC23D;border-radius:8px;background:#fff;padding:2px 14px}.gvb-aide summary{cursor:pointer;padding:8px 0;font-weight:600;color:#08065B}.gvb-aide-corps{padding:0 0 8px}.gvb-aide-corps p{margin:.3em 0}.gvb-guide{border-top-color:#007A2E}.gvb-guide li{margin:6px 0}@media print{.gvb-aide,.gvb-guide{display:none}}</style>';
});
