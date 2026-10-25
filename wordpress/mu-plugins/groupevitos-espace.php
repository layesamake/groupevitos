<?php
/**
 * Plugin Name: Groupe Vitos — Espace en ligne (parents, étudiants, enseignants)
 * Description: Portail public /espace/ : connexion par e-mail et mot de passe, comptes distincts des comptes WordPress, accès strictement limité aux étudiants rattachés.
 */

if (!defined('ABSPATH')) exit;

const GVE_COOKIE = 'gvb_espace';
const GVE_SESSION_HEURES = 12;

// ── Aiguillage ────────────────────────────────────────────────────────────────
add_action('init', function () {
    if (defined('GVB_ESSAI') || empty($_SERVER['REQUEST_URI'])) return;
    $chemin = (string) wp_parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (!preg_match('#^/espace(/.*)?$#', $chemin, $m)) return;
    gve_servir(trim($m[1] ?? '', '/'));
    exit;
}, 6);

function gve_fin() { if (defined('GVB_ESSAI')) throw new Exception('FIN'); exit; }
function gve_aller($url) { if (defined('GVB_ESSAI')) throw new Exception('REDIR:' . $url); wp_safe_redirect($url); exit; }
function gve_cookie($valeur, $expire) {
    if (defined('GVB_ESSAI')) { $GLOBALS['gve_cookies'][GVE_COOKIE] = $valeur; return; }
    setcookie(GVE_COOKIE, $valeur, ['expires' => $expire, 'path' => '/espace', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);
}
function gve_h($s) { return esc_html((string) $s); }
function gve_ip() { return md5(function_exists('gvb_ip_visiteur') ? gvb_ip_visiteur() : (string) ($_SERVER['REMOTE_ADDR'] ?? '')); }
function gve_limite($cle, $max, $duree) {
    $n = (int) get_transient($cle);
    if ($n >= $max) return false;
    set_transient($cle, $n + 1, $duree);
    return true;
}

// ── Sessions ──────────────────────────────────────────────────────────────────
function gve_compte() {
    static $cache = []; global $wpdb;
    $jeton = (string) ($_COOKIE[GVE_COOKIE] ?? '');
    if (!preg_match('/^[A-Za-z0-9]{48}$/', $jeton)) return null;
    $h = hash('sha256', $jeton);
    $s = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . gvb_table('sessions') . ' WHERE jeton_hash = %s AND expire > %s', $h, gvb_now()));
    if (!$s) return null;
    $c = gvb_compte_portail($s->compte_id);
    if (!$c || !$c->actif || $c->mdp === '') return null;
    $c->session_hash = $h;
    return $c;
}
function gve_ouvrir_session($c) {
    global $wpdb;
    $jeton = wp_generate_password(48, false, false);
    $wpdb->delete(gvb_table('sessions'), ['compte_id' => $c->id]); // une seule session active par compte
    $wpdb->insert(gvb_table('sessions'), ['compte_id' => $c->id, 'jeton_hash' => hash('sha256', $jeton), 'expire' => gmdate('Y-m-d H:i:s', time() + GVE_SESSION_HEURES * HOUR_IN_SECONDS), 'cree_le' => gvb_now()]);
    $wpdb->update(gvb_table('comptes'), ['derniere_connexion' => gvb_now()], ['id' => $c->id]);
    $_COOKIE[GVE_COOKIE] = $jeton;
    gve_cookie($jeton, time() + GVE_SESSION_HEURES * HOUR_IN_SECONDS);
}
function gve_fermer_session($c) {
    global $wpdb;
    if ($c) $wpdb->delete(gvb_table('sessions'), ['compte_id' => $c->id]);
    unset($_COOKIE[GVE_COOKIE]);
    gve_cookie('', time() - 3600);
}
function gve_csrf($c) { return hash_hmac('sha256', $c->session_hash, wp_salt('auth')); }
function gve_csrf_champ($c) { return '<input type="hidden" name="csrf" value="' . esc_attr(gve_csrf($c)) . '">'; }
function gve_csrf_ok($c) { return isset($_POST['csrf']) && hash_equals(gve_csrf($c), (string) $_POST['csrf']); }
function gve_exiger($c) { if (!$c) gve_aller(gvb_espace_url('connexion')); return $c; }

