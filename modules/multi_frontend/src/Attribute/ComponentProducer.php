<?php

declare(strict_types=1);

namespace Drupal\multi_frontend\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Declares a class as the producer for one component.
 *
 * A producer turns a module's internal model into exactly the props its
 * component declares. It is keyed by its own ID rather than by the component
 * ID, so that one component may be produced from more than one kind of
 * subject.
 *
 * Plugin namespace: Plugin\ComponentProducer.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ComponentProducer extends Plugin {

  /**
   * Constructs a ComponentProducer attribute.
   *
   * @param string $id
   *   The producer ID. Must contain a dot, so that reserved path segments
   *   such as "schema" can never collide with a producer ID in a URL.
   * @param string $component
   *   The SDC plugin ID this producer produces props for, such as
   *   "album:photo".
   * @param string $subject
   *   The kind of subject this producer accepts. "entity:<entity_type_id>"
   *   makes core derive an HTTP route with the matching parameter converter
   *   and view-access requirement. Any other value means no derived route.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $label
   *   (optional) A human readable label.
   * @param class-string|null $deriver
   *   (optional) The deriver class.
   */
  public function __construct(
    public readonly string $id,
    public readonly string $component,
    public readonly string $subject,
    public readonly ?TranslatableMarkup $label = NULL,
    public readonly ?string $deriver = NULL,
  ) {}

}
