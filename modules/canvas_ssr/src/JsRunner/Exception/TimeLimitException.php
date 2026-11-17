<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\JsRunner\Exception;

/**
 * A runner failure: invoke() went past the runner's time limit.
 *
 * As after any runner failure, the runner has no realm, and may have dropped
 * its registrations. The same module may finish on a less busy host, so the
 * failure may not recur. A runner throws it, rather than another runner
 * failure, so the renderer can warn that a component's render takes too
 * long.
 *
 * @see \Drupal\canvas_ssr\JsRunner\RunnerInterface::invoke()
 */
class TimeLimitException extends \RuntimeException {}
