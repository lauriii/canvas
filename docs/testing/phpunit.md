# PHPUnit

Follow [setup instructions](./setup.md) to ensure composer dev dependencies are
installed.

Check `.env.defaults` and if you need to make any alterations, copy it to `.env`
and edit as appropriate.

From the module directory, run:
```shell
composer run phpunit -- --help
```
or with DDEV:
```shell
ddev exec -d /var/www/html/modules/contrib/canvas composer run phpunit -- --help
```
