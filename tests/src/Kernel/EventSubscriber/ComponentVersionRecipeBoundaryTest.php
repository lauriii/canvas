<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\EventSubscriber;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\canvas\Plugin\Canvas\ComponentSource\SingleDirectoryComponent;
use Drupal\canvas\PropShape\PropShapeRepositoryInterface;
use Drupal\Core\Cache\CacheCollectorInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Recipe\Recipe;
use Drupal\Core\Recipe\RecipeRunner;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Component version hashes track prop shape changes made by recipes.
 *
 * Version hashes are computed from storable prop shapes. A recipe routinely
 * changes config those shapes depend on: creating the first image MediaType
 * makes every image prop storable as a Media entity reference instead of a
 * plain image field. Such a change only queues the affected prop shapes for
 * re-resolution, and that queue used to be drained at teardown — after the
 * generation pass that the recipe itself triggers.
 *
 * `drush site:install` applies every recipe in a single process, so teardown
 * happens once, at the very end, on a cache that was cold throughout. Both
 * chances to notice the change were therefore missed, and the resulting site
 * kept Component version hashes that disagreed with the prop shapes cached
 * next to them, until an unrelated event regenerated Components. Recipes
 * shipping component trees that reference the correct hash then failed to
 * render on a freshly installed site.
 *
 * @legacy-covers \Drupal\canvas\EventSubscriber\RecipeSubscriber::ensureComponentsExist
 * @legacy-covers \Drupal\canvas\PropShape\PersistentPropShapeRepository::resolveInvalidatedPropShapes
 * @legacy-covers \Drupal\canvas\PropShape\PersistentPropShapeRepository::updateCache
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('#slow')]
final class ComponentVersionRecipeBoundaryTest extends CanvasKernelTestBase {

  /**
   * Components with an image prop, mapped to the name of that prop.
   *
   * Both a single-value image prop and an array of them, because a `type:
   * array` prop shape resolves through its item prop shape and so must react
   * to the same change.
   *
   * @see tests/modules/canvas_test_sdc/components/image/image.component.yml
   * @see tests/modules/canvas_test_sdc/components/image-gallery/image-gallery.component.yml
   */
  private const IMAGE_PROP_COMPONENTS = [
    SingleDirectoryComponent::SOURCE_PLUGIN_ID . '.canvas_test_sdc.image' => 'image',
    SingleDirectoryComponent::SOURCE_PLUGIN_ID . '.canvas_test_sdc.image-gallery' => 'images',
  ];

  /**
   * Applies one of this test's fixture recipes.
   */
  private static function applyRecipe(string $name): void {
    $recipe = Recipe::createFromDirectory(__DIR__ . '/../../../fixtures/recipes/' . $name);
    RecipeRunner::processRecipe($recipe);
  }

  private function loadComponent(string $component_id): ComponentInterface {
    // loadUnchanged() bypasses the entity static cache, so assertions read the
    // state generation persisted rather than a stale in-memory copy.
    $component = $this->container->get(EntityTypeManagerInterface::class)
      ->getStorage(Component::ENTITY_TYPE_ID)
      ->loadUnchanged($component_id);
    \assert($component instanceof ComponentInterface);
    return $component;
  }

  /**
   * The active and all known versions of every image-prop Component.
   *
   * @return array<string, array{active: string, all: string[]}>
   */
  private function versionSnapshot(): array {
    $snapshot = [];
    foreach (\array_keys(self::IMAGE_PROP_COMPONENTS) as $component_id) {
      $component = $this->loadComponent($component_id);
      $snapshot[$component_id] = [
        'active' => $component->getActiveVersion(),
        'all' => $component->getVersions(),
      ];
    }
    return $snapshot;
  }

  /**
   * A recipe changing prop shapes mints new versions at its own boundary.
   *
   * No cache rebuild and no teardown in between: the generation pass that the
   * media recipe itself triggers must already compute hashes from the prop
   * shapes that recipe leaves behind.
   */
  public function testRecipeBoundaryMintsNewVersions(): void {
    self::applyRecipe('component_version_sdc');

    $before = $this->versionSnapshot();
    foreach ($before as $component_id => $versions) {
      self::assertCount(1, $versions['all'], $component_id);
    }

    self::applyRecipe('component_version_image_media_type');

    $after = $this->versionSnapshot();
    foreach (self::IMAGE_PROP_COMPONENTS as $component_id => $prop_name) {
      $born_with = $before[$component_id]['active'];
      self::assertNotSame($born_with, $after[$component_id]['active'], $component_id);
      self::assertCount(2, $after[$component_id]['all'], $component_id);
      // The hash the Component was born with stays in its version history, so
      // component trees created before this recipe keep resolving.
      self::assertContains($born_with, $after[$component_id]['all'], $component_id);

      // The new active version stores the prop as a Media entity reference,
      // which is what made the hash change in the first place.
      // @see \Drupal\canvas\Hook\ShapeMatchingHooks::mediaLibraryStorablePropShapeAlter()
      $prop_field_definition = $this->loadComponent($component_id)->getSettings()['prop_field_definitions'][$prop_name];
      self::assertSame('entity_reference', $prop_field_definition['field_type'], $component_id);
      self::assertSame('media_library_widget', $prop_field_definition['field_widget'], $component_id);
    }
  }

  /**
   * A single-process install converges without needing a cache rebuild.
   *
   * This is the `drush site:install` shape: every recipe applied in one
   * process, so the prop shape repository is destructed once, at the end, on a
   * cache that was cold throughout.
   */
  public function testSingleProcessInstallNeedsNoCacheRebuild(): void {
    self::applyRecipe('component_version_sdc');
    self::applyRecipe('component_version_image_media_type');

    $repository = $this->container->get(PropShapeRepositoryInterface::class);
    \assert($repository instanceof PropShapeRepositoryInterface);
    \assert($repository instanceof CacheCollectorInterface);

    // End of the install process.
    $repository->destruct();
    $installed = $this->versionSnapshot();

    // A plain request afterwards: the cache is loaded warm and nothing is
    // queued for re-resolution, so its teardown changes nothing.
    self::assertNotEmpty($repository->getUniquePropShapes());
    $repository->destruct();
    self::assertSame($installed, $this->versionSnapshot());

    // The decisive assertion: the freshly installed site has nothing left for
    // a later regeneration to heal. `hook_rebuild()` is what `drush cr`
    // reaches; a module install or another recipe would do just as well.
    // Otherwise the install left hashes that nothing detects as outdated, and
    // whichever of those happens first silently mints the versions the install
    // itself should have.
    $this->container->get(ModuleHandlerInterface::class)->invokeAll('rebuild');
    self::assertSame($installed, $this->versionSnapshot());
  }

}
