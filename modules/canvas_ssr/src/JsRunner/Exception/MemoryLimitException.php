<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\JsRunner\Exception;

/**
 * A runner failure: invoke() went past the runner's memory limit.
 *
 * As after any runner failure, the runner has no realm, and may have dropped
 * its registrations. Islands share a realm, so what earlier islands kept may
 * have left this one too little, and it may not recur. A runner throws it,
 * rather than another runner failure, so the renderer can render the island
 * again in a new realm, and warn if it runs out there too.
 *
 * @see \Drupal\canvas_ssr\JsRunner\RunnerInterface::invoke()
 */
class MemoryLimitException extends \RuntimeException {}
