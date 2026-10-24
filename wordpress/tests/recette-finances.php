<?php
// Recette du module Scolarité et finances (WordPress Playground, base jetable).
define('GVB_ESSAI', true);
$lignes = []; $ok = 0; $ko = 0;
$t = function ($nom, $cond, $detail = '') use (&$lignes, &$ok, &$ko) { $cond ? $ok++ : $ko++; $lignes[] = ($cond ? '  ok  ' : ' ECHEC ') . $nom . (!$cond && $detail !== '' ? ' — ' . $detail : ''); };
register_shutdown_function(function () { $e = error_get_last(); if ($e && in_array($e['type'], [E_ERROR, E_PARSE], true)) file_put_contents('/wordpress/wp-content/out/fatal.txt', print_r($e, true)); });
require '/wordpress/wp-load.php';
do_action('init');
global $wpdb;
$E = gvb_table('etudiants'); $I = gvb_table('inscriptions'); $D = gvb_table('demandes'); $C = gvb_table('charges'); $P = gvb_table('paiements'); $R = gvb_table('relances');
add_filter('wp_die_handler', fn() => function ($m) { throw new Exception('DIE:' . (is_wp_error($m) ? $m->get_error_message() : (string) $m)); });
add_filter('wp_redirect', function ($loc) { throw new Exception('REDIR:' . $loc); });
gvb_synchroniser_roles();
$envoyer = function ($action, $nonce, array $post) { $_POST = $post + ['_wpnonce' => wp_create_nonce($nonce)]; $_REQUEST = $_POST; try { do_action('admin_post_' . $action); return 'rien'; } catch (Throwable $e) { return urldecode($e->getMessage()); } };
$mk = function ($l, $r, $pole = '') { $id = wp_insert_user(['user_login' => $l, 'user_pass' => wp_generate_password(), 'role' => $r, 'user_email' => "$l@exemple.test"]); if ($pole) update_user_meta($id, 'gv_pole', $pole); return $id; };
$dir = $mk('d', 'gv_direction'); $sco = $mk('s', 'gv_scolarite'); $cpt = $mk('c', 'gv_comptabilite'); $rpU = $mk('ru', 'gv_responsable_pole', 'universite'); $rpL = $mk('rl', 'gv_responsable_pole', 'lycee');
wp_set_current_user($dir);

$accepter = function ($nom, $prenom, $pole = 'universite', $tel = '+223 70 00 00 01', $mail = '') use ($wpdb, $D) {
    $id = gvb_creer_demande(['pole' => $pole, 'annee' => '2026–2027', 'niveau' => 'Licence 1', 'formation_id' => 0, 'serie' => '', 'nom' => $nom, 'prenom' => $prenom, 'naissance' => '2006-03-14', 'sexe' => 'F', 'nationalite' => '', 'telephone' => $tel, 'email' => $mail, 'adresse' => '', 'parent_nom' => '', 'parent_telephone' => '', 'parent_email' => '', 'provenance' => '', 'message' => ''], 'agence');
    $wpdb->update(gvb_table('pieces'), ['recue' => 1], ['demande_id' => $id]); $wpdb->update($D, ['statut' => 'acceptee'], ['id' => $id]);
    return $id;
};

$lignes[] = '## Droits';
$t('Comptabilité encaisse, Direction annule, Scolarité consulte', user_can($cpt, 'gv_finances_creer') && user_can($cpt, 'gv_finances_modifier') && !user_can($cpt, 'gv_finances_annuler') && user_can($dir, 'gv_finances_annuler') && user_can($sco, 'gv_finances_voir') && !user_can($sco, 'gv_finances_creer'));
$t('Responsable de pôle consulte seulement', user_can($rpU, 'gv_finances_voir') && !user_can($rpU, 'gv_finances_creer') && !user_can($rpU, 'gv_finances_exporter'));

$lignes[] = "\n## Montants en lettres";
foreach ([[0, 'zéro'], [1, 'un'], [21, 'vingt et un'], [71, 'soixante et onze'], [80, 'quatre-vingts'], [91, 'quatre-vingt-onze'], [200, 'deux cents'], [455000, 'quatre cent cinquante-cinq mille'], [80000, 'quatre-vingt mille'], [1000, 'mille'], [1200000, 'un million deux cent mille'], [2000000, 'deux millions'], [2500, 'deux mille cinq cents'], [180, 'cent quatre-vingts']] as [$n, $att]) $t("$n en lettres", gvb_lettres($n) === $att, gvb_lettres($n));

