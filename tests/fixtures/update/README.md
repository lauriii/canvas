<!-- @cspell:ignore VARCHAR -->
# Updating the database dumps

**NOTE**: You might want to do this using MySQL, as MariaDB doesn't really use JSON columns, which will affect how inputs
are stored. See point 6) in _Creating a new dump_.

If you want to know more about MariaDB JSON datatype, see https://mariadb.com/docs/server/reference/data-types/string-data-types/json.

### Creating a new dump

1) Checkout the version of Drupal core to update from
2) Checkout the version of Drupal Canvas to update from
3) Setup the site with data that needs updating - this could be something like so
```
# Install minimal
drush si minimal -y
# Apply the base recipe
drush recipe modules/contrib/canvas/tests/fixtures/recipes/base
# Apply the test site recipe
drush recipe modules/contrib/canvas/tests/fixtures/recipes/test_site
# Clear cache
drush cr
# Enable all db driver modules
drush en -y pgsql sqlite mysql
# Enable canvas_stark theme
drush then canvas_stark
# Enable some test modules as required
drush en -y canvas_test_code_components canvas_test_sdc
# Uninstall modules that are deleted in HEAD
drush pm-uninstall -y canvas_dev_standard
```
4) Create any content or config as needed. Usually you will prefer to start with `bare` in an update path test though and append to it. So: try to resist.
5) Use core's db-tools to export a new dump
```
php core/scripts/db-tools.php dump-database-d8-mysql > modules/contrib/canvas/tests/fixtures/update/drupal-11.2.2-with-canvas-1.0.0-alpha1.bare.php
```
6) The DB dump tool doesn't correctly dump JSON fields - open the file from step 5 and make the following edits - search for all instances as there will be two for each field - one for the data table and one for the revision

**Before**
```php
'{field_name}_inputs' => [
  'type' => 'json',
  'not null' => FALSE,
  'length' => 100,
],
```
**After**
```php
'{field_name}_inputs' => [
  'description' => 'The input for this component instance in the component tree.',
  'type' => 'json',
  'pgsql_type' => 'jsonb',
  'mysql_type' => 'json',
  'sqlite_type' => 'json',
  'not null' => FALSE,
],
```
7) gzip the resultant file

```
gzip modules/contrib/canvas/tests/fixtures/update/drupal-11.2.2-with-canvas-1.0.0-alpha1.bare.php
```

### Updating an existing dump

1) Drop your database (take a backup first if you need it)
```
drush sql-drop -y
```
2) Gunzip the data dump
```
gunzip modules/contrib/canvas/tests/fixtures/update/drupal-11.2.2-with-canvas-1.0.0-alpha1.bare.php.gz
```
3) Import the dump
```
php core/scripts/db-tools.php import modules/contrib/canvas/tests/fixtures/update/drupal-11.2.2-with-canvas-1.0.0-alpha1.bare.php
```
4) Repeat steps 4-7 from the _Creating a new dump_ section

### Troubleshooting

If you receive this error during import

```
In PdoTrait.php line 115:

  SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax;
  check the manual that corresponds to your MySQL server version for the right syntax to use nea
  r 'NULL DEFAULT NULL,
  "field_canvas_demo_label" VARCHAR(255) NULL DEFAULT NULL,
  PRIMA' at line 13
```

It likely means step 6 from the _Creating a new dump_ section was not done properly - perform that step on the .php file and try again.
