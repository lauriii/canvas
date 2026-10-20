<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\Plugin\Field\FieldType\StringItem;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines the `canvas_icon` field type: a stored Icon API icon id.
 *
 * This is the field type Canvas maps every icon-shaped prop to, regardless of
 * component source (SDC or code component). Isolating icons in a dedicated
 * field type — rather than a plain `string` — lets the shared component-source
 * layer recognize icon props by field type and resolve their stored ids into
 * renderable inline SVG at render time, uniformly for any source.
 *
 * The stored value is the core Icon API's full icon id (`pack_id:icon_id`), a
 * plain string; storage is inherited unchanged from the `string` field type.
 * Pack scoping is enforced as a JSON Schema `pattern` on the prop, not by this
 * field type.
 *
 * @see \Drupal\canvas\Icon\IconPropShape
 * @see \Drupal\canvas\Icon\IconResolver
 * @see \Drupal\canvas\Plugin\Field\FieldWidget\IconWidget
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::resolveIconProps()
 *
 * @internal
 */
#[FieldType(
  id: 'canvas_icon',
  label: new TranslatableMarkup('Icon'),
  description: new TranslatableMarkup('Stores a Drupal Icon API icon id (pack_id:icon_id).'),
  category: 'canvas',
  default_widget: 'canvas_icon',
  default_formatter: 'string',
  no_ui: TRUE,
)]
final class IconItem extends StringItem {

  public const string PLUGIN_ID = 'canvas_icon';

}
