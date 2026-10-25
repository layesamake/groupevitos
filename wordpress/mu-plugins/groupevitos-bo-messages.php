<?php
/**
 * Plugin Name: Groupe Vitos — Back-office : messages du formulaire de contact
 * Description: Formulaire de contact du site public : réception durcie (validation stricte, limites de débit, jeton à usage unique, champ piège, origine contrôlée), et écran de gestion pour la Direction, la Scolarité et la Communication.
 */

if (!defined('ABSPATH')) exit;

const GVB_MESSAGE_ETATS = ['nouveau' => 'Nouveau', 'lu' => 'Lu', 'traite' => 'Traité', 'spam' => 'Indésirable'];
const GVB_MESSAGE_OBJETS = ['admission' => 'Admission et inscription', 'scolarite' => 'Scolarité, frais et paiements', 'partenariat' => 'Partenariat, stage ou collaboration', 'autre' => 'Autre demande'];
const GVB_MESSAGE_MAX = 2000;       // caractères du message
const GVB_MESSAGE_CORPS_MAX = 12000; // octets acceptés pour toute la requête
const GVB_MESSAGE_CONSERVATION_MOIS = 12;
// Limites de débit : par visiteur (adresse) et pour l'ensemble du site
const GVB_MESSAGE_LIMITES = ['tentatives_heure' => 12, 'messages_heure' => 3, 'messages_jour' => 8, 'par_email_jour' => 3, 'site_jour' => 250, 'mails_equipe_jour' => 40, 'jeton_min_secondes' => 4, 'jeton_max_secondes' => 7200, 'jetons_heure' => 60];

add_action('init', function () {
    if (get_option('gvb_db_messages') === '1') return;
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta('CREATE TABLE ' . gvb_table('messages') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  nom varchar(100) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  telephone varchar(24) NOT NULL DEFAULT '',
  objet varchar(16) NOT NULL DEFAULT 'autre',
  pole varchar(12) NOT NULL DEFAULT '',
  message text NOT NULL,
  statut varchar(12) NOT NULL DEFAULT 'nouveau',
  ip_hash varchar(64) NOT NULL DEFAULT '',
  consentement_le datetime NOT NULL,
  cree_le datetime NOT NULL,
  traite_par bigint(20) unsigned NOT NULL DEFAULT 0,
  traite_le datetime NULL,
  PRIMARY KEY  (id),
  KEY statut (statut),
  KEY cree_le (cree_le),
  KEY email (email)
) " . $wpdb->get_charset_collate() . ';');
    update_option('gvb_db_messages', '1', false);
}, 5);

// ── Outils de nettoyage et de limitation ──────────────────────────────────────
// Texte brut sûr : UTF-8 valide, sans balises, sans caractères de contrôle ni de direction cachés (usurpation visuelle)
function gvb_message_nettoyer($valeur, $max, $multiligne = false) {
    if (!is_scalar($valeur)) return '';
    $s = wp_check_invalid_utf8((string) wp_unslash($valeur));
    $s = wp_strip_all_tags($s);
    $s = preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', (string) $s);
    if ($multiligne) { $s = preg_replace("/\r\n?/", "\n", (string) $s); $s = preg_replace("/[ \t]+\n/", "\n", (string) $s); $s = preg_replace("/\n{3,}/", "\n\n", (string) $s); }
    else $s = preg_replace('/\s+/u', ' ', (string) $s);
    return mb_substr(trim((string) $s), 0, $max);
}
function gvb_message_cle($prefixe, $suffixe = '') { return 'gvb_m_' . $prefixe . '_' . substr(hash('sha256', $suffixe . '|' . wp_salt('nonce')), 0, 24); }
function gvb_message_compteur($cle) { return (int) get_transient($cle); }
function gvb_message_incrementer($cle, $duree) { set_transient($cle, gvb_message_compteur($cle) + 1, $duree); }

