<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\canvas\ContentTranslation\ComponentTreeFieldSymmetricalTranslationSynchronizer;
use Drupal\canvas\Plugin\Validation\Constraint\ComponentTreeSymmetricalTranslationConstraint;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for content_translation integration.
 */
final readonly class ContentTranslationHooks {

  public function __construct(
    private ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Implements hook_entity_type_alter().
   *
   * Attaches the ComponentTreeSymmetricalTranslation constraint to all
   * translatable entity types, so that saves are rejected when non-translatable
   * component input keys differ from the default translation.
   *
   * Only active when `content_translation` is installed.
   *
   * @see \Drupal\canvas\Plugin\Validation\Constraint\ComponentTreeSymmetricalTranslationConstraint
   * @see \Drupal\content_translation\Plugin\Validation\Constraint\ContentTranslationSynchronizedFieldsConstraint
   */
  #[Hook('entity_type_alter')]
  public function entityTypeAlter(array &$entity_types): void {
    // @todo Refactor to use `HookDependsOnModule` once Canvas depends on Drupal 11.5's https://www.drupal.org/node/3548805
    if (!$this->moduleHandler->moduleExists('content_translation')) {
      return;
    }

    // A sibling exists for config-defined component trees.
    // @see \Drupal\canvas\Hook\ConfigTranslationHooks::entityTypeAlter()
    // @see \Drupal\canvas\Plugin\Validation\Constraint\CanvasConfigEntityTranslationsAreValidConstraint
    foreach ($entity_types as $entity_type) {
      if ($entity_type instanceof ContentEntityTypeInterface && $entity_type->isTranslatable()) {
        $entity_type->addConstraint(ComponentTreeSymmetricalTranslationConstraint::PLUGIN_ID);
      }
    }
  }

  /**
   * Implements hook_modules_installed().
   *
   * Ensures the Canvas Page `components` base field override exists with the
   * symmetrical `translation_sync` setting whenever canvas and
   * content_translation are both installed, in whichever order.
   *
   * @see \Drupal\canvas\ContentTranslation\ComponentTreeFieldSymmetricalTranslationSynchronizer::ensureSymmetricalCanvasPageComponents()
   */
  #[Hook('modules_installed')]
  public function modulesInstalled(array $modules, bool $is_syncing): void {
    // During config sync, the override comes from the synced config itself.
    if ($is_syncing) {
      return;
    }
    if (\array_intersect(['canvas', 'content_translation'], $modules) === []) {
      return;
    }
    if (!$this->moduleHandler->moduleExists('content_translation')) {
      return;
    }
    ComponentTreeFieldSymmetricalTranslationSynchronizer::ensureSymmetricalCanvasPageComponents();
  }

}