// ── Mise en page ──────────────────────────────────────────────────────────────
function gve_page($titre, $html, $c = null, $actif = '') {
    if (!defined('GVB_ESSAI')) {
        nocache_headers(); header('Content-Type: text/html; charset=utf-8'); header('X-Robots-Tag: noindex, nofollow'); header('X-LiteSpeed-Cache-Control: no-cache'); header('Vary: Cookie'); header('X-Frame-Options: DENY'); header('Referrer-Policy: same-origin'); header('X-Content-Type-Options: nosniff');
    }
    $nav = '';
    if ($c) {
        $liens = $c->type === 'enseignant' ? ['' => 'Accueil', 'planning' => 'Emploi du temps', 'appel' => 'Appel', 'cours' => 'Mes cours', 'annonces' => 'Annonces'] : ['' => 'Accueil', 'planning' => 'Emploi du temps', 'presences' => 'Présences', 'finances' => 'Finances', 'annonces' => 'Annonces'];
        $e = (int) ($_GET['e'] ?? 0);
        foreach ($liens as $k => $l) $nav .= '<a href="' . esc_url(gvb_espace_url($k, $e && $c->type !== 'enseignant' ? ['e' => $e] : [])) . '"' . ($actif === $k ? ' class="actif" aria-current="page"' : '') . '>' . gve_h($l) . '</a>';
        $nav = '<nav>' . $nav . '<form method="post" action="' . esc_url(gvb_espace_url('deconnexion')) . '">' . gve_csrf_champ($c) . '<button type="submit" class="lien">Se déconnecter</button></form></nav>';
    }
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . gve_h($titre) . ' — Groupe VITO S.</title>'
        . '<link rel="icon" href="https://groupevitos.com/favicon.svg" type="image/svg+xml"><style>'
        . ':root{--ink:#08065B;--blue:#1F4FFF;--vert:#007A2E;--jaune:#FFC23D;--ligne:#D6DCEB;--fond:#F2F4F9;--muted:#4A5578}*{box-sizing:border-box}body{margin:0;font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#111827;background:var(--fond)}'
        . 'header{background:var(--ink);color:#fff;padding:14px 16px;display:flex;align-items:center;gap:12px;border-bottom:4px solid var(--jaune)}header img{height:34px;background:#fff;border-radius:6px;padding:3px}header strong{font-size:18px}'
        . 'nav{display:flex;flex-wrap:wrap;gap:4px;background:#fff;border-bottom:1px solid var(--ligne);padding:6px 12px;align-items:center}nav a,nav .lien{padding:8px 12px;border-radius:8px;color:var(--ink);text-decoration:none;font-weight:600;background:none;border:0;font:inherit;font-weight:600;cursor:pointer}nav a.actif{background:var(--ink);color:#fff}nav form{margin-left:auto}'
        . 'main{max-width:960px;margin:0 auto;padding:16px}h1{font-size:24px;color:var(--ink);margin:8px 0 16px}h2{font-size:18px;color:var(--ink);border-bottom:3px solid var(--jaune);padding-bottom:6px;margin:0 0 12px}'
        . '.boite{background:#fff;border:1px solid var(--ligne);border-radius:12px;padding:16px;margin-bottom:16px}.cartes{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:16px}.carte{background:#fff;border:1px solid var(--ligne);border-radius:12px;padding:14px}.carte strong{display:block;font-size:22px;color:var(--ink)}.carte small{color:var(--muted)}'
        . 'label{display:block;font-weight:600;margin:10px 0 4px}input,select,textarea{width:100%;min-height:44px;padding:8px 12px;border:1px solid #8A94B3;border-radius:8px;font:inherit}input[type=radio],input[type=checkbox]{width:auto;min-height:0}'
        . '.btn,button.btn{display:inline-block;margin-top:14px;padding:12px 20px;background:var(--vert);color:#fff;border:0;border-radius:10px;font:inherit;font-weight:700;cursor:pointer;text-decoration:none}.btn.sec{background:#fff;color:var(--ink);border:1px solid var(--ink)}'
        . '.msg{padding:10px 14px;border-radius:8px;margin-bottom:12px}.err{background:#FDECEA;border-left:5px solid #B42318}.ok{background:#E6F4EA;border-left:5px solid var(--vert)}table{width:100%;border-collapse:collapse}th,td{padding:8px 10px;border-bottom:1px solid var(--ligne);text-align:left;vertical-align:top}th{color:var(--ink);border-bottom:2px solid var(--jaune)}.d{text-align:right;white-space:nowrap}.def{overflow-x:auto}.petit{color:var(--muted);font-size:14px}'
        . '.badge{display:inline-block;padding:2px 10px;border-radius:999px;color:#fff;font-size:13px;font-weight:600}.annule{text-decoration:line-through;opacity:.55}.choix a{margin-right:6px}a{color:var(--blue)}@media print{header,nav,.noprint{display:none}body{background:#fff}}'
        . '</style></head><body><header><img src="https://groupevitos.com/logo-institut-vitos.webp" alt="" onerror="this.style.display=\'none\'"><strong>Espace en ligne — Groupe VITO S.</strong></header>' . $nav . '<main><h1>' . gve_h($titre) . '</h1>' . $html . '</main></body></html>';
}
function gve_msg($texte, $type = 'err') { return $texte ? '<div class="msg ' . $type . '" role="' . ($type === 'err' ? 'alert' : 'status') . '">' . gve_h($texte) . '</div>' : ''; }
function gve_badge($txt, $couleur) { return '<span class="badge" style="background:' . esc_attr($couleur) . '">' . gve_h($txt) . '</span>'; }

// ── Routeur ───────────────────────────────────────────────────────────────────
function gve_servir($page) {
    $c = gve_compte(); $post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    switch ($page) {
        case 'connexion': return gve_connexion($c, $post);
        case 'deconnexion': if ($post && $c && gve_csrf_ok($c)) gve_fermer_session($c); gve_aller(gvb_espace_url('connexion'));
        case 'invitation': case 'reinitialiser': return gve_definir_mdp($page, $post);
        case 'oubli': return gve_oubli($post);
        case 'desabonner': return gve_desabonner();
    }
    gve_exiger($c);
    $fonctions = ['' => 'gve_accueil', 'planning' => 'gve_planning', 'presences' => 'gve_presences', 'finances' => 'gve_finances', 'recu' => 'gve_recu', 'annonces' => 'gve_annonces', 'cours' => 'gve_cours', 'appel' => 'gve_appel'];
    if (!isset($fonctions[$page])) { status_header(404); return gve_page('Page introuvable', '<p>Cette page n’existe pas.</p>', $c); }
    return call_user_func($fonctions[$page], $c, $post);
}

