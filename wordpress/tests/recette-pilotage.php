<?php
// Recette du pilotage de la Direction : jeux de données chiffrés (WordPress Playground, base jetable).
define('GVB_ESSAI', true);
$lignes = []; $ok = 0; $ko = 0;
$t = function ($nom, $cond, $detail = '') use (&$lignes, &$ok, &$ko) { $cond ? $ok++ : $ko++; $lignes[] = ($cond ? '  ok  ' : ' ECHEC ') . $nom . (!$cond && $detail !== '' ? ' — ' . substr($detail, 0, 500) : ''); };
register_shutdown_function(function () { $e = error_get_last(); if ($e && in_array($e['type'], [E_ERROR, E_PARSE], true)) file_put_contents('/wordpress/wp-content/out/fatal.txt', print_r($e, true)); });
require '/wordpress/wp-load.php';
do_action('init');
global $wpdb;
$an = gvb_reglages()['annee']; $now = gvb_now(); $auj = gvb_aujourdhui(); $hier = gmdate('Y-m-d', time() - 86400);
$D = gvb_table('demandes'); $I = gvb_table('inscriptions'); $C = gvb_table('charges'); $S = gvb_table('seances'); $P = gvb_table('presences'); $E = gvb_table('etudiants');
add_filter('wp_die_handler', fn() => function ($m) { throw new Exception('DIE:' . (is_wp_error($m) ? $m->get_error_message() : (string) $m)); });
add_filter('wp_redirect', function ($loc) { throw new Exception('REDIR:' . $loc); });
gvb_synchroniser_roles();
$mk = function ($l, $r, $pole = '') { $id = wp_insert_user(['user_login' => $l, 'user_pass' => wp_generate_password(), 'role' => $r, 'user_email' => "$l@exemple.test"]); if ($pole) update_user_meta($id, 'gv_pole', $pole); return $id; };
$dir = $mk('d', 'gv_direction'); $sco = $mk('s', 'gv_scolarite'); $cpt = $mk('c', 'gv_comptabilite'); $rpL = $mk('rl', 'gv_responsable_pole', 'lycee'); $rpX = $mk('rx', 'gv_responsable_pole'); $com = $mk('com', 'gv_communication');
wp_set_current_user($dir);

