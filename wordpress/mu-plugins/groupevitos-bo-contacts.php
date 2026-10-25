<?php
/**
 * Plugin Name: Groupe Vitos — Back-office : contacts (plaquette)
 * Description: Le visiteur laisse son e-mail ou son numéro WhatsApp pour recevoir la plaquette ; contacts consultables par la Direction et la Scolarité.
 */

if (!defined('ABSPATH')) exit;

const GVB_CONTACT_STATUTS = ['envoye' => 'Envoyée', 'echec' => 'Échec d’envoi', 'manuel' => 'WhatsApp à envoyer', 'whatsapp_envoye' => 'WhatsApp envoyé', 'desinscrit' => 'Désinscrit', 'supprime' => 'Supprimé'];
const GVB_CONTACT_VALIDITE_JOURS = 30;
const GVB_CONSERVATION_MOIS = 24;

add_action('init', function () {
    if (get_option('gvb_db_contacts') === '2') return;
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta('CREATE TABLE ' . gvb_table('contacts') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  canal varchar(10) NOT NULL DEFAULT 'email',
  nom varchar(120) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  whatsapp varchar(24) NOT NULL DEFAULT '',
  pole varchar(12) NOT NULL DEFAULT '',
  source varchar(120) NOT NULL DEFAULT '',
  consentement_le datetime NOT NULL,
  token varchar(64) NOT NULL DEFAULT '',
  token_expire datetime NULL,
  statut varchar(16) NOT NULL DEFAULT 'envoye',
  envois int(11) NOT NULL DEFAULT 0,
  telechargements int(11) NOT NULL DEFAULT 0,
  dernier_envoi datetime NULL,
  cree_le datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY email (email),
  KEY whatsapp (whatsapp),
  KEY token (token),
  KEY cree_le (cree_le)
) " . $wpdb->get_charset_collate() . ';');
    update_option('gvb_db_contacts', '2', false);
}, 5);

function gvb_plaquette_chemin() {
    $dir = function_exists('gvc_dossier') ? gvc_dossier() : WP_CONTENT_DIR . '/uploads/groupevitos-prive';
    return $dir . '/plaquette.pdf';
}
function gvb_plaquette_lien($token) { return add_query_arg(['action' => 'gvb_plaquette', 't' => $token], admin_url('admin-post.php')); }
function gvb_desinscription_lien($token) { return add_query_arg(['action' => 'gvb_desinscrire', 't' => $token], admin_url('admin-post.php')); }

function gvb_normaliser_whatsapp($n) {
    $n = preg_replace('/[^\d+]/', '', (string) $n);
    if (str_starts_with($n, '00')) $n = '+' . substr($n, 2);
    if ($n !== '' && $n[0] !== '+') $n = '+223' . ltrim($n, '0');
    $chiffres = preg_replace('/\D/', '', $n);
    return strlen($chiffres) >= 8 && strlen($chiffres) <= 15 ? '+' . $chiffres : '';
}

// ── Brevo (SMTP) : identifiants saisis par la Direction dans Réglages ─────────
function gvb_smtp() { return wp_parse_args(get_option('gvb_smtp', []), ['actif' => 0, 'hote' => 'smtp-relay.brevo.com', 'port' => 587, 'login' => '', 'cle' => '', 'expediteur' => 'contact@groupevitos.com']); }
add_action('phpmailer_init', function ($m) {
    $c = gvb_smtp();
    if (!$c['actif'] || $c['login'] === '' || $c['cle'] === '') return;
    $m->isSMTP(); $m->Host = $c['hote']; $m->Port = (int) $c['port']; $m->SMTPAuth = true; $m->Username = $c['login']; $m->Password = $c['cle']; $m->SMTPSecure = 'tls';
    $m->setFrom($c['expediteur'], 'Groupe VITO S.', false);
});

// Dernière erreur d'envoi, affichée sur l'écran Contacts pour diagnostiquer (jamais de mot de passe dedans)
add_action('wp_mail_failed', function ($e) {
    $c = gvb_smtp();
    $msg = str_replace([$c['cle'], $c['login']], ['***', '***'], (string) $e->get_error_message());
    update_option('gvb_dernier_echec_mail', ['le' => gvb_now(), 'message' => mb_substr($msg, 0, 300), 'smtp' => $c['actif'] ? 'Brevo activé' : 'Brevo non activé (envoi standard)'], false);
});