$lignes[] = "\n## Échéancier à l’inscription";
$d1 = $accepter('Diarra', 'Awa', 'universite', '+223 70 00 00 01', 'awa@exemple.test');
gvb_inscrire($d1, 50000, 400000);
$e1 = $wpdb->get_row("SELECT * FROM $E WHERE demande_id = $d1");
$ch = $wpdb->get_results("SELECT * FROM $C WHERE etudiant_id = {$e1->id} ORDER BY echeance, id");
$t('4 échéances : inscription + 3 tranches', count($ch) === 4 && (int) $ch[0]->montant === 50000 && (int) $ch[1]->montant === 133333 && (int) $ch[3]->montant === 133334, json_encode(array_map(fn($c) => [$c->libelle, $c->montant, $c->echeance], $ch)));
$t('la somme des tranches égale la scolarité', (int) $ch[1]->montant + (int) $ch[2]->montant + (int) $ch[3]->montant === 400000);
$t('dates : inscription le jour même, tranches à +30, +60, +90 jours', $ch[1]->echeance === gmdate('Y-m-d', strtotime($ch[0]->echeance) + 30 * 86400) && $ch[3]->echeance === gmdate('Y-m-d', strtotime($ch[0]->echeance) + 90 * 86400));
$t('pas de doublon si on regénère', gvb_generer_charges($wpdb->get_var("SELECT id FROM $I WHERE etudiant_id = {$e1->id}")) === 0 && (int) $wpdb->get_var("SELECT COUNT(*) FROM $C WHERE etudiant_id = {$e1->id}") === 4);
$c = gvb_compte($e1->id);
$t('compte initial : dû 450 000, payé 0, aucun retard', $c['du'] === 450000 && $c['paye'] === 0 && $c['solde'] === 450000 && $c['retard'] === 0);

$lignes[] = "\n## Paiements";
wp_set_current_user($cpt);
$j1 = 'jeton-1';
$r = $envoyer('gvb_paiement_saisir', 'gvb_paiement_saisir', ['id' => $e1->id, 'montant' => '60000', 'mode' => 'especes', 'date' => gvb_aujourdhui(), 'jeton' => $j1]);
$p1 = $wpdb->get_row("SELECT * FROM $P ORDER BY id DESC LIMIT 1");
$t('paiement enregistré par la Comptabilité, reçu numéroté', str_contains($r, 'gv-recu') && $p1 && $p1->recu === 'REC-' . gmdate('Y') . '-00001', $r);
$c = gvb_compte($e1->id);
$t('imputation : inscription soldée, 10 000 sur la 1re tranche', $c['charges'][0]->etat === 'paye' && $c['charges'][1]->alloue === 10000 && $c['paye'] === 60000 && $c['solde'] === 390000);
$r = $envoyer('gvb_paiement_saisir', 'gvb_paiement_saisir', ['id' => $e1->id, 'montant' => '60000', 'mode' => 'especes', 'date' => gvb_aujourdhui(), 'jeton' => $j1]);
$t('double envoi du même formulaire bloqué', str_contains($r, 'déjà') && (int) $wpdb->get_var("SELECT COUNT(*) FROM $P") === 1, $r);
$r = $envoyer('gvb_paiement_saisir', 'gvb_paiement_saisir', ['id' => $e1->id, 'montant' => '0', 'mode' => 'especes', 'date' => gvb_aujourdhui(), 'jeton' => 'j2']); $t('montant nul refusé', str_contains($r, 'invalide'));
$r = $envoyer('gvb_paiement_saisir', 'gvb_paiement_saisir', ['id' => $e1->id, 'montant' => '5000', 'mode' => 'bitcoin', 'date' => gvb_aujourdhui(), 'jeton' => 'j3']); $t('mode inconnu refusé', str_contains($r, 'invalide'));
$r = $envoyer('gvb_paiement_saisir', 'gvb_paiement_saisir', ['id' => $e1->id, 'montant' => '5000', 'mode' => 'especes', 'date' => gmdate('Y-m-d', time() + 3 * 86400), 'jeton' => 'j4']); $t('date future refusée', str_contains($r, 'invalide'));
$r = $envoyer('gvb_paiement_saisir', 'gvb_paiement_saisir', ['id' => $e1->id, 'montant' => '5000', 'mode' => 'virement', 'date' => gvb_aujourdhui(), 'reference' => '', 'jeton' => 'j5']); $t('référence obligatoire hors espèces', str_contains($r, 'référence'));
$r = $envoyer('gvb_paiement_saisir', 'gvb_paiement_saisir', ['id' => $e1->id, 'montant' => '123333', 'mode' => 'mobile', 'date' => gvb_aujourdhui(), 'reference' => 'TX-998877', 'jeton' => 'j6']);
$t('2e paiement : reçu n° 2, 1re tranche soldée', $wpdb->get_var("SELECT recu FROM $P ORDER BY id DESC LIMIT 1") === 'REC-' . gmdate('Y') . '-00002' && gvb_compte($e1->id)['charges'][1]->etat === 'paye');
wp_set_current_user($sco); $r = $envoyer('gvb_paiement_saisir', 'gvb_paiement_saisir', ['id' => $e1->id, 'montant' => '1000', 'mode' => 'especes', 'date' => gvb_aujourdhui(), 'jeton' => 'j7']);
$t('Scolarité ne peut pas encaisser', str_starts_with($r, 'DIE:') && (int) $wpdb->get_var("SELECT COUNT(*) FROM $P") === 2);
wp_set_current_user($cpt); $r = $envoyer('gvb_paiement_annuler', 'gvb_paiement_annuler', ['id' => $p1->id, 'e' => $e1->id, 'motif' => 'Erreur']);
$t('Comptabilité ne peut pas annuler un paiement', str_starts_with($r, 'DIE:') && (int) $wpdb->get_var("SELECT annule FROM $P WHERE id = {$p1->id}") === 0);
wp_set_current_user($dir); $r = $envoyer('gvb_paiement_annuler', 'gvb_paiement_annuler', ['id' => $p1->id, 'e' => $e1->id, 'motif' => '']); $t('annulation sans motif refusée', str_contains($r, 'motif'));
$envoyer('gvb_paiement_annuler', 'gvb_paiement_annuler', ['id' => $p1->id, 'e' => $e1->id, 'motif' => 'Erreur de saisie']);
$c = gvb_compte($e1->id);
$t('paiement annulé : reçu conservé, compte recalculé', (int) $wpdb->get_var("SELECT annule FROM $P WHERE id = {$p1->id}") === 1 && $c['paye'] === 123333 && $c['charges'][0]->etat === 'paye' && $c['charges'][1]->etat === 'partiel', json_encode([$c['paye'], $c['charges'][1]->etat]));
$t('le numéro de reçu annulé n’est pas réutilisé', gvb_enregistrer_paiement($e1->id, 1000, 'especes', gvb_aujourdhui(), '', '') > 0 && $wpdb->get_var("SELECT recu FROM $P ORDER BY id DESC LIMIT 1") === 'REC-' . gmdate('Y') . '-00003');

