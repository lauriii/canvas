<?php

declare(strict_types=1);

namespace Drupal\canvas\ComponentSource;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;

/**
 * Defines an interface for component source plugins to discover components.
 *
 * - Handles all discovery concerns, the results of which are tracked in Canvas'
 *   Component config entities. Changes that affect current or future instances
 *   of this component are reflected in versions of the Component config entity.
 * - ComponentSourceInterface handles all other concerns. That is: all instance
 *   concerns.
 *
 * @see \Drupal\canvas\Attribute\ComponentSource
 * @see \Drupal\canvas\ComponentSource\ComponentSourceInterface
 *
 * Corresponds to `type: canvas.component_source_local_id.*` in config schema:
 * @phpstan-type ComponentSourceSpecificId string
 * @phpstan-import-type ComponentConfigEntityId from \Drupal\canvas\Entity\Component
 *
 * @internal
 */
interface ComponentCandidatesDiscoveryInterface extends ContainerInjectionInterface {

  /**
   * Returns all components in this source, including irrelevant ones.
   *
   * @return array<ComponentSourceSpecificId, mixed>
   *   Keys: list of source-specific component IDs, values: whatever represents
   *   those components: plugin instances, config entities, or something else.
   *
   * @see \Drupal\canvas\ComponentSource\ComponentSourceInterface::getSourceSpecificComponentIds())
   *
   * @todo https://www.drupal.org/node/3526045
   */
  public function discover(): array;

  /**
   * Whether the given component is eligible to be used in Canvas.
   *
   * @param ComponentSourceSpecificId $source_specific_id
   *   The source-specific ID ("source-local" ID): an ID that only needs to make
   *   sense to this source.
   *
   * @throws \Drupal\canvas\ComponentDoesNotMeetRequirementsException
   *    When the component does not meet requirements.
   */
  public function checkRequirements(string $source_specific_id): void;

  /**
   * Computes settings to use for the current component in its Component.
   *
   * Canvas will only create a new version of the Component if the settings this
   * computes do not match the active version's settings.
   * Corresponds to `type: canvas.component_source_settings.*` in config schema.
   *
   * @param ComponentSourceSpecificId $source_specific_id
   *   The source-specific ID ("source-local" ID): an ID that only needs to make
   *   sense to this source.
   *
   * @return array
   *
   * @see \Drupal\canvas\Entity\VersionedConfigEntityBase::getActiveVersion()
   */
  public function computeComponentSettings(string $source_specific_id): array;

  /**
   * Computes initial Component provider — if provided by a Drupal extension.
   *
   *  Will correspond to `provider` in `type: canvas.component.*`.
   *
   * @param ComponentSourceSpecificId $source_specific_id
   *
   * @return string|null
   */
  public function computeInitialComponentProvider(string $source_specific_id): ?string;

  /**
   * Computes initial Component status: possibly irrelevant despite eligible.
   *
   * This will correspond to the `status` field of Component config entities.
   *
   * @param ComponentSourceSpecificId $source_specific_id
   *
   * @return bool
   *
   * @see \Drupal\canvas\Entity\ComponentInterface::status()
   */
  public function computeInitialComponentStatus(string $source_specific_id): bool;

  /**
   * Computes Component metadata that can change over time: label.
   *
   * @param ComponentSourceSpecificId $source_specific_id
   *
   * @return array{'label': string, 'status'?: bool}
   *   Returns:
   *   - `label`: string
   *   - (optional) `status`: boolean — for ComponentSource plugins that need to
   *     be able to automatically disable components
   *
   * @see \Drupal\canvas\Entity\ComponentInterface::label()
   * @see \Drupal\canvas\Entity\ComponentInterface::status()
   */
  public function computeCurrentComponentMetadata(string $source_specific_id): array;

  /**
   * @param ComponentSourceSpecificId $source_specific_component_id
   * @return ComponentConfigEntityId
   */
  public static function getComponentConfigEntityId(string $source_specific_component_id): string;

  /**
   * @param ComponentConfigEntityId $component_id
   * @return ComponentSourceSpecificId
   */
  public static function getSourceSpecificComponentId(string $component_id): string;

}
