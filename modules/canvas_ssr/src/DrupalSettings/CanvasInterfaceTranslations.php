<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\DrupalSettings;

use Drupal\canvas\CodeComponentDataProvider;
use Drupal\Core\Cache\CacheableMetadata;

/**
 * Builds a graph's interface translations, as Canvas gives the browser.
 *
 * In the browser, Canvas gives an island the interface translations of the
 * strings its graph's components pass to `t()` and `formatPlural()`, at
 * drupalSettings.canvasData.v0.translations, for `useTranslation()`. A
 * component doesn't declare them. This builds the same value, from the same
 * CodeComponentDataProvider. Canvas 1.12 doesn't give them.
 *
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent::renderComponent()
 */
final readonly class CanvasInterfaceTranslations {

  /**
   * Where Canvas puts them, keyed as a data dependency would be.
   */
  public const string KEY = CodeComponentDataProvider::V0 . '.translations';

  /**
   * Constructs a CanvasInterfaceTranslations.
   *
   * @param \Closure(): \Drupal\canvas\CodeComponentDataProvider $dataProvider
   *   Returns Canvas's provider of drupalSettings.canvasData. A closure,
   *   since the provider's dependencies lead back to this.
   */
  public function __construct(
    private \Closure $dataProvider,
  ) {}

  /**
   * Returns the interface translations of some components' strings.
   *
   * @param list<\Drupal\canvas\Entity\JavaScriptComponent> $components
   *   The components: in a preview, their drafts.
   *
   * @return \Drupal\canvas_ssr\DrupalSettings\DataValue|null
   *   The translations, or NULL on a Canvas that doesn't give them. The
   *   value is NULL where none of the strings is translated, as Canvas then
   *   leaves the key unset. Its cacheability is Canvas's: the interface
   *   language context and the locale tag, if any component has strings.
   */
  public function forComponents(array $components): ?DataValue {
    $getter = $this->getter();
    if ($getter === NULL) {
      return NULL;
    }
    // The value and its cacheability come from one call, made once.
    $built = NULL;
    $build = static function () use ($getter, $components, &$built): array {
      if ($built === NULL) {
        $cacheability = new CacheableMetadata();
        $value = $getter($components, $cacheability)[CodeComponentDataProvider::V0]['translations'] ?? NULL;
        $built = [$value, $cacheability];
      }

      return $built;
    };

    return new DataValue(
      static fn(): mixed => $build()[0],
      static fn(): CacheableMetadata => $build()[1],
    );
  }

  /**
   * Returns Canvas's getter of interface translations, if it has one.
   *
   * @return (\Closure(list<\Drupal\canvas\Entity\JavaScriptComponent>, \Drupal\Core\Cache\RefinableCacheableDependencyInterface): array<string, array<string, mixed>>)|null
   *   The getter, or NULL on a Canvas that doesn't give translations.
   */
  private function getter(): ?\Closure {
    $name = 'getCanvasDataTranslationsV0';
    if (!\in_array($name, \get_class_methods(CodeComponentDataProvider::class), TRUE)) {
      return NULL;
    }
    $provider = ($this->dataProvider)();

    return (new \ReflectionMethod($provider, $name))->getClosure($provider);
  }

}
