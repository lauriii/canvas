<?php

declare(strict_types=1);

namespace Drupal\canvas\Attribute;

/**
 * Marks a CanvasConfigUpdater method that must run when a Component is saved.
 *
 * The update-path methods on \Drupal\canvas\CanvasConfigUpdater fix legacy
 * Component config. A post_update only runs once per site (and never for
 * imported config), so each such method must ALSO be invoked from
 * \Drupal\canvas\Entity\Component::preSave() — otherwise a component re-saved
 * outside the update path silently keeps its outdated data.
 *
 * That wiring is easy to forget, so it is enforced statically: every method
 * carrying this attribute must appear in Component::preSave(), and the
 * `postUpdate` it names — the one-time update path that heals already-stored
 * config — must exist.
 *
 * @see \Drupal\canvas\Entity\Component::preSave()
 * @see \Canvas\PHPStan\Rules\ComponentPreSaveUpdateMethodsRule
 * @see \Drupal\canvas\Attribute\NotAppliedOnComponentPreSave
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class ComponentPreSaveUpdate {

  /**
   * @param string $postUpdate
   *   The `hook_post_update_NAME` function that applies this same update to
   *   already-stored config (existing sites heal on update; freshly-saved
   *   config heals via Component::preSave()).
   */
  public function __construct(
    public readonly string $postUpdate,
  ) {}

}