// Jeton signé, à usage unique, valable de 4 secondes à 2 heures après son émission (bloque les envois instantanés des robots et les rejeux)
function gvb_message_jeton($horodatage = null) {
    $t = (int) ($horodatage ?? time());
    return $t . '.' . hash_hmac('sha256', 'msg|' . $t, wp_salt('auth'));
}
function gvb_message_jeton_verifier($jeton) {
    if (!is_string($jeton) || !preg_match('/^(\d{9,11})\.([a-f0-9]{64})$/', $jeton, $m)) return 'invalide';
    if (!hash_equals(hash_hmac('sha256', 'msg|' . $m[1], wp_salt('auth')), $m[2])) return 'invalide';
    $age = time() - (int) $m[1];
    if ($age < GVB_MESSAGE_LIMITES['jeton_min_secondes']) return 'trop_tot';
    if ($age > GVB_MESSAGE_LIMITES['jeton_max_secondes']) return 'expire';
    if (get_transient('gvb_mj_' . substr($m[2], 0, 32)) !== false) return 'deja_utilise';
    return 'ok';
}

// ── Routes publiques ──────────────────────────────────────────────────────────
add_action('rest_api_init', function () {
    register_rest_route('groupevitos/v1', '/message/jeton', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => 'gvb_message_jeton_route']);
    register_rest_route('groupevitos/v1', '/message', ['methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'gvb_message_recevoir']);
});
// Réponses jamais mises en cache ; CORS limité aux seules adresses du site
add_filter('rest_pre_serve_request', function ($servi, $resultat, $requete) {
    if (str_starts_with($requete->get_route(), '/groupevitos/v1/message')) {
        $origine = (string) $requete->get_header('origin');
        if (in_array($origine, GVC_ORIGINES, true)) {
            header('Access-Control-Allow-Origin: ' . $origine); header('Access-Control-Allow-Methods: GET, POST, OPTIONS'); header('Access-Control-Allow-Headers: Content-Type'); header('Vary: Origin');
        } else {
            header_remove('Access-Control-Allow-Origin');
        }
        header('Cache-Control: no-store, max-age=0'); header('X-Content-Type-Options: nosniff');
    }
    return $servi;
}, 20, 3);

function gvb_message_origine_ok(WP_REST_Request $r) { return in_array((string) $r->get_header('origin'), GVC_ORIGINES, true); }

function gvb_message_jeton_route(WP_REST_Request $r) {
    if (!gvb_message_origine_ok($r)) return new WP_Error('origine', 'Origine non autorisée.', ['status' => 403]);
    $cle = gvb_message_cle('jetons', gvb_ip_visiteur());
    if (gvb_message_compteur($cle) >= GVB_MESSAGE_LIMITES['jetons_heure']) return new WP_Error('trop_de_demandes', 'Trop de demandes. Réessayez plus tard.', ['status' => 429]);
    gvb_message_incrementer($cle, HOUR_IN_SECONDS);
    return ['t' => gvb_message_jeton()];
}

function gvb_message_erreur($code, $texte, $statut = 422) { return new WP_Error($code, $texte, ['status' => $statut]); }

function gvb_message_recevoir(WP_REST_Request $r) {
    global $wpdb;
    $L = GVB_MESSAGE_LIMITES;
    // 1. Taille de la requête et origine : refus avant tout autre travail
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > GVB_MESSAGE_CORPS_MAX) return gvb_message_erreur('trop_gros', 'Message trop volumineux.', 413);
    if (!gvb_message_origine_ok($r)) return gvb_message_erreur('origine', 'Origine non autorisée.', 403);
    $p = $r->get_body_params() ?: (array) $r->get_json_params();
    if (strlen(wp_json_encode($p)) > GVB_MESSAGE_CORPS_MAX) return gvb_message_erreur('trop_gros', 'Message trop volumineux.', 413);

    // 2. Limites de débit (le site entier, puis le visiteur) : chaque tentative compte, même invalide
    $jour = gmdate('Ymd');
    $ip = gvb_ip_visiteur();
    $cle_site = gvb_message_cle('site', $jour);
    if (gvb_message_compteur($cle_site) >= $L['site_jour']) return gvb_message_erreur('indisponible', 'Le formulaire est momentanément indisponible. Appelez-nous ou écrivez à ' . gvb_reglages()['email'] . '.', 503);
    $cle_tent = gvb_message_cle('tent', $ip);
    if (gvb_message_compteur($cle_tent) >= $L['tentatives_heure']) return gvb_message_erreur('trop_de_demandes', 'Trop de tentatives depuis votre connexion. Réessayez dans une heure.', 429);
    gvb_message_incrementer($cle_tent, HOUR_IN_SECONDS);
    $cle_h = gvb_message_cle('heure', $ip); $cle_j = gvb_message_cle('jour', $ip . $jour);
    if (gvb_message_compteur($cle_h) >= $L['messages_heure'] || gvb_message_compteur($cle_j) >= $L['messages_jour']) return gvb_message_erreur('trop_de_demandes', 'Vous avez déjà envoyé plusieurs messages. Réessayez plus tard ou appelez-nous.', 429);

    // 3. Champ piège : un robot le remplit ; on répond « ok » sans rien enregistrer
    if (!empty($p['site_web'])) return ['ok' => true];

    // 4. Jeton signé à usage unique
    $verdict = gvb_message_jeton_verifier($p['jeton'] ?? '');
    if ($verdict === 'trop_tot') return gvb_message_erreur('trop_tot', 'Merci de patienter quelques secondes avant d’envoyer le formulaire.', 429);
    if ($verdict !== 'ok') return gvb_message_erreur('jeton', 'Le formulaire a expiré. Rechargez la page puis réessayez.', 400);

    // 5. Validation stricte par liste blanche
    if (empty($p['consentement'])) return gvb_message_erreur('consentement', 'Veuillez accepter le traitement de vos données.');
    $nom = gvb_message_nettoyer($p['nom'] ?? '', 100);
    if (mb_strlen($nom) < 2 || preg_match('#https?:|www\.|@#i', $nom)) return gvb_message_erreur('nom', 'Indiquez votre nom (sans adresse web ni e-mail).');
    $email_brut = is_scalar($p['email'] ?? null) ? trim((string) wp_unslash($p['email'])) : '';
    if ($email_brut === '' || strlen($email_brut) > 190 || preg_match('/[\r\n\0,;<>"\s]/', $email_brut)) return gvb_message_erreur('email', 'Adresse e-mail invalide.');
    $email = sanitize_email($email_brut);
    if (!is_email($email) || strtolower($email) !== strtolower($email_brut)) return gvb_message_erreur('email', 'Adresse e-mail invalide.');
    $email = strtolower($email);
    $tel = gvb_message_nettoyer($p['telephone'] ?? '', 24);
    $tel_saisi = is_scalar($p['telephone'] ?? '') ? trim((string) ($p['telephone'] ?? '')) : 'x';
    if (($tel_saisi !== '' && $tel === '') || ($tel !== '' && !preg_match('/^\+?[0-9][0-9 ().\-]{5,22}$/', $tel))) return gvb_message_erreur('telephone', 'Numéro de téléphone invalide.');
    $objet = is_scalar($p['objet'] ?? null) && isset(GVB_MESSAGE_OBJETS[(string) $p['objet']]) ? (string) $p['objet'] : '';
    if ($objet === '') return gvb_message_erreur('objet', 'Choisissez l’objet de votre message.');
    $pole = is_scalar($p['pole'] ?? null) && isset(GVB_POLES[(string) $p['pole']]) ? (string) $p['pole'] : '';
    $message = gvb_message_nettoyer($p['message'] ?? '', GVB_MESSAGE_MAX, true);
    if (mb_strlen($message) < 10) return gvb_message_erreur('message', 'Votre message est trop court (10 caractères au minimum).');
    if (preg_match_all('#https?:|www\.|\[/?url#i', $message) > 1) return gvb_message_erreur('liens', 'Votre message contient trop de liens.');
    if (preg_match('/(.)\1{14,}/u', $message)) return gvb_message_erreur('message', 'Votre message n’est pas valide.');

    // 6. Doublons et limite par adresse e-mail
    $empreinte = 'gvb_md_' . substr(hash('sha256', $email . '|' . $message), 0, 32);
    if (get_transient($empreinte) !== false) { set_transient('gvb_mj_' . substr(explode('.', $p['jeton'])[1], 0, 32), 1, 3 * HOUR_IN_SECONDS); return ['ok' => true]; }
    $cle_e = gvb_message_cle('email', $email . $jour);
    if (gvb_message_compteur($cle_e) >= $L['par_email_jour']) return gvb_message_erreur('trop_de_demandes', 'Nous avons déjà reçu plusieurs messages de cette adresse. Nous vous répondons au plus vite.', 429);

    // 7. Enregistrement (requête préparée) ; l'adresse du visiteur n'est conservée que sous forme d'empreinte
    $now = gvb_now();
    set_transient('gvb_mj_' . substr(explode('.', $p['jeton'])[1], 0, 32), 1, 3 * HOUR_IN_SECONDS);
    set_transient($empreinte, 1, DAY_IN_SECONDS);
    gvb_message_incrementer($cle_h, HOUR_IN_SECONDS); gvb_message_incrementer($cle_j, DAY_IN_SECONDS); gvb_message_incrementer($cle_e, DAY_IN_SECONDS); gvb_message_incrementer($cle_site, DAY_IN_SECONDS);
    $ok = $wpdb->insert(gvb_table('messages'), ['nom' => $nom, 'email' => $email, 'telephone' => $tel, 'objet' => $objet, 'pole' => $pole, 'message' => $message, 'statut' => 'nouveau', 'ip_hash' => hash('sha256', $ip . '|' . wp_salt('nonce')), 'consentement_le' => $now, 'cree_le' => $now], ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);
    if (!$ok) return gvb_message_erreur('enregistrement', 'Votre message n’a pas pu être enregistré. Réessayez ou appelez-nous.', 500);
    $id = (int) $wpdb->insert_id;

    // 8. Alerte à l'équipe : sujet fixe, aucune donnée du visiteur dans les en-têtes ; pas d'accusé de réception automatique (évite d'écrire à un tiers)
    $cle_mails = gvb_message_cle('mails', $jour);
    if (gvb_message_compteur($cle_mails) < $L['mails_equipe_jour']) {
        gvb_message_incrementer($cle_mails, DAY_IN_SECONDS);
        gvb_mail(gvb_reglages()['email'], 'Nouveau message depuis le site — ' . GVB_MESSAGE_OBJETS[$objet], "Un visiteur a écrit depuis le formulaire de contact du site.\n\nÀ consulter et traiter dans le back-office :\n" . gvb_url('gv-message', ['id' => $id]) . "\n\n(Le contenu du message n'est volontairement pas recopié dans cet e-mail.)");
    }
    gvb_log(0, 'Message reçu du formulaire de contact', 'message #' . $id . ' — ' . $objet);
    return ['ok' => true];
}