// ── Connexion, invitation, mot de passe ───────────────────────────────────────
function gve_connexion($c, $post) {
    if ($c) gve_aller(gvb_espace_url());
    global $wpdb; $msg = '';
    if ($post) {
        $email = sanitize_email(wp_unslash($_POST['email'] ?? '')); $mdp = (string) wp_unslash($_POST['mdp'] ?? '');
        if (!gve_limite('gve_lg_' . md5(strtolower($email) . gve_ip()), 5, 15 * MINUTE_IN_SECONDS) || !gve_limite('gve_lgip_' . gve_ip(), 30, 15 * MINUTE_IN_SECONDS)) $msg = 'Trop de tentatives. Réessayez dans 15 minutes.';
        else {
            $compte = is_email($email) ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . gvb_table('comptes') . ' WHERE LOWER(email) = LOWER(%s)', $email)) : null;
            $hash = $compte && $compte->mdp !== '' ? $compte->mdp : wp_hash_password('leurre-' . wp_generate_password(8));
            $ok = wp_check_password($mdp, $hash) && $compte && $compte->actif && $compte->mdp !== '';
            if ($ok) { delete_transient('gve_lg_' . md5(strtolower($email) . gve_ip())); gve_ouvrir_session($compte); gve_aller(gvb_espace_url()); }
            $msg = 'Adresse e-mail ou mot de passe incorrect.';
        }
    }
    gve_page('Connexion', gve_msg($msg) . '<div class="boite"><form method="post"><label for="email">Adresse e-mail</label><input id="email" name="email" type="email" autocomplete="username" required><label for="mdp">Mot de passe</label><input id="mdp" name="mdp" type="password" autocomplete="current-password" required><button class="btn" type="submit">Se connecter</button></form><p class="petit"><a href="' . esc_url(gvb_espace_url('oubli')) . '">Mot de passe oublié ou première connexion ?</a></p><p class="petit">L’accès se fait sur invitation de l’établissement.</p></div>');
}
function gve_verifier_mdp($mdp, $mdp2) {
    if ($mdp !== $mdp2) return 'Les deux mots de passe ne sont pas identiques.';
    if (mb_strlen($mdp) < 10) return 'Le mot de passe doit contenir au moins 10 caractères.';
    if (!preg_match('/[A-Za-z]/', $mdp) || !preg_match('/\d/', $mdp)) return 'Le mot de passe doit contenir des lettres et au moins un chiffre.';
    return null;
}
function gve_jeton_valide($jeton, $types) {
    global $wpdb;
    if (!preg_match('/^[A-Za-z0-9]{40}$/', $jeton)) return null;
    $j = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . gvb_table('jetons') . ' WHERE jeton_hash = %s AND utilise = 0 AND expire > %s', hash('sha256', $jeton), gvb_now()));
    return $j && in_array($j->type, $types, true) ? $j : null;
}
function gve_definir_mdp($page, $post) {
    global $wpdb;
    $jeton = (string) ($post ? ($_POST['t'] ?? '') : ($_GET['t'] ?? ''));
    $j = gve_jeton_valide($jeton, [$page === 'invitation' ? 'invitation' : 'reset']);
    $c = $j ? gvb_compte_portail($j->compte_id) : null;
    if (!$j || !$c || !$c->actif) { status_header(410); return gve_page('Lien expiré', '<div class="boite"><p>Ce lien n’est plus valable (expiré ou déjà utilisé).</p><p><a class="btn" href="' . esc_url(gvb_espace_url('oubli')) . '">Recevoir un nouveau lien</a></p></div>'); }
    $msg = '';
    if ($post) {
        $mdp = (string) wp_unslash($_POST['mdp'] ?? ''); $err = gve_verifier_mdp($mdp, (string) wp_unslash($_POST['mdp2'] ?? ''));
        if ($err) $msg = $err;
        else {
            $wpdb->update(gvb_table('comptes'), ['mdp' => wp_hash_password($mdp)], ['id' => $c->id]);
            $wpdb->update(gvb_table('jetons'), ['utilise' => 1], ['compte_id' => $c->id]);
            $wpdb->delete(gvb_table('sessions'), ['compte_id' => $c->id]);
            gvb_log(0, 'Mot de passe défini (portail)', $c->email);
            return gve_page('Mot de passe enregistré', gve_msg('Votre mot de passe est enregistré. Vous pouvez vous connecter.', 'ok') . '<p><a class="btn" href="' . esc_url(gvb_espace_url('connexion')) . '">Se connecter</a></p>');
        }
    }
    gve_page($page === 'invitation' ? 'Créer votre mot de passe' : 'Nouveau mot de passe', gve_msg($msg) . '<div class="boite"><p>Compte : <strong>' . gve_h($c->email) . '</strong></p><form method="post"><input type="hidden" name="t" value="' . esc_attr($jeton) . '"><label for="mdp">Mot de passe (10 caractères minimum, lettres et chiffres)</label><input id="mdp" name="mdp" type="password" autocomplete="new-password" required minlength="10"><label for="mdp2">Confirmer le mot de passe</label><input id="mdp2" name="mdp2" type="password" autocomplete="new-password" required minlength="10"><button class="btn" type="submit">Enregistrer</button></form></div>');
}
function gve_oubli($post) {
    global $wpdb; $fait = false; $msg = '';
    if ($post) {
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        if (!gve_limite('gve_oubli_' . gve_ip(), 5, HOUR_IN_SECONDS)) $msg = 'Trop de demandes. Réessayez plus tard.';
        else {
            $c = is_email($email) ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . gvb_table('comptes') . ' WHERE LOWER(email) = LOWER(%s) AND actif = 1', $email)) : null;
            if ($c && gve_limite('gve_oubli_c' . $c->id, 3, HOUR_IN_SECONDS)) gvb_envoyer_invitation($c->id, $c->mdp !== '');
            $fait = true;
        }
    }
    gve_page('Mot de passe oublié', gve_msg($msg) . ($fait ? gve_msg('Si cette adresse correspond à un compte, un e-mail vient d’être envoyé avec un lien (valable quelques heures). Pensez à regarder vos courriers indésirables.', 'ok') : '') . '<div class="boite"><form method="post"><label for="email">Votre adresse e-mail</label><input id="email" name="email" type="email" required><button class="btn" type="submit">Recevoir un lien</button></form></div>');
}
function gve_desabonner() {
    global $wpdb;
    $e = base64_decode(strtr((string) ($_GET['e'] ?? ''), '-_', '+/'), true); $s = (string) ($_GET['s'] ?? '');
    if (!$e || !is_email($e) || !hash_equals(hash_hmac('sha256', strtolower($e), wp_salt('auth')), $s)) { status_header(400); return gve_page('Lien invalide', '<div class="boite"><p>Ce lien de désabonnement n’est pas valide.</p></div>'); }
    if (!gvb_est_desinscrit($e)) $wpdb->insert(gvb_table('desinscrits'), ['email_hash' => hash('sha256', strtolower($e)), 'cree_le' => gvb_now()]);
    $wpdb->query($wpdb->prepare('UPDATE ' . gvb_table('comptes') . ' SET notif_ok = 0 WHERE LOWER(email) = LOWER(%s)', $e));
    gve_page('Désabonnement', gve_msg('Vous ne recevrez plus de notifications par e-mail à cette adresse.', 'ok') . '<div class="boite"><p class="petit">Pour des raisons de scolarité (échéances, absences), contactez l’établissement.</p></div>');
}

