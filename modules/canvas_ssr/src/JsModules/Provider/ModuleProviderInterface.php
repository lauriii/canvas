<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\JsModules\Provider;

use Drupal\canvas_ssr\JsModules\ProvidedModules;

/**
 * Supplies modules for SSR to load, such as Canvas's packages.
 *
 * Components don't come from providers: which version of a component an
 * import reaches depends on the render, as when a preview swaps in a draft.
 * ComponentModules builds those.
 *
 * A provided module shouldn't call fetch() as it evaluates. It evaluates
 * once, for whichever island imports it first, or before any render where
 * a runner evaluates libraries ahead of time, so later islands would read
 * the result without its cacheability. It should fetch from a function a
 * component calls.
 *
 * Nor should it keep what it fetches past the call that fetched it, as in
 * a cache of its own. An island of another component that read it from
 * there would make no fetch, and carry none of its cacheability. Keeping
 * it in a store the component hands over is fine, since an island takes on
 * what earlier islands sharing a component's module fetched. "swr" is the
 * exception: SsrOnlyModuleProvider replaces it with a shim that fetches
 * again for every island.
 *
 * @see \Drupal\canvas_ssr\ComponentInfo\ComponentModules
 * @see \Drupal\canvas_ssr\JsModules\AllProvidedModules
 */
interface ModuleProviderInterface {

  /**
   * Returns this provider's modules.
   *
   * @return \Drupal\canvas_ssr\JsModules\ProvidedModules
   *   The modules. AllProvidedModules caches them until one of the list's
   *   cache tags is invalidated. The base also varies by its cache contexts.
   */
  public function getModules(): ProvidedModules;

}
