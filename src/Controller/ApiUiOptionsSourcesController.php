<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\OptionsSource\OptionsSourceInterface;
use Drupal\canvas\OptionsSource\OptionsSourceManager;
use Drupal\Core\Cache\CacheableJsonResponse;

/**
 * Lists the options sources a code component prop can use.
 *
 * @internal This HTTP API is intended only for the Canvas UI. These controllers
 *   and associated routes may change at any time.
 */
final class ApiUiOptionsSourcesController extends ApiControllerBase {

  public function __construct(
    private readonly OptionsSourceManager $optionsSourceManager,
  ) {}

  public function __invoke(): CacheableJsonResponse {
    $data = [];
    foreach ($this->optionsSourceManager->getDefinitions() as $id => $definition) {
      $source = $this->optionsSourceManager->createInstance($id);
      \assert($source instanceof OptionsSourceInterface);
      foreach ($source->getSuggestions() as $suggestion) {
        $data[] = [
          'group' => (string) $definition['label'],
          'label' => $suggestion['label'],
          'options' => ['source' => $id] + $suggestion['configuration'],
        ];
      }
    }
    // Uncached: only the code editor requests it, when editing a prop.
    $response = new CacheableJsonResponse(['data' => $data]);
    $response->getCacheableMetadata()->setCacheMaxAge(0);
    return $response;
  }

}
