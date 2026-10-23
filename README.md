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