// ── Accès aux données (toujours via le rattachement du compte) ────────────────
function gve_enfants($c) { return $c->type === 'enseignant' ? [] : gvb_etudiants_du_compte($c->id); }
function gve_enfant($c, $enfants) {
    $id = (int) ($_GET['e'] ?? 0);
    if (!$id) return $enfants[0] ?? null;
    foreach ($enfants as $e) if ((int) $e->id === $id) return $e;
    return null;
}
function gve_choix_enfant($c, $enfants, $courant, $page) {
    if (count($enfants) < 2) return '';
    $h = '<p class="choix">';
    foreach ($enfants as $e) $h .= '<a class="btn' . ($courant && $e->id === $courant->id ? '' : ' sec') . '" href="' . esc_url(gvb_espace_url($page, ['e' => $e->id])) . '">' . gve_h($e->prenom) . '</a>';
    return $h . '</p>';
}
function gve_seances_etudiant($e, $du, $au) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare('SELECT s.*, c.intitule, en.nom AS e_nom, en.prenom AS e_prenom, r.nom AS salle_nom FROM ' . gvb_table('seances') . ' s JOIN ' . gvb_table('cours') . ' c ON c.id = s.cours_id JOIN ' . gvb_table('enseignants') . ' en ON en.id = s.enseignant_id LEFT JOIN ' . gvb_table('salles') . ' r ON r.id = s.salle_id WHERE s.pole = %s AND LOWER(s.niveau) = LOWER(%s) AND (s.formation_id = 0 OR s.formation_id = %d) AND s.date_seance BETWEEN %s AND %s ORDER BY s.date_seance, s.debut', $e->pole, $e->niveau, $e->formation_id, $du, $au));
}
function gve_tableau_seances($seances, $avec_ens = true, $enseignant = false) {
    $par = []; foreach ($seances as $s) $par[$s->date_seance][] = $s;
    if (!$par) return '<div class="boite"><p>Aucune séance cette semaine.</p></div>';
    $h = '';
    foreach ($par as $jour => $l) {
        $h .= '<div class="boite"><h2>' . gve_h(GVB_JOURS[(int) gmdate('N', strtotime($jour)) - 1] . ' ' . gvb_d($jour)) . '</h2><div class="def"><table><tbody>';
        foreach ($l as $s) $h .= '<tr class="' . ($s->statut === 'annulee' ? 'annule' : '') . '"><td class="d"><strong>' . gve_h(gvb_hhmm($s->debut) . '–' . gvb_hhmm($s->fin)) . '</strong></td><td>' . gve_h($s->intitule) . ($enseignant ? '<br><span class="petit">' . gve_h(gvb_groupe_libelle($s->pole, $s->formation_id, $s->niveau)) . '</span>' : ($avec_ens ? '<br><span class="petit">' . gve_h(mb_strtoupper($s->e_nom) . ' ' . $s->e_prenom) . '</span>' : '')) . '</td><td>' . gve_h($s->salle_nom ?: '') . '</td><td>' . ($s->statut === 'annulee' ? gve_badge('Annulée', '#6B7280') . ($s->motif ? '<br><span class="petit">' . gve_h($s->motif) . '</span>' : '') : ($s->statut === 'tenue' ? gve_badge('Tenue', '#007A2E') : '')) . '</td></tr>';
        $h .= '</tbody></table></div></div>';
    }
    return $h;
}
function gve_semaine() { return gvb_lundi(preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['sem'] ?? '') ? $_GET['sem'] : ''); }
function gve_nav_semaine($page, $sem, $args = []) {
    $l = fn($d, $t) => '<a class="btn sec" href="' . esc_url(gvb_espace_url($page, $args + ['sem' => $d])) . '">' . $t . '</a> ';
    return '<p class="noprint">' . $l(gmdate('Y-m-d', strtotime($sem) - 7 * DAY_IN_SECONDS), '← Précédente') . $l(gvb_lundi(gvb_aujourdhui()), 'Cette semaine') . $l(gmdate('Y-m-d', strtotime($sem) + 7 * DAY_IN_SECONDS), 'Suivante →') . '</p><p class="petit">Semaine du ' . gve_h(gvb_d($sem)) . ' au ' . gve_h(gvb_d(gmdate('Y-m-d', strtotime($sem) + 6 * DAY_IN_SECONDS))) . '</p>';
}
function gve_annonces_pour($c) {
    global $wpdb; $out = [];
    $rows = $wpdb->get_results('SELECT * FROM ' . gvb_table('annonces') . ' WHERE archive = 0 ORDER BY id DESC LIMIT 100');
    if ($c->type === 'enseignant') { $en = $wpdb->get_row($wpdb->prepare('SELECT poles FROM ' . gvb_table('enseignants') . ' WHERE id = %d', $c->enseignant_id)); $poles = $en && $en->poles !== '' ? explode(',', $en->poles) : null; }
    $enf = $c->type === 'enseignant' ? [] : gve_enfants($c);
    foreach ($rows as $a) {
        if ($c->type === 'enseignant') { if (!in_array($a->audience, ['tous', 'enseignants'], true) || ($a->pole !== '' && $poles !== null && !in_array($a->pole, $poles, true))) continue; }
        else {
            if (!in_array($a->audience, ['tous', 'familles'], true)) continue;
            $ok = false; foreach ($enf as $e) if (($a->pole === '' || $a->pole === $e->pole) && ($a->niveau === '' || mb_strtolower($a->niveau) === mb_strtolower($e->niveau))) $ok = true;
            if (!$ok) continue;
        }
        $out[] = $a;
    }
    return $out;
}
function gve_liste_annonces($annonces) {
    if (!$annonces) return '<p class="petit">Aucune annonce.</p>';
    $h = ''; foreach ($annonces as $a) $h .= '<div class="boite"><h2>' . gve_h($a->titre) . '</h2><p class="petit">' . gve_h(gvb_d($a->publie_le)) . '</p>' . wpautop(esc_html($a->corps)) . '</div>';
    return $h;
}

