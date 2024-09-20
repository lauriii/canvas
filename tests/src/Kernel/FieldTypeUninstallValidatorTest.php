<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\Core\Database\Database;
use Drupal\Core\Extension\ModuleUninstallValidatorException;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\field\Entity\FieldConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\experience_builder\Traits\TestDataUtilitiesTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;

/**
 * @coversDefaultClass \Drupal\experience_builder\FieldTypeUninstallValidator
 * @group experience_builder
 *
 * @todo Add extra test cases
 *   - Default value stores the expression unescaped(is that possible?).
 *   - Field used in multiple entities of different types.
 *   - Others?
 *   - Test with field that does not use dedicated storage.
 */
final class FieldTypeUninstallValidatorTest extends KernelTestBase {

  use ContribStrictConfigSchemaTestTrait;
  use NodeCreationTrait;
  use TestDataUtilitiesTrait;

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
    $this->container->get('module_installer')->install(['experience_builder', 'xb_test_config_node_article', 'sdc_test']);
    $node = $this->createNode([
      'title' => 'Test node',
      'type' => 'article',
      'field_xb_test' => [
        'tree' => self::encodeXBData([
          ComponentTreeStructure::ROOT_UUID => [
            [
              'uuid' => 'dynamic-static-card2df',
              'component' => 'sdc_test+my-cta',
            ],
          ],
        ]),
        'props' => self::encodeXBData([
          'dynamic-static-card2df' => [
            'text' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
            ],
            'href' => [
              'sourceType' => 'static:field_item:link',
              'value' => [
                'uri' => 'https://drupal.org',
                'title' => NULL,
                'options' => [],
              ],
              'expression' => 'ℹ︎link␟uri',
            ],
          ],
        ]),
      ],
    ]);

    $this->assertInstanceOf(Node::class, $node);
    try {
      $this->container->get('module_installer')->uninstall(['link']);
      $this->fail('Expected an exception');
    }
    catch (ModuleUninstallValidatorException $exception) {
      // Assert exception message mentions but the content entity and the default value.
      $this->assertStringContainsString('The following reasons prevent the modules from being uninstalled: Provides a field type, <em class="placeholder">link</em>, that is in use in the content of the following entities: <em class="placeholder">node</em> id=<em class="placeholder">1</em> revision=<em class="placeholder">1</em>, Provides a field type, <em class="placeholder">link</em>, that is in use in the content of the following entities: <em class="placeholder">node</em> id=<em class="placeholder">1</em> revision=<em class="placeholder">1</em>, Provides a field type, <em class="placeholder">link</em>, that is in use in the default value of the following fields: <em class="placeholder">field_xb_test</em>', $exception->getMessage());
    }

    $component_tree_without_link = [
      'tree' => self::encodeXBData([
        ComponentTreeStructure::ROOT_UUID => [
          [
            'uuid' => 'dynamic-static-card2df',
            'component' => 'sdc_test+my-cta',
          ],
        ],
      ]),
      'props' => self::encodeXBData([
        'dynamic-static-card2df' => [
          'text' => [
            'sourceType' => 'dynamic',
            'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
          ],
        ],
      ]),
    ];

    // Save a new revision that does not use the 'link' field.
    $node->set('field_xb_test', $component_tree_without_link)->setNewRevision();
    $node->save();

    try {
      $this->container->get('module_installer')->uninstall(['link']);
      $this->fail('Expected an exception');
    }
    catch (ModuleUninstallValidatorException $exception) {
      // Assert exception message mentions but the content entity and the default value.
      $this->assertStringContainsString('The following reasons prevent the modules from being uninstalled: Provides a field type, <em class="placeholder">link</em>, that is in use in the content of the following entities: <em class="placeholder">node</em> id=<em class="placeholder">1</em> revision=<em class="placeholder">1</em>, Provides a field type, <em class="placeholder">link</em>, that is in use in the default value of the following fields: <em class="placeholder">field_xb_test</em>', $exception->getMessage());
    }

    // Delete the previous revision that used the 'link' field.
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->deleteRevision(1);

    try {
      $this->container->get('module_installer')->uninstall(['link']);
      $this->fail('Expected an exception');
    }
    catch (ModuleUninstallValidatorException $exception) {
      // Assert exception message no longer mentions the content entity.
      $this->assertStringContainsString('The following reasons prevent the modules from being uninstalled: Provides a field type, <em class="placeholder">link</em>, that is in use in the default value of the following fields: <em class="placeholder">field_xb_test</em>', $exception->getMessage());
    }

    // Update default value for component to not use the 'link' field.
    $field_config = FieldConfig::loadByName('node', 'article', 'field_xb_test');
    $this->assertInstanceOf(FieldConfig::class, $field_config);
    $field_config->setDefaultValue($component_tree_without_link);
    $field_config->save();

    // Now since the neither the revision nor the default value contain the link
    // field, the 'link' module can be uninstalled without an error.
    $this->container->get('module_installer')->uninstall(['link']);
  }

}
