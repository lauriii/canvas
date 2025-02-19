<?php

declare(strict_types=1);

namespace Drupal\experience_builder\PropSource;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\experience_builder\ComponentSource\UrlRewriteInterface;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\JsonSchemaInterpreter\JsonSchemaStringFormat;

/**
 * Prop source that is only used when previewing example URLs.
 *
 * Example links and image URLs should refer to real resources, but the full
 * URL cannot be hardcoded. Component sources that implement UrlRewriteInterface
 * are capable of taking a relative URL and expanding it to an absolute URL
 * that can be displayed in a preview.
 *
 * @see \Drupal\experience_builder\ComponentSource\UrlRewriteInterface
 */
final class UrlPreviewPropSource extends PropSourceBase {

  private readonly UrlRewriteInterface $componentSource;

  public function __construct(
    private readonly mixed $value,
    private readonly array $jsonSchema,
    private readonly string $componentId,
  ) {
    $component = Component::load($componentId);
    assert($component instanceof Component);
    $componentSource = $component->getComponentSource();
    assert($componentSource instanceof UrlRewriteInterface);
    $this->componentSource = $componentSource;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSourceTypePrefix(): string {
    return 'url-preview';
  }

  /**
   * {@inheritdoc}
   */
  public function getSourceType(): string {
    return self::getSourceTypePrefix();
  }

  /**
   * {@inheritdoc}
   */
  public function toArray(): array {
    return [
      'sourceType' => $this->getSourceType(),
      'value' => $this->value,
      'jsonSchema' => $this->jsonSchema,
      'componentId' => $this->componentId,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function parse(array $sdc_prop_source): static {
    // `sourceType = url-preview` requires a value and schema to be specified.
    $missing = array_diff(['value', 'jsonSchema', 'componentId'], array_keys($sdc_prop_source));
    if (!empty($missing)) {
      throw new \LogicException(sprintf('Missing the keys %s.', implode(',', $missing)));
    }
    assert(array_key_exists('value', $sdc_prop_source));
    assert(array_key_exists('jsonSchema', $sdc_prop_source));
    assert(array_key_exists('componentId', $sdc_prop_source));

    return new self(
      $sdc_prop_source['value'],
      $sdc_prop_source['jsonSchema'],
      $sdc_prop_source['componentId'],
    );
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate(?FieldableEntityInterface $host_entity): mixed {
    if (is_string($this->value)) {
      \assert(self::isUrlJsonSchema($this->jsonSchema));
      return $this->componentSource->rewriteExampleUrl($this->value);
    }

    \assert($this->jsonSchema['type'] === 'object');
    \assert(\is_array($this->value));
    $evaluated = [];
    foreach ($this->value as $property_name => $property_value) {
      if (self::isUrlJsonSchema($this->jsonSchema['properties'][$property_name])) {
        $evaluated[$property_name] = $this->componentSource->rewriteExampleUrl($property_value);
      }
      else {
        $evaluated[$property_name] = $property_value;
      }
    }
    return $evaluated;
  }

  private static function isUrlJsonSchema(array $property_definition): bool {
    if ($property_definition['type'] !== 'string') {
      return FALSE;
    }
    return in_array($property_definition['format'] ?? '', [
      JsonSchemaStringFormat::URI->value,
      JsonSchemaStringFormat::URI_REFERENCE->value,
      JsonSchemaStringFormat::IRI->value,
      JsonSchemaStringFormat::IRI_REFERENCE->value,
    ]);
  }

  public function asChoice(): string {
    throw new \LogicException();
  }

}
