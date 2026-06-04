<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\EventSubscriber;

use Drupal\canvas\ComponentIncompatibilityReasonRepository;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Recipe\Recipe;
use Drupal\Core\Recipe\RecipeRunner;
use Drupal\FunctionalTests\Core\Recipe\RecipeTestTrait;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests incompatibility reasons stay in sync when components are regenerated.
 *
 * Component generation runs both directly (e.g. on cache rebuild) and through
 * recipes: Drupal CMS and its site templates enable and disable components by
 * applying recipes, and applying a recipe dispatches RecipeAppliedEvent, which
 * makes RecipeSubscriber re-run generation. Both triggers reach the same code
 * in ComponentSourceManager::generateComponentsForSource(), so this exercises
 * both via a data provider.
 *
 * Two concrete regressions this guards against:
 * - A component that a recipe makes eligible again keeps a stale auto-disable
 *   reason and stays flagged incompatible, with no UI affordance to recover it.
 * - Applying any later recipe silently clears a site owner's explicit "Manually
 *   disabled" decision, so a component they deliberately turned off reappears.
 *
 * @legacy-covers \Drupal\canvas\EventSubscriber\RecipeSubscriber::onApply
 * @legacy-covers \Drupal\canvas\ComponentSource\ComponentSourceManager::generateComponentsForSource
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('#slow')]
final class ComponentReasonSyncRecipeTest extends CanvasKernelTestBase {

  use GenerateComponentConfigTrait;
  use RecipeTestTrait;

  private ComponentIncompatibilityReasonRepository $reasonRepository;
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();
    $this->reasonRepository = $this->container->get(ComponentIncompatibilityReasonRepository::class);
    $this->entityTypeManager = $this->container->get(EntityTypeManagerInterface::class);
  }

  /**
   * The two ways component generation is triggered in production.
   *
   * @return array<string, array{string}>
   *   The trigger key, resolved to an action by triggerRegeneration().
   */
  public static function triggerProvider(): array {
    return [
      'direct generate' => ['direct'],
      'recipe apply' => ['recipe'],
    ];
  }

  /**
   * Regenerates components via the given trigger.
   */
  private function triggerRegeneration(string $trigger): void {
    match ($trigger) {
      'direct' => $this->generateComponentConfig(),
      'recipe' => self::applyTriggerRecipe(),
      default => throw new \InvalidArgumentException("Unknown trigger: $trigger"),
    };
  }

  /**
   * Applies a no-config recipe, which dispatches RecipeAppliedEvent.
   */
  private static function applyTriggerRecipe(): void {
    $recipe = Recipe::createFromDirectory(__DIR__ . '/../../../fixtures/recipes/component_reason_sync');
    RecipeRunner::processRecipe($recipe);
  }

  /**
   * Regenerating components clears stale auto-disable reasons when eligible.
   *
   * The component stays disabled — the site owner re-enables it.
   */
  #[DataProvider('triggerProvider')]
  public function testAutoDisabledReasonsCleared(string $trigger): void {
    // Generate initial component config so eligible components get entities.
    $this->generateComponentConfig();

    $component_storage = $this->entityTypeManager->getStorage(Component::ENTITY_TYPE_ID);
    $component = $component_storage->load('sdc.canvas_test_sdc.druplicon');
    \assert($component instanceof ComponentInterface);
    self::assertTrue($component->status());

    // Simulate the component having previously been auto-disabled: store
    // requirement-failure reasons and disable the entity, as the normal flow
    // does when checkRequirements() throws.
    $this->reasonRepository->storeReasons(
      'sdc',
      'sdc.canvas_test_sdc.druplicon',
      ['Prop "logo_url" has contentMediaType which is not supported.']
    );
    $component->disable()->save();
    self::assertFalse($component->status());
    self::assertNotEmpty($this->reasonRepository->getReasons()['sdc']['sdc.canvas_test_sdc.druplicon'] ?? []);

    // The component is eligible, so regeneration should clear its reasons. It
    // remains disabled — re-enabling is the site owner's choice.
    $this->triggerRegeneration($trigger);

    // loadUnchanged() bypasses the entity static cache so the assertion reads
    // the state regeneration persisted, not our stale in-memory copy.
    $component = $component_storage->loadUnchanged('sdc.canvas_test_sdc.druplicon');
    \assert($component instanceof ComponentInterface);
    self::assertFalse($component->status());
    self::assertArrayNotHasKey(
      'sdc.canvas_test_sdc.druplicon',
      $this->reasonRepository->getReasons()['sdc'] ?? []
    );
  }

  /**
   * Regenerating components must not clear a site owner's manual disable.
   *
   * Reasons and disabled status are preserved across regeneration.
   */
  #[DataProvider('triggerProvider')]
  public function testManuallyDisabledSurvives(string $trigger): void {
    // Generate initial component config so eligible components get entities.
    $this->generateComponentConfig();

    $component_storage = $this->entityTypeManager->getStorage(Component::ENTITY_TYPE_ID);
    $component = $component_storage->load('sdc.canvas_test_sdc.druplicon');
    \assert($component instanceof ComponentInterface);

    // Simulate a site owner manually disabling the component.
    $component->disable()->save();
    $this->reasonRepository->storeReasons(
      'sdc',
      'sdc.canvas_test_sdc.druplicon',
      [ComponentIncompatibilityReasonRepository::MANUALLY_DISABLED_REASON]
    );

    // Even though the component is technically eligible, regeneration must
    // respect the manual disable.
    $this->triggerRegeneration($trigger);

    // loadUnchanged() bypasses the entity static cache so the assertion reads
    // the state regeneration persisted, not our stale in-memory copy.
    $component = $component_storage->loadUnchanged('sdc.canvas_test_sdc.druplicon');
    \assert($component instanceof ComponentInterface);
    self::assertFalse($component->status());
    self::assertSame(
      [ComponentIncompatibilityReasonRepository::MANUALLY_DISABLED_REASON],
      $this->reasonRepository->getReasons()['sdc']['sdc.canvas_test_sdc.druplicon'] ?? []
    );
  }

}
