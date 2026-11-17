<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\JsRunner\Exception;

/**
 * A script failure: thrown by invoke() when the JS itself failed.
 *
 * A module threw while it evaluated or the component rendered, an import
 * didn't load, or, where the runner can tell, a promise never settled.
 * Running the same modules with the same payload fails the same way again,
 * so a CSR fallback for it can be cached like a render. The runner keeps its
 * realm and its registrations. Anything else invoke() throws is a runner
 * failure, which may not happen again. See ADR 2.
 *
 * @see \Drupal\canvas_ssr\JsRunner\RunnerInterface::invoke()
 */
class ScriptException extends \RuntimeException {}
