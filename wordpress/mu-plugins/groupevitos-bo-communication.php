<?php
/**
 * Plugin Name: Groupe Vitos — Back-office : espaces en ligne, annonces et notifications
 * Description: Comptes du portail (familles, étudiants, enseignants), invitations, annonces ciblées, file d'envoi des notifications par e-mail.
 */

if (!defined('ABSPATH')) exit;

const GVB_TYPES_COMPTE = ['parent' => 'Parent / tuteur', 'etudiant' => 'Étudiant ou élève', 'enseignant' => 'Enseignant'];
const GVB_AUDIENCES = ['tous' => 'Tous (familles et enseignants)', 'familles' => 'Familles et étudiants', 'enseignants' => 'Enseignants'];
const GVB_NOTIFS_PAR_JOUR = 250;

function gvb_espace_url($page = '', $args = []) {
    $u = home_url('/espace/' . ($page !== '' ? trim($page, '/') . '/' : ''));
    return $args ? add_query_arg(array_map('rawurlencode', $args), $u) : $u;
}
function gvb_notifs_reglages() { return wp_parse_args(get_option('gvb_notifs', []), ['absence' => 1, 'seance' => 1, 'paiement' => 1]); }

// ── Comptes ───────────────────────────────────────────────────────────────────
function gvb_compte_portail($id) { global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . gvb_table('comptes') . ' WHERE id = %d', (int) $id)); }
function gvb_comptes_de($etudiant_id) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare('SELECT c.* FROM ' . gvb_table('comptes') . ' c JOIN ' . gvb_table('comptes_etudiants') . ' l ON l.compte_id = c.id WHERE l.etudiant_id = %d ORDER BY c.type, c.email', (int) $etudiant_id));
}
function gvb_etudiants_du_compte($compte_id) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare('SELECT e.* FROM ' . gvb_table('etudiants') . ' e JOIN ' . gvb_table('comptes_etudiants') . ' l ON l.etudiant_id = e.id WHERE l.compte_id = %d ORDER BY e.nom, e.prenom', (int) $compte_id));
}
function gvb_lier_etudiant($compte_id, $etudiant_id) {
    global $wpdb; $t = gvb_table('comptes_etudiants');
    if (!$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE compte_id = %d AND etudiant_id = %d", $compte_id, $etudiant_id))) $wpdb->insert($t, ['compte_id' => (int) $compte_id, 'etudiant_id' => (int) $etudiant_id]);
}
function gvb_creer_compte($type, $email, $nom, array $etudiants = [], $enseignant_id = 0) {
    global $wpdb;
    if (!isset(GVB_TYPES_COMPTE[$type])) return new WP_Error('type', 'Type de compte invalide.');
    $email = strtolower(sanitize_email($email));
    if (!is_email($email)) return new WP_Error('email', 'Adresse e-mail invalide.');
    $nom = mb_substr(sanitize_text_field($nom), 0, 160);
    $ens = null;
    if ($type === 'enseignant') {
        $ens = gvb_enseignant($enseignant_id);
        if (!$ens) return new WP_Error('ens', 'Enseignant introuvable.');
        if ($nom === '') $nom = $ens->prenom . ' ' . $ens->nom;
    } else {
        if (!$etudiants) return new WP_Error('etu', 'Indiquez au moins un étudiant à rattacher.');
        foreach ($etudiants as $eid) if (!gvb_etudiant($eid)) return new WP_Error('etu', 'Étudiant introuvable ou hors de votre périmètre.');
    }
    $existant = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . gvb_table('comptes') . ' WHERE LOWER(email) = LOWER(%s)', $email));
    if ($existant) {
        if ($existant->type !== $type) return new WP_Error('doublon', 'Cette adresse est déjà utilisée par un compte « ' . GVB_TYPES_COMPTE[$existant->type] . ' ».');
        if ($type === 'enseignant') return new WP_Error('doublon', 'Cet enseignant a déjà un compte.');
        foreach ($etudiants as $eid) gvb_lier_etudiant($existant->id, $eid);
        gvb_log(0, 'Compte rattaché à un étudiant', $email);
        return (int) $existant->id;
    }
    if ($type !== 'enseignant' && $nom === '') $nom = $email;
    $wpdb->insert(gvb_table('comptes'), ['type' => $type, 'email' => $email, 'nom' => $nom, 'enseignant_id' => $ens ? $ens->id : 0, 'cree_le' => gvb_now()]);
    $id = (int) $wpdb->insert_id;
    foreach ($etudiants as $eid) gvb_lier_etudiant($id, $eid);
    gvb_log(0, 'Compte du portail créé', $email . ' (' . $type . ')');
    return $id;
}
function gvb_jeton_creer($compte_id, $type, $heures) {
    global $wpdb;
    $wpdb->query($wpdb->prepare('UPDATE ' . gvb_table('jetons') . " SET utilise = 1 WHERE compte_id = %d AND type = %s AND utilise = 0", $compte_id, $type));
    $jeton = wp_generate_password(40, false, false);
    $wpdb->insert(gvb_table('jetons'), ['compte_id' => $compte_id, 'type' => $type, 'jeton_hash' => hash('sha256', $jeton), 'expire' => gmdate('Y-m-d H:i:s', time() + $heures * HOUR_IN_SECONDS), 'cree_le' => gvb_now()]);
    return $jeton;
}
function gvb_envoyer_invitation($compte_id, $reset = false) {
    $c = gvb_compte_portail($compte_id);
    if (!$c) return new WP_Error('compte', 'Compte introuvable.');
    $jeton = gvb_jeton_creer($c->id, $reset ? 'reset' : 'invitation', $reset ? 2 : 72);
    $lien = gvb_espace_url($reset ? 'reinitialiser' : 'invitation', ['t' => $jeton]);
    $ok = gvb_mail($c->email, $reset ? 'Réinitialisation de votre mot de passe — Groupe VITO S.' : 'Votre espace en ligne — Groupe VITO S.',
        ($reset ? "Bonjour,\n\nPour choisir un nouveau mot de passe (lien valable 2 heures) :\n" : "Bonjour,\n\nLe Groupe VITO S. vous ouvre son espace en ligne (emploi du temps, présences, situation financière, annonces).\nCréez votre mot de passe avec ce lien, valable 72 heures :\n") . $lien . "\n\nSi vous n'êtes pas à l'origine de cette demande, ignorez ce message.\n\nGroupe VITO S.");
    return $ok ? true : new WP_Error('mail', 'L’e-mail n’a pas pu être envoyé (voir Réglages → envoi des e-mails).');
}