$lignes[] = "\n## Échéances : ajout, modification, annulation";
wp_set_current_user($cpt);
$r = $envoyer('gvb_charge_ajouter', 'gvb_charge_ajouter', ['e' => $e1->id, 'libelle' => 'Frais d’examen', 'montant' => '15000', 'echeance' => gvb_aujourdhui()]);
$t('ajout d’une ligne par la Comptabilité', (int) $wpdb->get_var("SELECT COUNT(*) FROM $C WHERE etudiant_id = {$e1->id} AND libelle LIKE 'Frais d%examen' AND annule = 0") === 1, $r);
$r = $envoyer('gvb_charge_ajouter', 'gvb_charge_ajouter', ['e' => $e1->id, 'libelle' => '', 'montant' => '1', 'echeance' => gvb_aujourdhui()]); $t('ligne sans libellé refusée', str_contains($r, 'obligatoires'));
$exam = $wpdb->get_row("SELECT * FROM $C WHERE etudiant_id = {$e1->id} AND type = 'autre' LIMIT 1");
$envoyer('gvb_charge_modifier', 'gvb_charge_modifier', ['id' => $exam->id, 'e' => $e1->id, 'montant' => '20000', 'echeance' => gvb_aujourdhui()]);
$t('modification du montant', (int) $wpdb->get_var("SELECT montant FROM $C WHERE id = {$exam->id}") === 20000);
$r = $envoyer('gvb_charge_annuler', 'gvb_charge_annuler', ['id' => $exam->id, 'e' => $e1->id, 'motif' => '']); $t('annulation d’échéance : motif obligatoire', str_contains($r, 'motif'));
$envoyer('gvb_charge_annuler', 'gvb_charge_annuler', ['id' => $exam->id, 'e' => $e1->id, 'motif' => 'Dispense']);
$t('échéance annulée : sort du compte', (int) $wpdb->get_var("SELECT annule FROM $C WHERE id = {$exam->id}") === 1 && gvb_compte($e1->id)['du'] === 450000);

