<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\JsParser;

/**
 * A parse would have used more memory than the request has left.
 *
 * What's left is what remains of PHP's memory_limit.
 *
 * @internal
 *
 * @see \Drupal\canvas_ssr\JsParser\JsModuleParser::MEMORY_MARGIN
 */
final class ParseOutOfHeadroomException extends \RuntimeException {}
