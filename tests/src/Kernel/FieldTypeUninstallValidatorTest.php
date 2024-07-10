<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\Core\Database\Database;
use Drupal\Core\Extension\ModuleUninstallValidatorException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;

/**
 * @coversDefaultClass \Drupal\experience_builder\FieldTypeUninstallValidator
 * @group experience_builder
 *
 * @todo Add extra test cases
 *   - Field is used in previous revision.
 *   - Default value stores the expression unescaped(is that possible?).
 *   - Field used in multiple entities of different types.
 *   - Others?
 *   - Test with field that does not use dedicated storage.
 */
final class FieldTypeUninstallValidatorTest extends KernelTestBase {

  use ContribStrictConfigSchemaTestTrait;
  use NodeCreationTrait;

  protected function setUp(): void {
    parent::setUp();
    // Clone the current connection and replace the current prefix.
    $connection_info = Database::getConnectionInfo('default');
    if (!empty($connection_info)) {
      Database::renameConnection('default', 'simpletest_original_default');
      foreach ($connection_info as $target => $value) {
        // Replace the full table prefix definition to ensure that no table
        // prefixes of the test runner leak into the test.
        $connection_info[$target]['prefix'] = $this->databasePrefix;
      }
    }
    if (!isset($connection_info['default']['driver']) || $connection_info['default']['driver'] !== 'mysql') {
      $this->markTestSkipped('This test only runs for the MySQL database driver. See https://drupal.org/i/3452756');
    }
  }

  /**
   * Tests the FieldUninstallValidator.
   */
  public function testUninstall(): void {
    $this->container->get('module_installer')->install(['experience_builder', 'link', 'node', 'text', 'xb_test_config_node_article', 'image']);
    $this->createNode([
      'title' => 'Test node',
      'type' => 'article',
      'field_xb_test' => [
        'tree' => '[{"uuid":"dynamic-static-card2df","type":"sdc_test:my-cta"}]',
        'props' => '{"dynamic-static-card2df":{"text":{"sourceType":"dynamic","expression":"ℹ︎␜entity:node:article␝title␞␟value"},"href":{"sourceType":"static:field_item:link","value":{"uri":"https:\/\/drupal.org","title":null,"options":[]},"expression":"ℹ︎link␟uri"}}}',
      ],
    ]);
    $this->expectException(ModuleUninstallValidatorException::class);
    // For now match crude messages that prove we have caught both default and content uses.
    $this->expectExceptionMessage('Provides a field type, <em class="placeholder">link</em>, that is in use in the content of the following entities: <em class="placeholder">node</em> id=<em class="placeholder">1</em> revision=<em class="placeholder">1</em>, Provides a field type, <em class="placeholder">link</em>, that is in use in the content of the following entities: <em class="placeholder">node</em> id=<em class="placeholder">1</em> revision=<em class="placeholder">1</em>, Provides a field type, <em class="placeholder">link</em>, that is in use in the default value of the following fields: <em class="placeholder">field_xb_test</em>');
    $this->container->get('module_installer')->uninstall(['link']);
  }

}