// ── File de notifications ─────────────────────────────────────────────────────
function gvb_desabonnement_lien($email) { return gvb_espace_url('desabonner', ['e' => rtrim(strtr(base64_encode($email), '+/', '-_'), '='), 's' => hash_hmac('sha256', strtolower($email), wp_salt('auth'))]); }
function gvb_est_desinscrit($email) { global $wpdb; return (bool) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . gvb_table('desinscrits') . ' WHERE email_hash = %s', hash('sha256', strtolower($email)))); }
function gvb_notif_ajouter($cle, $email, $sujet, $corps, $type) {
    global $wpdb;
    $email = sanitize_email($email);
    if (!is_email($email) || gvb_est_desinscrit($email)) return false;
    if ($wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . gvb_table('notifs') . ' WHERE cle = %s', mb_substr($cle, 0, 190)))) return false;
    $wpdb->insert(gvb_table('notifs'), ['cle' => mb_substr($cle, 0, 190), 'email' => $email, 'sujet' => mb_substr($sujet, 0, 255), 'corps' => $corps, 'type' => $type, 'cree_le' => gvb_now()]);
    return true;
}
function gvb_notifs_traiter($limite = 10) {
    global $wpdb; $t = gvb_table('notifs'); $envoyes = 0;
    $aujourdhui = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE statut = 'envoye' AND envoye_le >= %s", gmdate('Y-m-d') . ' 00:00:00'));
    foreach ($wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE statut = 'attente' ORDER BY id LIMIT %d", (int) $limite)) as $n) {
        if ($aujourdhui + $envoyes >= GVB_NOTIFS_PAR_JOUR) break;
        if (gvb_est_desinscrit($n->email)) { $wpdb->update($t, ['statut' => 'ignore', 'erreur' => 'Désinscrit'], ['id' => $n->id]); continue; }
        $ok = gvb_mail($n->email, $n->sujet, $n->corps . "\n\n—\nGroupe VITO S.\nSe désabonner de ces messages : " . gvb_desabonnement_lien($n->email));
        if ($ok) { $wpdb->update($t, ['statut' => 'envoye', 'envoye_le' => gvb_now(), 'tentatives' => $n->tentatives + 1, 'erreur' => ''], ['id' => $n->id]); $envoyes++; }
        else $wpdb->update($t, ['statut' => $n->tentatives + 1 >= 3 ? 'echec' : 'attente', 'tentatives' => $n->tentatives + 1, 'erreur' => 'Envoi refusé'], ['id' => $n->id]);
    }
    return $envoyes;
}
add_filter('cron_schedules', function ($s) { $s['gvb_cinq_minutes'] = ['interval' => 300, 'display' => 'Toutes les 5 minutes']; return $s; });
add_action('init', function () { if (!wp_next_scheduled('gvb_notifs_cron')) wp_schedule_event(time() + 120, 'gvb_cinq_minutes', 'gvb_notifs_cron'); }, 20);
add_action('gvb_notifs_cron', function () {
    global $wpdb; gvb_notifs_traiter(40);
    $wpdb->query($wpdb->prepare('DELETE FROM ' . gvb_table('sessions') . ' WHERE expire < %s', gvb_now()));
    $wpdb->query($wpdb->prepare('DELETE FROM ' . gvb_table('jetons') . ' WHERE expire < %s', gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS)));
});

