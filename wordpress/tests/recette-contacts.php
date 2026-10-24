<?php
// Recette du module Contacts (plaquette). WordPress Playground, base jetable.
define('GVB_ESSAI', true);
$lignes = []; $ok = 0; $ko = 0;
$t = function ($nom, $cond, $detail = '') use (&$lignes, &$ok, &$ko) { $cond ? $ok++ : $ko++; $lignes[] = ($cond ? '  ok  ' : ' ECHEC ') . $nom . (!$cond && $detail !== '' ? ' — ' . $detail : ''); };
register_shutdown_function(function () { $e = error_get_last(); if ($e && in_array($e['type'], [E_ERROR, E_PARSE], true)) file_put_contents('/wordpress/wp-content/out/fatal.txt', print_r($e, true)); });
require '/wordpress/wp-load.php';
do_action('init');
global $wpdb;
$_SERVER['REMOTE_ADDR'] = '203.0.113.20';
add_filter('wp_die_handler', fn() => function ($m) { throw new Exception('DIE:' . (is_wp_error($m) ? $m->get_error_message() : (string) $m)); });
add_filter('wp_redirect', function ($loc) { throw new Exception('REDIR:' . $loc); });
gvb_synchroniser_roles();
$T = gvb_table('contacts');
@mkdir(dirname(gvb_plaquette_chemin()), 0777, true);
file_put_contents(gvb_plaquette_chemin(), "%PDF-1.4\n%%EOF\n");

$pub = fn($champs) => (function () use ($champs) { $r = new WP_REST_Request('POST', '/groupevitos/v1/plaquette'); $r->set_body_params($champs); $rep = rest_do_request($r); return [$rep->get_status(), $rep->get_data()]; })();
$lignes[] = '## Formulaire';
[$c, $d] = $pub(['canal' => 'email', 'nom' => 'Awa Diarra', 'email' => 'visiteur@exemple.test', 'pole' => 'lycee', 'consentement' => '1']);
$ct = $wpdb->get_row("SELECT * FROM $T WHERE email = 'visiteur@exemple.test'");
$t('e-mail enregistré, envoyé', $c === 200 && $ct && $ct->nom === 'Awa Diarra' && $ct->statut === 'envoye' && $ct->envois === '1' && $ct->pole === 'lycee', json_encode($d));
[$c] = $pub(['canal' => 'email', 'email' => 'sansnom@exemple.test', 'consentement' => '1']); $t('nom obligatoire', $c === 422);
$t('e-mail parti avec le lien personnel', !empty($GLOBALS['gvb_mails']) && $GLOBALS['gvb_mails'][0][0] === 'visiteur@exemple.test' && strlen($ct->token) === 40);
[$c] = $pub(['canal' => 'email', 'nom' => 'X', 'email' => 'pas-un-mail', 'consentement' => '1']); $t('e-mail invalide : refusé', $c === 422);
[$c] = $pub(['canal' => 'email', 'nom' => 'X', 'email' => 'x@exemple.test']); $t('sans consentement : refusé', $c === 422);
[$c] = $pub(['canal' => 'whatsapp', 'nom' => 'X', 'whatsapp' => '12', 'consentement' => '1']); $t('numéro WhatsApp invalide : refusé', $c === 422);
[$c] = $pub(['canal' => 'whatsapp', 'nom' => 'Sidi', 'whatsapp' => '70 11 22 33', 'pole' => 'universite', 'consentement' => '1']);
$w = $wpdb->get_row("SELECT * FROM $T WHERE canal = 'whatsapp'");
$t('WhatsApp : numéro normalisé (+223), mis en file', $c === 200 && $w && $w->whatsapp === '+22370112233' && $w->statut === 'manuel', json_encode($w));
add_filter('gvb_whatsapp_envoyer', fn($ok, $num, $lien) => str_starts_with($lien, 'http') && $num === '+22376000001', 10, 3);
[$c] = $pub(['canal' => 'whatsapp', 'nom' => 'Fanta', 'whatsapp' => '+223 76 00 00 01', 'consentement' => '1']);
$t('crochet API WhatsApp : statut « envoyé »', $wpdb->get_var("SELECT statut FROM $T WHERE whatsapp = '+22376000001'") === 'whatsapp_envoye');
$n = (int) $wpdb->get_var("SELECT COUNT(*) FROM $T"); [$c] = $pub(['canal' => 'email', 'nom' => 'Piege', 'email' => 'piege@exemple.test', 'consentement' => '1', 'site_web' => 'spam']);
$t('champ piège : rien enregistré', $c === 200 && (int) $wpdb->get_var("SELECT COUNT(*) FROM $T") === $n);
for ($i = 0; $i < 6; $i++) [$c] = $pub(['canal' => 'email', 'nom' => 'Test', 'email' => "a$i@exemple.test", 'consentement' => '1']);
$t('limite par adresse IP', $c === 429);
$t('lecture publique impossible', rest_do_request(new WP_REST_Request('GET', '/groupevitos/v1/plaquette'))->get_status() === 404);

