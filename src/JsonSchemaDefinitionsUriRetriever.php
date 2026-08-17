<?php

declare(strict_types=1);

namespace Drupal\canvas;

use JsonSchema\Constraints\BaseConstraint;
use JsonSchema\Exception\ResourceNotFoundException;
use JsonSchema\Uri\UriRetriever;
use JsonSchema\UriRetrieverInterface;

/**
 * Resolves `json-schema-definitions://` `$ref`s without the stream wrapper.
 *
 * `justinrainbow/json-schema` resolves `$ref` URIs with `file_get_contents()`,
 * which requires the `json-schema-definitions://` scheme to be registered in
 * PHP's global stream wrapper registry. That registration is not guaranteed on
 * every code path — most notably `hook_rebuild()` while a recipe installs
 * Canvas, where the container that just gained Canvas's stream wrapper service
 * has not been re-registered yet. This retriever resolves those `$ref`s
 * directly from the extension's `schema.json`, so schema resolution no longer
 * depends on the stream wrapper being registered, and delegates all other URIs
 * to the default retriever.
 *
 * @see https://git.drupalcode.org/project/canvas/-/issues/3570043
 * @see \Drupal\canvas\JsonSchemaDefinitionsStreamwrapper
 * @internal
 */
final class JsonSchemaDefinitionsUriRetriever implements UriRetrieverInterface {

  private ?UriRetriever $fallback = NULL;

  /**
   * {@inheritdoc}
   */
  public function retrieve($uri, $baseUri = NULL) {
    if (\is_string($uri) && \str_starts_with($uri, 'json-schema-definitions://')) {
      $definition = JsonSchemaDefinitionsStreamwrapper::getDefinition($uri);
      if ($definition === NULL) {
        throw new ResourceNotFoundException(\sprintf('JSON schema not found: %s', $uri));
      }
      return BaseConstraint::arrayToObjectRecursive($definition);
    }
    return ($this->fallback ??= new UriRetriever())->retrieve($uri, $baseUri);
  }

}