// ── Pages famille ─────────────────────────────────────────────────────────────
function gve_accueil($c, $post) {
    $annonces = array_slice(gve_annonces_pour($c), 0, 3);
    if ($c->type === 'enseignant') {
        global $wpdb; $auj = gvb_aujourdhui(); $h = '';
        $s = $wpdb->get_results($wpdb->prepare('SELECT s.*, c.intitule, r.nom AS salle_nom FROM ' . gvb_table('seances') . ' s JOIN ' . gvb_table('cours') . ' c ON c.id = s.cours_id LEFT JOIN ' . gvb_table('salles') . ' r ON r.id = s.salle_id WHERE s.enseignant_id = %d AND s.date_seance = %s ORDER BY s.debut', $c->enseignant_id, $auj));
        $h .= '<div class="boite"><h2>Aujourd’hui</h2>';
        if (!$s) $h .= '<p>Aucune séance aujourd’hui.</p>';
        foreach ($s as $x) $h .= '<p><strong>' . gve_h(gvb_hhmm($x->debut) . '–' . gvb_hhmm($x->fin)) . '</strong> — ' . gve_h($x->intitule) . ' · ' . gve_h(gvb_groupe_libelle($x->pole, $x->formation_id, $x->niveau)) . ($x->statut === 'annulee' ? ' ' . gve_badge('Annulée', '#6B7280') : ' <a class="btn sec" href="' . esc_url(gvb_espace_url('appel', ['id' => $x->id])) . '">' . ($x->statut === 'tenue' ? 'Voir l’appel' : 'Faire l’appel') . '</a>') . '</p>';
        $h .= '</div><h2>Annonces</h2>' . gve_liste_annonces($annonces);
        return gve_page('Bonjour ' . $c->nom, $h, $c, '');
    }
    $enfants = gve_enfants($c); $h = '';
    foreach ($enfants as $e) {
        $cp = gvb_compte($e->id); $st = gvb_stats_presence(['du' => gmdate('Y-m-01'), 'au' => gvb_aujourdhui(), 'pole' => '', 'niveau' => '', 'etudiant' => $e->id]); $o = $st[$e->id] ?? null;
        $h .= '<div class="boite"><h2>' . gve_h($e->prenom . ' ' . mb_strtoupper($e->nom)) . '</h2><p class="petit">' . gve_h(GVB_POLES[$e->pole] ?? '') . ' · ' . gve_h($e->niveau) . ' · matricule ' . gve_h($e->matricule) . '</p><div class="cartes"><div class="carte"><small>Reste à payer</small><strong>' . gve_h(gvb_montant(max(0, $cp['solde']))) . '</strong>' . ($cp['retard'] > 0 ? '<small>dont ' . gve_h(gvb_montant($cp['retard'])) . ' en retard</small>' : '') . '</div><div class="carte"><small>Présence ce mois-ci</small><strong>' . ($o ? $o['taux'] . ' %' : '—') . '</strong><small>' . ($o ? $o['absent'] . ' absence(s)' : 'aucun appel') . '</small></div></div>'
            . '<a class="btn sec" href="' . esc_url(gvb_espace_url('planning', ['e' => $e->id])) . '">Emploi du temps</a> <a class="btn sec" href="' . esc_url(gvb_espace_url('presences', ['e' => $e->id])) . '">Présences</a> <a class="btn sec" href="' . esc_url(gvb_espace_url('finances', ['e' => $e->id])) . '">Finances</a></div>';
    }
    if (!$enfants) $h .= '<div class="boite"><p>Aucun étudiant n’est rattaché à votre compte. Contactez la scolarité.</p></div>';
    gve_page('Bonjour ' . $c->nom, $h . '<h2>Annonces</h2>' . gve_liste_annonces($annonces), $c, '');
}
function gve_planning($c, $post) {
    $sem = gve_semaine(); $au = gmdate('Y-m-d', strtotime($sem) + 6 * DAY_IN_SECONDS);
    if ($c->type === 'enseignant') {
        global $wpdb;
        $s = $wpdb->get_results($wpdb->prepare('SELECT s.*, c.intitule, r.nom AS salle_nom, "" AS e_nom, "" AS e_prenom FROM ' . gvb_table('seances') . ' s JOIN ' . gvb_table('cours') . ' c ON c.id = s.cours_id LEFT JOIN ' . gvb_table('salles') . ' r ON r.id = s.salle_id WHERE s.enseignant_id = %d AND s.date_seance BETWEEN %s AND %s ORDER BY s.date_seance, s.debut', $c->enseignant_id, $sem, $au));
        return gve_page('Emploi du temps', gve_nav_semaine('planning', $sem) . gve_tableau_seances($s, false, true), $c, 'planning');
    }
    $enfants = gve_enfants($c); $e = gve_enfant($c, $enfants);
    if (!$e) { status_header(404); return gve_page('Introuvable', '<p>Étudiant introuvable.</p>', $c, 'planning'); }
    gve_page('Emploi du temps — ' . $e->prenom, gve_choix_enfant($c, $enfants, $e, 'planning') . gve_nav_semaine('planning', $sem, ['e' => $e->id]) . gve_tableau_seances(gve_seances_etudiant($e, $sem, $au)), $c, 'planning');
}
function gve_presences($c, $post) {
    global $wpdb; $enfants = gve_enfants($c); $e = gve_enfant($c, $enfants);
    if ($c->type === 'enseignant' || !$e) { status_header(404); return gve_page('Introuvable', '<p>Page introuvable.</p>', $c, 'presences'); }
    $st = gvb_stats_presence(['du' => '2000-01-01', 'au' => gvb_aujourdhui(), 'pole' => '', 'niveau' => '', 'etudiant' => $e->id]); $o = $st[$e->id] ?? null;
    $rows = $wpdb->get_results($wpdb->prepare('SELECT p.statut AS pst, p.note, s.date_seance, s.debut, s.fin, c.intitule FROM ' . gvb_table('presences') . ' p JOIN ' . gvb_table('seances') . ' s ON s.id = p.seance_id JOIN ' . gvb_table('cours') . ' c ON c.id = s.cours_id WHERE p.etudiant_id = %d AND p.statut <> %s ORDER BY s.date_seance DESC, s.debut DESC LIMIT 60', $e->id, 'present'));
    $couleurs = ['absent' => '#B42318', 'retard' => '#8A5A00', 'excuse' => '#4B5578'];
    $h = gve_choix_enfant($c, $enfants, $e, 'presences') . '<div class="cartes"><div class="carte"><small>Taux de présence</small><strong>' . ($o ? $o['taux'] . ' %' : '—') . '</strong><small>' . ($o ? $o['total'] . ' séance(s) avec appel' : 'Aucun appel enregistré') . '</small></div><div class="carte"><small>Absences</small><strong>' . ($o ? $o['absent'] : 0) . '</strong><small>' . ($o ? $o['retard'] . ' retard(s), ' . $o['excuse'] . ' excusée(s)' : '') . '</small></div></div><div class="boite"><h2>Absences et retards</h2><div class="def"><table><thead><tr><th>Date</th><th>Cours</th><th>État</th></tr></thead><tbody>';
    foreach ($rows as $r) $h .= '<tr><td>' . gve_h(gvb_d($r->date_seance) . ' ' . gvb_hhmm($r->debut)) . '</td><td>' . gve_h($r->intitule) . '</td><td>' . gve_badge(GVB_PRESENCES[$r->pst] ?? $r->pst, $couleurs[$r->pst] ?? '#4B5578') . ($r->note ? '<br><span class="petit">' . gve_h($r->note) . '</span>' : '') . '</td></tr>';
    gve_page('Présences — ' . $e->prenom, $h . ($rows ? '' : '<tr><td colspan="3">Aucune absence ni retard.</td></tr>') . '</tbody></table></div></div>', $c, 'presences');
}
function gve_finances($c, $post) {
    global $wpdb; $enfants = gve_enfants($c); $e = gve_enfant($c, $enfants);
    if ($c->type === 'enseignant' || !$e) { status_header(404); return gve_page('Introuvable', '<p>Page introuvable.</p>', $c, 'finances'); }
    $cp = gvb_compte($e->id);
    $h = gve_choix_enfant($c, $enfants, $e, 'finances') . '<div class="cartes"><div class="carte"><small>Total dû</small><strong>' . gve_h(gvb_montant($cp['du'])) . '</strong></div><div class="carte"><small>Payé</small><strong>' . gve_h(gvb_montant($cp['paye'])) . '</strong></div><div class="carte"><small>' . ($cp['solde'] < 0 ? 'Crédit' : 'Reste à payer') . '</small><strong>' . gve_h(gvb_montant(abs($cp['solde']))) . '</strong></div></div><div class="boite"><h2>Échéances</h2><div class="def"><table><thead><tr><th>Libellé</th><th>Échéance</th><th class="d">Montant</th><th class="d">Reste</th><th>État</th></tr></thead><tbody>';
    $et = ['paye' => ['Payé', '#007A2E'], 'partiel' => ['Partiel', '#8A5A00'], 'retard' => ['En retard', '#B42318'], 'avenir' => ['À venir', '#4B5578']];
    foreach ($cp['charges'] as $ch) $h .= '<tr><td>' . gve_h($ch->libelle) . '</td><td>' . gve_h(gvb_d($ch->echeance)) . '</td><td class="d">' . gve_h(gvb_montant($ch->montant)) . '</td><td class="d">' . gve_h(gvb_montant($ch->reste)) . '</td><td>' . gve_badge($et[$ch->etat][0], $et[$ch->etat][1]) . '</td></tr>';
    $h .= ($cp['charges'] ? '' : '<tr><td colspan="5">Aucune échéance.</td></tr>') . '</tbody></table></div></div><div class="boite"><h2>Paiements et reçus</h2><div class="def"><table><thead><tr><th>Date</th><th>Reçu</th><th>Mode</th><th class="d">Montant</th><th></th></tr></thead><tbody>';
    $ps = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . gvb_table('paiements') . ' WHERE etudiant_id = %d AND annule = 0 ORDER BY date_paiement DESC, id DESC', $e->id));
    foreach ($ps as $p) $h .= '<tr><td>' . gve_h(gvb_d($p->date_paiement)) . '</td><td>' . gve_h($p->recu) . '</td><td>' . gve_h(GVB_MODES[$p->mode] ?? $p->mode) . '</td><td class="d">' . gve_h(gvb_montant($p->montant)) . '</td><td><a href="' . esc_url(gvb_espace_url('recu', ['id' => $p->id, 'e' => $e->id])) . '">Reçu</a></td></tr>';
    gve_page('Finances — ' . $e->prenom, $h . ($ps ? '' : '<tr><td colspan="5">Aucun paiement enregistré.</td></tr>') . '</tbody></table></div></div>', $c, 'finances');
}
function gve_recu($c, $post) {
    global $wpdb; $enfants = gve_enfants($c); $p = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . gvb_table('paiements') . ' WHERE id = %d AND annule = 0', absint($_GET['id'] ?? 0))); $e = null;
    if ($p && $c->type !== 'enseignant') foreach ($enfants as $x) if ((int) $x->id === (int) $p->etudiant_id) $e = $x;
    if (!$p || !$e) { status_header(404); return gve_page('Introuvable', '<p>Reçu introuvable.</p>', $c, 'finances'); }
    $cp = gvb_compte($e->id);
    gve_page('Reçu ' . $p->recu, '<div class="boite"><p><strong>GROUPE VITO S.</strong> — Reçu de paiement n° ' . gve_h($p->recu) . ' du ' . gve_h(gvb_d($p->date_paiement)) . '</p><p>Étudiant : <strong>' . gve_h(mb_strtoupper($e->nom) . ' ' . $e->prenom) . '</strong> — matricule ' . gve_h($e->matricule) . ' — ' . gve_h(GVB_POLES[$e->pole] ?? '') . ', ' . gve_h($e->niveau) . '</p><p>Somme reçue : <strong>' . gve_h(gvb_montant($p->montant)) . '</strong> (' . gve_h(gvb_lettres($p->montant)) . ' francs CFA)</p><p>Mode : ' . gve_h(GVB_MODES[$p->mode] ?? $p->mode) . ($p->reference ? ' — réf. ' . gve_h($p->reference) : '') . '</p><p>Reste à payer à ce jour : <strong>' . gve_h(gvb_montant(max(0, $cp['solde']))) . '</strong></p></div><p class="noprint"><button class="btn" onclick="window.print()">Imprimer</button> <a class="btn sec" href="' . esc_url(gvb_espace_url('finances', ['e' => $e->id])) . '">← Finances</a></p>', $c, 'finances');
}
function gve_annonces($c, $post) { gve_page('Annonces', gve_liste_annonces(gve_annonces_pour($c)), $c, 'annonces'); }

