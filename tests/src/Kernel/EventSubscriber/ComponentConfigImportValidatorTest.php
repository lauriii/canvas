<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\EventSubscriber;

use Drupal\canvas\Audit\ComponentAudit;
use Drupal\canvas\Audit\RevisionAuditEnum;
use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent;
use Drupal\Core\Config\ConfigImporter;
use Drupal\Core\Config\ConfigImporterException;
use Drupal\Core\Config\StorageCacheInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that config imports cannot delete in-use Canvas components.
 *
 * @see \Drupal\canvas\EventSubscriber\ComponentConfigImportValidator
 */
#[Group('canvas')]
#[RunTestsInSeparateProcesses]
final class ComponentConfigImportValidatorTest extends CanvasKernelTestBase {

  use UserCreationTrait;

  /**
   * A distinctive fragment of the block message logged by the subscriber.
   */
  private const string BLOCK_MESSAGE_FRAGMENT = 'would delete the in-use Canvas component';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...self::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $this->setUpCurrentUser();
  }

  /**
   * Deleting an in-use Component config entity is blocked (published usage).
   *
   * The sync is otherwise internally consistent (the Folder no longer
   * references the component), so core's own config-dependency validation does
   * not fire: the subscriber is the sole reason the import is blocked.
   */
  public function testBlockedWhenComponentDeletedWithPublishedUsage(): void {
    [$js_component_id, $component] = $this->createEnabledJsComponent();
    $this->createPageUsing($component);

    $importer = $this->stageDeletions([
      'canvas.js_component.' . $js_component_id,
      $component->getConfigDependencyName(),
    ]);

    self::assertImportBlocked($importer);
    // The import aborted before writing: the config entities still exist.
    self::assertInstanceOf(Component::class, Component::load($component->id()));
  }

  /**
   * Deleting only the backing js_component (Component retained) is blocked.
   *
   * Vector B: the Component survives the import but would point at now-missing
   * configuration.
   */
  public function testBlockedWhenBackingConfigDeletedWithPublishedUsage(): void {
    [$js_component_id, $component] = $this->createEnabledJsComponent();
    $this->createPageUsing($component);

    // Delete only the js_component; keep the Component config entity.
    $importer = $this->stageDeletions([
      'canvas.js_component.' . $js_component_id,
    ]);

    self::assertImportBlocked($importer);
    self::assertInstanceOf(JavaScriptComponent::class, JavaScriptComponent::load($js_component_id));
  }

  /**
   * Deleting a Component used only by an auto-save draft is blocked.
   */
  public function testBlockedWhenOnlyAutoSaveUsage(): void {
    [$js_component_id, $component] = $this->createEnabledJsComponent();

    // The default revision has no usage; only an auto-save draft references
    // the component.
    $page = Page::create([
      'title' => $this->randomMachineName(),
      'components' => [],
    ]);
    $page->save();
    $page->set('components', $this->treeUsing($component));
    $this->container->get(AutoSaveManager::class)->saveEntity($page);

    $audit = $this->container->get(ComponentAudit::class);
    self::assertFalse($audit->hasUsages($component, RevisionAuditEnum::All));
    self::assertTrue($audit->hasUsages($component, RevisionAuditEnum::AutoSave));

    $importer = $this->stageDeletions([
      'canvas.js_component.' . $js_component_id,
      $component->getConfigDependencyName(),
    ]);

    self::assertImportBlocked($importer);
  }

  /**
   * Deleting an unused Component via config import is allowed (no over-block).
   */
  public function testAllowedWhenNoUsage(): void {
    [$js_component_id, $component] = $this->createEnabledJsComponent();
    $component_id = $component->id();

    $importer = $this->stageDeletions([
      'canvas.js_component.' . $js_component_id,
      $component->getConfigDependencyName(),
    ]);

    // No usages exist, so the import must succeed and remove the config.
    $importer->import();
    self::assertNull(Component::load($component_id));
    self::assertNull(JavaScriptComponent::load($js_component_id));
  }

  /**
   * Recreating an in-use Component with the same versions is allowed.
   */
  #[DataProvider('providerRecreateBackingConfig')]
  public function testAllowedWhenInUseComponentRecreatedWithSameVersions(bool $recreate_backing_config): void {
    [$js_component_id, $component] = $this->createEnabledJsComponent();
    $this->createPageUsing($component);
    $new_uuid = $this->container->get('uuid')->generate();

    $this->stageRecreate($component, static fn (array $data): array => ['uuid' => $new_uuid] + $data);
    if ($recreate_backing_config) {
      $sync = $this->container->get('config.storage.sync');
      $backing_config_name = 'canvas.js_component.' . $js_component_id;
      $data = $sync->read($backing_config_name);
      self::assertIsArray($data);
      $data['uuid'] = $this->container->get('uuid')->generate();
      $sync->write($backing_config_name, $data);
    }
    $this->configImporter()->import();

    $recreated = Component::load($component->id());
    self::assertInstanceOf(Component::class, $recreated);
    self::assertSame($new_uuid, $recreated->uuid());
    self::assertSame($component->getVersions(), $recreated->getVersions());
  }

  /**
   * Whether the backing code component also receives a different UUID.
   *
   * @return array<string, array{bool}>
   *   The backing configuration recreation cases.
   */
  public static function providerRecreateBackingConfig(): array {
    return [
      'without backing config recreation' => [FALSE],
      'with backing config recreation' => [TRUE],
    ];
  }

  /**
   * Losing active or historical versions when recreating a Component is blocked.
   */
  #[DataProvider('providerMissingComponentVersion')]
  public function testBlockedWhenRecreatedComponentLosesVersions(bool $remove_active_version): void {
    [$js_component_id, $component] = $this->createEnabledJsComponent();
    $historical_version = $component->getActiveVersion();
    $this->createPageUsing($component);

    // Adding a slot creates a new version while retaining the original one.
    $js_component = JavaScriptComponent::load($js_component_id);
    self::assertInstanceOf(JavaScriptComponent::class, $js_component);
    $js_component->set('slots', ['content' => ['title' => 'Content']])->save();
    $component = Component::load($component->id());
    self::assertInstanceOf(Component::class, $component);
    self::assertCount(2, $component->getVersions());
    $this->createPageUsing($component);
    $new_uuid = $this->container->get('uuid')->generate();

    $importer = $this->stageRecreate($component, static function (array $data) use ($new_uuid, $historical_version, $remove_active_version): array {
      $data['uuid'] = $new_uuid;
      if ($remove_active_version) {
        $data['active_version'] = '0000aaaa1111bbbb';
      }
      else {
        unset($data['versioned_properties'][$historical_version]);
      }
      return $data;
    });

    self::assertImportBlocked($importer);
    self::assertSame($component->uuid(), Component::load($component->id())?->uuid());
  }

  /**
   * Provides missing active and historical component versions.
   *
   * @return array<string, array{bool}>
   *   Whether to remove the active version instead of the historical version.
   */
  public static function providerMissingComponentVersion(): array {
    return [
      'active version missing' => [TRUE],
      'historical version missing' => [FALSE],
    ];
  }

  /**
   * Creates and enables a code component with no props or slots.
   *
   * @return array{string, \Drupal\canvas\Entity\Component}
   *   The js_component machine name and its generated Component config entity.
   */
  private function createEnabledJsComponent(): array {
    $js_component_id = $this->randomMachineName();
    JavaScriptComponent::create([
      'machineName' => $js_component_id,
      'name' => $this->getRandomGenerator()->sentences(3),
      'status' => FALSE,
      'props' => [],
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
    ])->enable()->save();
    // Saving the js_component auto-generates its tracking Component config
    // entity via JavascriptComponentStorage.
    $component = Component::load(JsComponent::componentIdFromJavascriptComponentId($js_component_id));
    \assert($component instanceof Component);
    return [$js_component_id, $component];
  }

  /**
   * Builds a single-item component tree referencing the given component.
   *
   * @return array<int, array<string, mixed>>
   *   A component tree value for a Canvas Page `components` field.
   */
  private function treeUsing(Component $component): array {
    return [
      [
        'uuid' => $this->container->get('uuid')->generate(),
        'component_id' => $component->id(),
        'inputs' => [],
      ],
    ];
  }

  /**
   * Creates and saves a Page that uses the given component.
   */
  private function createPageUsing(Component $component): void {
    $page = Page::create([
      'title' => $this->randomMachineName(),
      'components' => $this->treeUsing($component),
    ]);
    $page->save();
  }

  /**
   * Stages sync storage that deletes the given config names on import.
   *
   * Deleting a Component config entity also removes it from any Folder that
   * contained it, mirroring a real config export so that the only config-level
   * change is the deletion itself (and core's dependency validation stays
   * quiet).
   */
  private function stageDeletions(array $config_names): ConfigImporter {
    $sync = $this->container->get('config.storage.sync');
    $this->copyConfig($this->container->get(StorageCacheInterface::class), $sync);
    foreach ($config_names as $name) {
      $sync->delete($name);
    }
    $deleted_component_names = \array_values(\array_filter(
      $config_names,
      static fn (string $name): bool => \str_starts_with($name, 'canvas.component.'),
    ));
    if ($deleted_component_names !== []) {
      self::removeComponentsFromFolders($sync, $deleted_component_names);
    }
    return $this->configImporter();
  }

  /**
   * Stages sync storage that recreates the given Component on import.
   *
   * @param \Drupal\canvas\Entity\Component $component
   *   The Component to recreate.
   * @param callable(array): array $alter
   *   Alters the Component's exported data, and must change its UUID.
   */
  private function stageRecreate(Component $component, callable $alter): ConfigImporter {
    $sync = $this->container->get('config.storage.sync');
    $this->copyConfig($this->container->get(StorageCacheInterface::class), $sync);
    $name = $component->getConfigDependencyName();
    $data = $sync->read($name);
    \assert(\is_array($data));
    $sync->write($name, $alter($data));
    return $this->configImporter();
  }

  /**
   * Removes deleted Component references from Folder config in sync storage.
   */
  private static function removeComponentsFromFolders(StorageInterface $sync, array $deleted_component_names): void {
    $deleted_item_ids = \array_map(
      static fn (string $name): string => \substr($name, \strlen('canvas.component.')),
      $deleted_component_names,
    );
    foreach ($sync->listAll('canvas.folder.') as $folder_name) {
      $data = $sync->read($folder_name);
      if (!\is_array($data)) {
        continue;
      }
      $original = $data;
      if (($data['configEntityTypeId'] ?? NULL) === Component::ENTITY_TYPE_ID && isset($data['items'])) {
        $data['items'] = \array_values(\array_diff($data['items'], $deleted_item_ids));
      }
      if (isset($data['dependencies']['config'])) {
        $data['dependencies']['config'] = \array_values(\array_diff($data['dependencies']['config'], $deleted_component_names));
      }
      if ($data !== $original) {
        $sync->write($folder_name, $data);
      }
    }
  }

  /**
   * Asserts the import is blocked by the subscriber (not merely by core).
   */
  private static function assertImportBlocked(ConfigImporter $importer): void {
    try {
      $importer->import();
      self::fail('The config import should have been blocked.');
    }
    catch (ConfigImporterException) {
      $errors = \implode("\n", \array_map(strval(...), $importer->getErrors()));
      self::assertStringContainsString(self::BLOCK_MESSAGE_FRAGMENT, $errors);
    }
  }

}