// ── Purge automatique (conservation limitée) ──────────────────────────────────
add_action('init', function () { if (!wp_next_scheduled('gvb_messages_purge')) wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'gvb_messages_purge'); }, 20);
add_action('gvb_messages_purge', 'gvb_messages_purger');
function gvb_messages_purger() {
    global $wpdb; $t = gvb_table('messages');
    $a = $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE cree_le < %s", gmdate('Y-m-d H:i:s', strtotime('-' . GVB_MESSAGE_CONSERVATION_MOIS . ' months'))));
    $b = $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE statut = 'spam' AND cree_le < %s", gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS)));
    return (int) $a + (int) $b;
}

// ── Écrans du back-office ─────────────────────────────────────────────────────
function gvb_messages_nouveaux() { global $wpdb; return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . gvb_table('messages') . " WHERE statut = 'nouveau'"); }
function gvb_message_badge($statut) {
    $couleurs = ['nouveau' => '#B54708', 'lu' => '#1F4FFF', 'traite' => '#007A2E', 'spam' => '#667085'];
    return '<span class="gvb-badge" style="background:' . esc_attr($couleurs[$statut] ?? '#667085') . '">' . esc_html(GVB_MESSAGE_ETATS[$statut] ?? $statut) . '</span>';
}

function gvb_page_messages() {
    gvb_exiger('contacts', 'voir');
    global $wpdb; $t = gvb_table('messages');
    $statut = isset($_GET['statut'], GVB_MESSAGE_ETATS[sanitize_key(wp_unslash($_GET['statut']))]) ? sanitize_key(wp_unslash($_GET['statut'])) : '';
    $q = isset($_GET['q']) ? mb_substr(sanitize_text_field(wp_unslash($_GET['q'])), 0, 80) : '';
    $where = '1=1'; $args = [];
    if ($statut !== '') { $where .= ' AND statut = %s'; $args[] = $statut; }
    if ($q !== '') { $like = '%' . $wpdb->esc_like($q) . '%'; $where .= ' AND (nom LIKE %s OR email LIKE %s OR message LIKE %s)'; array_push($args, $like, $like, $like); }
    $sql = "SELECT * FROM $t WHERE $where ORDER BY (statut = 'nouveau') DESC, id DESC LIMIT 300";
    $lignes = [];
    foreach ($wpdb->get_results($args ? $wpdb->prepare($sql, ...$args) : $sql) as $m) {
        $apercu = mb_substr(str_replace("\n", ' ', $m->message), 0, 90) . (mb_strlen($m->message) > 90 ? '…' : '');
        $lignes[] = '<tr' . ($m->statut === 'nouveau' ? ' style="font-weight:600"' : '') . '><td>' . esc_html(gvb_dt($m->cree_le)) . '</td><td>' . esc_html($m->nom) . '<br><small>' . esc_html($m->email) . '</small></td><td>' . esc_html(GVB_MESSAGE_OBJETS[$m->objet] ?? $m->objet) . '</td><td>' . esc_html($apercu) . '</td><td>' . gvb_message_badge($m->statut) . '</td><td><a class="button button-primary" href="' . esc_url(gvb_url('gv-message', ['id' => $m->id])) . '">Ouvrir</a></td></tr>';
    }
    gvb_debut('Messages du site', 'journal');
    echo '<form method="get" class="gvb-filtres"><input type="hidden" name="page" value="gv-messages">' . gvb_liste('statut', 'État', ['' => 'Tous'] + GVB_MESSAGE_ETATS, $statut) . gvb_champ('q', 'Recherche', $q) . gvb_bouton('Filtrer', false) . '</form>';
    echo gvb_tableau(['Reçu le', 'Expéditeur', 'Objet', 'Message', 'État', ''], $lignes, 'Aucun message pour le moment.');
    echo '<p class="gvb-rappel">Messages envoyés depuis le formulaire de la page Contact. Conservation : ' . GVB_MESSAGE_CONSERVATION_MOIS . ' mois ; les messages indésirables sont supprimés après 30 jours. Aucune réponse automatique n’est envoyée au visiteur : répondez depuis votre messagerie.</p>';
    gvb_fin();
}