// Adresses à prévenir pour un étudiant : comptes du portail (notifications actives), sinon parent du dossier, sinon l'étudiant
function gvb_destinataires_etudiant($e) {
    $mails = []; $comptes = gvb_comptes_de($e->id);
    if ($comptes) { // un compte existe : ses préférences font foi (pas de repli sur le dossier)
        foreach ($comptes as $c) if ($c->actif && $c->notif_ok && $c->type !== 'enseignant') $mails[strtolower($c->email)] = $c->email;
        return array_values($mails);
    }
    foreach ([$e->parent_email, $e->email] as $m) if (is_email($m)) { $mails[strtolower($m)] = $m; if ($e->parent_email) break; }
    return array_values($mails);
}
function gvb_destinataires_groupe($s) {
    $mails = [];
    foreach (gvb_etudiants_du_groupe($s) as $e) foreach (gvb_destinataires_etudiant($e) as $m) $mails[strtolower($m)] = $m;
    return $mails;
}
function gvb_apres_envoi_rapide() { gvb_notifs_traiter(10); }

add_action('gvb_appel_enregistre', function ($s, $statuts) {
    if (empty(gvb_notifs_reglages()['absence'])) return;
    global $wpdb;
    $c = $wpdb->get_row($wpdb->prepare('SELECT intitule FROM ' . gvb_table('cours') . ' WHERE id = %d', $s->cours_id));
    foreach ($statuts as $eid => $st) {
        if ($st !== 'absent') continue;
        $e = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . gvb_table('etudiants') . ' WHERE id = %d', $eid)); if (!$e) continue;
        foreach (gvb_destinataires_etudiant($e) as $m) gvb_notif_ajouter('absence:' . $s->id . ':' . $e->id . ':' . strtolower($m), $m, 'Absence de ' . $e->prenom . ' — ' . gvb_d($s->date_seance),
            "Bonjour,\n\n" . $e->prenom . ' ' . $e->nom . ' était absent(e) au cours « ' . ($c->intitule ?? '') . ' » le ' . gvb_d($s->date_seance) . ' de ' . gvb_hhmm($s->debut) . ' à ' . gvb_hhmm($s->fin) . ".\nSi cette absence est justifiée, merci de prévenir la scolarité.", 'absence');
    }
    gvb_apres_envoi_rapide();
}, 10, 2);
add_action('gvb_seance_annulee', function ($s, $motif) {
    if (empty(gvb_notifs_reglages()['seance'])) return;
    global $wpdb; $c = $wpdb->get_row($wpdb->prepare('SELECT intitule FROM ' . gvb_table('cours') . ' WHERE id = %d', $s->cours_id)); $ens = $wpdb->get_row($wpdb->prepare('SELECT email FROM ' . gvb_table('enseignants') . ' WHERE id = %d', $s->enseignant_id));
    $mails = gvb_destinataires_groupe($s); if ($ens && is_email($ens->email)) $mails[strtolower($ens->email)] = $ens->email;
    foreach ($mails as $m) gvb_notif_ajouter('annul:' . $s->id . ':' . strtolower($m), $m, 'Séance annulée — ' . ($c->intitule ?? '') . ' (' . gvb_d($s->date_seance) . ')', "Bonjour,\n\nLa séance « " . ($c->intitule ?? '') . ' » du ' . gvb_d($s->date_seance) . ' de ' . gvb_hhmm($s->debut) . ' à ' . gvb_hhmm($s->fin) . " est annulée.\nMotif : " . $motif, 'seance');
    gvb_apres_envoi_rapide();
}, 10, 2);
add_action('gvb_seance_reportee', function ($s, $date, $debut, $fin) {
    if (empty(gvb_notifs_reglages()['seance'])) return;
    global $wpdb; $c = $wpdb->get_row($wpdb->prepare('SELECT intitule FROM ' . gvb_table('cours') . ' WHERE id = %d', $s->cours_id)); $ens = $wpdb->get_row($wpdb->prepare('SELECT email FROM ' . gvb_table('enseignants') . ' WHERE id = %d', $s->enseignant_id));
    $mails = gvb_destinataires_groupe($s); if ($ens && is_email($ens->email)) $mails[strtolower($ens->email)] = $ens->email;
    foreach ($mails as $m) gvb_notif_ajouter('report:' . $s->id . ':' . md5($date . $debut) . ':' . strtolower($m), $m, 'Séance reportée — ' . ($c->intitule ?? ''), "Bonjour,\n\nLa séance « " . ($c->intitule ?? '') . ' » prévue le ' . gvb_d($s->date_seance) . ' de ' . gvb_hhmm($s->debut) . ' à ' . gvb_hhmm($s->fin) . ' est reportée au ' . gvb_d($date) . ' de ' . $debut . ' à ' . $fin . '.', 'seance');
    gvb_apres_envoi_rapide();
}, 10, 4);
add_action('gvb_paiement_enregistre', function ($pid, $e, $recu, $montant) {
    if (empty(gvb_notifs_reglages()['paiement'])) return;
    foreach (gvb_destinataires_etudiant($e) as $m) gvb_notif_ajouter('paiement:' . $pid . ':' . strtolower($m), $m, 'Reçu de paiement ' . $recu, "Bonjour,\n\nNous avons bien reçu un paiement de " . gvb_montant($montant) . ' pour ' . $e->prenom . ' ' . $e->nom . " (reçu $recu).\nVous retrouvez vos reçus dans votre espace en ligne : " . gvb_espace_url(), 'paiement');
    gvb_apres_envoi_rapide();
}, 10, 4);