// ── Formulaire public ─────────────────────────────────────────────────────────
add_action('rest_api_init', function () {
    register_rest_route('groupevitos/v1', '/plaquette', ['methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'gvb_recevoir_contact']);
});

function gvb_recevoir_contact(WP_REST_Request $r) {
    global $wpdb;
    $p = $r->get_params();
    if (!empty($p['site_web'])) return ['ok' => true];
    $cle = 'gvb_rlc_' . md5(gvb_ip_visiteur());
    $n = (int) get_transient($cle);
    if ($n >= 5) return new WP_Error('trop_de_demandes', 'Trop de demandes depuis cette adresse. Réessayez plus tard.', ['status' => 429]);
    if (empty($p['consentement'])) return new WP_Error('consentement', 'Veuillez accepter le traitement de vos données.', ['status' => 422]);
    $canal = ($p['canal'] ?? '') === 'whatsapp' ? 'whatsapp' : 'email';
    $email = ''; $wa = '';
    if ($canal === 'email') {
        $email = sanitize_email(wp_unslash($p['email'] ?? ''));
        if (!is_email($email)) return new WP_Error('email', 'Adresse e-mail invalide.', ['status' => 422]);
    } else {
        $wa = gvb_normaliser_whatsapp(wp_unslash($p['whatsapp'] ?? ''));
        if ($wa === '') return new WP_Error('whatsapp', 'Numéro WhatsApp invalide (indicatif pays puis numéro).', ['status' => 422]);
    }
    $nom = mb_substr(sanitize_text_field(wp_unslash($p['nom'] ?? '')), 0, 120);
    if ($nom === '') return new WP_Error('nom', 'Indiquez votre nom.', ['status' => 422]);
    $pole = isset(GVB_POLES[$p['pole'] ?? '']) ? $p['pole'] : '';
    $source = mb_substr(sanitize_text_field(wp_unslash($p['source'] ?? '')), 0, 120);
    set_transient($cle, $n + 1, HOUR_IN_SECONDS);

    $t = gvb_table('contacts');
    $col = $canal === 'email' ? 'email' : 'whatsapp';
    $val = $canal === 'email' ? $email : $wa;
    $existant = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE $col = %s AND statut NOT IN ('supprime') ORDER BY id DESC LIMIT 1", $val));
    if ($existant && $existant->statut === 'desinscrit') return new WP_Error('desinscrit', 'Cette adresse s’est désinscrite. Contactez-nous pour la réactiver.', ['status' => 422]);
    // Au plus 3 envois par jour pour un même contact
    if ($existant && $existant->dernier_envoi && $existant->envois >= 3 && strtotime($existant->dernier_envoi) > time() - DAY_IN_SECONDS) return ['ok' => true, 'canal' => $canal];

    $now = gvb_now();
    $token = wp_generate_password(40, false, false);
    $expire = gmdate('Y-m-d H:i:s', strtotime($now) + GVB_CONTACT_VALIDITE_JOURS * DAY_IN_SECONDS);
    if ($existant) {
        $id = (int) $existant->id;
        $wpdb->update($t, ['nom' => $nom, 'token' => $token, 'token_expire' => $expire, 'pole' => $pole ?: $existant->pole], ['id' => $id]);
    } else {
        $wpdb->insert($t, ['canal' => $canal, 'nom' => $nom, 'email' => $email, 'whatsapp' => $wa, 'pole' => $pole, 'source' => $source, 'consentement_le' => $now, 'token' => $token, 'token_expire' => $expire, 'statut' => 'envoye', 'cree_le' => $now]);
        $id = (int) $wpdb->insert_id;
    }
    $lien = gvb_plaquette_lien($token);
    if ($canal === 'email') {
        $ok = gvb_mail($email, 'La plaquette du Groupe VITO S.', "Bonjour $nom,\n\nMerci de votre intérêt pour le Groupe VITO S. Téléchargez notre plaquette (lien valable " . GVB_CONTACT_VALIDITE_JOURS . " jours) :\n$lien\n\nPour toute question, répondez à ce message ou écrivez à " . gvb_reglages()['email'] . ".\n\nGroupe VITO S.\n\nSe désinscrire : " . gvb_desinscription_lien($token));
        $statut = $ok ? 'envoye' : 'echec';
    } else {
        $ok = apply_filters('gvb_whatsapp_envoyer', false, $wa, $lien);
        $statut = $ok ? 'whatsapp_envoye' : 'manuel';
        if ($statut === 'manuel') gvb_mail(gvb_reglages()['email'], 'Plaquette à envoyer par WhatsApp', "Un visiteur demande la plaquette sur WhatsApp : $wa\n\nÀ traiter : " . gvb_url('gv-contacts'));
    }
    $wpdb->query($wpdb->prepare("UPDATE $t SET statut = %s, envois = envois + 1, dernier_envoi = %s WHERE id = %d", $statut, $now, $id));
    gvb_log(0, 'Plaquette demandée (' . $canal . ')', 'contact #' . $id . ' — ' . $statut);
    return ['ok' => true, 'canal' => $canal];
}

// ── Téléchargement et désinscription (liens personnels) ───────────────────────
function gvb_contact_par_token($token) {
    global $wpdb;
    $token = preg_replace('/[^A-Za-z0-9]/', '', (string) $token);
    if (strlen($token) < 30) return null;
    return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . gvb_table('contacts') . ' WHERE token = %s', $token));
}
function gvb_action_plaquette() {
    global $wpdb;
    $c = gvb_contact_par_token($_GET['t'] ?? '');
    if (!$c || in_array($c->statut, ['desinscrit', 'supprime'], true) || !$c->token_expire || strtotime($c->token_expire) < time()) wp_die('Ce lien n’est plus valable. Demandez à nouveau la plaquette sur groupevitos.com.', 'Lien expiré', ['response' => 410, 'back_link' => false]);
    $f = gvb_plaquette_chemin();
    if (!is_file($f)) wp_die('La plaquette est momentanément indisponible. Merci de réessayer plus tard.', 'Indisponible', ['response' => 503, 'back_link' => false]);
    $wpdb->query($wpdb->prepare('UPDATE ' . gvb_table('contacts') . ' SET telechargements = telechargements + 1 WHERE id = %d', $c->id));
    nocache_headers();
    header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="Plaquette-Groupe-VITOS.pdf"'); header('Content-Length: ' . filesize($f)); header('X-Robots-Tag: noindex');
    readfile($f);
    gvb_terminer();
}
add_action('admin_post_nopriv_gvb_plaquette', 'gvb_action_plaquette');
add_action('admin_post_gvb_plaquette', 'gvb_action_plaquette');

