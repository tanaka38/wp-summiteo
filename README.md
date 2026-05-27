# WP Summiteo

Plugin WordPress métier pour cloner et adapter des pages locales, avec prise en charge Elementor, Avia Builder, OpenAI et Unsplash.

## Installation

Télécharger le ZIP installable :

```text
https://raw.githubusercontent.com/tanaka38/wp-summiteo/main/releases/wp-summiteo-v46-wordpress.zip
```

Puis l'installer depuis WordPress :

```text
Extensions > Ajouter une extension > Téléverser une extension
```

## Mise à jour automatique WordPress

Dans WP Summiteo, renseigner le champ **URL manifeste mise à jour** avec :

```text
https://raw.githubusercontent.com/tanaka38/wp-summiteo/main/update.json
```

WordPress proposera une mise à jour lorsqu'une version supérieure sera publiée dans `update.json`.

## Structure

```text
wp-summiteo/
  wp-summiteo.php
releases/
  wp-summiteo-v46-wordpress.zip
update.json
```

## Publier une nouvelle version

1. Mettre à jour la version dans `wp-summiteo/wp-summiteo.php`.
2. Générer un nouveau ZIP dans `releases/`.
3. Mettre à jour `update.json` :
   - `version`
   - `download_url`
   - `last_updated`
   - `changelog`
4. Commit et push sur `main`.