$demande = function ($nom, $prenom, $niveau, $pole, $source = 'agence') use ($wpdb, $D) {
    $id = gvb_creer_demande(['pole' => $pole, 'annee' => '2026–2027', 'niveau' => $niveau, 'formation_id' => 0, 'serie' => '', 'nom' => $nom, 'prenom' => $prenom, 'naissance' => '2006-03-14', 'sexe' => 'F', 'nationalite' => '', 'telephone' => '', 'email' => '', 'adresse' => '', 'parent_nom' => '', 'parent_telephone' => '', 'parent_email' => '', 'provenance' => '', 'message' => ''], $source);
    $wpdb->update(gvb_table('pieces'), ['recue' => 1], ['demande_id' => $id]);
    return $id;
};
$jours = fn($n) => gmdate('Y-m-d H:i:s', time() - $n * 86400);
// Admissions : Awa (inscrite, site), Moussa (acceptée), Sidi (refusée), Nouvelle (en attente depuis 10 jours), Fanta (Lycée, inscrite)
$dA = $demande('Diarra', 'Awa', 'Licence 1', 'universite', 'site'); $dB = $demande('Traoré', 'Moussa', 'Licence 1', 'universite'); $dC = $demande('Keita', 'Sidi', 'Licence 1', 'universite'); $dD = $demande('Coulibaly', 'Nana', 'Licence 1', 'universite'); $dF = $demande('Sy', 'Fanta', '10e année', 'lycee');
foreach ([$dA, $dB, $dC, $dD, $dF] as $x) $wpdb->update($D, ['cree_le' => $jours(20)], ['id' => $x]);
$wpdb->update($D, ['statut' => 'acceptee', 'decide_le' => $jours(16)], ['id' => $dA]); $wpdb->update($D, ['statut' => 'acceptee', 'decide_le' => $jours(18)], ['id' => $dB]); $wpdb->update($D, ['statut' => 'refusee', 'decide_le' => $jours(14), 'motif' => 'x'], ['id' => $dC]); $wpdb->update($D, ['statut' => 'nouvelle', 'cree_le' => $jours(10)], ['id' => $dD]); $wpdb->update($D, ['statut' => 'acceptee', 'decide_le' => $jours(18)], ['id' => $dF]);
gvb_inscrire($dA, 50000, 400000, ''); gvb_inscrire($dF, 10000, 150000, '');
$eA = (int) $wpdb->get_var("SELECT id FROM $E WHERE demande_id = $dA"); $eF = (int) $wpdb->get_var("SELECT id FROM $E WHERE demande_id = $dF");
$wpdb->insert($I, ['demande_id' => $dA, 'etudiant_id' => $eA, 'matricule' => 'UNI-2025-0001', 'pole' => 'universite', 'annee' => '2025–2026', 'niveau' => 'Licence 1', 'frais' => '{}', 'statut' => 'validee', 'inscrit_le' => $jours(300)]);
// Finances : Awa paie 60 000 (retard de plus de 90 jours), Fanta règle tout (160 000)
gvb_enregistrer_paiement($eA, 60000, 'especes', $auj, '', ''); gvb_enregistrer_paiement($eF, 160000, 'virement', $auj, 'VIR-1', '');
$wpdb->query("UPDATE $C SET echeance = '" . gmdate('Y-m-d', time() - 100 * 86400) . "' WHERE etudiant_id = $eA AND libelle LIKE '%tranche 1/3'");
$wpdb->query("UPDATE $C SET echeance = '" . gmdate('Y-m-d', time() - 120 * 86400) . "' WHERE etudiant_id = $eA AND libelle LIKE 'Frais d%'");
// Pédagogie : 2 cours (1 couvert), séances hier : tenue ×2 (présent, absent, retard), planifiée non traitée ×1, annulée ×1
$wpdb->insert(gvb_table('cours'), ['pole' => 'universite', 'formation_id' => 0, 'niveau' => 'Licence 1', 'intitule' => 'Gestion de projet', 'volume' => 40, 'cree_le' => $now]); $c1 = (int) $wpdb->insert_id;
$wpdb->insert(gvb_table('cours'), ['pole' => 'lycee', 'formation_id' => 0, 'niveau' => '10e année', 'intitule' => 'Mathématiques', 'volume' => 60, 'cree_le' => $now]); $c2 = (int) $wpdb->insert_id;
$wpdb->insert(gvb_table('enseignants'), ['nom' => 'Kouyaté', 'prenom' => 'I', 'email' => 'i@exemple.test', 'cree_le' => $now, 'modifie_le' => $now]); $en = (int) $wpdb->insert_id;
$wpdb->insert(gvb_table('affectations'), ['cours_id' => $c1, 'enseignant_id' => $en, 'annee' => $an, 'heures' => 40, 'cree_le' => $now]); $wpdb->insert(gvb_table('affectations'), ['cours_id' => $c2, 'enseignant_id' => $en, 'annee' => $an, 'heures' => 20, 'cree_le' => $now]);
$sc = function ($cours, $pole, $niveau, $debut, $fin, $statut) use ($wpdb, $S, $en, $an, $hier, $now) { $wpdb->insert($S, ['cours_id' => $cours, 'enseignant_id' => $en, 'salle_id' => 0, 'annee' => $an, 'pole' => $pole, 'niveau' => $niveau, 'date_seance' => $hier, 'debut' => $debut, 'fin' => $fin, 'statut' => $statut, 'cree_le' => $now]); return (int) $wpdb->insert_id; };
$sA = $sc($c1, 'universite', 'Licence 1', '08:00:00', '10:00:00', 'tenue'); $sB = $sc($c1, 'universite', 'Licence 1', '10:00:00', '12:00:00', 'tenue'); $sc($c1, 'universite', 'Licence 1', '14:00:00', '15:00:00', 'planifiee'); $sc($c1, 'universite', 'Licence 1', '15:00:00', '16:00:00', 'annulee'); $sL = $sc($c2, 'lycee', '10e année', '08:00:00', '09:00:00', 'tenue');
$pr = fn($s, $e, $st) => $wpdb->insert($P, ['seance_id' => $s, 'etudiant_id' => $e, 'statut' => $st, 'saisi_le' => $now]);
$pr($sA, $eA, 'present'); $pr($sB, $eA, 'absent'); $pr($sL, $eF, 'retard');
// Espaces, notifications, communication
$wpdb->insert(gvb_table('comptes'), ['type' => 'parent', 'email' => 'a@exemple.test', 'mdp' => 'x', 'derniere_connexion' => $now, 'cree_le' => $now]); $wpdb->insert(gvb_table('comptes'), ['type' => 'parent', 'email' => 'b@exemple.test', 'mdp' => '', 'cree_le' => $jours(10)]);
$wpdb->insert(gvb_table('notifs'), ['cle' => 'k1', 'email' => 'a@exemple.test', 'statut' => 'echec', 'cree_le' => $now]); $wpdb->insert(gvb_table('notifs'), ['cle' => 'k2', 'email' => 'a@exemple.test', 'statut' => 'envoye', 'envoye_le' => $now, 'cree_le' => $now]);
$wpdb->insert(gvb_table('contacts'), ['canal' => 'email', 'email' => 'v@exemple.test', 'consentement_le' => $now, 'telechargements' => 2, 'cree_le' => $now]); $wpdb->insert(gvb_table('publications'), ['texte' => 'x', 'statut' => 'publiee', 'cree_le' => $now]);