function gvb_action_desinscrire() {
    global $wpdb;
    $c = gvb_contact_par_token($_GET['t'] ?? '');
    if ($c && $c->statut !== 'supprime') $wpdb->update(gvb_table('contacts'), ['statut' => 'desinscrit', 'token' => '', 'token_expire' => null], ['id' => $c->id]);
    wp_die('Vous êtes désinscrit : vous ne recevrez plus de message de notre part. Pour effacer vos données, écrivez-nous.', 'Désinscription', ['response' => 200, 'back_link' => false]);
}
add_action('admin_post_nopriv_gvb_desinscrire', 'gvb_action_desinscrire');
add_action('admin_post_gvb_desinscrire', 'gvb_action_desinscrire');

// ── Écran Contacts ────────────────────────────────────────────────────────────
function gvb_filtres_contacts() {
    return ['canal' => in_array($_GET['canal'] ?? '', ['email', 'whatsapp'], true) ? $_GET['canal'] : '', 'pole' => isset(GVB_POLES[$_GET['pole'] ?? '']) ? $_GET['pole'] : '', 'statut' => isset(GVB_CONTACT_STATUTS[$_GET['statut'] ?? '']) ? $_GET['statut'] : '', 'q' => sanitize_text_field(wp_unslash($_GET['q'] ?? ''))];
}
function gvb_requete_contacts($f, $limite = 0) {
    global $wpdb;
    $where = ['1=1']; $args = [];
    foreach (['canal', 'pole', 'statut'] as $k) if ($f[$k] !== '') { $where[] = "$k = %s"; $args[] = $f[$k]; }
    if ($f['q'] !== '') { $like = '%' . $wpdb->esc_like($f['q']) . '%'; $where[] = '(email LIKE %s OR whatsapp LIKE %s OR nom LIKE %s)'; array_push($args, $like, $like, $like); }
    $sql = 'SELECT * FROM ' . gvb_table('contacts') . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC' . ($limite ? ' LIMIT ' . (int) $limite : '');
    return $args ? $wpdb->get_results($wpdb->prepare($sql, $args)) : $wpdb->get_results($sql);
}