$lignes[] = "\n## Retards et relances";
$wpdb->query("UPDATE $C SET echeance = '" . gmdate('Y-m-d', time() - 20 * 86400) . "' WHERE etudiant_id = {$e1->id} AND libelle LIKE '%tranche 2/3'");
$wpdb->query("UPDATE $C SET echeance = '" . gmdate('Y-m-d', time() - 50 * 86400) . "' WHERE etudiant_id = {$e1->id} AND libelle LIKE '%tranche 1/3'");
$c = gvb_compte($e1->id);
$t('retard calculé sur les échéances échues non soldées', $c['retard'] > 0 && $c['jours'] >= 20 && $c['depuis'] === gmdate('Y-m-d', time() - 50 * 86400), json_encode([$c['retard'], $c['jours'], $c['depuis']]));
$imp = gvb_liste_impayes();
$t('l’étudiant figure parmi les impayés', count($imp) === 1 && $imp[0]->id == $e1->id);
$r = $envoyer('gvb_relancer', 'gvb_relancer', ['id' => $e1->id, 'canal' => 'email']);
$t('relance e-mail envoyée à l’étudiant', str_contains($r, 'gv_msg') && end($GLOBALS['gvb_mails'])[0] === 'awa@exemple.test' && (int) $wpdb->get_var("SELECT COUNT(*) FROM $R WHERE etudiant_id = {$e1->id}") === 1, $r);
$r = $envoyer('gvb_relancer', 'gvb_relancer', ['id' => $e1->id, 'canal' => 'email']); $t('2e relance e-mail refusée avant 7 jours', str_contains($r, '7 jours'));
$r = $envoyer('gvb_relancer', 'gvb_relancer', ['id' => $e1->id, 'canal' => 'whatsapp']); $t('relance WhatsApp notée (canal distinct)', str_contains($r, 'gv_msg') && (int) $wpdb->get_var("SELECT COUNT(*) FROM $R WHERE canal = 'whatsapp'") === 1);
$e1->compte = gvb_compte($e1->id); $msg = gvb_message_relance($e1);
$t('message de relance personnalisé', str_contains($msg, 'Awa') && str_contains($msg, 'FCFA') && str_contains($msg, 'UNI-2026-0001') && !str_contains($msg, '{'), $msg);
wp_set_current_user($sco); $r = $envoyer('gvb_relancer', 'gvb_relancer', ['id' => $e1->id, 'canal' => 'whatsapp']); $t('Scolarité ne peut pas relancer', str_starts_with($r, 'DIE:'));

$lignes[] = "\n## Réinscription et annulation d’inscription";
wp_set_current_user($dir);
$r = gvb_reinscrire($e1->id, '2027–2028', 'Licence 2', 55000, 420000);
$t('réinscription : nouvel échéancier pour la nouvelle année', $r === true && (int) $wpdb->get_var("SELECT COUNT(*) FROM $C WHERE etudiant_id = {$e1->id} AND libelle LIKE '%2027–2028%' AND annule = 0") === 4);
$ins2 = $wpdb->get_row("SELECT * FROM $I WHERE etudiant_id = {$e1->id} AND annee = '2027–2028'");
$envoyer('gvb_inscription_annuler', 'gvb_inscription_annuler', ['ins' => $ins2->id, 'motif' => 'Désistement']);
$t('annulation de l’inscription : ses échéances sont annulées, celles des autres années conservées', (int) $wpdb->get_var("SELECT COUNT(*) FROM $C WHERE etudiant_id = {$e1->id} AND libelle LIKE '%2027–2028%' AND annule = 0") === 0 && (int) $wpdb->get_var("SELECT COUNT(*) FROM $C WHERE etudiant_id = {$e1->id} AND libelle LIKE '%2026–2027%' AND annule = 0") === 4);
$d2 = $accepter('Traoré', 'Moussa', 'lycee'); gvb_inscrire($d2, 10000, 150000); $e2 = $wpdb->get_row("SELECT * FROM $E WHERE demande_id = $d2");
$t('étudiant sans e-mail ni parent : relance e-mail impossible', (function () use ($e2, $envoyer, $wpdb, $C) { $wpdb->query("UPDATE $C SET echeance = '2020-01-01' WHERE etudiant_id = {$e2->id}"); return str_contains($envoyer('gvb_relancer', 'gvb_relancer', ['id' => $e2->id, 'canal' => 'email']), 'adresse'); })());