// ── Pages enseignant ──────────────────────────────────────────────────────────
function gve_cours($c, $post) {
    global $wpdb;
    if ($c->type !== 'enseignant') { status_header(404); return gve_page('Introuvable', '<p>Page introuvable.</p>', $c); }
    $an = gvb_reglages()['annee']; $h = gvb_heures_seances($c->enseignant_id, $an);
    $rows = $wpdb->get_results($wpdb->prepare('SELECT a.heures, k.intitule, k.pole, k.niveau FROM ' . gvb_table('affectations') . ' a JOIN ' . gvb_table('cours') . ' k ON k.id = a.cours_id WHERE a.enseignant_id = %d AND a.annee = %s ORDER BY k.pole, k.niveau', $c->enseignant_id, $an));
    $t = '<div class="cartes"><div class="carte"><small>Heures affectées</small><strong>' . gve_h(gvb_heures_affectees_ens($c->enseignant_id, $an)) . ' h</strong></div><div class="carte"><small>Planifiées</small><strong>' . gve_h(gvb_h($h['planifiees'])) . '</strong></div><div class="carte"><small>Effectuées</small><strong>' . gve_h(gvb_h($h['effectuees'])) . '</strong></div></div><div class="boite"><h2>Cours ' . gve_h($an) . '</h2><div class="def"><table><thead><tr><th>Cours</th><th>Groupe</th><th class="d">Heures</th></tr></thead><tbody>';
    foreach ($rows as $r) $t .= '<tr><td>' . gve_h($r->intitule) . '</td><td>' . gve_h((GVB_POLES[$r->pole] ?? '') . ' · ' . $r->niveau) . '</td><td class="d">' . (int) $r->heures . ' h</td></tr>';
    gve_page('Mes cours', $t . ($rows ? '' : '<tr><td colspan="3">Aucune affectation.</td></tr>') . '</tbody></table></div></div>', $c, 'cours');
}
function gve_appel($c, $post) {
    global $wpdb;
    if ($c->type !== 'enseignant') { status_header(404); return gve_page('Introuvable', '<p>Page introuvable.</p>', $c); }
    $id = absint($_GET['id'] ?? $_POST['id'] ?? 0);
    if (!$id) {
        $rows = $wpdb->get_results($wpdb->prepare('SELECT s.*, k.intitule FROM ' . gvb_table('seances') . ' s JOIN ' . gvb_table('cours') . " k ON k.id = s.cours_id WHERE s.enseignant_id = %d AND s.statut <> 'annulee' AND s.date_seance BETWEEN %s AND %s ORDER BY s.date_seance DESC, s.debut DESC", $c->enseignant_id, gmdate('Y-m-d', time() - 30 * DAY_IN_SECONDS), gvb_aujourdhui()));
        $h = '<div class="boite"><h2>Séances des 30 derniers jours</h2><div class="def"><table><tbody>';
        foreach ($rows as $s) $h .= '<tr><td>' . gve_h(gvb_d($s->date_seance) . ' ' . gvb_hhmm($s->debut)) . '</td><td>' . gve_h($s->intitule) . '<br><span class="petit">' . gve_h(gvb_groupe_libelle($s->pole, $s->formation_id, $s->niveau)) . '</span></td><td>' . ($s->statut === 'tenue' ? gve_badge('Appel fait', '#007A2E') : gve_badge('À faire', '#8A5A00')) . '</td><td><a href="' . esc_url(gvb_espace_url('appel', ['id' => $s->id])) . '">' . ($s->statut === 'tenue' ? 'Voir / corriger' : 'Faire l’appel') . '</a></td></tr>';
        return gve_page('Appel', $h . ($rows ? '' : '<tr><td>Aucune séance récente.</td></tr>') . '</tbody></table></div></div>', $c, 'appel');
    }
    $s = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . gvb_table('seances') . ' WHERE id = %d AND enseignant_id = %d', $id, $c->enseignant_id));
    if (!$s) { status_header(404); return gve_page('Introuvable', '<p>Séance introuvable.</p>', $c, 'appel'); }
    $modifiable = $s->statut !== 'annulee' && $s->date_seance <= gvb_aujourdhui() && $s->date_seance >= gmdate('Y-m-d', time() - 14 * DAY_IN_SECONDS); $msg = '';
    if ($post && $modifiable) {
        if (!gve_csrf_ok($c)) $msg = 'Session expirée : rechargez la page.';
        else {
            $st = isset($_POST['statut']) && is_array($_POST['statut']) ? array_map('sanitize_key', wp_unslash($_POST['statut'])) : []; $notes = isset($_POST['note']) && is_array($_POST['note']) ? wp_unslash($_POST['note']) : [];
            $r = gvb_enregistrer_appel($s->id, $st, $notes);
            if (is_wp_error($r)) $msg = $r->get_error_message(); else { gvb_log(0, 'Appel via l’espace enseignant', 'compte #' . $c->id . ' — séance #' . $s->id); $msg = 'ok:Appel enregistré (' . $r . ' étudiant(s)).'; $s = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . gvb_table('seances') . ' WHERE id = %d', $s->id)); }
        }
    }
    $k = $wpdb->get_row($wpdb->prepare('SELECT intitule FROM ' . gvb_table('cours') . ' WHERE id = %d', $s->cours_id));
    $etus = gvb_etudiants_du_groupe($s); $ex = []; foreach ($wpdb->get_results($wpdb->prepare('SELECT * FROM ' . gvb_table('presences') . ' WHERE seance_id = %d', $s->id)) as $p) $ex[$p->etudiant_id] = $p;
    $h = (str_starts_with($msg, 'ok:') ? gve_msg(substr($msg, 3), 'ok') : gve_msg($msg)) . '<p>' . gve_h(gvb_d($s->date_seance) . ' · ' . gvb_hhmm($s->debut) . '–' . gvb_hhmm($s->fin) . ' · ' . gvb_groupe_libelle($s->pole, $s->formation_id, $s->niveau)) . '</p>';
    if (!$modifiable) $h .= gve_msg($s->statut === 'annulee' ? 'Séance annulée.' : 'L’appel n’est modifiable que du jour de la séance jusqu’à 14 jours après. Contactez la scolarité pour une correction.', 'err');
    $h .= '<div class="boite">' . ($modifiable ? '<form method="post">' . gve_csrf_champ($c) . '<input type="hidden" name="id" value="' . (int) $s->id . '">' : '') . '<div class="def"><table><thead><tr><th>Étudiant</th>';
    foreach (GVB_PRESENCES as $l) $h .= '<th>' . gve_h($l) . '</th>'; $h .= '<th>Note</th></tr></thead><tbody>';
    foreach ($etus as $et) {
        $cur = $ex[$et->id]->statut ?? 'present';
        $h .= '<tr><td><strong>' . gve_h(mb_strtoupper($et->nom) . ' ' . $et->prenom) . '</strong><br><span class="petit">' . gve_h($et->matricule) . '</span></td>';
        foreach (GVB_PRESENCES as $kk => $l) $h .= '<td><input type="radio" name="statut[' . (int) $et->id . ']" value="' . esc_attr($kk) . '"' . checked($cur === $kk, true, false) . ($modifiable ? '' : ' disabled') . ' aria-label="' . esc_attr($l . ' — ' . $et->nom) . '"></td>';
        $h .= '<td>' . ($modifiable ? '<input type="text" name="note[' . (int) $et->id . ']" value="' . esc_attr($ex[$et->id]->note ?? '') . '" maxlength="120">' : gve_h($ex[$et->id]->note ?? '')) . '</td></tr>';
    }
    $h .= ($etus ? '' : '<tr><td colspan="6">Aucun étudiant actif dans ce groupe.</td></tr>') . '</tbody></table></div>' . ($modifiable && $etus ? '<button class="btn" type="submit">Enregistrer l’appel</button>' : '') . ($modifiable ? '</form>' : '') . '</div><p><a class="btn sec" href="' . esc_url(gvb_espace_url('appel')) . '">← Mes séances</a></p>';
    gve_page('Appel — ' . ($k->intitule ?? ''), $h, $c, 'appel');
}