function gvb_page_contacts() {
    gvb_exiger('contacts', 'voir');
    $f = gvb_filtres_contacts();
    $lignes = [];
    foreach (gvb_requete_contacts($f, 500) as $c) {
        $contact = $c->canal === 'email' ? $c->email : $c->whatsapp;
        $actions = '';
        if ($c->canal === 'whatsapp' && $c->token && $c->statut === 'manuel' && gvb_can('contacts', 'modifier')) {
            $texte = 'Bonjour, voici la plaquette du Groupe VITO S. : ' . gvb_plaquette_lien($c->token);
            $actions .= '<a class="button button-primary" target="_blank" rel="noopener" href="' . esc_url('https://wa.me/' . preg_replace('/\D/', '', $c->whatsapp) . '?text=' . rawurlencode($texte)) . '">Envoyer sur WhatsApp</a> '
                . gvb_form('gvb_contact_marquer', 'gvb_contact_marquer', ['id' => $c->id], 'class="gvb-inline"') . gvb_bouton('Marquer envoyé', false) . '</form> ';
        }
        if (gvb_can('contacts', 'annuler') && $c->statut !== 'supprime') $actions .= gvb_form('gvb_contact_supprimer', 'gvb_contact_supprimer', ['id' => $c->id], 'class="gvb-inline" onsubmit="return confirm(\'Effacer les données de ce contact ?\')"') . gvb_bouton('Effacer', false) . '</form>';
        $perime = strtotime($c->cree_le) < strtotime('-' . GVB_CONSERVATION_MOIS . ' months');
        $lignes[] = '<tr><td>' . esc_html(gvb_dt($c->cree_le)) . ($perime ? ' <span class="gvb-ko">À supprimer</span>' : '') . '</td><td>' . esc_html($c->nom ?: '-') . '</td><td>' . esc_html($c->canal === 'email' ? 'E-mail' : 'WhatsApp') . '</td><td>' . esc_html($contact ?: '—') . '</td><td>' . esc_html(GVB_POLES[$c->pole] ?? '—') . '</td><td>' . gvb_badge(in_array($c->statut, ['envoye', 'whatsapp_envoye'], true) ? 'validee' : ($c->statut === 'echec' ? 'incomplete' : ($c->statut === 'manuel' ? 'nouvelle' : 'annulee')), GVB_CONTACT_STATUTS[$c->statut] ?? $c->statut) . '</td><td>' . (int) $c->telechargements . '</td><td>' . $actions . '</td></tr>';
    }
    $actions = gvb_can('contacts', 'exporter') ? '<a class="button" href="' . esc_url(wp_nonce_url(add_query_arg(array_merge(['action' => 'gvb_export_contacts'], array_filter($f)), admin_url('admin-post.php')), 'gvb_export_contacts')) . '">Exporter (CSV)</a>' : '';
    gvb_debut('Contacts — plaquette', 'utilisateurs', $actions);
    echo '<form method="get" class="gvb-filtres"><input type="hidden" name="page" value="gv-contacts">' . gvb_liste('canal', 'Canal', ['' => 'Tous', 'email' => 'E-mail', 'whatsapp' => 'WhatsApp'], $f['canal']) . gvb_liste('pole', 'Pôle', ['' => 'Tous'] + GVB_POLES, $f['pole']) . gvb_liste('statut', 'État', ['' => 'Tous'] + GVB_CONTACT_STATUTS, $f['statut']) . gvb_champ('q', 'Recherche', $f['q'], 'search') . gvb_bouton('Filtrer', false) . '</form>';
    echo gvb_tableau(['Reçu le', 'Nom', 'Canal', 'Contact', 'Pôle', 'État', 'Téléch.', 'Actions'], $lignes, 'Aucun contact pour le moment.');
    if (($e = get_option('gvb_dernier_echec_mail')) && is_array($e)) echo '<div class="gvb-alerte"><strong>Dernier échec d’envoi (' . esc_html(gvb_dt($e['le'])) . ') :</strong> ' . esc_html($e['smtp']) . ' — ' . esc_html($e['message']) . '</div>';
    echo '<p class="gvb-rappel">Conservation : ' . GVB_CONSERVATION_MOIS . ' mois. ' . (is_file(gvb_plaquette_chemin()) ? 'Plaquette en place.' : '<strong class="gvb-ko">La plaquette n’est pas encore déposée sur le serveur.</strong>') . '</p>';
    gvb_fin();
}

