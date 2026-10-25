<?php
// Recette : formulaire de contact (réception durcie) et écran « Messages du site » (WordPress Playground).
define('GVB_ESSAI', true);
$lignes = []; $ok = 0; $ko = 0;
$t = function ($nom, $cond, $detail = '') use (&$lignes, &$ok, &$ko) { $cond ? $ok++ : $ko++; $lignes[] = ($cond ? '  ok  ' : ' ECHEC ') . $nom . (!$cond && $detail !== '' ? ' — ' . substr($detail, 0, 500) : ''); };
register_shutdown_function(function () { $e = error_get_last(); if ($e && in_array($e['type'], [E_ERROR, E_PARSE], true)) file_put_contents('/wordpress/wp-content/out/fatal.txt', print_r($e, true)); });
require '/wordpress/wp-load.php';
do_action('init');
global $wpdb;
add_filter('wp_die_handler', fn() => function ($m) { throw new Exception('DIE:' . (is_wp_error($m) ? $m->get_error_message() : (string) $m)); });
add_filter('wp_redirect', function ($loc) { throw new Exception('REDIR:' . $loc); });
gvb_synchroniser_roles();
$mk = function ($l, $r, $pole = '') { $id = wp_insert_user(['user_login' => $l, 'user_pass' => wp_generate_password(), 'role' => $r, 'user_email' => "$l@exemple.test", 'first_name' => ucfirst($l)]); if ($pole) update_user_meta($id, 'gv_pole', $pole); return $id; };
$dir = $mk('direction', 'gv_direction'); $sco = $mk('scolarite', 'gv_scolarite'); $cpt = $mk('compta', 'gv_comptabilite'); $rp = $mk('resp', 'gv_responsable_pole', 'lycee'); $com = $mk('comm', 'gv_communication'); $nul = $mk('abonne', 'subscriber');
$T = gvb_table('messages');
$nb = fn() => (int) $wpdb->get_var("SELECT COUNT(*) FROM $T");
$vider = function () use ($wpdb) { $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_gvb\\_m%' OR option_name LIKE '\\_transient\\_timeout\\_gvb\\_m%'"); wp_cache_flush(); };
$ORIGINE = 'https://groupevitos.com'; $GLOBALS['n_jetons'] = 0;
$valide = ['nom' => 'Aminata Konaté', 'email' => 'aminata@exemple.test', 'telephone' => '+223 70 00 00 00', 'objet' => 'admission', 'pole' => 'universite', 'message' => "Bonjour,\nJe souhaite connaître les conditions d'inscription en Licence 1.", 'consentement' => '1', 'site_web' => ''];
// Envoie une requête comme le fait le site : jeton ancien de 10 s, origine autorisée, adresse du visiteur donnée
$poster = function (array $d = [], $ip = '41.73.0.1', $origine = null, $jeton = 'auto', $taille = null) use ($valide, $ORIGINE) {
    $_SERVER['REMOTE_ADDR'] = $ip; unset($_SERVER['HTTP_CF_CONNECTING_IP']); $_SERVER['CONTENT_LENGTH'] = $taille ?? 600;
    $d = $d + $valide; if ($jeton === 'auto') $d['jeton'] = gvb_message_jeton(time() - 3600 + (++$GLOBALS['n_jetons'])); elseif ($jeton !== null) $d['jeton'] = $jeton;
    $rq = new WP_REST_Request('POST', '/groupevitos/v1/message'); $o = $origine ?? $ORIGINE; if ($o !== '') $rq->set_header('origin', $o);
    $rq->set_body_params($d);
    return gvb_message_recevoir($rq);
};
$code = fn($r) => is_wp_error($r) ? (int) ($r->get_error_data()['status'] ?? 0) : 200;
$cle = fn($r) => is_wp_error($r) ? $r->get_error_code() : 'ok';

$lignes[] = '## Adresse du visiteur derrière Cloudflare';
$_SERVER['REMOTE_ADDR'] = '172.70.1.1'; $_SERVER['HTTP_CF_CONNECTING_IP'] = '41.73.2.3'; $t('requête venue de Cloudflare : on lit l’adresse annoncée', gvb_ip_visiteur() === '41.73.2.3');
$_SERVER['REMOTE_ADDR'] = '2606:4700::1234'; $t('plage IPv6 de Cloudflare reconnue', gvb_ip_visiteur() === '41.73.2.3');
$_SERVER['REMOTE_ADDR'] = '203.0.113.9'; $t('en-tête usurpé par un client hors Cloudflare : ignoré', gvb_ip_visiteur() === '203.0.113.9');
$_SERVER['REMOTE_ADDR'] = '172.70.1.1'; $_SERVER['HTTP_CF_CONNECTING_IP'] = "1.2.3.4\r\nX: y"; $t('en-tête invalide : adresse directe conservée', gvb_ip_visiteur() === '172.70.1.1');
$_SERVER['HTTP_CF_CONNECTING_IP'] = ''; unset($_SERVER['HTTP_CF_CONNECTING_IP']); $_SERVER['REMOTE_ADDR'] = '41.73.0.1'; $t('sans Cloudflare : adresse directe', gvb_ip_visiteur() === '41.73.0.1');

$lignes[] = "\n## Jeton à usage unique";
$vider();
$t('jeton valide (10 s)', gvb_message_jeton_verifier(gvb_message_jeton(time() - 10)) === 'ok');
$t('jeton trop récent (envoi instantané d’un robot)', gvb_message_jeton_verifier(gvb_message_jeton()) === 'trop_tot');
$t('jeton expiré (plus de 2 h)', gvb_message_jeton_verifier(gvb_message_jeton(time() - 8000)) === 'expire');
$j = gvb_message_jeton(time() - 10); $t('jeton falsifié (signature changée)', gvb_message_jeton_verifier(substr($j, 0, -2) . '00') === 'invalide' && gvb_message_jeton_verifier('x') === 'invalide');
$t('jetons de formes inattendues refusés', gvb_message_jeton_verifier('') === 'invalide' && gvb_message_jeton_verifier(['a']) === 'invalide' && gvb_message_jeton_verifier(str_repeat('9', 5000)) === 'invalide' && gvb_message_jeton_verifier("1.\x00") === 'invalide');

$lignes[] = "\n## Réception d’un message valide";
$vider(); $avant = $nb();
$j1 = gvb_message_jeton(time() - 10);
$r = $poster([], '41.73.0.1', null, $j1); $t('message valide accepté', $r === ['ok' => true], json_encode($r));
$m = $wpdb->get_row("SELECT * FROM $T ORDER BY id DESC LIMIT 1");
$t('enregistré avec ses champs, état « nouveau »', $nb() === $avant + 1 && $m->nom === 'Aminata Konaté' && $m->email === 'aminata@exemple.test' && $m->objet === 'admission' && $m->pole === 'universite' && $m->statut === 'nouveau' && str_contains($m->message, "Licence 1"));
$t('adresse du visiteur non conservée en clair (empreinte seulement)', strlen($m->ip_hash) === 64 && !str_contains(json_encode($m), '41.73.0.1'));
$mails = $GLOBALS['gvb_mails'] ?? []; $mail = end($mails);
$t('alerte à l’équipe : sujet fixe, contenu du message non recopié, lien vers le back-office', $mail && $mail[0] === gvb_reglages()['email'] && str_starts_with($mail[1], 'Nouveau message depuis le site') && !str_contains($mail[2], 'Licence') && !str_contains($mail[2], 'Aminata') && str_contains($mail[2], 'gv-message'));
$t('aucun accusé de réception envoyé au visiteur (pas de rebond ni de relais de spam)', !array_filter($mails, fn($x) => $x[0] === 'aminata@exemple.test'));
$r = $poster([], '41.73.0.1', null, $j1); $t('même jeton rejoué : refusé', $cle($r) === 'jeton' && $code($r) === 400, $cle($r));

$lignes[] = "\n## Origine, taille, robots";
$vider(); $avant = $nb();
$r = $poster([], '41.73.1.1', 'https://site-pirate.example'); $t('origine étrangère : 403', $code($r) === 403);
$r = $poster([], '41.73.1.1', ''); $t('sans en-tête Origin (script direct) : 403', $code($r) === 403);
$r = $poster([], '41.73.1.1', 'http://localhost:4322'); $t('origine de développement autorisée', $code($r) === 200);
$vider();
$r = $poster([], '41.73.1.2', null, 'auto', 50000); $t('requête annoncée trop volumineuse : 413', $code($r) === 413);
$r = $poster(['message' => str_repeat('a ', 9000)], '41.73.1.2'); $t('corps réel trop volumineux : 413', $code($r) === 413);
$avant = $nb(); $r = $poster(['site_web' => 'http://spam.example'], '41.73.1.3'); $t('champ piège rempli : réponse neutre, rien enregistré', $r === ['ok' => true] && $nb() === $avant);
$r = $poster([], '41.73.1.4', null, null); $t('sans jeton : refusé', $cle($r) === 'jeton');
$r = $poster([], '41.73.1.5', null, gvb_message_jeton()); $t('envoi immédiat : demande de patienter (429)', $cle($r) === 'trop_tot' && $code($r) === 429);

$lignes[] = "\n## Validation stricte";
$vider(); $i = 0; $ip = function () use (&$i) { return '41.74.' . (++$i) . '.1'; };
$rejet = function ($nom, array $d, $attendu) use ($poster, $ip, $cle, $t, $nb) { $avant = $nb(); $r = $poster($d, $ip()); $t($nom, $cle($r) === $attendu && $nb() === $avant, $cle($r)); };
$rejet('consentement manquant', ['consentement' => ''], 'consentement');
$rejet('nom vide', ['nom' => ' '], 'nom');
$rejet('nom contenant une adresse web', ['nom' => 'Voir http://pirate.example'], 'nom');
$rejet('nom contenant une adresse e-mail', ['nom' => 'moi@pirate.example'], 'nom');
$rejet('nom envoyé sous forme de tableau', ['nom' => ['a', 'b']], 'nom');
$rejet('e-mail invalide', ['email' => 'pas-un-email'], 'email');
$rejet('injection d’en-tête par retour à la ligne dans l’e-mail', ['email' => "a@exemple.test\r\nBcc: victime@exemple.test"], 'email');
$rejet('plusieurs destinataires dans l’e-mail', ['email' => 'a@exemple.test,b@exemple.test'], 'email');
$rejet('e-mail avec guillemets ou chevrons', ['email' => '"a"<b@exemple.test>'], 'email');
$rejet('e-mail trop long', ['email' => str_repeat('a', 200) . '@exemple.test'], 'email');
$rejet('téléphone invalide', ['telephone' => '<script>alert(1)</script>'], 'telephone');
$rejet('téléphone avec lettres', ['telephone' => 'appelez-moi'], 'telephone');
$rejet('objet hors liste', ['objet' => 'pirate'], 'objet');
$rejet('objet envoyé sous forme de tableau', ['objet' => ['admission']], 'objet');
$rejet('message trop court', ['message' => 'Salut'], 'message');
$rejet('message rempli de liens', ['message' => 'Bonjour voir http://a.example et https://b.example et www.c.example'], 'liens');
$rejet('message à répétition de caractères', ['message' => str_repeat('x', 30)], 'message');
$avant = $nb(); $r = $poster(['pole' => 'pirate', 'message' => 'Question sur un pôle qui n’existe pas du tout'], $ip()); $m = $wpdb->get_row("SELECT * FROM $T ORDER BY id DESC LIMIT 1"); $t('pôle inconnu : ignoré (champ vidé), message accepté', $code($r) === 200 && $m->pole === '' && $nb() === $avant + 1, $cle($r) . ' / ' . $nb() . ' vs ' . $avant);

$lignes[] = "\n## Injections (SQL, scripts, en-têtes)";
$vider();
$r = $poster(['email' => 'inj1@exemple.test', 'nom' => "Robert'); DROP TABLE {$T};--", 'message' => "Bonjour'; DELETE FROM {$T}; -- \" OR \"1\"=\"1 et UNION SELECT user_pass FROM wp_users"], $ip());
$t('injection SQL : acceptée comme simple texte, table intacte', $code($r) === 200 && $nb() >= 1 && (bool) $wpdb->get_var("SHOW TABLES LIKE '$T'"));
$m = $wpdb->get_row("SELECT * FROM $T ORDER BY id DESC LIMIT 1"); $t('le texte est conservé tel quel, sans exécution', str_contains($m->nom, 'DROP TABLE') && str_contains($m->message, 'UNION SELECT'));
$r = $poster(['email' => 'inj2@exemple.test', 'message' => "Bonjour <script>alert('xss')</script> <img src=x onerror=alert(1)> <b>gras</b> merci de me répondre"], $ip());
$m = $wpdb->get_row("SELECT * FROM $T ORDER BY id DESC LIMIT 1"); $t('balises HTML supprimées avant enregistrement', $code($r) === 200 && !str_contains($m->message, '<') && !str_contains($m->message, 'onerror'));
$r = $poster(['email' => 'inj3@exemple.test', 'nom' => "Eve\r\nSubject: faux", 'message' => "Bonjour,\r\nSubject: faux\r\nBcc: victime@exemple.test\r\nmerci de répondre"], $ip());
$m = $wpdb->get_row("SELECT * FROM $T ORDER BY id DESC LIMIT 1"); $mails = $GLOBALS['gvb_mails']; $mail = end($mails);
$t('retours à la ligne du nom aplatis', $code($r) === 200 && !preg_match('/[\r\n]/', $m->nom));
$t('l’e-mail à l’équipe ne contient aucune donnée du visiteur (pas d’injection possible)', !str_contains($mail[1], 'Eve') && !str_contains($mail[1], 'Bcc') && !str_contains($mail[1], 'faux') && !str_contains($mail[2], 'victime') && !str_contains($mail[2], 'Eve'));
$r = $poster(['email' => 'inj4@exemple.test', 'nom' => "Eve Bcc: victime@exemple.test"], $ip()); $t('adresse e-mail glissée dans le nom : refusée', $cle($r) === 'nom');
$r = $poster(['email' => 'inj5@exemple.test', 'nom' => "Zoé\u{202E}elbmis\u{200B}", 'message' => "Texte avec caractères \u{202E}cachés\u{200F} et \x01 de contrôle valides"], $ip());
$m = $wpdb->get_row("SELECT * FROM $T ORDER BY id DESC LIMIT 1"); $t('caractères de contrôle et de direction cachés supprimés', !preg_match('/[\x{202A}-\x{202E}\x{200B}-\x{200F}\x01]/u', $m->nom . $m->message));
$avant = $nb(); $r = $poster(['email' => 'inj6@exemple.test', 'message' => "Bonjour " . "\xC3\x28" . " octets invalides pour tester l'encodage"], $ip()); $t('UTF-8 invalide : message refusé, jamais enregistré brut', $cle($r) === 'message' && $nb() === $avant, $cle($r) . ' ' . (is_wp_error($r) ? $r->get_error_message() : ''));

$lignes[] = "\n## Limites de débit (anti-saturation)";
$vider(); $avant = $nb(); $ip1 = '41.75.0.1';
$codes = []; for ($k = 0; $k < 5; $k++) { $codes[] = $code($poster(['message' => "Message numéro $k bien distinct pour la limite", 'email' => "visiteur$k@exemple.test"], $ip1)); }
$t('un même visiteur : 3 messages par heure, les suivants refusés (429)', $codes === [200, 200, 200, 429, 429], json_encode($codes));
$t('seuls 3 messages enregistrés', $nb() === $avant + 3);
$vider(); $codes = []; for ($k = 0; $k < 14; $k++) $codes[] = $code($poster(['email' => 'pas-valide'], '41.75.0.2'));
$t('rafale de tentatives invalides : coupée au bout de 12 (429)', $codes[11] === 422 && $codes[12] === 429 && $codes[13] === 429, json_encode($codes));
$vider(); $codes = []; for ($k = 0; $k < 4; $k++) $codes[] = $code($poster(['message' => "Autre message numéro $k assez long pour passer", 'email' => 'meme@exemple.test'], '41.76.' . $k . '.1'));
$t('une même adresse e-mail : 3 messages par jour depuis des connexions différentes', $codes === [200, 200, 200, 429], json_encode($codes));
$vider(); $avant = $nb(); $poster([], '41.77.0.1'); $r = $poster([], '41.77.0.2'); $t('message identique répété : une seule fois enregistré', $nb() === $avant + 1 && $r === ['ok' => true]);
$vider(); set_transient(gvb_message_cle('site', gmdate('Ymd')), 250, DAY_IN_SECONDS); $r = $poster([], '41.78.0.1'); $t('plafond quotidien du site atteint : 503, formulaire suspendu', $code($r) === 503);
$vider(); $avant_mails = count($GLOBALS['gvb_mails']); set_transient(gvb_message_cle('mails', gmdate('Ymd')), 40, DAY_IN_SECONDS); $avant = $nb(); $poster(['message' => 'Un message de plus après le plafond des alertes e-mail'], '41.78.0.2');
$t('au-delà de 40 alertes e-mail par jour : message gardé, plus de courriel (pas de saturation de la boîte)', $nb() === $avant + 1 && count($GLOBALS['gvb_mails']) === $avant_mails);
$vider();
$_SERVER['REMOTE_ADDR'] = '172.70.9.9'; $_SERVER['HTTP_CF_CONNECTING_IP'] = '41.79.0.1'; $a = gvb_message_cle('tent', gvb_ip_visiteur()); $_SERVER['HTTP_CF_CONNECTING_IP'] = '41.79.0.2'; $b = gvb_message_cle('tent', gvb_ip_visiteur());
$t('derrière Cloudflare, deux visiteurs ont des compteurs distincts (pas de blocage collectif)', $a !== $b);

$lignes[] = "\n## Jeton : émission";
$vider(); $rq = new WP_REST_Request('GET', '/groupevitos/v1/message/jeton'); $rq->set_header('origin', $ORIGINE); $_SERVER['REMOTE_ADDR'] = '41.80.0.1'; $r = gvb_message_jeton_route($rq);
$t('jeton délivré au site', is_array($r) && preg_match('/^\d+\.[a-f0-9]{64}$/', $r['t']));
$rq2 = new WP_REST_Request('GET', '/groupevitos/v1/message/jeton'); $rq2->set_header('origin', 'https://pirate.example'); $t('jeton refusé à une origine étrangère', $code(gvb_message_jeton_route($rq2)) === 403);
for ($k = 0; $k < 62; $k++) $r = gvb_message_jeton_route($rq); $t('émission de jetons limitée (429)', $code($r) === 429);

$lignes[] = "\n## Écran « Messages du site »";
$vider();
$page = function ($uid, $get = [], $fn = 'gvb_page_messages') { wp_set_current_user($uid); $_GET = $get; ob_start(); try { $fn(); } catch (Exception $e) { ob_end_clean(); return $e->getMessage(); } return ob_get_clean(); };
$envoyer = function ($action, $nonce, array $post) { $_POST = $post + ['_wpnonce' => wp_create_nonce($nonce)]; $_REQUEST = $_POST; $_SERVER['REQUEST_METHOD'] = 'POST'; try { do_action('admin_post_' . $action); return 'rien'; } catch (Exception $e) { return $e->getMessage(); } };
$wpdb->query("DELETE FROM $T");
$wpdb->insert($T, ['nom' => '<img src=x onerror=alert(1)>Piège', 'email' => 'p@exemple.test', 'telephone' => '', 'objet' => 'autre', 'pole' => '', 'message' => "<script>alert('x')</script>\nSuite du message", 'statut' => 'nouveau', 'ip_hash' => str_repeat('a', 64), 'consentement_le' => gvb_now(), 'cree_le' => gvb_now()]);
$id1 = (int) $wpdb->insert_id;
$wpdb->insert($T, ['nom' => 'Bakary', 'email' => 'b@exemple.test', 'telephone' => '70 00 00 00', 'objet' => 'admission', 'pole' => 'lycee', 'message' => 'Une question sur le Lycée et les frais.', 'statut' => 'nouveau', 'ip_hash' => str_repeat('b', 64), 'consentement_le' => gvb_now(), 'cree_le' => gvb_now()]);
$id2 = (int) $wpdb->insert_id;
foreach (['direction' => $dir, 'scolarité' => $sco, 'communication' => $com] as $nom => $uid) { $h = $page($uid); $t("$nom : la liste s’affiche", str_contains($h, 'Messages du site') && str_contains($h, 'Bakary'), substr($h, 0, 150)); }
foreach (['comptabilité' => $cpt, 'responsable de pôle' => $rp, 'compte sans accès' => $nul] as $nom => $uid) $t("$nom : accès refusé", str_starts_with($page($uid), 'DIE:'));
$h = $page($dir); $t('XSS : le contenu hostile est affiché neutralisé dans la liste', !str_contains($h, '<script>') && !str_contains($h, '<img src=x') && str_contains($h, '&lt;img'));
$h = $page($dir, ['id' => $id1], 'gvb_page_message'); $wpdb->update($T, ['statut' => 'nouveau'], ['id' => $id1]); $t('XSS : le détail neutralise aussi le message', !str_contains($h, "<script>alert('x')") && str_contains($h, '&lt;script&gt;') && !str_contains($h, '<img src=x'));
$h = $page($sco, ['q' => "' OR 1=1 -- \"; DROP TABLE x"]); $t('recherche avec injection SQL : aucune erreur, aucun résultat', str_contains($h, 'Aucun message') && (bool) $wpdb->get_var("SHOW TABLES LIKE '$T'"));
$h = $page($sco, ['statut' => 'pirate']); $t('filtre d’état inconnu ignoré', str_contains($h, 'Bakary'));
$h = $page($sco, ['statut' => 'nouveau', 'q' => 'Bakary']); $t('filtres combinés', str_contains($h, 'Bakary') && !str_contains($h, 'Piège'));
$h = $page($sco, ['id' => $id2], 'gvb_page_message'); $t('l’ouverture par la Scolarité marque le message « lu »', str_contains($h, 'Répondre par e-mail') && $wpdb->get_var("SELECT statut FROM $T WHERE id = $id2") === 'lu');
$t('le lien de réponse est un mailto: sûr', str_contains($h, 'href="mailto:b@exemple.test?subject='));
$h = $page($sco, ['id' => 999999], 'gvb_page_message'); $t('message inconnu : page vide sans erreur', str_contains($h, 'introuvable'));
wp_set_current_user($sco); $r = $envoyer('gvb_message_etat', 'gvb_message_etat', ['id' => $id2, 'etat' => 'traite']);
$t('la Scolarité marque un message « traité »', str_starts_with($r, 'REDIR:') && $wpdb->get_var("SELECT statut FROM $T WHERE id = $id2") === 'traite');
$r = $envoyer('gvb_message_etat', 'gvb_message_etat', ['id' => $id2, 'etat' => 'pirate; DROP']); $t('état inventé refusé, rien de modifié', str_starts_with($r, 'REDIR:') && str_contains($r, 'gv_err') && $wpdb->get_var("SELECT statut FROM $T WHERE id = $id2") === 'traite', $r);
$r = $envoyer('gvb_message_etat', 'mauvais-nonce', ['id' => $id2, 'etat' => 'spam']); $t('jeton de sécurité (nonce) invalide refusé', str_starts_with($r, 'DIE:') && $wpdb->get_var("SELECT statut FROM $T WHERE id = $id2") === 'traite');
wp_set_current_user($cpt); $r = $envoyer('gvb_message_etat', 'gvb_message_etat', ['id' => $id2, 'etat' => 'spam']); $t('la Comptabilité ne peut pas modifier un message', str_starts_with($r, 'DIE:'));
wp_set_current_user($com); $r = $envoyer('gvb_message_etat', 'gvb_message_etat', ['id' => $id2, 'etat' => 'spam']); $t('le Chargé de communication peut classer un message', str_starts_with($r, 'REDIR:') && $wpdb->get_var("SELECT statut FROM $T WHERE id = $id2") === 'spam');
wp_set_current_user($sco); $r = $envoyer('gvb_message_supprimer', 'gvb_message_supprimer', ['id' => $id2]); $t('la Scolarité ne peut pas supprimer', str_starts_with($r, 'DIE:') && (int) $wpdb->get_var("SELECT COUNT(*) FROM $T WHERE id = $id2") === 1);
wp_set_current_user($dir); $r = $envoyer('gvb_message_supprimer', 'gvb_message_supprimer', ['id' => $id2]); $t('la Direction supprime définitivement', str_starts_with($r, 'REDIR:') && (int) $wpdb->get_var("SELECT COUNT(*) FROM $T WHERE id = $id2") === 0);

$lignes[] = "\n## Intégration au back-office";
$items = []; foreach (gvb_menu_sections()['communication'][3] as $i) $items[] = $i[0]; $t('entrée « Messages » dans la section Communication', in_array('Messages', $items, true) && array_search('Messages', $items, true) < array_search('Contacts', $items, true), json_encode($items));
$t('écran de détail caché du menu', isset(GVB_PAGES_CACHEES['gv-message']));
$wpdb->query("DELETE FROM $T"); $wpdb->insert($T, ['nom' => 'N', 'email' => 'n@exemple.test', 'objet' => 'autre', 'message' => 'message nouveau pour le compteur', 'statut' => 'nouveau', 'consentement_le' => gvb_now(), 'cree_le' => gvb_now()]);
wp_set_current_user($sco); $ta = gvb_taches(); $t('tâche « à traiter » : nouveaux messages comptés (Scolarité)', isset($ta['messages_nouveaux']) && $ta['messages_nouveaux'][1] === 1, json_encode($ta['messages_nouveaux'] ?? null));
wp_set_current_user($rp); $ta = gvb_taches(); $t('pas de tâche messages pour un Responsable de pôle', !isset($ta['messages_nouveaux']));
$wpdb->query("DELETE FROM $T"); $vieux = gmdate('Y-m-d H:i:s', strtotime('-13 months')); $spam = gmdate('Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS);
foreach ([[$vieux, 'lu'], [$spam, 'spam'], [gvb_now(), 'spam'], [gvb_now(), 'nouveau']] as [$d, $s]) $wpdb->insert($T, ['nom' => 'x', 'email' => 'x@exemple.test', 'objet' => 'autre', 'message' => 'message de test pour la purge', 'statut' => $s, 'consentement_le' => $d, 'cree_le' => $d]);
$n = gvb_messages_purger(); $t('purge : messages de plus de 12 mois et indésirables de plus de 30 jours supprimés, le reste conservé', $n === 2 && $nb() === 2, "$n / " . $nb());
$t('purge quotidienne planifiée', (bool) wp_next_scheduled('gvb_messages_purge'));

file_put_contents('/wordpress/wp-content/out/resultat.txt', implode("\n", $lignes) . "\n\n=== $ok réussis, $ko échecs ===\n");
