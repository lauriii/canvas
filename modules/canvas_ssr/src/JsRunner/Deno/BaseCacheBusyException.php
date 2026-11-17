<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\JsRunner\Deno;

/**
 * Thrown when another process is building a base cache.
 *
 * The caller asked not to wait for it.
 *
 * @see \Drupal\canvas_ssr\JsRunner\Deno\BaseCaches::ensure()
 */
final class BaseCacheBusyException extends \RuntimeException {}
