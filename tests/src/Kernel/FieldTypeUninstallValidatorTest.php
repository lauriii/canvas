<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\Core\Database\Database;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Extension\ModuleUninstallValidatorException;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\experience_builder\Entity\Page;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\experience_builder\TestSite\XBTestSetup;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;

/**
 * @coversDefaultClass \Drupal\experience_builder\FieldTypeUninstallValidator
 * @group experience_builder
 */
final class FieldTypeUninstallValidatorTest extends KernelTestBase {

  use ContribStrictConfigSchemaTestTrait;
  use NodeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema('user');
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
    // The `sdc_test_all_props` module includes props that use
    // `contentMediaType: text/html` which enables the CKEditor 5 module which
    // requires the default theme to be installed.
    // @see \_ckeditor5_theme_css()
    \Drupal::service('theme_installer')->install(['stark']);
  }

  /**
   * Tests the FieldUninstallValidator.
   *
   * @dataProvider uninstallDataProvider
   */
  public function testUninstall(string $entity_type_id, string $bundle, string $field_name): void {
    $installer = $this->container->get('module_installer');
    $installer->install(['experience_builder']);
    $installer->install(['xb_test_config_node_article', 'xb_test_sdc']);
    $entity_storage = $this->container->get('entity_type.manager')->getStorage($entity_type_id);
    $entity = $entity_storage->create([
      'title' => 'Test content',
      'type' => $bundle,
      $field_name => $this->getComponentTreeItemValue(TRUE),
    ]);
    assert($entity instanceof ContentEntityInterface);
    $entity->save();

    $this->assertUninstallFailureReasons([
      'Provides a field type, <em class="placeholder">link</em>, that is in use in the content of the following entities: <em class="placeholder">' . $entity->getEntityTypeId() . '</em> id=<em class="placeholder">1</em> revision=<em class="placeholder">1</em>',
      'Provides a field type, <em class="placeholder">link</em>, that is in use in the default value of the following fields: <em class="placeholder">field_xb_test</em>',
    ]);

    // Save a new revision that does not use the 'link' field.
    $entity->set($field_name, $this->getComponentTreeItemValue(FALSE))->setNewRevision();
    $entity->save();
    $this->assertUninstallFailureReasons([
      'Provides a field type, <em class="placeholder">link</em>, that is in use in the content of the following entities: <em class="placeholder">' . $entity->getEntityTypeId() . '</em> id=<em class="placeholder">1</em> revision=<em class="placeholder">1</em>',
      'Provides a field type, <em class="placeholder">link</em>, that is in use in the default value of the following fields: <em class="placeholder">field_xb_test</em>',
    ]);

    // Delete the previous revision that used the 'link' field.
    $storage = \Drupal::entityTypeManager()->getStorage($entity_type_id);
    assert($storage instanceof RevisionableStorageInterface);
    $storage->deleteRevision(1);

    $this->assertUninstallFailureReasons([
      'Provides a field type, <em class="placeholder">link</em>, that is in use in the default value of the following fields: <em class="placeholder">field_xb_test</em>',
    ]);

    // We catch usages in base field definition default value.
    $this->updateFieldDefaultValue(Page::ENTITY_TYPE_ID, PAGE::ENTITY_TYPE_ID, 'components', $this->getComponentTreeItemValue(TRUE));
    $installer->install(['xb_test_page']);
    $this->assertUninstallFailureReasons([
      'Provides a field type, <em class="placeholder">link</em>, that is in use in the default value of the following fields: <em class="placeholder">components, field_xb_test</em>',
    ]);

    // Clear field definitions default values.
    $this->updateFieldDefaultValue('node', 'article', 'field_xb_test', $this->getComponentTreeItemValue(FALSE));
    $this->updateFieldDefaultValue(Page::ENTITY_TYPE_ID, PAGE::ENTITY_TYPE_ID, 'components', $this->getComponentTreeItemValue(FALSE));
    $installer->uninstall(['xb_test_page']);

    if ($entity->getEntityTypeId() === Page::ENTITY_TYPE_ID) {
      $entity->delete();
    }

    // We should now be able to uninstall the 'link' module but because 'link'
    // is dependency for 'experience_builder' we should get an error because
    // of the XB fields.
    $this->assertUninstallFailureReasons(
      ['The <em class="placeholder">Experience Builder</em> field type is used in the following field: node.field_xb_test'],
      // Ensure 'link' is not in the error message.
      'link'
    );
  }

  /**
   * @return \Generator<int, array{string, string, string}>
   */
  public static function uninstallDataProvider(): \Generator {
    yield [Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID, 'components'];
    yield ['node', 'article', 'field_xb_test'];
  }

  /**
   * Test if the field is used in multiple entities of different types.
   */
  public function testUninstallXbFieldMultipleEntityTypes(): void {
    $installer = $this->container->get('module_installer');
    $installer->install(['experience_builder']);
    $installer->install(['xb_test_config_node_article', 'field', 'xb_test_sdc', 'taxonomy']);
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
      'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
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
    assert($storage instanceof RevisionableStorageInterface);
    $storage->deleteRevision(1);
    $this->updateFieldDefaultValue('taxonomy_term', 'tags', 'field_tag_test', $this->getComponentTreeItemValue(FALSE));
    $this->assertUninstallFailureReasons([
      'Provides a field type, <em class="placeholder">link</em>, that is in use in the default value of the following fields: <em class="placeholder">field_xb_test</em>',
    ]);

    $this->updateFieldDefaultValue('node', 'article', 'field_xb_test', $this->getComponentTreeItemValue(FALSE));
    // We should now be able to uninstall the 'link' module but because 'link'
    // is dependency for 'experience_builder' we should get an error because
    // of the XB fields.
    $this->assertUninstallFailureReasons(
      ['The <em class="placeholder">Experience Builder</em> field type is used in the following fields: node.field_xb_test, taxonomy_term.field_tag_test'],
      // Ensure 'link' is not in the error message.
      'link'
    );
  }

  private function updateFieldDefaultValue(string $entity_type, string $bundle, string $field_name, array $default_value): void {
    if ($entity_type === Page::ENTITY_TYPE_ID) {
      \Drupal::state()->set('xb_test_page.components_default_value', $default_value);
      \Drupal::entityTypeManager()->clearCachedDefinitions();
    }
    else {
      $field_config = FieldConfig::loadByName($entity_type, $bundle, $field_name);
      $this->assertInstanceOf(FieldConfig::class, $field_config);
      $field_config->setDefaultValue($default_value);
      $field_config->save();
    }
  }

  private function assertUninstallFailureReasons(array $reasons, string|null $not_contains = NULL): void {
    try {
      $this->container->get('module_installer')->uninstall(['link']);
      $this->fail('Expected an exception');
    }
    catch (ModuleUninstallValidatorException $exception) {
      if ($reasons) {
        $this->assertSame($reasons, array_unique($reasons));
        $this->assertSame(
          'The following reasons prevent the modules from being uninstalled: ' . implode(', ', $reasons),
          strtok($exception->getMessage(), ';'),
        );
      }
      if ($not_contains) {
        $this->assertStringNotContainsString($not_contains, $exception->getMessage());
      }

    }
  }

  private function getComponentTreeItemValue(bool $include_link): array {
    $component_tree_item = [
      [
        'uuid' => XBTestSetup::UUID_STATIC_CARD1,
        'component_id' => 'sdc.xb_test_sdc.my-cta',
        'component_version' => '6c057d67bf6d7f42',
        'inputs' => [
          'text' => [
            'sourceType' => 'static:field_item:string',
            'value' => 'hello, world!',
            'expression' => 'ℹ︎string␟value',
          ],
          'href' => [
            'sourceType' => 'static:field_item:uri',
            'value' => 'https://drupal.org',
            'expression' => 'ℹ︎uri␟value',
          ],
        ],
      ],
    ];
    if ($include_link) {
      $component_tree_item[0]['inputs']['href'] = [
        'sourceType' => 'static:field_item:link',
        'value' => [
          'uri' => 'https://drupal.org',
          'title' => NULL,
          'options' => [],
        ],
        'expression' => 'ℹ︎link␟uri',
      ];
    }
    return $component_tree_item;
  }

}
