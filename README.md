# groupevitos.com

Site public en **Astro (statique)**, alimenté par **WordPress headless** sur `admin.groupevitos.com`.

## Fonctionnement

1. Une publication dans WordPress déclenche (après 60 s, pour regrouper les modifications) un `repository_dispatch` GitHub via le mu-plugin `wordpress/mu-plugins/groupevitos-headless.php`.
2. GitHub Actions (`.github/workflows/deploy.yml`) lit les contenus via l'API REST WordPress et construit le site.
3. L'archive `dist/` est envoyée sur Hostinger (`scripts/deploy-hostinger.mjs`, API « Deploy static site archive »).
4. Le workflow attend que `https://groupevitos.com/build.txt` affiche la nouvelle version.

Un push sur `main` ou un lancement manuel (onglet Actions, ou bouton « Reconstruire le site » dans l'admin WordPress) fait la même chose.

Si WordPress ne répond pas pendant le build, le build échoue et **le site en ligne n'est pas touché**
(sauf si la variable de dépôt `WP_REQUIRED` vaut `false`, à utiliser tant que WordPress n'est pas installé).

## Contenus

| WordPress | Site |
|---|---|
| Titre et slogan du site (Réglages → Général) | En-tête, accueil, balises `<title>` |
| Page de slug `accueil` | Corps de la page d'accueil |
| Autres pages publiées | `/<slug>/` (pages enfants : `/<parent>/<enfant>/`), menu dans l'ordre « Ordre » des pages de premier niveau |
| Articles | `/actualites/` et `/actualites/<slug>/`, 3 derniers sur l'accueil |

## WordPress (admin.groupevitos.com)

Les mu-plugins de `wordpress/mu-plugins/` s'installent avec :

```bash
HOSTINGER_API_TOKEN=... node scripts/installer-mu-plugins.mjs
```

- `groupevitos-headless.php` : reconstruction du site, réglage du jeton GitHub (Réglages → Site public), redirection du front WordPress
- `groupevitos-formations.php` : type de contenu « Formations » et ses champs (`niveau`, `duree`, `parcours`, `admission`, `finalite`, `enseignements`, `competences`, `methodes`, `debouches`, `carriere`), exposés dans l'API REST

## Reprise de l'ancien site

L'ancien site (Elementor, sauvegarde Duplicator du 09/12/2025) avait été piraté : seuls ses textes et images ont été repris, jamais ses fichiers PHP.

1. `python scripts/nettoyer-ancien-site.py ../groupevitos-ancien-site` : HTML nettoyé dans `import.json`
2. `WP_USER=... WP_APP_PASSWORD=... node scripts/importer-ancien-site.mjs ../groupevitos-ancien-site` : simulation
3. Même commande avec `--appliquer` : envoi des images, création ou mise à jour des pages et formations

## Développement

```bash
npm install
npm run dev
```

## Configuration

| Où | Nom | Valeur |
|---|---|---|
| GitHub → Settings → Secrets → Actions | `HOSTINGER_API_TOKEN` | jeton API Hostinger (hPanel → Profil → API) |
| GitHub → Settings → Variables → Actions | `WP_REQUIRED` | `false` tant que WordPress n'est pas installé, puis supprimer |
| `wp-config.php` | `GROUPEVITOS_GITHUB_REPO` | `proprietaire/groupevitos` |
| `wp-config.php` | `GROUPEVITOS_GITHUB_TOKEN` | jeton GitHub fine-grained, ce dépôt seulement, permission *Contents : Read and write* |

## Hébergement

- Hostinger Cloud (`cloud_economy_v2`), compte `u966150428`, sites `groupevitos.com` et `admin.groupevitos.com`
- DNS Cloudflare (proxy actif, SSL Full strict), domaine chez Porkbun
- Emails Hostinger Mail (MX, SPF, DKIM, DMARC configurés dans Cloudflare)