function gvb_page_message() {
    gvb_exiger('contacts', 'voir');
    global $wpdb; $id = absint($_GET['id'] ?? 0);
    $m = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . gvb_table('messages') . ' WHERE id = %d', $id));
    gvb_debut('Message', 'journal', '<a class="button" href="' . esc_url(gvb_url('gv-messages')) . '">← Messages</a>');
    if (!$m) { echo '<p class="gvb-vide">Message introuvable.</p>'; gvb_fin(); return; }
    if ($m->statut === 'nouveau' && gvb_can('contacts', 'modifier')) { $wpdb->update(gvb_table('messages'), ['statut' => 'lu', 'traite_par' => get_current_user_id(), 'traite_le' => gvb_now()], ['id' => $m->id]); $m->statut = 'lu'; }
    $sujet = 'Votre message au Groupe VITO S. — ' . (GVB_MESSAGE_OBJETS[$m->objet] ?? '');
    $repondre = 'mailto:' . $m->email . '?subject=' . rawurlencode($sujet);
    echo '<div class="gvb-boite" style="max-width:820px"><p>' . gvb_message_badge($m->statut) . ' reçu le ' . esc_html(gvb_dt($m->cree_le)) . '</p><table class="widefat striped" style="max-width:680px"><tbody>'
        . '<tr><th scope="row">Nom</th><td>' . esc_html($m->nom) . '</td></tr><tr><th scope="row">E-mail</th><td><a href="' . esc_url($repondre, ['mailto']) . '">' . esc_html($m->email) . '</a></td></tr>'
        . '<tr><th scope="row">Téléphone</th><td>' . esc_html($m->telephone ?: '—') . '</td></tr><tr><th scope="row">Objet</th><td>' . esc_html(GVB_MESSAGE_OBJETS[$m->objet] ?? $m->objet) . '</td></tr>'
        . '<tr><th scope="row">Pôle</th><td>' . esc_html(GVB_POLES[$m->pole] ?? '—') . '</td></tr></tbody></table>'
        . '<h2>Message</h2><div style="white-space:pre-wrap;word-break:break-word;background:#F8F9FC;border:1px solid var(--ligne);border-radius:8px;padding:12px 14px">' . esc_html($m->message) . '</div>'
        . '<p class="gvb-rappel">Traité par : ' . esc_html(($u = $m->traite_par ? get_userdata($m->traite_par) : null) ? $u->display_name : '—') . '</p>';
    echo '<div class="gvb-actions"><a class="button button-primary" href="' . esc_url($repondre, ['mailto']) . '">Répondre par e-mail</a>';
    if (gvb_can('contacts', 'modifier')) foreach (['traite' => 'Marquer comme traité', 'lu' => 'Remettre en « lu »', 'spam' => 'Signaler comme indésirable'] as $etat => $lib) if ($etat !== $m->statut) echo gvb_form('gvb_message_etat', 'gvb_message_etat', ['id' => $m->id, 'etat' => $etat], 'class="gvb-inline"') . gvb_bouton($lib, false) . '</form>';
    if (gvb_can('contacts', 'annuler')) echo gvb_form('gvb_message_supprimer', 'gvb_message_supprimer', ['id' => $m->id], 'class="gvb-inline" onsubmit="return confirm(\'Supprimer définitivement ce message ?\')"') . gvb_bouton('Supprimer', false) . '</form>';
    echo '</div></div>';
    gvb_fin();
}

