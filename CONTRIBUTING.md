# See it in action + recommended development environment
1. Drupal 11 (preferably a clone for Git archeology: `git clone https://git.drupalcode.org/project/drupal.git` — 10.3 will work too).
2. `composer require drush/drush`
3. `drush si standard`
4. `drush pm:install experience_builder xb_dev_standard`
5. Build the front end: `cd modules/contrib/experience_builder/ui` and then either
    * With Node.js available: `npm install && npm run build`
    * With Docker available: `docker build --output dist .`
6. Browse to `/node/add/article` just enter a title for the article and hit save. This will create a node with an empty canvas for the field `field_xb_demo`.
7. In the toolbar, click "Experience Builder"! 🥳
8. If you're curious: look at the code, step through it with a debugger, and join us!
9. If you want to run *all* tests locally, including the OpenAPI spec one: `composer require league/openapi-psr7-validator webflo/drupal-finder devizzent/cebe-php-openapi --dev`

# During development
The following commands assume the recommended development details outlined above, particularly the location of the `vendor` directory. If your `vendor` directory is not adjacent to your `index.php` — if you created your environment using [`drupal/recommended-project`](https://packagist.org/packages/drupal/recommended-project), for example — you will need to adjust the command path (i.e., `../vendor` instead of `vendor`). If you are using our DDEV add-on ([`TravisCarden/ddev-drupal-xb-dev`](https://github.com/TravisCarden/ddev-drupal-xb-dev)), convenience commands are provided.

## `phpcs`
Manually, from the Drupal project root (i.e. where `index.php` lives):
```shell
vendor/bin/phpcs -s modules/contrib/experience_builder/ --standard=modules/contrib/experience_builder/phpcs.xml --basepath=modules/contrib/experience_builder
```

Or using the DDEV add-on:
```shell
ddev xb-phpcs
```

## `phpstan`
Manually, from the Drupal project root (i.e. where `index.php` lives):
```shell
php vendor/bin/phpstan analyze modules/contrib/experience_builder --memory-limit=256M --configuration=modules/contrib/experience_builder/phpstan.neon
```

Or using the DDEV add-on:
```shell
ddev xb-phpstan
```

# Architectural Decision Records
When architectural decisions are made, they should be recorded in _ADRs_. To create an ADR:

1. Install <https://github.com/npryce/adr-tools> — see [installation instructions](https://github.com/npryce/adr-tools/blob/master/INSTALL.md).
2. From the root of this project: ```adr new This Is A New Decision```.

# C4 model & Auto-Generated Diagrams
To edit: either modify locally, or copy/paste `docs/XB.dsl` into <https://structurizr.com/dsl> for easy previewing!

When updating the C4 model in `docs/XB.dsl`, the diagrams (in `docs/diagrams/`) must be updated too. To do that:

```
structurizr-cli export -workspace docs/XB.dsl --format mermaid --output docs/diagrams && for file in docs/diagrams/*.mmd; do printf "\`\`\`mermaid\n" | cat - $file  > temp && mv -f temp $file && echo $'\n```' >> $file; done && find docs/diagrams/ -name "*.mmd" -exec sh -c 'mv "$1" "${1%.mmd}.md"' _ {} \;
```
This will auto-update `docs/diagrams/structurizr-*.md`.

(After installing `structurizr-cli`: <https://docs.structurizr.com/cli/installation>.)

# Data Model Diagram

To edit: either modify locally, or copy/paste `docs/diagrams/*.md` (not the ones with the `structurizr-` prefix) into <https://mermaid.live/edit> for easy previewing!
