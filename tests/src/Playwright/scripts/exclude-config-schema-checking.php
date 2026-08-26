<?php

/**
 * @file
 * Adds config objects to a test site's config schema checker exclusion list.
 *
 * The Playwright harness installs its site via `test-site.php install` without a
 * \Drupal\TestSite\TestSetupInterface, so — unlike CanvasTestSetup — nothing
 * registers Canvas's config schema checker exclusions. This script patches the
 * test site's services.yml so that subsequently applied recipes (via
 * `drupal recipe`) do not fail strict schema validation on known-invalid core
 * config.
 *
 * Usage:
 *   php exclude-config-schema-checking.php <services.yml> <config-name>…
 *
 * @see \Drupal\Tests\canvas\TestSite\CanvasTestSetup
 * @see \Drupal\Core\Test\FunctionalTestSetupTrait::prepareSettings()
 */

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

// Locate and load the Composer autoloader by walking up from this script, so
// the script works regardless of the working directory it is invoked from.
$dir = __DIR__;
$autoloader = NULL;
for ($i = 0; $i < 15; $i++) {
  if (is_file($dir . '/autoload.php')) {
    $autoloader = $dir . '/autoload.php';
    break;
  }
  $dir = dirname($dir);
}
if ($autoloader === NULL) {
  fwrite(STDERR, "Unable to locate the Composer autoloader.\n");
  exit(1);
}
require_once $autoloader;

$args = $_SERVER['argv'] ?? [];
$services_yml = $args[1] ?? '';
$exclusions = array_slice($args, 2);
if ($services_yml === '' || $exclusions === [] || !is_file($services_yml)) {
  // Nothing to do (e.g. strict config schema checking is not enabled).
  exit(0);
}

$services = Yaml::parseFile($services_yml) ?? [];
// The `testing.config_schema_checker` service only exists when strict config
// schema checking is enabled; its second argument is the exclusion list.
if (!isset($services['services']['testing.config_schema_checker']['arguments'][1])
  || !is_array($services['services']['testing.config_schema_checker']['arguments'][1])) {
  exit(0);
}
$excluded = &$services['services']['testing.config_schema_checker']['arguments'][1];
foreach ($exclusions as $exclusion) {
  if (!in_array($exclusion, $excluded, TRUE)) {
    $excluded[] = $exclusion;
  }
}
file_put_contents($services_yml, Yaml::dump($services));