add_action('admin_post_gvb_message_etat', function () {
    gvb_garde('contacts', 'modifier', 'gvb_message_etat');
    global $wpdb; $id = absint($_POST['id'] ?? 0); $etat = sanitize_key(wp_unslash($_POST['etat'] ?? ''));
    if (!isset(GVB_MESSAGE_ETATS[$etat]) || $etat === 'nouveau') gvb_retour('gv-message', ['id' => $id], null, 'État invalide.');
    $wpdb->update(gvb_table('messages'), ['statut' => $etat, 'traite_par' => get_current_user_id(), 'traite_le' => gvb_now()], ['id' => $id]);
    gvb_log(0, 'Message du site : ' . GVB_MESSAGE_ETATS[$etat], 'message #' . $id);
    gvb_retour('gv-message', ['id' => $id], 'Message mis à jour.');
});
add_action('admin_post_gvb_message_supprimer', function () {
    gvb_garde('contacts', 'annuler', 'gvb_message_supprimer');
    global $wpdb; $id = absint($_POST['id'] ?? 0);
    $wpdb->delete(gvb_table('messages'), ['id' => $id], ['%d']);
    gvb_log(0, 'Message du site supprimé', 'message #' . $id);
    gvb_retour('gv-messages', [], 'Message supprimé.');
});