add_action('admin_post_gvb_contact_marquer', function () {
    gvb_garde('contacts', 'modifier', 'gvb_contact_marquer');
    global $wpdb; $id = absint($_POST['id'] ?? 0);
    $wpdb->update(gvb_table('contacts'), ['statut' => 'whatsapp_envoye'], ['id' => $id, 'statut' => 'manuel']);
    gvb_log(0, 'Plaquette envoyée sur WhatsApp', 'contact #' . $id);
    gvb_retour('gv-contacts', [], 'Contact marqué comme envoyé.');
});
add_action('admin_post_gvb_contact_supprimer', function () {
    gvb_garde('contacts', 'annuler', 'gvb_contact_supprimer');
    global $wpdb; $id = absint($_POST['id'] ?? 0);
    $wpdb->update(gvb_table('contacts'), ['email' => '', 'nom' => '', 'whatsapp' => '', 'token' => '', 'token_expire' => null, 'source' => '', 'statut' => 'supprime'], ['id' => $id]);
    gvb_log(0, 'Données d’un contact effacées', 'contact #' . $id);
    gvb_retour('gv-contacts', [], 'Données effacées.');
});
add_action('admin_post_gvb_export_contacts', function () {
    gvb_garde('contacts', 'exporter', 'gvb_export_contacts');
    $neutre = fn($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v;
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="contacts-' . gmdate('Ymd') . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['Reçu le', 'Nom', 'Canal', 'E-mail', 'WhatsApp', 'Pôle', 'État', 'Téléchargements', 'Consentement'], ';');
    foreach (gvb_requete_contacts(gvb_filtres_contacts()) as $c) fputcsv($o, array_map($neutre, [$c->cree_le, $c->nom, $c->canal, $c->email, $c->whatsapp, GVB_POLES[$c->pole] ?? '', GVB_CONTACT_STATUTS[$c->statut] ?? $c->statut, $c->telechargements, $c->consentement_le]), ';');
    gvb_log(0, 'Export des contacts');
    gvb_terminer();
});

// ── Réglages Brevo (Direction) ────────────────────────────────────────────────
add_action('gvb_reglages_apres', function () {
    if (!gvb_can('parametres', 'modifier')) return;
    $c = gvb_smtp();
    echo '<div class="gvb-boite" style="max-width:640px"><h2>' . gvb_icone('reglage', 18) . 'Envoi des e-mails (Brevo)</h2>' . gvb_form('gvb_smtp_sauver', 'gvb_smtp_sauver')
        . '<label class="gvb-cases"><input type="checkbox" name="actif" value="1"' . checked($c['actif'], 1, false) . '> Envoyer via Brevo</label>'
        . gvb_champ('hote', 'Serveur SMTP', $c['hote']) . gvb_champ('port', 'Port', $c['port'], 'number') . gvb_champ('login', 'Identifiant SMTP Brevo', $c['login'])
        . gvb_champ('cle', 'Clé SMTP (laisser vide pour conserver l’actuelle)', '', 'password', 'autocomplete="new-password" placeholder="' . ($c['cle'] !== '' ? '•••••••• enregistrée' : '') . '"')
        . gvb_champ('expediteur', 'Adresse d’expédition (validée dans Brevo)', $c['expediteur'], 'email') . gvb_bouton('Enregistrer') . '</form></div>';
});
add_action('admin_post_gvb_smtp_sauver', function () {
    gvb_garde('parametres', 'modifier', 'gvb_smtp_sauver');
    $ancien = gvb_smtp();
    $cle = (string) wp_unslash($_POST['cle'] ?? '');
    $exp = sanitize_email(wp_unslash($_POST['expediteur'] ?? ''));
    if (!is_email($exp)) gvb_retour('gv-reglages', [], null, 'Adresse d’expédition invalide.');
    update_option('gvb_smtp', ['actif' => empty($_POST['actif']) ? 0 : 1, 'hote' => sanitize_text_field(wp_unslash($_POST['hote'] ?? '')) ?: 'smtp-relay.brevo.com', 'port' => absint($_POST['port'] ?? 587) ?: 587,
        'login' => sanitize_text_field(wp_unslash($_POST['login'] ?? '')), 'cle' => $cle !== '' ? $cle : $ancien['cle'], 'expediteur' => $exp], false);
    gvb_log(0, 'Réglages d’envoi des e-mails modifiés');
    gvb_retour('gv-reglages', [], 'Réglages d’envoi enregistrés.');
});
