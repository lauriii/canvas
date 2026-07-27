<?php

namespace Drupal\canvas_ai\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Schema wrapper for a single component edit within edit_components.
 *
 * A component's prop shapes vary per component and can be nested, so they
 * cannot be typed in the tool schema; each edit therefore carries the target
 * UUID as a typed field and the prop changes as a free-form YAML block. Used
 * only as the ComplexToolItems item type of the edit_components list.
 *
 * @see \Drupal\canvas_ai\Plugin\AiFunctionCall\EditComponents
 */
#[FunctionCall(
  id: 'canvas_ai:component_edit',
  function_name: 'canvas_ai_component_edit',
  name: 'Component Edit',
  description: 'A single component edit: the target component UUID and its prop changes.',
  group: 'modification_tools',
  context_definitions: [
    'component_uuid' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Component UUID"),
      description: new TranslatableMarkup("The UUID of a component already present on the page."),
      required: TRUE,
    ),
    'props' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Prop changes"),
      description: new TranslatableMarkup("A YAML block of 'prop_name: value' pairs to set on this component. Include only the props being changed; nested values (e.g. an image object) are allowed."),
      required: TRUE,
    ),
  ],
)]
final class ComponentEdit extends FunctionCallBase {

}
