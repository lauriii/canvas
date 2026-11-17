<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\JsParser;

/**
 * A parse took longer than JsModuleParser allows.
 *
 * @internal
 *
 * @see \Drupal\canvas_ssr\Timeouts
 */
final class ParseTimedOutException extends \RuntimeException {}
