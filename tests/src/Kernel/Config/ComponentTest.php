<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Config;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Entity\VersionedConfigEntityInterface;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ConfigInstallerInterface;
use Drupal\Core\Config\StorageCacheInterface;
use Drupal\Core\Entity\EntityListBuilderInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Component.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('canvas_component_sources')]
class ComponentTest extends CanvasKernelTestBase {

  use GenerateComponentConfigTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->generateComponentConfig();
  }

  protected function midTestSetUp(): void {
    // The Standard install profile's "image" media type must be installed when
    // the media_library module gets installed.
    // @see core/profiles/standard/config/optional/media.type.image.yml
    $this->enableModules(['field', 'file', 'image', 'media']);
    $this->generateComponentConfig();
    $this->setInstallProfile('standard');
    $this->container->get(ConfigInstallerInterface::class)->installOptionalConfig();

    $modules = [
      'media_library',
      'views',
      'user',
      'filter',
    ];
    $this->enableModules($modules);
    $this->generateComponentConfig();
    // @see \Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem::generateSampleValue()
    $this->installEntitySchema('media');

    // @see \Drupal\media_library\Plugin\Field\FieldWidget\MediaLibraryWidget
    $this->installEntitySchema('user');

    // @see core/profiles/standard/config/optional/media.type.image.yml
    $this->installConfig(['media']);

    // A sample value is generated during the test, which needs this table.
    $this->installSchema('file', ['file_usage']);

    // @see \Drupal\media_library\MediaLibraryEditorOpener::__construct()
    $this->installEntitySchema('filter_format');
  }

  /**
   * @see \Drupal\canvas\Hook\ShapeMatchingHooks::mediaLibraryStorablePropShapeAlter()
   * @see \Drupal\Tests\canvas\Kernel\MediaLibraryHookStoragePropAlterTest
   */
  public function testComponentAutoUpdate(): void {
    $initial_components = Component::loadMultiple();
    $this->assertNotEmpty($initial_components);

    // Originally:
    // - uses `image` field type
    // - one version
    // - depends on `image` module
    $this->assertArrayHasKey('sdc.canvas_test_sdc.image', $initial_components);
    $initial_component = $initial_components['sdc.canvas_test_sdc.image'];
    $this->assertSame('image', $initial_component->getSettings()['prop_field_definitions']['image']['field_type']);
    $initial_expected_version = 'f4d1c916802ab8db';
    self::assertSame($initial_expected_version, $initial_component->getActiveVersion());
    self::assertSame([$initial_expected_version], $initial_component->getVersions());
    self::assertSame([
      'config' => [
        'image.style.canvas_parametrized_width',
      ],
      'module' => ['canvas_test_sdc', 'file', 'image'],
    ], $initial_component->getDependencies());
    self::assertSame([
      'config' => [
        'image.style.canvas_parametrized_width',
      ],
      'module' => ['canvas_test_sdc', 'file', 'image'],
    ], $initial_component->calculateDependencies()->getDependencies());
    self::assertSame([
      'config' => [
        'image.style.canvas_parametrized_width',
      ],
      'module' => ['canvas_test_sdc', 'file', 'image'],
    ], $initial_component->getVersionSpecificDependencies(VersionedConfigEntityInterface::ACTIVE_VERSION));

    // Then:
    // - uses `entity_reference` field type
    // - two versions
    // - depends on both the 'image' and `media_library` module, because there
    //   are now two versions.
    $this->midTestSetUp();
    $updated_component = Component::load('sdc.canvas_test_sdc.image');
    \assert($updated_component instanceof Component);
    $this->assertSame('entity_reference', $updated_component->getSettings()['prop_field_definitions']['image']['field_type']);
    $updated_expected_version = 'fb40be57bd7e0973';
    self::assertSame($updated_expected_version, $updated_component->getActiveVersion());
    self::assertSame([$updated_expected_version, 'f4d1c916802ab8db'], $updated_component->getVersions());
    self::assertSame([
      'config' => [
        'field.field.media.image.field_media_image',
        'image.style.canvas_parametrized_width',
        'media.type.image',
      ],
      'module' => [
        'canvas_test_sdc',
        'file',
        'image',
        'media',
        'media_library',
      ],
    ], $updated_component->getDependencies());
    self::assertSame([
      'config' => [
        'image.style.canvas_parametrized_width',
      ],
      'module' => ['canvas_test_sdc', 'file', 'image'],
    ], $updated_component->getVersionSpecificDependencies($initial_expected_version));
    self::assertSame([
      'config' => [
        'field.field.media.image.field_media_image',
        'image.style.canvas_parametrized_width',
        'media.type.image',
      ],
      'module' => [
        'canvas_test_sdc',
        'file',
        'media',
        'media_library',
      ],
    ], $updated_component->getVersionSpecificDependencies(VersionedConfigEntityInterface::ACTIVE_VERSION));

    // Now specifically load the old version, and check that calling
    // ::calculateDependencies() again causes ::getDependencies() to return only
    // the dependencies of THAT version. ⚠️
    self::assertTrue($updated_component->isLoadedVersionActiveVersion());
    $updated_component->loadVersion('f4d1c916802ab8db');
    self::assertFalse($updated_component->isLoadedVersionActiveVersion());
    $this->assertSame('image', $updated_component->getSettings()['prop_field_definitions']['image']['field_type']);
    self::assertSame([
      'config' => [
        'field.field.media.image.field_media_image',
        'image.style.canvas_parametrized_width',
        'media.type.image',
      ],
      'module' => [
        'canvas_test_sdc',
        'file',
        'image',
        'media',
        'media_library',
      ],
    ], $updated_component->getDependencies());
    self::assertSame([
      'config' => [
        'field.field.media.image.field_media_image',
        'image.style.canvas_parametrized_width',
        'media.type.image',
      ],
      'module' => [
        'canvas_test_sdc',
        'file',
        'image',
        'media',
        'media_library',
      ],
    ], $updated_component->calculateDependencies()->getDependencies());
    $updated_component->loadVersion($updated_expected_version);
    self::assertTrue($updated_component->isLoadedVersionActiveVersion());

    // Finally, because no component instances exist that use the old version,
    // the old version can be deleted, and then:
    // - uses `entity_reference`
    // - one version
    // - depends on the `media_library` module
    $updated_component->deleteVersion($initial_expected_version)->save();
    $component_without_obsolete_versions = Component::load('sdc.canvas_test_sdc.image');
    \assert($component_without_obsolete_versions instanceof Component);
    $this->assertSame('entity_reference', $updated_component->getSettings()['prop_field_definitions']['image']['field_type']);
    self::assertSame($updated_expected_version, $updated_component->getActiveVersion());
    self::assertSame([$updated_expected_version], $updated_component->getVersions());
    self::assertSame([
      'config' => [
        'field.field.media.image.field_media_image',
        'image.style.canvas_parametrized_width',
        'media.type.image',
      ],
      'module' => ['canvas_test_sdc', 'file', 'media', 'media_library'],
    ], $updated_component->getDependencies());
  }

  /**
   * Tests importing a Component whose active version changed.
   *
   * This is the "edit a component in one environment, deploy to another"
   * workflow: the sync storage contains a newer active version than the target
   * environment's active configuration. Core copies each exported property onto
   * the already loaded entity, so `active_version` changes underneath the
   * loaded version.
   *
   * @see \Drupal\canvas\Entity\VersionedConfigEntityBase::set()
   * @see \Drupal\Core\Config\Entity\ConfigEntityStorage::updateFromStorageRecord()
   */
  public function testConfigImportOfNewActiveVersion(): void {
    [$component_id, $original_version, $new_version] = $this->stageNewActiveVersionForImport();

    // The import must succeed: this is an `update` for a versioned config
    // entity whose active version differs from the stored one.
    $importer = $this->configImporter();
    $importer->import();
    // Core catches exceptions thrown while importing a single config object,
    // logs them as an import error and continues, leaving that object at its
    // stored value. So the absence of errors is what proves the import worked.
    // @see \Drupal\Core\Config\ConfigImporter::importInvokeOwner()
    self::assertSame([], \array_map(strval(...), $importer->getErrors()));

    $imported = Component::load($component_id);
    \assert($imported instanceof Component);
    self::assertSame($new_version, $imported->getActiveVersion());
    self::assertTrue($imported->isLoadedVersionActiveVersion());
    // The previously active version must survive the import, so that existing
    // component instances still pointing at it keep resolving.
    self::assertSame([$new_version, $original_version], $imported->getVersions());
    self::assertSame(['title', 'noodles'], \array_keys($imported->getSettings()['prop_field_definitions']));
    // The version that was active before the import is unchanged.
    self::assertSame(['title'], \array_keys($imported->getSettings($original_version)['prop_field_definitions']));
    // No config drift: the active configuration now matches sync exactly.
    self::assertFalse($this->configImporter()->getStorageComparer()->hasChanges());
  }

  /**
   * Tests the active version invariant still holds while syncing.
   *
   * The loaded version is only realigned when the imported record assigns a new
   * active version. Saving a deliberately loaded historical version must keep
   * failing, also while syncing: it would write that version's data over the
   * active one.
   *
   * @see \Drupal\canvas\Entity\VersionedConfigEntityBase::preSave()
   */
  public function testSavingWhileNonActiveVersionIsLoadedIsRejected(): void {
    [$component_id, $original_version] = $this->stageNewActiveVersionForImport();
    $this->configImporter()->import();

    $component = Component::load($component_id);
    \assert($component instanceof Component);
    // While syncing, targeting a historical version is allowed …
    $component->setSyncing(TRUE)->loadVersion($original_version);
    self::assertFalse($component->isLoadedVersionActiveVersion());

    // … but saving in that state is not.
    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage("'component' entity is a versioned config entity, and its loaded version is not the active version.");
    $component->save();
  }

  /**
   * Stages sync storage containing a newer active version of a Component.
   *
   * Mirrors a real deployment: a code component gains a prop in one
   * environment (which generates a new version of its Component config entity),
   * the resulting configuration is exported, and the target environment is
   * still at the previous version.
   *
   * @return array{string, string, string}
   *   The Component config entity ID, its active version in the active
   *   configuration, and its (newer) active version in sync storage.
   */
  private function stageNewActiveVersionForImport(): array {
    // Create an enabled code component: this auto-generates a corresponding
    // Component config entity with a single version.
    // @see \Drupal\canvas\EntityHandlers\JavascriptComponentStorage::doPostSave()
    $js_component_id = $this->randomMachineName();
    $props = [
      'title' => [
        'type' => 'string',
        'title' => 'Title',
        'examples' => ['Title'],
      ],
    ];
    $js_component = JavaScriptComponent::create([
      'machineName' => $js_component_id,
      'name' => $this->getRandomGenerator()->sentences(3),
      'status' => TRUE,
      'props' => $props,
      'required' => [],
      'slots' => [],
      'js' => [
        'original' => 'console.log("hey");',
        'compiled' => 'console.log("hey");',
      ],
      'css' => [
        'original' => '',
        'compiled' => '',
      ],
      'dataDependencies' => [],
    ]);
    $js_component->save();

    $component_id = JsComponent::componentIdFromJavascriptComponentId($js_component_id);
    $component = Component::load($component_id);
    \assert($component instanceof Component);
    $config_name = $component->getConfigDependencyName();
    $original_version = $component->getActiveVersion();
    self::assertSame([$original_version], $component->getVersions());
    // Capture the exported configuration prior to the version bump: this is
    // what the target environment's active configuration contains.
    $original_config_data = $this->config($config_name)->getRawData();

    // Adding a prop bumps the auto-generated Component's active version.
    $props['noodles'] = [
      'type' => 'string',
      'title' => 'What sort of noodles do you like?',
      'examples' => ['Soba'],
    ];
    $js_component->setProps($props)->save();
    $component = Component::load($component_id);
    \assert($component instanceof Component);
    $new_version = $component->getActiveVersion();
    self::assertNotSame($original_version, $new_version);
    self::assertSame([$new_version, $original_version], $component->getVersions());

    // Export that state: sync storage is now what would be deployed.
    $this->copyConfig(
      $this->container->get(StorageCacheInterface::class),
      $this->container->get('config.storage.sync'),
    );

    // Rewind the active configuration to the state prior to the version bump.
    // This deliberately bypasses the entity API, because version history cannot
    // travel backwards through it.
    $this->container->get(ConfigFactoryInterface::class)
      ->getEditable($config_name)
      ->setData($original_config_data)
      ->save(TRUE);
    $this->container->get(EntityTypeManagerInterface::class)
      ->getStorage(Component::ENTITY_TYPE_ID)
      ->resetCache([$component_id]);
    $component = Component::load($component_id);
    \assert($component instanceof Component);
    self::assertSame([$original_version], $component->getVersions());

    return [$component_id, $original_version, $new_version];
  }

  public function testOperations(): void {
    $list_builder = $this->container->get(EntityTypeManagerInterface::class)->getListBuilder(Component::ENTITY_TYPE_ID);
    \assert($list_builder instanceof EntityListBuilderInterface);
    $component = Component::load('sdc.canvas_test_sdc.image');
    \assert($component instanceof ComponentInterface);
    $operations = $list_builder->getOperations($component);
    self::assertArrayHasKey('disable', $operations);
    self::assertArrayNotHasKey('enable', $operations);
    self::assertArrayNotHasKey('delete', $operations);

    $component->disable()->save();
    $operations = $list_builder->getOperations($component);
    self::assertArrayNotHasKey('disable', $operations);
    self::assertArrayHasKey('enable', $operations);
    self::assertArrayNotHasKey('delete', $operations);
  }

  /**
   * The empty-slot marker Component can never become enabled (placeable).
   *
   * @see \Drupal\canvas\Entity\Component::preSave()
   */
  public function testEmptySlotMarkerCannotBeEnabled(): void {
    $marker = Component::load(ComponentInterface::EMPTY_SLOT_MARKER_ID);
    \assert($marker instanceof ComponentInterface);
    self::assertFalse($marker->status());

    // Even an explicit enable — for example, a recipe config action enabling
    // every `canvas.component.*.*` — must not make the marker placeable.
    $marker->enable()->save();
    self::assertFalse($marker->status());

    $reloaded = Component::load(ComponentInterface::EMPTY_SLOT_MARKER_ID);
    \assert($reloaded instanceof ComponentInterface);
    self::assertFalse($reloaded->status());
  }

}
