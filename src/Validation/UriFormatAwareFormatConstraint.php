<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Validation;

use Drupal\Component\Utility\UrlHelper;
use JsonSchema\Constraints\FormatConstraint;
use JsonSchema\Entity\JsonPointer;

/**
 * Defines a custom component validator.
 *
 * The default validator provided by justinrainbow/json-schema uses filter_var
 * to validate URIs but this incorrectly flags URIs like entity:node/3 as
 * invalid.
 *
 * This has been fixed in the 6.3.0 release of justinrainbow/json-schema however
 * Drupal core only allows the 5.x series.
 */
final class UriFormatAwareFormatConstraint extends FormatConstraint {

  /**
   * {@inheritdoc}
   */
  public function check(&$element, $schema = NULL, ?JsonPointer $path = NULL, $i = NULL): void {
    if (!isset($schema->format) || $this->factory->getConfig(self::CHECK_MODE_DISABLE_FORMAT)) {
      return;
    }

    // Override the check for the uri format.
    if ($schema->format === 'uri') {
      if (\is_string($element) && !UrlHelper::isValid($element)) {
        $this->addError($path, 'Invalid URL format', 'format', ['format' => $schema->format]);
      }
      return;
    }
    parent::check($element, $schema, $path, $i);
  }

}
