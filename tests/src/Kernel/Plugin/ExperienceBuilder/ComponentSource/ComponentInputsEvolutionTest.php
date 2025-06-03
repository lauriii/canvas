<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Plugin\ExperienceBuilder\ComponentSource;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\experience_builder\Controller\ClientServerConversionTrait;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Entity\ComponentInterface;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemListInstantiatorTrait;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\experience_builder\Kernel\Traits\CiModulePathTrait;
use Drupal\Tests\experience_builder\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\experience_builder\Traits\CrawlerTrait;
use Drupal\Tests\experience_builder\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\experience_builder\Traits\SingleDirectoryComponentTreeTestTrait;

/**
 * Test explicit inputs can evolve as input schema & shape matching change.
 *
 * @group experience_builder
 * @todo Add test coverage for the former in https://www.drupal.org/i/3501708
 */
final class ComponentInputsEvolutionTest extends KernelTestBase {

  use ConstraintViolationsTestTrait;
  use ContribStrictConfigSchemaTestTrait;
  use SingleDirectoryComponentTreeTestTrait;
  use GenerateComponentConfigTrait;
  use CiModulePathTrait;
  use CrawlerTrait;
  use ComponentTreeItemListInstantiatorTrait;
  use ClientServerConversionTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'experience_builder',
    'media',
    'path',
    'file',
    'image',
    'link',
    'options',
    'system',
    'block',
    'datetime',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', 'users_data');
    $this->generateComponentConfig();
  }

  /**
   * @see hook_storage_prop_shape_alter()
   * @covers \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\SingleDirectoryComponent::updateConfigEntity()
   * @covers \Drupal\experience_builder\Entity\VersionedConfigEntityBase::generateVersionStringForData()
   */
  public function testStorablePropShapeChanges(): void {
    $component = Component::load('sdc.experience_builder.my-hero');
    \assert($component instanceof ComponentInterface);
    self::assertEquals([
      'heading' => 'string',
      'subheading' => 'string',
      'cta1' => 'string',
      'cta1href' => 'link',
      'cta2' => 'string',
    ], \array_map(static fn (array $field) => $field['field_type'], $component->getSettings()['prop_field_definitions']));

    $uuid = \Drupal::service(UuidInterface::class);

    // Create an item for the component in it's current form.
    $items = self::staticallyCreateDanglingComponentTreeItemList(\Drupal::typedDataManager());
    $first_uuid = $uuid->generate();
    $original_version = $component->getActiveVersion();
    self::assertSame([$original_version], $component->getVersions());
    $items->setValue([
      [
        'uuid' => $first_uuid,
        'component_id' => $component->id(),
        // Collapsed inputs with static defaults pinned to the active component
        // version.
        'inputs' => [
          'heading' => 'mirror my melody',
          'subheading' => '',
          'cta1' => '',
          'cta1href' => ['uri' => 'http://arachnophobia.com/', 'options' => []],
          'cta2' => '',
        ],
      ],
    ]);
    self::assertSame([], self::violationsToArray($items->validate()));

    // Creating a component of this type should set the `version` field property
    // and column to the active version.
    self::assertSame($original_version, $items->first()?->getComponentVersion());
    self::assertSame($original_version, $items->getValue()[0]['version']);

    // Converting to a client-side model should expand the plain inputs into
    // structured values.
    // @todo Simplify the client-side model in https://www.drupal.org/i/3528043
    $client_model = $items->getClientSideRepresentation();

    $expected_original_client_model = [
      'layout' => [
        [
          'uuid' => $first_uuid,
          'nodeType' => 'component',
          'type' => 'sdc.experience_builder.my-hero@' . $original_version,
          'slots' => [],
          'name' => NULL,
        ],
      ],
      'model' => [
        $first_uuid => [
          'source' => [
            'heading' => [
              'sourceType' => 'static:field_item:string',
              'expression' => 'ℹ︎string␟value',
            ],
            'cta1href' => [
              'sourceType' => 'static:field_item:link',
              'sourceTypeSettings' => [
                'instance' => [
                  'title' => \DRUPAL_DISABLED,
                ],
              ],
              'value' => [
                'uri' => 'http://arachnophobia.com/',
                'options' => [],
              ],
              'expression' => 'ℹ︎link␟uri',
            ],
          ],
          'resolved' => [
            'heading' => 'mirror my melody',
            'cta1href' => 'http://arachnophobia.com/',
          ],
        ],
      ],
    ];
    self::assertEquals($expected_original_client_model, $client_model);

    // Now enable the 'xb_test_storage_prop_shape_alter' module to change the
    // field type used for populating the cta1href prop.
    // @see \Drupal\xb_test_storage_prop_shape_alter\Hook\XbTestStoragePropShapeAlterHooks::storagePropShapeAlter()
    \Drupal::service(ModuleInstallerInterface::class)
      ->install(['xb_test_storage_prop_shape_alter']);
    $this->generateComponentConfig();
    $component = Component::load('sdc.experience_builder.my-hero');
    \assert($component instanceof ComponentInterface);
    $new_version = $component->getActiveVersion();
    self::assertNotEquals($original_version, $new_version);
    self::assertSame([$new_version, $original_version], $component->getVersions());
    self::assertEquals([
      'heading' => 'string',
      'subheading' => 'string',
      'cta1' => 'string',
      'cta1href' => 'uri',
      'cta2' => 'string',
    ], \array_map(static fn(array $field) => $field['field_type'], $component->getSettings()['prop_field_definitions']));

    $new_items = self::staticallyCreateDanglingComponentTreeItemList(\Drupal::typedDataManager());
    $second_uuid = $uuid->generate();
    $new_items->setValue([
      [
        'uuid' => $second_uuid,
        'component_id' => $component->id(),
        'inputs' => [
          'heading' => 'mirror my melody',
          'subheading' => '',
          'cta1' => '',
          'cta1href' => 'http://arachnophobia.com/',
          'cta2' => '',
        ],
      ],
    ]);
    self::assertSame([], self::violationsToArray($new_items->validate()));

    // Creating a component of this type should set the `version` field property
    // and column to the active version.
    self::assertSame($new_version, $new_items->first()?->getComponentVersion());
    self::assertSame($new_version, $new_items->getValue()[0]['version']);

    $new_client_model = $new_items->getClientSideRepresentation();

    self::assertEquals([
      'layout' => [
        [
          'uuid' => $second_uuid,
          'nodeType' => 'component',
          'type' => 'sdc.experience_builder.my-hero@' . $new_version,
          'slots' => [],
          'name' => NULL,
        ],
      ],
      'model' => [
        $second_uuid => [
          'source' => [
            'heading' => [
              'sourceType' => 'static:field_item:string',
              'expression' => 'ℹ︎string␟value',
            ],
            'cta1href' => [
              'sourceType' => 'static:field_item:uri',
              'expression' => 'ℹ︎uri␟value',
            ],
          ],
          'resolved' => [
            'heading' => 'mirror my melody',
            'cta1href' => 'http://arachnophobia.com/',
          ],
        ],
      ],
    ], $new_client_model);

    // Converting the old client model should still retain the reference to the
    // old version.
    $component_tree_item_list_values = self::convertClientToServer($client_model['layout'], $client_model['model']);
    \assert(\array_key_exists('version', $component_tree_item_list_values[0]));
    self::assertSame($original_version, $component_tree_item_list_values[0]['version']);
    // Create a new item list from this.
    $original_items = self::staticallyCreateDanglingComponentTreeItemList(\Drupal::typedDataManager());
    $original_items->setValue($component_tree_item_list_values);
    self::assertSame([], self::violationsToArray($original_items->validate()));
    // Should still equal the original model, even though the field type is now
    // different for the cta1href prop for new component instances: existing
    // component instances remain unchanged.
    // @todo Allow the content author to switch to the new field type in https://drupal.org/i/3463996
    self::assertEquals($expected_original_client_model, $original_items->getClientSideRepresentation());

    // If we uninstall the module, the Component should again point to the
    // original field type.
    \Drupal::service(ModuleInstallerInterface::class)->uninstall(['xb_test_storage_prop_shape_alter']);
    $this->generateComponentConfig();
    $component = Component::load('sdc.experience_builder.my-hero');
    \assert($component instanceof ComponentInterface);
    $newest_version = $component->getActiveVersion();
    self::assertEquals($original_version, $newest_version);
    self::assertSame([$original_version, $new_version, $original_version], $component->getVersions());
    self::assertEquals([
      'heading' => 'string',
      'subheading' => 'string',
      'cta1' => 'string',
      'cta1href' => 'link',
      'cta2' => 'string',
    ], \array_map(static fn (array $field) => $field['field_type'], $component->getSettings()['prop_field_definitions']));

    $newest_items = self::staticallyCreateDanglingComponentTreeItemList(\Drupal::typedDataManager());
    $newest_items->setValue([
      [
        'uuid' => $uuid->generate(),
        'component_id' => $component->id(),
        'inputs' => [
          'heading' => 'mirror my melody',
          'subheading' => '',
          'cta1' => '',
          'cta1href' => ['uri' => 'http://arachnophobia.com/', 'options' => []],
          'cta2' => '',
        ],
      ],
    ]);
    self::assertSame([], self::violationsToArray($newest_items->validate()));

    // Creating a component of this type should set the `version` field property
    // and column to the active version.
    self::assertSame($original_version, $newest_items->first()?->getComponentVersion());
    self::assertSame($original_version, $newest_items->getValue()[0]['version']);
  }

}