$lignes[] = "\n## Réglages";
$envoyer('gvb_fin_sauver', 'gvb_fin_sauver', ['tranches' => '1', 'delai' => '15', 'intervalle' => '30', 'relance' => 'Bonjour {prenom}, {montant} dus.']);
$d3 = $accepter('Keita', 'Sidi'); gvb_inscrire($d3, 0, 300000); $e3 = $wpdb->get_row("SELECT * FROM $E WHERE demande_id = $d3");
$ch3 = $wpdb->get_results("SELECT * FROM $C WHERE etudiant_id = {$e3->id}");
$t('une seule tranche, pas de frais d’inscription à zéro', count($ch3) === 1 && (int) $ch3[0]->montant === 300000 && !str_contains($ch3[0]->libelle, 'tranche') && $ch3[0]->echeance === gmdate('Y-m-d', strtotime(mysql2date('Y-m-d', gvb_now())) + 15 * 86400), json_encode($ch3));
wp_set_current_user($cpt); $r = $envoyer('gvb_fin_sauver', 'gvb_fin_sauver', ['tranches' => '9', 'delai' => '1', 'intervalle' => '1', 'relance' => 'x']); $t('réglages réservés à la Direction', str_starts_with($r, 'DIE:') && gvb_fin_reglages()['tranches'] === 1);

$lignes[] = "\n## Écrans, périmètre et exports";
wp_set_current_user($dir); $_GET = []; ob_start(); gvb_page_finances(); $h = ob_get_clean();
$t('écran Finances : indicateurs, par pôle, journal', str_contains($h, 'Reste à recouvrer') && str_contains($h, 'Journal des paiements') && str_contains($h, 'REC-' . gmdate('Y') . '-00001'));
$_GET = ['id' => $e1->id]; ob_start(); gvb_page_compte(); $h = ob_get_clean(); $t('écran Compte : échéancier et paiements', str_contains($h, 'Échéancier') && str_contains($h, 'tranche 1/3') && str_contains($h, 'Enregistrer un paiement'));
$_GET = ['id' => $p1->id]; ob_start(); gvb_page_recu(); $h = ob_get_clean(); $t('reçu annulé : mention « REÇU ANNULÉ »', str_contains($h, 'REÇU ANNULÉ') && str_contains($h, 'soixante mille francs CFA'));
$_GET = []; ob_start(); gvb_page_impayes(); $h = ob_get_clean(); $t('écran Impayés', str_contains($h, 'UNI-2026-0001') && str_contains($h, 'Relancer par e-mail'));
wp_set_current_user($rpL); $_GET = ['id' => $e1->id]; $t('Responsable du Lycée : compte d’un étudiant de l’Université refusé', (function () { try { gvb_page_compte(); return false; } catch (Exception $e) { return str_starts_with($e->getMessage(), 'DIE:'); } })());
$_GET = []; ob_start(); gvb_page_impayes(); $h = ob_get_clean(); $t('Responsable du Lycée : ne voit que ses impayés', str_contains($h, 'LYC-2026-0001') && !str_contains($h, 'UNI-2026-0001'));
ob_start(); gvb_page_finances(); $h = ob_get_clean(); $t('Responsable : écran Finances sans export ni formulaire', !str_contains($h, 'Exporter le journal'));
wp_set_current_user($cpt); $_GET = ['id' => $e1->id]; ob_start(); gvb_page_compte(); $h = ob_get_clean(); $t('Comptabilité : peut encaisser mais pas annuler un paiement', str_contains($h, 'Enregistrer un paiement') && !str_contains($h, 'gvb_paiement_annuler'));
$wpdb->update($E, ['nom' => '=HYPERLINK("http://x")'], ['id' => $e1->id]);
$_GET = ['_wpnonce' => wp_create_nonce('gvb_export_paiements')]; $_REQUEST = $_GET; ob_start(); try { do_action('admin_post_gvb_export_paiements'); } catch (Throwable $x) {} $csv = ob_get_clean();
$t('export du journal : reçus présents, formule neutralisée', str_contains($csv, 'REC-' . gmdate('Y') . '-00001') && str_contains($csv, "'=HYPERLINK"));
$_GET = ['_wpnonce' => wp_create_nonce('gvb_export_impayes')]; $_REQUEST = $_GET; ob_start(); try { do_action('admin_post_gvb_export_impayes'); } catch (Throwable $x) {} $csv = ob_get_clean(); $t('export des impayés', str_contains($csv, 'UNI-2026-0001'));
wp_set_current_user($sco); $_GET = ['_wpnonce' => wp_create_nonce('gvb_export_paiements')]; $_REQUEST = $_GET;
$t('export refusé à la Scolarité', (function () { try { ob_start(); do_action('admin_post_gvb_export_paiements'); ob_end_clean(); return false; } catch (Exception $e) { ob_end_clean(); return str_starts_with($e->getMessage(), 'DIE:'); } })());

file_put_contents('/wordpress/wp-content/out/resultat.txt', implode("\n", $lignes) . "\n\n=== $ok réussis, $ko échecs ===\n");