$lignes[] = '## Droits';
$t('Direction : pilotage + export ; Comptabilité et Responsables : consultation ; Scolarité et Communication : aucun', user_can($dir, 'gv_pilotage_exporter') && user_can($cpt, 'gv_pilotage_voir') && !user_can($cpt, 'gv_pilotage_exporter') && user_can($rpL, 'gv_pilotage_voir') && !user_can($sco, 'gv_pilotage_voir') && !user_can($com, 'gv_pilotage_voir'));

$lignes[] = "\n## Indicateurs (Direction, tous pôles)";
$d = gvb_pilotage_donnees($an);
$a = $d['admissions'];
$t('demandes : 5 (1 via le site, 4 agence)', $a['total'] === 5 && $a['site'] === 1 && $a['agence'] === 4, json_encode($a));
$t('acceptées 3 (dont 2 inscrites), taux d’acceptation 60 %, conversion 40 %', $a['acceptees'] === 3 && $a['inscrites'] === 2 && $a['taux_acceptation'] === 60 && $a['taux_conversion'] === 40, json_encode([$a['acceptees'], $a['inscrites'], $a['taux_acceptation'], $a['taux_conversion']]));
$t('délai moyen de décision ≈ 4,0 jours (4, 2, 6, 2 → 3,5…)', $a['delai_jours'] !== null && $a['delai_jours'] >= 3 && $a['delai_jours'] <= 5, json_encode($a['delai_jours']));
$e = $d['effectifs']; $t('effectifs : 2 actifs (1 Université, 1 Lycée)', $e['actifs'] === 2 && $e['par_pole']['universite'] === 1 && $e['par_pole']['lycee'] === 1, json_encode($e));
$t('évolution : 1 inscription en 2025–2026, 2 en 2026–2027', ($d['evolution']['2025–2026']['inscriptions'] ?? 0) === 1 && ($d['evolution'][$an]['inscriptions'] ?? 0) === 2, json_encode($d['evolution']));
$f = $d['finances'];
$t('finances : attendu 610 000 (450 000 + 160 000)', $f['attendu'] === 610000, json_encode($f['attendu']));
$t('finances : encaissé 220 000, recouvrement 36 %', $f['encaisse'] === 220000 && $f['recouvrement'] === 36, json_encode([$f['encaisse'], $f['recouvrement']]));
$t('finances : retard = 123 333 (Awa), 1 étudiant, tranche « + de 90 j »', $f['retard'] === 123333 && $f['nb_retard'] === 1 && $f['vieillissement']['+ de 90 j'] === 123333 && $f['vieillissement']['0–30 j'] === 0, json_encode($f['vieillissement']));
$t('finances : encaissements du mois courant = 220 000', $f['mensuel'][gmdate('Y-m')] === 220000 && count($f['mensuel']) === 12, json_encode($f['mensuel']));
$t('finances : facturé pour l’année = 610 000', $f['attendu_annee'] === 610000, json_encode($f['attendu_annee']));
$p = $d['pedagogie'];
$t('pédagogie : 3 séances tenues sur 30 jours, 1 annulée, 1 à régulariser', $p['tenues'] === 3 && $p['annulees'] === 1 && $p['a_regulariser'] === 1, json_encode($p));
$t('pédagogie : présence 67 % (3 appels, 1 absence), taux de réalisation 75 %', $p['presence_taux'] === 67 && $p['presence_total'] === 3 && $p['absences'] === 1 && $p['taux_realisation'] === 75, json_encode([$p['presence_taux'], $p['taux_realisation']]));
$t('pédagogie : heures effectuées 5 h sur 60 h affectées', (float) $p['heures_effectuees'] === 5.0 && $p['heures_affectees'] === 60, json_encode([$p['heures_effectuees'], $p['heures_affectees']]));
$t('pédagogie : 1 cours couvert sur 2 (le Lycée : 20 h sur 60)', $p['cours'] === 2 && $p['cours_couverts'] === 1);
$c = $d['espaces']; $t('espaces : 1 compte actif sur 2 (50 %), 1 connecté, 1 notification envoyée, 1 en échec', $c['total'] === 2 && $c['actives'] === 1 && $c['taux_activation'] === 50 && $c['connectes_30j'] === 1 && $c['notifs_envoyees'] === 1 && $c['notifs_echec'] === 1, json_encode($c));
$co = $d['communication']; $t('communication : 1 contact, 2 téléchargements, 1 publication', $co['contacts_30j'] === 1 && $co['telechargements'] === 2 && $co['publications_30j'] === 1, json_encode($co));
$libs = array_map(fn($x) => $x[0], $d['alertes']);
$t('« à traiter » : demande en attente > 7 jours, acceptée non inscrite, impayés > 60 j, séance sans appel, cours non couvert, notification en échec, invitation non activée', count($d['alertes']) === 7 && in_array('Séances passées sans appel ni annulation', $libs, true) && in_array('Invitations non activées depuis plus de 7 jours', $libs, true), json_encode($libs));
$t('alerte impayés exprimée en montant', (function () use ($d) { foreach ($d['alertes'] as $x) if ($x[0] === 'Impayés de plus de 60 jours') return $x[1] === 123333 && !empty($x[3]); return false; })());

