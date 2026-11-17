<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\JsRunner\Exception;

/**
 * Thrown for a call that would reach the runner while it's invoking.
 *
 * A bound callback runs in the middle of an invoke, and one can render an
 * island: a same-site fetch whose subrequest builds a page with islands on
 * it, say. That render can't use the runner, which is busy with the outer
 * one, so it falls back to CSR. The outer invoke goes on unaffected.
 *
 * It's neither a script failure nor a runner failure: the runner never got
 * the call, so nothing is reset.
 *
 * @see \Drupal\canvas_ssr\JsRunner\RunnerGuard
 */
final class RunnerBusyException extends \LogicException {}