$lignes[] = "\n## Téléchargement";
$telecharger = function ($token) { $_GET = ['t' => $token]; try { ob_start(); do_action('admin_post_nopriv_gvb_plaquette'); ob_end_clean(); return 'rien'; } catch (Exception $e) { $s = ob_get_clean(); return $e->getMessage() . (isset($s) ? '' : ''); } };
$r = $telecharger($ct->token);
$t('lien valide : PDF servi, compteur incrémenté', $r === 'FIN' && (int) $wpdb->get_var("SELECT telechargements FROM $T WHERE id = {$ct->id}") === 1, $r);
$t('jeton inconnu : refusé', str_starts_with($telecharger(str_repeat('a', 40)), 'DIE:'));
$wpdb->update($T, ['token_expire' => gmdate('Y-m-d H:i:s', time() - 60)], ['id' => $ct->id]);
$t('lien expiré : refusé', str_starts_with($telecharger($ct->token), 'DIE:'));
$wpdb->update($T, ['token_expire' => gmdate('Y-m-d H:i:s', time() + 3600)], ['id' => $ct->id]);
$_GET = ['t' => $ct->token]; try { do_action('admin_post_nopriv_gvb_desinscrire'); } catch (Exception $e) {}
$t('désinscription : état mis à jour, lien invalidé', $wpdb->get_var("SELECT statut FROM $T WHERE id = {$ct->id}") === 'desinscrit' && str_starts_with($telecharger($ct->token), 'DIE:'));
delete_transient('gvb_rlc_' . md5('203.0.113.20'));
[$c] = $pub(['canal' => 'email', 'nom' => 'Awa', 'email' => 'visiteur@exemple.test', 'consentement' => '1']); $t('contact désinscrit : pas de réenvoi', $c === 422);

$lignes[] = "\n## Consultation";
$envoyer = function ($action, $nonce, array $post) { $_POST = $post + ['_wpnonce' => wp_create_nonce($nonce)]; $_REQUEST = $_POST; try { do_action('admin_post_' . $action); return 'rien'; } catch (Throwable $e) { return $e->getMessage(); } };
$mk = function ($login, $role) { return wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(), 'role' => $role, 'user_email' => "$login@exemple.test"]); };
$dir = $mk('d1', 'gv_direction'); $sco = $mk('s1', 'gv_scolarite'); $cpt = $mk('c1', 'gv_comptabilite'); $rp = $mk('r1', 'gv_responsable_pole');
$t('droits : Direction et Scolarité voient, Comptabilité et Responsable non', user_can($dir, 'gv_contacts_voir') && user_can($sco, 'gv_contacts_voir') && !user_can($cpt, 'gv_contacts_voir') && !user_can($rp, 'gv_contacts_voir'));
$t('effacement réservé à la Direction', user_can($dir, 'gv_contacts_annuler') && !user_can($sco, 'gv_contacts_annuler'));
wp_set_current_user($cpt); $_GET = []; $t('page Contacts refusée à la comptabilité', (function () { try { gvb_page_contacts(); return false; } catch (Exception $e) { return str_starts_with($e->getMessage(), 'DIE:'); } })());
wp_set_current_user($dir); $_GET = []; ob_start(); gvb_page_contacts(); $h = ob_get_clean();
$t('page Contacts : liste, bouton WhatsApp, plaquette en place', str_contains($h, '+22370112233') && str_contains($h, 'wa.me/22370112233') && str_contains($h, 'Plaquette en place'));
$_GET = ['_wpnonce' => wp_create_nonce('gvb_export_contacts')]; $_REQUEST = $_GET; ob_start(); try { do_action('admin_post_gvb_export_contacts'); } catch (Throwable $e) {} $csv = ob_get_clean();
$t('export CSV', str_contains($csv, '+22370112233') && str_contains($csv, 'E-mail'));
$envoyer('gvb_contact_marquer', 'gvb_contact_marquer', ['id' => $w->id]);
$t('« marquer envoyé »', $wpdb->get_var("SELECT statut FROM $T WHERE id = {$w->id}") === 'whatsapp_envoye');
wp_set_current_user($sco); $r = $envoyer('gvb_contact_supprimer', 'gvb_contact_supprimer', ['id' => $w->id]);
$t('Scolarité ne peut pas effacer', str_starts_with($r, 'DIE:') && $wpdb->get_var("SELECT whatsapp FROM $T WHERE id = {$w->id}") === '+22370112233');
wp_set_current_user($dir); $r2 = $envoyer('gvb_contact_supprimer', 'gvb_contact_supprimer', ['id' => $w->id]);
$t('effacement par la Direction : données retirées', (string) $wpdb->get_var("SELECT whatsapp FROM $T WHERE id = {$w->id}") === '' && $wpdb->get_var("SELECT statut FROM $T WHERE id = {$w->id}") === 'supprime', $r2 . json_encode($wpdb->get_row("SELECT * FROM $T WHERE id = {$w->id}")) . $wpdb->last_error);
$envoyer('gvb_smtp_sauver', 'gvb_smtp_sauver', ['actif' => '1', 'login' => 'u@brevo.test', 'cle' => 'secret-test', 'expediteur' => 'contact@groupevitos.com', 'port' => '587']);
$envoyer('gvb_smtp_sauver', 'gvb_smtp_sauver', ['actif' => '1', 'login' => 'u@brevo.test', 'cle' => '', 'expediteur' => 'contact@groupevitos.com']);
$t('réglages Brevo : clé conservée si champ vide', gvb_smtp()['cle'] === 'secret-test' && gvb_smtp()['actif'] === 1);
ob_start(); $_GET = []; gvb_page_reglages(); $h = ob_get_clean(); $t('la clé n’est jamais réaffichée', !str_contains($h, 'secret-test') && str_contains($h, 'Brevo'));
ob_start(); gvb_page_tableau(); $h = ob_get_clean(); $t('tableau de bord affiche la carte Contacts', str_contains($h, 'Contacts plaquette'));

file_put_contents('/wordpress/wp-content/out/resultat.txt', implode("\n", $lignes) . "\n\n=== $ok réussis, $ko échecs ===\n");
