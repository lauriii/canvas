<?php

declare(strict_types=1);

namespace Drupal\canvas\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines an attribute for a component source.
 *
 * @see \Drupal\canvas\ComponentSource\ComponentSourceInterface
 * @see \Drupal\canvas\ComponentSource\ComponentSourceManager
 * @see \Drupal\canvas\ComponentSource\ComponentSourceBase
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ComponentSource extends Plugin {

  /**
   * @param string $id
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   * @param class-string|false $discovery
   *   FQCN to a ComponentCandidatesDiscoveryInterface implementation, or FALSE
   *   if no discovery.
   *   Some ComponentSource plugins provide a fixed set of components (then the
   *   module must provide the necessary Component config entities in its
   *   `config/install` directory).
   * @param class-string|null $deriver
   * @param list<string> $discoveryCacheTags
   *   The cache tags associated with this ComponentSource plugin's discovery.
   *   Enables code dependent on the components discovered by this
   *   ComponentSource to result in immediately visible updates for changes in
   *   source-specific metadata or functionality, even if those changes do not
   *   result in updates to the corresponding Component config entities.
   *   For example: a changed example image URL for an image included with an
   *   SDC, which causes zero changes in Component config entities, and hence
   *   would not be impacted by an invalidation of the `config:component_list`
   *   cache tag. Hence the need for an additional cache tag.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly bool $supportsImplicitInputs,
    public readonly string|false $discovery,
    public readonly ?string $deriver = NULL,
    public readonly array $discoveryCacheTags = [],
  ) {
    if (is_string($discovery)) {
      \assert(class_exists($discovery));
    }
  }

}
