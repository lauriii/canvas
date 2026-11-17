<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\JsParser;

/**
 * A parse used more memory than JsModuleParser allows.
 *
 * @internal
 *
 * @see \Drupal\canvas_ssr\JsParser\JsModuleParser::MEMORY_LIMIT
 */
final class ParseOutOfMemoryException extends \RuntimeException {}