// ── Annonces ──────────────────────────────────────────────────────────────────
function gvb_destinataires_annonce($a) {
    global $wpdb; $mails = [];
    if (in_array($a->audience, ['tous', 'familles'], true)) {
        $w = ["statut = 'actif'"]; $args = [];
        if ($a->pole !== '') { $w[] = 'pole = %s'; $args[] = $a->pole; }
        if ($a->niveau !== '') { $w[] = 'LOWER(niveau) = LOWER(%s)'; $args[] = $a->niveau; }
        $sql = 'SELECT * FROM ' . gvb_table('etudiants') . ' WHERE ' . implode(' AND ', $w);
        foreach ($args ? $wpdb->get_results($wpdb->prepare($sql, $args)) : $wpdb->get_results($sql) as $e) foreach (gvb_destinataires_etudiant($e) as $m) $mails[strtolower($m)] = $m;
    }
    if (in_array($a->audience, ['tous', 'enseignants'], true)) foreach ($wpdb->get_results('SELECT * FROM ' . gvb_table('enseignants') . ' WHERE actif = 1') as $e) {
        if ($a->pole !== '' && $e->poles !== '' && !in_array($a->pole, explode(',', $e->poles), true)) continue;
        if (is_email($e->email)) $mails[strtolower($e->email)] = $e->email;
    }
    return $mails;
}
function gvb_page_annonces() {
    gvb_exiger('espaces', 'voir');
    global $wpdb; $pole_u = gvb_pole_utilisateur(); $l = [];
    foreach ($wpdb->get_results('SELECT * FROM ' . gvb_table('annonces') . ' ORDER BY id DESC LIMIT 100') as $a) {
        if ($pole_u !== '' && $a->pole !== $pole_u) continue;
        $act = gvb_can('espaces', 'modifier') ? gvb_form('gvb_annonce_archiver', 'gvb_annonce_archiver', ['id' => $a->id], 'class="gvb-inline"') . gvb_bouton($a->archive ? 'Republier' : 'Archiver', false) . '</form>' : '';
        $l[] = '<tr' . ($a->archive ? ' style="opacity:.55"' : '') . '><td>' . esc_html(gvb_dt($a->publie_le)) . '</td><td><strong>' . esc_html($a->titre) . '</strong><br><small>' . esc_html(mb_strimwidth((string) $a->corps, 0, 120, '…')) . '</small></td><td>' . esc_html(GVB_AUDIENCES[$a->audience] ?? $a->audience) . '<br><small>' . esc_html(($a->pole ? GVB_POLES[$a->pole] : 'Tous pôles') . ($a->niveau ? ' · ' . $a->niveau : '')) . '</small></td><td>' . ($a->par_email ? 'E-mail + espace' : 'Espace seulement') . '</td><td>' . $act . '</td></tr>';
    }
    gvb_debut('Annonces', 'rapport');
    if (gvb_can('espaces', 'creer')) {
        $poles = ($pole_u !== '' && $pole_u !== '__aucun__') ? [$pole_u => GVB_POLES[$pole_u]] : ['' => 'Tous les pôles'] + GVB_POLES;
        echo '<div class="gvb-boite"><h2>' . gvb_icone('plus', 18) . 'Nouvelle annonce</h2>' . gvb_form('gvb_annonce_creer', 'gvb_annonce_creer') . gvb_champ('titre', 'Titre *', '', 'text', 'required maxlength="190"') . gvb_zone('corps', 'Message *', '', 6)
            . '<div class="gvb-champs">' . gvb_liste('audience', 'Destinataires', GVB_AUDIENCES, 'familles') . gvb_liste('pole', 'Pôle', $poles) . gvb_champ('niveau', 'Niveau (facultatif)', '', 'text', 'maxlength="40" placeholder="Licence 1, 10e année…"') . '</div>'
            . '<label class="gvb-cases"><input type="checkbox" name="par_email" value="1" checked> Envoyer aussi par e-mail</label>' . gvb_bouton('Publier') . '</form></div>';
    }
    echo '<div class="gvb-boite"><h2>' . gvb_icone('journal', 18) . 'Annonces publiées</h2>' . gvb_tableau(['Date', 'Annonce', 'Destinataires', 'Diffusion', ''], $l, 'Aucune annonce.') . '</div>';
    gvb_fin();
}
add_action('admin_post_gvb_annonce_creer', function () {
    gvb_garde('espaces', 'creer', 'gvb_annonce_creer');
    global $wpdb; $pole_u = gvb_pole_utilisateur();
    $titre = mb_substr(sanitize_text_field(wp_unslash($_POST['titre'] ?? '')), 0, 190); $corps = trim(mb_substr(sanitize_textarea_field(wp_unslash($_POST['corps'] ?? '')), 0, 5000));
    $aud = isset(GVB_AUDIENCES[$_POST['audience'] ?? '']) ? $_POST['audience'] : 'familles'; $pole = isset(GVB_POLES[$_POST['pole'] ?? '']) ? $_POST['pole'] : '';
    if ($pole_u === '__aucun__') gvb_retour('gv-annonces', [], null, 'Aucun pôle ne vous est attribué.');
    if ($pole_u !== '') { $pole = $pole_u; if ($aud === 'tous') $aud = 'familles'; }
    if ($titre === '' || $corps === '') gvb_retour('gv-annonces', [], null, 'Le titre et le message sont obligatoires.');
    $par_email = empty($_POST['par_email']) ? 0 : 1;
    $wpdb->insert(gvb_table('annonces'), ['titre' => $titre, 'corps' => $corps, 'audience' => $aud, 'pole' => $pole, 'niveau' => mb_substr(sanitize_text_field(wp_unslash($_POST['niveau'] ?? '')), 0, 40), 'par_email' => $par_email, 'auteur_id' => get_current_user_id(), 'publie_le' => gvb_now()]);
    $id = (int) $wpdb->insert_id; $a = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . gvb_table('annonces') . ' WHERE id = %d', $id)); $n = 0;
    if ($par_email) {
        $dest = gvb_destinataires_annonce($a);
        if (count($dest) > 2000) gvb_retour('gv-annonces', [], null, 'Plus de 2000 destinataires : précisez un pôle ou un niveau.');
        foreach ($dest as $m) if (gvb_notif_ajouter('annonce:' . $id . ':' . strtolower($m), $m, $titre, $corps . "\n\nRetrouvez toutes les annonces dans votre espace : " . gvb_espace_url('annonces'), 'annonce')) $n++;
        gvb_apres_envoi_rapide();
    }
    gvb_log(0, 'Annonce publiée', $titre . ($par_email ? ' — ' . $n . ' e-mail(s) en file' : ''));
    gvb_retour('gv-annonces', [], 'Annonce publiée' . ($par_email ? ' : ' . $n . ' e-mail(s) en cours d’envoi.' : '.'));
});
add_action('admin_post_gvb_annonce_archiver', function () {
    gvb_garde('espaces', 'modifier', 'gvb_annonce_archiver');
    global $wpdb; $a = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . gvb_table('annonces') . ' WHERE id = %d', absint($_POST['id'] ?? 0))); $pole_u = gvb_pole_utilisateur();
    if (!$a || ($pole_u !== '' && $a->pole !== $pole_u)) gvb_retour('gv-annonces', [], null, 'Annonce introuvable.');
    $wpdb->update(gvb_table('annonces'), ['archive' => $a->archive ? 0 : 1], ['id' => $a->id]);
    gvb_retour('gv-annonces', [], $a->archive ? 'Annonce republiée.' : 'Annonce archivée.');
});

