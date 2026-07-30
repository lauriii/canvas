<?php

namespace Drupal\canvas_ai\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Schema wrapper for a single placement operation within place_components.
 *
 * A component's prop shapes vary per component and can be nested, so the
 * component tree cannot be typed in the tool schema; each operation therefore
 * carries its target/placement/reference_uuid as typed fields and the
 * components to place as a free-form YAML block. Used only as the
 * ComplexToolItems item type of the place_components list.
 *
 * @see \Drupal\canvas_ai\Plugin\AiFunctionCall\PlaceComponents
 */
#[FunctionCall(
  id: 'canvas_ai:placement_operation',
  function_name: 'canvas_ai_placement_operation',
  name: 'Placement Operation',
  description: 'A single placement: where to place components, plus the components to place there.',
  group: 'modification_tools',
  context_definitions: [
    'target' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Target"),
      description: new TranslatableMarkup("The region name, such as 'content', or 'parent-uuid/slot_name' to place inside a component's slot."),
      required: TRUE,
    ),
    'placement' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Placement"),
      description: new TranslatableMarkup("Whether to place the components 'inside' a target that has no children yet, or 'above'/'below' the component named by reference_uuid."),
      required: TRUE,
      constraints: [
        'Choice' => ['above', 'below', 'inside'],
      ],
    ),
    'reference_uuid' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Reference UUID"),
      description: new TranslatableMarkup("The UUID of the component to place above or below. Required for 'above' and 'below'; omit it for 'inside'."),
      required: FALSE,
    ),
    'components' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Components"),
      description: new TranslatableMarkup("The components to place, as a YAML list starting at the top level. Each entry is a single-key map of the component ID to its 'props' and, when the component has slots, its 'slots'."),
      required: TRUE,
    ),
  ],
)]
final class PlacementOperation extends FunctionCallBase {

}
