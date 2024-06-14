# See it in action + recommended development environment
1. Drupal 11 (preferably a git clone, for git archeology — 10.3 will work too).
2. `composer require drush/drush`
3. `drush si standard`
4. `drush pm:install experience_builder`
5. Browse to `/node/add/article` — you'll see a `🪄 XB Demo ✨` field. Don't touch that — just enter a title for the article and hit save: a component is rendered using the article title 🤓
6. If you're curious: look at the code, step through it with a debugger, and join us!

# Initial set up
All of these steps must only be performed _once_.
## For `phpcs`
To allow Experience Builder to reuse Drupal core's `phpcs` ruleset:
```
ln -sv /PATH/TO/DRUPAL/ROOT/core/core/phpcs.xml.dist core.phpcs.xml.dist
```

# During development
## `phpcs`
From Drupal project root (i.e. where `index.php` lives):
```
vendor/bin/phpcs -s modules/contrib/experience_builder/ --standard=modules/contrib/experience_builder/phpcs.xml --basepath=modules/contrib/experience_builder
```

## `phpstan`
From Drupal project root (i.e. where `index.php` lives):
```
php vendor/bin/phpstan analyze modules/contrib/experience_builder --memory-limit=256M --configuration=modules/contrib/experience_builder/phpstan.neon
```

# Architectural Decision Records
When architectural decisions are made, they should be recorded in _ADRs_. To create an ADR:

1. Install <https://github.com/npryce/adr-tools> — see [installation instructions](https://github.com/npryce/adr-tools/blob/master/INSTALL.md).
2. From the root of this project: ```adr new This Is A New Decision```.
