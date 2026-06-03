# WP Summiteo

Plugin WordPress metier pour cloner et adapter des pages locales, avec prise en charge Elementor, Avia Builder, OpenAI, Unsplash, Pexels et Adobe Stock.

## Installation

Telecharger le ZIP installable :

```text
https://raw.githubusercontent.com/tanaka38/wp-summiteo/main/releases/wp-summiteo-v80-wordpress.zip
```

Puis l'installer depuis WordPress :

```text
Extensions > Ajouter une extension > Televerser une extension
```

## Mise a jour automatique WordPress

Dans WP Summiteo, renseigner le champ **URL manifeste mise a jour** avec :

```text
https://raw.githubusercontent.com/tanaka38/wp-summiteo/main/update.json
```

WordPress proposera une mise a jour lorsqu'une version superieure sera publiee dans `update.json`.

## Structure

```text
wp-summiteo/
  wp-summiteo.php
releases/
  wp-summiteo-v80-wordpress.zip
update.json
```

## Publier une nouvelle version

1. Mettre a jour la version dans `wp-summiteo/wp-summiteo.php`.
2. Generer un nouveau ZIP dans `releases/`.
3. Mettre a jour `update.json` :
   - `version`
   - `download_url`
   - `last_updated`
   - `changelog`
4. Commit et push sur `main`.
