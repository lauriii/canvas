<?php

namespace Drupal\experience_builder\PathProcessor;

use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Defines a path processor to rewrite React-based XB routes.
 *
 * The main XB frontend route can have additional parameters that are handled
 * by the React router; Drupal does not care about these, so we strip them off.
 * This is the cleanest way until core supports this directly in routing.
 *
 * @see https://www.drupal.org/project/drupal/issues/2741939
 */
class ExperienceBuilderPathProcessor implements InboundPathProcessorInterface {

  /**
   * {@inheritdoc}
   */
  public function processInbound($path, Request $request) {
    if (preg_match('@^/xb/(?!api/)[^/]+/[^/]+@', $path, $matches)) {
      return $matches[0];
    }
    return $path;
  }

}