$lignes[] = "\n## Filtre par pôle et périmètre";
$dl = gvb_pilotage_donnees($an, 'lycee');
$t('filtre « Lycée » : 1 demande, 1 actif, attendu 160 000, aucun retard, 1 cours sur 1 non couvert', $dl['admissions']['total'] === 1 && $dl['effectifs']['actifs'] === 1 && $dl['finances']['attendu'] === 160000 && $dl['finances']['retard'] === 0 && $dl['pedagogie']['cours'] === 1 && $dl['pedagogie']['cours_couverts'] === 0, json_encode([$dl['admissions']['total'], $dl['finances']['attendu'], $dl['pedagogie']['cours']]));
$t('filtre par pôle : les espaces en ligne (transversaux) disparaissent', $dl['espaces'] === null && $dl['communication'] === null);
wp_set_current_user($rpL); $dr = gvb_pilotage_donnees($an, 'universite');
$t('Responsable du Lycée : son pôle est imposé (le filtre « Université » est ignoré)', $dr['pole'] === 'lycee' && $dr['admissions']['total'] === 1 && $dr['finances']['attendu'] === 160000);
$t('Responsable du Lycée : pas de données transversales', $dr['espaces'] === null);
wp_set_current_user($rpX); $dn = gvb_pilotage_donnees($an); $t('Responsable sans pôle : aucune donnée', $dn['pole'] === '__aucun__' && $dn['admissions'] === null && $dn['finances'] === null);
$_GET = []; ob_start(); gvb_page_pilotage(); $h = ob_get_clean(); $t('Responsable sans pôle : message explicite', str_contains($h, 'Aucun pôle ne vous est attribué'));
wp_set_current_user($cpt); $dc = gvb_pilotage_donnees($an);
$t('Comptabilité : finances visibles, espaces en ligne masqués', $dc['finances'] !== null && $dc['espaces'] === null && $dc['communication'] === null);
wp_set_current_user($sco); $_GET = []; $t('Scolarité : écran refusé', (function () { try { gvb_page_pilotage(); return false; } catch (Exception $e) { return str_starts_with($e->getMessage(), 'DIE:'); } })());