// ── Espaces en ligne (comptes) ────────────────────────────────────────────────
function gvb_page_espaces() {
    gvb_exiger('espaces', 'voir');
    global $wpdb; $type = isset(GVB_TYPES_COMPTE[$_GET['type'] ?? '']) ? $_GET['type'] : ''; $q = sanitize_text_field(wp_unslash($_GET['q'] ?? ''));
    $w = ['1=1']; $a = [];
    if ($type !== '') { $w[] = 'type = %s'; $a[] = $type; }
    if ($q !== '') { $like = '%' . $wpdb->esc_like($q) . '%'; $w[] = '(email LIKE %s OR nom LIKE %s)'; array_push($a, $like, $like); }
    $sql = 'SELECT * FROM ' . gvb_table('comptes') . ' WHERE ' . implode(' AND ', $w) . ' ORDER BY id DESC LIMIT 300';
    $l = [];
    foreach ($a ? $wpdb->get_results($wpdb->prepare($sql, $a)) : $wpdb->get_results($sql) as $c) {
        $liés = array_filter(gvb_etudiants_du_compte($c->id), fn($e) => gvb_pole_autorise($e->pole));
        if ($c->type !== 'enseignant' && !$liés && gvb_pole_utilisateur() !== '') continue;
        $noms = implode(', ', array_map(fn($e) => $e->prenom . ' ' . mb_strtoupper($e->nom), $liés));
        $act = '';
        if (gvb_can('espaces', 'modifier')) {
            $act = gvb_form('gvb_compte_action', 'gvb_compte_action', ['id' => $c->id, 'op' => $c->mdp === '' ? 'invitation' : 'reset'], 'class="gvb-inline"') . gvb_bouton($c->mdp === '' ? 'Renvoyer l’invitation' : 'Lien de réinitialisation', false) . '</form> '
                . gvb_form('gvb_compte_action', 'gvb_compte_action', ['id' => $c->id, 'op' => 'bascule'], 'class="gvb-inline"') . gvb_bouton($c->actif ? 'Désactiver' : 'Réactiver', false) . '</form>';
        }
        $l[] = '<tr' . ($c->actif ? '' : ' style="opacity:.55"') . '><td><strong>' . esc_html($c->email) . '</strong><br><small>' . esc_html($c->nom) . '</small></td><td>' . esc_html(GVB_TYPES_COMPTE[$c->type] ?? $c->type) . '</td><td>' . esc_html($c->type === 'enseignant' ? '—' : ($noms ?: '—')) . '</td><td>' . ($c->mdp === '' ? gvb_badge('nouvelle', 'Invitation en attente') : ($c->derniere_connexion ? esc_html(gvb_dt($c->derniere_connexion)) : '—')) . '</td><td>' . $act . '</td></tr>';
    }
    gvb_debut('Espaces en ligne', 'utilisateurs', '<a class="button" target="_blank" rel="noopener" href="' . esc_url(gvb_espace_url()) . '">Ouvrir le portail</a>');
    echo '<form method="get" class="gvb-filtres"><input type="hidden" name="page" value="gv-espaces">' . gvb_liste('type', 'Type', ['' => 'Tous'] + GVB_TYPES_COMPTE, $type) . gvb_champ('q', 'Recherche', $q, 'search') . gvb_bouton('Filtrer', false) . '</form>';
    echo gvb_tableau(['Compte', 'Type', 'Étudiant(s)', 'Dernière connexion', 'Actions'], $l, 'Aucun compte. Invitez un parent depuis la fiche d’un étudiant, ou créez un compte ci-dessous.');
    if (gvb_can('espaces', 'creer')) {
        $ens = []; foreach ($wpdb->get_results('SELECT * FROM ' . gvb_table('enseignants') . ' WHERE actif = 1 ORDER BY nom') as $e) if (gvb_ens_dans_perimetre($e)) $ens[$e->id] = gvb_nom_ens($e) . ($e->email ? ' — ' . $e->email : '');
        echo '<div class="gvb-boite"><h2>' . gvb_icone('plus', 18) . 'Inviter</h2>' . gvb_form('gvb_compte_creer', 'gvb_compte_creer') . '<div class="gvb-champs">' . gvb_liste('type', 'Type de compte', GVB_TYPES_COMPTE, 'parent') . gvb_champ('email', 'E-mail *', '', 'email', 'required') . gvb_champ('nom', 'Nom (facultatif)', '') . gvb_champ('matricules', 'Matricule(s) de l’étudiant, séparés par une virgule', '', 'text', 'placeholder="UNI-2026-0001, UNI-2026-0002"') . ($ens ? gvb_liste('enseignant', 'Enseignant (pour un compte enseignant)', [0 => '—'] + $ens) : '') . '</div>' . gvb_bouton('Créer et envoyer l’invitation') . '</form></div>';
    }
    gvb_fin();
}
add_action('admin_post_gvb_compte_creer', function () {
    gvb_garde('espaces', 'creer', 'gvb_compte_creer');
    global $wpdb; $type = sanitize_key($_POST['type'] ?? ''); $ids = [];
    foreach (array_filter(array_map('trim', explode(',', (string) wp_unslash($_POST['matricules'] ?? '')))) as $m) {
        $id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . gvb_table('etudiants') . ' WHERE matricule = %s', strtoupper($m)));
        if (!$id || !gvb_etudiant($id)) gvb_retour('gv-espaces', [], null, 'Matricule introuvable ou hors de votre périmètre : ' . $m);
        $ids[] = $id;
    }
    $r = gvb_creer_compte($type, wp_unslash($_POST['email'] ?? ''), wp_unslash($_POST['nom'] ?? ''), $ids, absint($_POST['enseignant'] ?? 0));
    if (is_wp_error($r)) gvb_retour('gv-espaces', [], null, $r);
    $c = gvb_compte_portail($r);
    if ($c->mdp !== '') gvb_retour('gv-espaces', [], 'Compte existant : étudiant rattaché.');
    $m = gvb_envoyer_invitation($r);
    gvb_retour('gv-espaces', [], is_wp_error($m) ? null : 'Compte créé, invitation envoyée.', is_wp_error($m) ? 'Compte créé, mais ' . $m->get_error_message() : null);
});
add_action('admin_post_gvb_compte_action', function () {
    gvb_garde('espaces', 'modifier', 'gvb_compte_action');
    global $wpdb; $c = gvb_compte_portail(absint($_POST['id'] ?? 0)); $op = sanitize_key($_POST['op'] ?? '');
    if (!$c) gvb_retour('gv-espaces', [], null, 'Compte introuvable.');
    if ($op === 'bascule') {
        $wpdb->update(gvb_table('comptes'), ['actif' => $c->actif ? 0 : 1], ['id' => $c->id]);
        if ($c->actif) $wpdb->delete(gvb_table('sessions'), ['compte_id' => $c->id]);
        gvb_log(0, $c->actif ? 'Compte du portail désactivé' : 'Compte du portail réactivé', $c->email);
        gvb_retour('gv-espaces', [], $c->actif ? 'Compte désactivé : sessions fermées.' : 'Compte réactivé.');
    }
    $m = gvb_envoyer_invitation($c->id, $op === 'reset');
    if (is_wp_error($m)) gvb_retour('gv-espaces', [], null, $m);
    gvb_log(0, $op === 'reset' ? 'Lien de réinitialisation envoyé' : 'Invitation renvoyée', $c->email);
    gvb_retour('gv-espaces', [], 'E-mail envoyé.');
});
// Blocs sur les fiches étudiant et enseignant
add_action('gvb_fiche_etudiant_apres', function ($e) {
    if (!gvb_can('espaces', 'voir')) return;
    $l = array_map(fn($c) => '<li>' . esc_html($c->email) . ' — ' . esc_html(GVB_TYPES_COMPTE[$c->type] ?? '') . ($c->mdp === '' ? ' <em>(invitation en attente)</em>' : '') . ($c->actif ? '' : ' <em>(désactivé)</em>') . '</li>', gvb_comptes_de($e->id));
    echo '<div class="gvb-boite"><h2>' . gvb_icone('utilisateurs', 18) . 'Espace en ligne</h2>' . ($l ? '<ul>' . implode('', $l) . '</ul>' : '<p class="gvb-rappel">Aucun accès pour cet étudiant.</p>');
    if (gvb_can('espaces', 'creer')) echo gvb_form('gvb_compte_creer', 'gvb_compte_creer', ['matricules' => $e->matricule], 'class="gvb-inline"') . gvb_liste('type', 'Inviter', ['parent' => 'un parent', 'etudiant' => 'l’étudiant'], 'parent') . gvb_champ('email', 'Adresse e-mail', $e->parent_email ?: $e->email, 'email', 'required') . gvb_bouton('Envoyer l’invitation', false) . '</form>';
    echo '</div>';
});
add_action('gvb_fiche_enseignant_apres', function ($e) {
    if (!gvb_can('espaces', 'voir')) return;
    global $wpdb; $c = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . gvb_table('comptes') . ' WHERE enseignant_id = %d', $e->id));
    echo '<div class="gvb-boite"><h2>' . gvb_icone('utilisateurs', 18) . 'Espace enseignant</h2>';
    if ($c) echo '<p>Compte : ' . esc_html($c->email) . ($c->mdp === '' ? ' <em>(invitation en attente)</em>' : '') . '</p>';
    elseif (gvb_can('espaces', 'creer') && is_email($e->email)) echo gvb_form('gvb_compte_creer', 'gvb_compte_creer', ['type' => 'enseignant', 'enseignant' => $e->id, 'email' => $e->email], 'class="gvb-inline"') . gvb_bouton('Inviter à l’espace enseignant (' . $e->email . ')', false) . '</form>';
    else echo '<p class="gvb-rappel">Renseignez l’adresse e-mail de l’enseignant pour l’inviter.</p>';
    echo '</div>';
});

