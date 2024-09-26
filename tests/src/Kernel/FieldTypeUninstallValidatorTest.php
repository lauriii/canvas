<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\Core\Database\Database;
use Drupal\Core\Extension\ModuleUninstallValidatorException;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\experience_builder\Traits\TestDataUtilitiesTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;

/**
 * @coversDefaultClass \Drupal\experience_builder\FieldTypeUninstallValidator
 * @group experience_builder
 *
 * @todo Add extra test cases
 *   - Default value stores the expression unescaped(is that possible?).
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
      'field_xb_test' => $this->getComponentTreeItemValue(TRUE),
    ]);
    $this->assertInstanceOf(Node::class, $node);

    $this->assertUninstallFailureReasons([
      'Provides a field type, <em class="placeholder">link</em>, that is in use in the content of the following entities: <em class="placeholder">node</em> id=<em class="placeholder">1</em> revision=<em class="placeholder">1</em>',
      'Provides a field type, <em class="placeholder">link</em>, that is in use in the content of the following entities: <em class="placeholder">node</em> id=<em class="placeholder">1</em> revision=<em class="placeholder">1</em>',
      'Provides a field type, <em class="placeholder">link</em>, that is in use in the default value of the following fields: <em class="placeholder">field_xb_test</em>',
    ]);

    // Save a new revision that does not use the 'link' field.
    $node->set('field_xb_test', $this->getComponentTreeItemValue(FALSE))->setNewRevision();
    $node->save();
    $this->assertUninstallFailureReasons([
      'Provides a field type, <em class="placeholder">link</em>, that is in use in the content of the following entities: <em class="placeholder">node</em> id=<em class="placeholder">1</em> revision=<em class="placeholder">1</em>',
      'Provides a field type, <em class="placeholder">link</em>, that is in use in the default value of the following fields: <em class="placeholder">field_xb_test</em>',
    ]);

    // Delete the previous revision that used the 'link' field.
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->deleteRevision(1);
    $this->assertUninstallFailureReasons([
      'Provides a field type, <em class="placeholder">link</em>, that is in use in the default value of the following fields: <em class="placeholder">field_xb_test</em>',
    ]);

    $this->updateFieldDefaultValue('node', 'article', 'field_xb_test', $this->getComponentTreeItemValue(FALSE));
    $this->container->get('module_installer')->uninstall(['link']);
  }

  /**
   * Test if the field is used in multiple entities of different types.
   */
  public function testUninstallXbFieldMultipleEntityTypes(): void {
    $this->container->get('module_installer')->install(['experience_builder', 'xb_test_config_node_article', 'field', 'sdc_test', 'taxonomy']);
    $vocabulary = Vocabulary::create([
      'vid' => 'tags',
      'description' => 'Tags vocabulary',
      'name' => 'Tags',
    ]);
    $vocabulary->save();

    FieldStorageConfig::create([
      'entity_type' => 'taxonomy_term',
      'field_name' => 'field_tag_test',
      'type' => 'component_tree',
    ])->save();

    FieldConfig::create([
      'entity_type' => 'taxonomy_term',
      'field_name' => 'field_tag_test',
      'bundle' => 'tags',
      'label' => 'Taxonomy Test Field',
      'required' => TRUE,
    ])->setDefaultValue($this->getComponentTreeItemValue(TRUE))
      ->save();

    $taxonomy = Term::create([
      'name' => 'Tags',
      'vid' => 'tags',
      'field_tag_test' => $this->getComponentTreeItemValue(TRUE),
    ]);
    $taxonomy->save();

    $this->assertUninstallFailureReasons([
      'Provides a field type, <em class="placeholder">link</em>, that is in use in the content of the following entities: <em class="placeholder">taxonomy_term</em> id=<em class="placeholder">1</em> revision=<em class="placeholder">1</em>',
      'Provides a field type, <em class="placeholder">link</em>, that is in use in the content of the following entities: <em class="placeholder">taxonomy_term</em> id=<em class="placeholder">1</em> revision=<em class="placeholder">1</em>',
      'Provides a field type, <em class="placeholder">link</em>, that is in use in the default value of the following fields: <em class="placeholder">field_xb_test, field_tag_test</em>',
    ]);

    // Save a new revision that does not use the 'link' field.
    $taxonomy->set('field_tag_test', $this->getComponentTreeItemValue(FALSE))->setNewRevision();
    $taxonomy->save();
    $this->assertUninstallFailureReasons([
      'Provides a field type, <em class="placeholder">link</em>, that is in use in the content of the following entities: <em class="placeholder">taxonomy_term</em> id=<em class="placeholder">1</em> revision=<em class="placeholder">1</em>',
      'Provides a field type, <em class="placeholder">link</em>, that is in use in the default value of the following fields: <em class="placeholder">field_xb_test, field_tag_test</em>',
    ]);

    // Delete the previous revision that used the 'link' field.
    $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $storage->deleteRevision(1);
    $this->updateFieldDefaultValue('taxonomy_term', 'tags', 'field_tag_test', $this->getComponentTreeItemValue(FALSE));
    $this->assertUninstallFailureReasons([
      'Provides a field type, <em class="placeholder">link</em>, that is in use in the default value of the following fields: <em class="placeholder">field_xb_test</em>',
    ]);

    $this->updateFieldDefaultValue('node', 'article', 'field_xb_test', $this->getComponentTreeItemValue(FALSE));
    $this->container->get('module_installer')->uninstall(['link']);
  }

  private function updateFieldDefaultValue(string $entity_type, string $bundle, string $field_name, array $default_value): void {
    $field_config = FieldConfig::loadByName($entity_type, $bundle, $field_name);
    $this->assertInstanceOf(FieldConfig::class, $field_config);
    $field_config->setDefaultValue($default_value);
    $field_config->save();
  }

  private function assertUninstallFailureReasons(array $reasons): void {
    // @todo Assert that there are no duplicated reasons in
    //   https://drupal.org/i/3476891.
    $expected_message = 'The following reasons prevent the modules from being uninstalled: ' . implode(', ', $reasons);
    try {
      $this->container->get('module_installer')->uninstall(['link']);
      $this->fail('Expected an exception');
    }
    catch (ModuleUninstallValidatorException $exception) {
      $this->assertStringContainsString($expected_message, $exception->getMessage());
    }
  }

  private function getComponentTreeItemValue(bool $include_link): array {
    $component_tree_item = [
      'tree' => self::encodeXBData([
        ComponentTreeStructure::ROOT_UUID => [
          [
            'uuid' => 'dynamic-static-card2df',
            'component' => 'sdc_test+my-cta',
          ],
        ],
      ]),
    ];
    $props = [
      'dynamic-static-card2df' => [
        'text' => [
          'sourceType' => 'static:field_item:string',
          'value' => 'hello, world!',
          'expression' => 'ℹ︎string␟value',
        ],
      ],
    ];
    if ($include_link) {
      $props['dynamic-static-card2df']['href'] = [
        'sourceType' => 'static:field_item:link',
        'value' => [
          'uri' => 'https://drupal.org',
          'title' => NULL,
          'options' => [],
        ],
        'expression' => 'ℹ︎link␟uri',
      ];
    }
    $component_tree_item['props'] = self::encodeXBData($props);
    return $component_tree_item;
  }

}
