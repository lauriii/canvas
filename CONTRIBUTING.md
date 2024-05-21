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
