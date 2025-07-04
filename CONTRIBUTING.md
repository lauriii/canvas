# Contributing

For joining the development process of Experience Builder (XB for short) or trying the development process, we strongly recommend the use of [DDEV](https://ddev.com/get-started/) (version 1.24.0 or later), and the use of the [drupal-xb/ddev-drupal-xb-dev addon](https://github.com/drupal-xb/ddev-drupal-xb-dev).

## Useful links
1. [Issue queue](https://www.drupal.org/project/issues/experience_builder?categories=All)
2. [Source code](https://git.drupalcode.org/project/experience_builder)

## DDEV for your local environment

```shell
# Extracted from the ddev-drupal-xb-dev plugin
mkdir ~/Sites/xb-dev
cd ~/Sites/xb-dev
ddev config --project-type=drupal --php-version=8.3 --docroot=web
# XB requires Drupal >= 11.2
ddev composer create drupal/recommended-project:11.x@dev --no-install
ddev add-on get drupal-xb/ddev-drupal-xb-dev
# This will clone the 'experience_builder' repo under web/modules/contrib
ddev xb-setup
ddev xb-dev-extras
```
Additionally, you should add  `$settings['extension_discovery_scan_tests'] = TRUE;` to the end of the `sites/default/settings.php` file (this allows the `xb_dev_standard` hidden module to be installed).

### First experience
After this process, you will get the `drush uli` to login into the admin area. You will see an article created. If you edit the article, you will see a new link "Experience Builder: Test" to edit this entity with the new XB UI.

### Usage
The most common commands in the development process are:
1. `ddev xb-site-install`: This will reinstall the site and enable a couple of commands. Very useful when you update XB and what to have a fresh installation.
2. `ddev xb-ui-build`: This will build the `experience_builder/ui` javascript application. Required whenever you update XB.
3. Tests and linting: The commands `xb-eslint`, `xb-fix`, `xb-phpcs`, `xb-phpstan` and `xb-phpunit` must be used before any commit to ensure code looks good and pass tests.
4. More commands with `ddev | grep xb-` and in the [ddev-drupal-xb-dev repo usage](https://github.com/drupal-xb/ddev-drupal-xb-dev#usage).

Tip: Use `ddev help <command>` for additional information about the command and arguments available.

## Setting up you local manually
1. Clone Drupal 11 (preferably a clone for Git archeology: `git clone https://git.drupalcode.org/project/drupal.git` — >= Drupal 11.2 is required).
2. `cd drupal && git clone git@git.drupal.org:project/experience_builder.git modules/contrib/experience_builder`
4. `composer require drush/drush`
5. `vendor/bin/drush si standard`
4. Add `$settings['extension_discovery_scan_tests'] = TRUE;` to the end of the `sites/default/settings.php` file (this allows the `xb_dev_standard` hidden module to be installed).
6. `vendor/bin/drush pm:install experience_builder xb_dev_standard`
7.  Build the front end: `cd modules/contrib/experience_builder/ui` and then either
    * With Node.js available: `npm install && npm run build`
    * With Docker available: `docker build --output dist .`
8.  Browse to `/node/add/article` just enter a title for the article and hit save. This will create a node with an empty canvas for the field `field_xb_demo`.
9.  In the toolbar, click "Experience Builder"! 🥳
10. If you're curious: look at the code, step through it with a debugger, and join us!
11. If you want to run *all* tests locally: `composer require drupal/simple_oauth:^6 jangregor/phpstan-prophecy league/openapi-psr7-validator webflo/drupal-finder devizzent/cebe-php-openapi --dev && composer update`

### During development
The following commands assume the recommended development details outlined above, particularly the location of the `vendor` directory. If your `vendor` directory is not adjacent to your `index.php` — if you created your environment using [`drupal/recommended-project`](https://packagist.org/packages/drupal/recommended-project), for example — you will need to adjust the command path (i.e., `../vendor` instead of `vendor`).

### Usage of the commands
#### `phpcs`
Manually, from the Drupal project root (i.e. where `index.php` lives):
```shell
vendor/bin/phpcs -s modules/contrib/experience_builder/ --standard=modules/contrib/experience_builder/phpcs.xml --basepath=modules/contrib/experience_builder
```
#### `phpstan`
Manually, from the Drupal project root (i.e. where `index.php` lives):
```shell
php vendor/bin/phpstan analyze modules/contrib/experience_builder --memory-limit=256M --configuration=modules/contrib/experience_builder/phpstan.neon
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

# Developer Tips & Tricks

## 1. Inspecting the rendered preview markup
There is a secret keyboard shortcut that is very helpful for debugging the HTML inside the iFrame.

If you press and hold the `V` key, the React app will 'disappear' until you release the `V` key.

If you press & hold the `V` key and then click on the iFrame (focusing into it),  then the V key can be released and the UI will remain hidden. Once hidden, it becomes much easier to use the browser's developer tools to inspect elements inside the iFrame.

You can then click (focus) outside the iFrame and tap the `V` key once more to return the UI.

## 2. Always start from a fresh start
1. Reinstall the site frequently (`ddev xb-site-install`).
2. If you're working with sqlite, delete the database frequently (`rm web/sites/default/.ht.sqlite`).
3. Build the UI frequently (`ddev xb-ui-build`).

# Releases

For now, Experience Builder does _not_ include the built UI in its `git` repository, because it is currently optimized
for development.

Therefore, every release must build the UI, commit the built UI, and revert it. Similar to Drupal core's releases.

This has been semi-automated: use `sh scripts/tag-release.sh` to be asked what tag to create, and it'll create that tag
in a (temporary) working directory without touching the Experience Builder `git` repository the command runs from. Prior
to pushing, you'll be given the opportunity to inspect the result.