$lignes[] = "\n## Écran, export, impression";
wp_set_current_user($dir); $_GET = ['annee' => $an]; ob_start(); gvb_page_pilotage(); $h = ob_get_clean();
$t('écran Pilotage : rubriques et chiffres clés', str_contains($h, 'À traiter') && str_contains($h, 'Admissions') && str_contains($h, '610 000 FCFA') && str_contains($h, '36 % recouvré') && str_contains($h, 'Vie pédagogique') && str_contains($h, 'Espaces en ligne et communication') && str_contains($h, 'Imprimer'));
$t('écran Pilotage : graphiques (barres) et alerte impayés en montant', str_contains($h, 'gvb-barre') && str_contains($h, '123 333 FCFA'));
$_GET = ['annee' => $an, 'pole' => 'lycee']; ob_start(); gvb_page_pilotage(); $h = ob_get_clean(); $t('écran filtré sur le Lycée : pas de rubrique Espaces', str_contains($h, 'Lycée') && !str_contains($h, 'Espaces en ligne et communication'));
$_GET = ['_wpnonce' => wp_create_nonce('gvb_export_pilotage'), 'annee' => $an]; $_REQUEST = $_GET; ob_start(); try { do_action('admin_post_gvb_export_pilotage'); } catch (Throwable $x) {} $csv = ob_get_clean();
$t('export CSV : indicateurs et valeurs', str_contains($csv, 'Rubrique;Indicateur;Valeur') && str_contains($csv, 'Finances;Attendu;610000') && str_contains($csv, 'Admissions;Demandes;5') && str_contains($csv, 'Évolution;"Inscriptions 2025–2026";1'), substr($csv, 0, 400));
wp_set_current_user($cpt); $_GET = ['_wpnonce' => wp_create_nonce('gvb_export_pilotage')]; $_REQUEST = $_GET;
$t('export refusé à la Comptabilité', (function () { try { ob_start(); do_action('admin_post_gvb_export_pilotage'); ob_end_clean(); return false; } catch (Exception $e) { ob_end_clean(); return str_starts_with($e->getMessage(), 'DIE:'); } })());
$t('barres : valeurs vides gérées', str_contains(gvb_barres([]), 'Pas encore de données') && str_contains(gvb_barres(['A' => 0, 'B' => 5]), 'width:100%'));

file_put_contents('/wordpress/wp-content/out/resultat.txt', implode("\n", $lignes) . "\n\n=== $ok réussis, $ko échecs ===\n");
