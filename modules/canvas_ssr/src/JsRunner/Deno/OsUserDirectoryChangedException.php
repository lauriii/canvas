<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\JsRunner\Deno;

/**
 * Thrown when an OS user's directory changed after it was checked.
 *
 * Someone deleted it, and it may have been replaced by another OS user's.
 * Nothing more is written there or read from there.
 *
 * @see \Drupal\canvas_ssr\JsRunner\Deno\OsUserDirectory::assertIntact()
 */
final class OsUserDirectoryChangedException extends \RuntimeException {}