// ── Notifications : écran et réglages ─────────────────────────────────────────
function gvb_page_notifs() {
    gvb_exiger('espaces', 'voir');
    global $wpdb; $t = gvb_table('notifs'); $st = in_array($_GET['statut'] ?? '', ['attente', 'envoye', 'echec', 'ignore'], true) ? $_GET['statut'] : '';
    $cnt = $wpdb->get_results("SELECT statut, COUNT(*) n FROM $t GROUP BY statut", OBJECT_K);
    $rows = $st ? $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE statut = %s ORDER BY id DESC LIMIT 200", $st)) : $wpdb->get_results("SELECT * FROM $t ORDER BY id DESC LIMIT 200"); $l = [];
    $lib = ['attente' => ['etude', 'En attente'], 'envoye' => ['validee', 'Envoyé'], 'echec' => ['incomplete', 'Échec'], 'ignore' => ['annulee', 'Ignoré']];
    foreach ($rows as $n) $l[] = '<tr><td>' . esc_html(gvb_dt($n->cree_le)) . '</td><td>' . esc_html($n->type) . '</td><td>' . esc_html($n->email) . '</td><td>' . esc_html($n->sujet) . '</td><td>' . gvb_badge($lib[$n->statut][0] ?? 'annulee', $lib[$n->statut][1] ?? $n->statut) . ($n->erreur ? '<br><small>' . esc_html($n->erreur) . '</small>' : '') . '</td></tr>';
    $actions = gvb_can('espaces', 'modifier') ? gvb_form('gvb_notifs_action', 'gvb_notifs_action', ['op' => 'envoyer'], 'class="gvb-inline"') . gvb_bouton('Envoyer le lot en attente', false) . '</form> ' . gvb_form('gvb_notifs_action', 'gvb_notifs_action', ['op' => 'reessayer'], 'class="gvb-inline"') . gvb_bouton('Réessayer les échecs', false) . '</form>' : '';
    gvb_debut('Notifications', 'alerte', $actions);
    echo '<div class="gvb-cartes">' . gvb_carte('En attente', (int) ($cnt['attente']->n ?? 0), 'Envoi automatique toutes les 5 min', 'horloge') . gvb_carte('Envoyées', (int) ($cnt['envoye']->n ?? 0), 'Plafond ' . GVB_NOTIFS_PAR_JOUR . ' par jour', 'check', 'ok') . gvb_carte('Échecs', (int) ($cnt['echec']->n ?? 0), '', 'alerte', !empty($cnt['echec']->n) ? 'attention' : '') . '</div>';
    echo '<form method="get" class="gvb-filtres"><input type="hidden" name="page" value="gv-notifs">' . gvb_liste('statut', 'État', ['' => 'Tous', 'attente' => 'En attente', 'envoye' => 'Envoyés', 'echec' => 'Échecs', 'ignore' => 'Ignorés'], $st) . gvb_bouton('Filtrer', false) . '</form>';
    echo gvb_tableau(['Date', 'Type', 'Destinataire', 'Sujet', 'État'], $l, 'Aucune notification.');
    gvb_fin();
}
add_action('admin_post_gvb_notifs_action', function () {
    gvb_garde('espaces', 'modifier', 'gvb_notifs_action');
    global $wpdb; $op = sanitize_key($_POST['op'] ?? '');
    if ($op === 'reessayer') { $n = $wpdb->query($wpdb->prepare('UPDATE ' . gvb_table('notifs') . " SET statut = 'attente', tentatives = 0, erreur = '' WHERE statut = %s", 'echec')); gvb_retour('gv-notifs', [], $n . ' notification(s) remises en file.'); }
    $n = gvb_notifs_traiter(40);
    gvb_retour('gv-notifs', [], $n . ' notification(s) envoyée(s).');
});
add_action('gvb_reglages_apres', function () {
    if (!gvb_can('parametres', 'modifier')) return;
    $r = gvb_notifs_reglages();
    echo '<div class="gvb-boite" style="max-width:640px"><h2>' . gvb_icone('alerte', 18) . 'Notifications automatiques</h2>' . gvb_form('gvb_notifs_sauver', 'gvb_notifs_sauver')
        . '<label class="gvb-cases"><input type="checkbox" name="absence" value="1"' . checked($r['absence'], 1, false) . '> Prévenir les parents d’une absence (le jour de l’appel)</label>'
        . '<label class="gvb-cases"><input type="checkbox" name="seance" value="1"' . checked($r['seance'], 1, false) . '> Prévenir d’une séance reportée ou annulée</label>'
        . '<label class="gvb-cases"><input type="checkbox" name="paiement" value="1"' . checked($r['paiement'], 1, false) . '> Envoyer l’avis de reçu après un paiement</label>' . gvb_bouton('Enregistrer') . '</form></div>';
});
add_action('admin_post_gvb_notifs_sauver', function () {
    gvb_garde('parametres', 'modifier', 'gvb_notifs_sauver');
    update_option('gvb_notifs', ['absence' => empty($_POST['absence']) ? 0 : 1, 'seance' => empty($_POST['seance']) ? 0 : 1, 'paiement' => empty($_POST['paiement']) ? 0 : 1], false);
    gvb_log(0, 'Réglages des notifications modifiés');
    gvb_retour('gv-reglages', [], 'Réglages enregistrés.');
});
