<?php

namespace Drupal\canvas_ai;

/**
 * The result of mapping an AI placement request to Canvas layout operations.
 *
 * Carries the UI operations (with calculated nodePaths and assigned UUIDs), the
 * AI-provided component structure with those UUIDs, and the predicted
 * post-placement layout, so a tool can echo real UUIDs and positions back to
 * the model for reference_uuid chaining in follow-up placements.
 *
 * @see \Drupal\canvas_ai\CanvasAiPageBuilderHelper::generateComponentPlacementData()
 * @see \Drupal\canvas_ai\Plugin\AiFunctionCall\PlaceComponents
 */
final readonly class CanvasAiPlacementResult {

  /**
   * Constructs a CanvasAiPlacementResult.
   *
   * @param array $operations
   *   The UI operations payload, keyed by 'operations'.
   * @param array $componentStructureWithUuids
   *   The parsed page builder output after UUID assignment.
   * @param array $predictedLayout
   *   The predicted post-placement layout, as a region-keyed UUID tree.
   */
  public function __construct(
    public array $operations,
    public array $componentStructureWithUuids,
    public array $predictedLayout,
  ) {
  }

}
