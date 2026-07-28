<?php

declare(strict_types=1);

namespace Drupal\canvas_ai;

use Symfony\Component\Yaml\Yaml;

/**
 * Builds the component catalog and per-component metadata for AI agents.
 *
 * Splits the component context into two views so agents spend tokens in
 * proportion to the components they actually use:
 * - a lightweight catalog (id, name, description) of every component, for
 *   scanning candidates;
 * - detailed metadata (description, props, and slots) for a named subset,
 *   fetched on demand.
 *
 * @see \Drupal\canvas_ai\Plugin\AiFunctionCall\GetComponentContext
 * @see \Drupal\canvas_ai\Plugin\AiFunctionCall\GetComponentDetails
 */
final class CanvasAiComponentContextHelper {

  public function __construct(
    private readonly CanvasAiPageBuilderHelper $pageBuilderHelper,
  ) {
  }

  /**
   * Decodes the AI component context into an array.
   *
   * @return array
   *   The component context, keyed by component id.
   */
  private function getComponentContextArray(): array {
    $context = Yaml::parse($this->pageBuilderHelper->getComponentContextForAi());
    return \is_array($context) ? $context : [];
  }

  /**
   * Gets the description, props, and slots for the given components.
   *
   * @param array $component_ids
   *   The component ids to retrieve details for.
   *
   * @return string
   *   The component metadata as a YAML string, keyed by component id. If any
   *   of the given ids does not exist, only the "does not exist" error(s) are
   *   returned, with no component data.
   */
  public function getComponentDetails(array $component_ids): string {
    $context = $this->getComponentContextArray();
    $errors = [];
    $details = [];
    foreach ($component_ids as $component_id) {
      if (!isset($context[$component_id])) {
        $errors[] = \sprintf('Component with id "%s" does not exist.', $component_id);
        continue;
      }
      $details[$component_id] = $context[$component_id];
    }
    if (!empty($errors)) {
      return implode("\n", $errors);
    }
    return Yaml::dump($details, 4, 2);
  }

  /**
   * Gets the lean component catalog for AI.
   *
   * Returns every component's name and description (keyed by id), dropping
   * props and slots so agents can scan candidates cheaply.
   *
   * @return string
   *   The component catalog as a YAML string, keyed by component id.
   */
  public function getComponentCatalog(): string {
    $catalog = [];
    foreach ($this->getComponentContextArray() as $component_id => $component_data) {
      $catalog[$component_id] = [
        'name' => $component_data['name'] ?? '',
        'description' => $component_data['description'] ?? '',
      ];
    }
    return Yaml::dump($catalog, 4, 2);
  }

}
