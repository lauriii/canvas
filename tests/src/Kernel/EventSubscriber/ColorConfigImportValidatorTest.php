<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\EventSubscriber;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Color;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\Pattern;
use Drupal\Core\Config\ConfigImporter;
use Drupal\Core\Config\ConfigImporterException;
use Drupal\Core\Config\StorageCacheInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that config imports cannot delete an in-use Brand Kit color.
 *
 * @see \Drupal\canvas\EventSubscriber\ColorConfigImportValidator
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class ColorConfigImportValidatorTest extends CanvasKernelTestBase {

  use UserCreationTrait;

  /**
   * A distinctive fragment of the block message logged by the subscriber.
   */
  private const string BLOCK_MESSAGE_FRAGMENT = 'would delete the in-use Brand Kit color';

  private const string COMPONENT_ID = 'sdc.canvas_test_sdc.color-valid';

  private Color $color;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $this->container->get(ComponentSourceManager::class)->generateComponents();
    $this->setUpCurrentUser();

    $this->color = Color::create([
      'name' => 'Import Blue',
      'cssVariable' => '--color-import-blue',
      'value' => [
        'colorSpace' => 'srgb',
        'components' => [0.0, 0.0, 1.0],
        'hex' => '#0000ff',
      ],
      'weight' => 0,
    ]);
    $this->color->save();
  }

  /**
   * Deleting a color used by a content entity's default revision is blocked.
   */
  public function testBlockedWhenUsedInDefaultRevision(): void {
    $page = Page::create([
      'title' => $this->randomMachineName(),
      'components' => $this->treeUsingColor(),
    ]);
    self::assertEntityIsValid($page);
    $page->save();

    $importer = $this->stageColorDeletion();

    self::assertImportBlocked($importer);
    // The import aborted before writing: the color still exists.
    self::assertInstanceOf(Color::class, Color::load($this->color->id()));
  }

  /**
   * Deleting a color used by only a prior revision is blocked.
   *
   * The delete access gate allows this — a prior revision is never rendered.
   * Configuration import is different: it is the one path that can strand a
   * reference the gate would have inlined, and prior revisions can be restored.
   */
  public function testBlockedWhenUsedInPriorRevisionOnly(): void {
    $page = Page::create([
      'title' => $this->randomMachineName(),
      'components' => $this->treeUsingColor(),
    ]);
    self::assertEntityIsValid($page);
    $page->save();
    $page->setNewRevision(TRUE);
    $page->set('components', [])->save();

    // The color is now allowed to be deleted through the UI…
    $brand_kit_maintainer = $this->createUser([Color::ADMIN_PERMISSION]);
    \assert($brand_kit_maintainer instanceof UserInterface);
    self::assertTrue($this->color->access('delete', $brand_kit_maintainer));

    // …but not through a configuration import.
    self::assertImportBlocked($this->stageColorDeletion());
  }

  /**
   * Deleting a color used by only an auto-save draft is blocked.
   */
  public function testBlockedWhenOnlyAutoSaveUsage(): void {
    $page = Page::create([
      'title' => $this->randomMachineName(),
      'components' => [],
    ]);
    self::assertEntityIsValid($page);
    $page->save();
    $page->setComponentTree($this->treeUsingColor());
    $this->container->get(AutoSaveManager::class)->saveEntity($page);

    self::assertImportBlocked($this->stageColorDeletion());
  }

  /**
   * Deleting an unused color via config import is allowed (no over-block).
   */
  public function testAllowedWhenNoUsage(): void {
    $color_id = $this->color->id();

    $this->stageColorDeletion()->import();

    self::assertNull(Color::load($color_id));
  }

  /**
   * Deleting a color whose only usage is config the same import fixes is fine.
   *
   * Config usages are answered from the *current* dependency graph, which the
   * import is about to replace. Blocking on them would reject an export that
   * correctly inlined the color everywhere.
   */
  public function testAllowedWhenConfigUsageIsResolvedByTheSameImport(): void {
    $instance_uuid = $this->container->get('uuid')->generate();
    $pattern = Pattern::create([
      'id' => 'color_import_pattern',
      'label' => 'Color Import Pattern',
      'component_tree' => $this->treeUsingColor($instance_uuid, '#0000ff'),
    ]);
    $pattern->save();

    // Sync mirrors an export taken after the color was inlined everywhere.
    $sync = $this->container->get('config.storage.sync');
    $this->copyConfig($this->container->get(StorageCacheInterface::class), $sync);
    $sync->delete($this->color->getConfigDependencyName());

    // The active site still references the color, so the import both deletes
    // the color and rewrites the only config entity depending on it.
    $pattern->setComponentTree($this->treeUsingColor($instance_uuid))->save();
    self::assertContains($this->color->getConfigDependencyName(), $pattern->getDependencies()['config']);

    $color_id = $this->color->id();
    $this->configImporter()->import();

    self::assertNull(Color::load($color_id));
    $reloaded = Pattern::load('color_import_pattern');
    self::assertInstanceOf(Pattern::class, $reloaded);
    $inputs = $reloaded->getComponentTree()->getComponentTreeItemByUuid($instance_uuid)?->getInputs();
    self::assertIsArray($inputs);
    self::assertSame('#0000ff', $inputs['background_color']);
  }

  /**
   * Builds a single-item component tree with a color prop.
   *
   * @param string|null $instance_uuid
   *   The component instance UUID, or NULL to generate one.
   * @param string|null $background_color
   *   A literal CSS value, or NULL to reference the test color.
   *
   * @return array<int, array{uuid: string, component_id: string, component_version: string, inputs: array<string, string>}>
   *   A component tree value.
   */
  private function treeUsingColor(?string $instance_uuid = NULL, ?string $background_color = NULL): array {
    $component = Component::load(self::COMPONENT_ID);
    \assert($component instanceof Component);
    return [
      [
        'uuid' => $instance_uuid ?? $this->container->get('uuid')->generate(),
        'component_id' => $component->id(),
        'component_version' => $component->getActiveVersion(),
        'inputs' => [
          'heading' => 'Color Test Heading',
          'background_color' => $background_color ?? Color::REFERENCE_PREFIX . $this->color->id(),
        ],
      ],
    ];
  }

  /**
   * Stages sync storage that deletes the test color on import.
   */
  private function stageColorDeletion(): ConfigImporter {
    $sync = $this->container->get('config.storage.sync');
    $this->copyConfig($this->container->get(StorageCacheInterface::class), $sync);
    $sync->delete($this->color->getConfigDependencyName());
    return $this->configImporter();
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
