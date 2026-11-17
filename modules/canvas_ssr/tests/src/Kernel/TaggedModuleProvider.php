<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel;

use Drupal\canvas_ssr\JsModules\ProvidedModule;
use Drupal\canvas_ssr\JsModules\ProvidedModules;
use Drupal\canvas_ssr\JsModules\Provider\ModuleProviderInterface;
use Drupal\Core\Cache\CacheableMetadata;

/**
 * Supplies one module, cached on a tag of its own.
 *
 * Unlike the real providers, nothing else carries its tag, so a test can
 * change it without touching the import map.
 *
 * @see \Drupal\Tests\canvas_ssr\Kernel\JsModules\BaseModulesTest
 */
final class TaggedModuleProvider implements ModuleProviderInterface {

  /**
   * The bare specifier that imports the module.
   */
  public const string SPECIFIER = '@canvas-ssr-test/tagged';

  /**
   * The module URL.
   */
  public const string URL = '/_canvas_ssr/test/tagged.js';

  /**
   * The cache tag on the module.
   */
  public const string TAG = 'canvas_ssr_test:tagged';

  /**
   * The module's source.
   *
   * Static, so a test can change it without the container. Invalidate TAG
   * after changing it.
   */
  public static string $source = 'export const tagged = "a";';

  /**
   * What reading the source throws, or NULL to read it.
   */
  public static ?\Throwable $failure = NULL;

  /**
   * How many times the source was read, or failed to be.
   */
  public static int $loads = 0;

  /**
   * {@inheritdoc}
   */
  public function getModules(): ProvidedModules {
    return new ProvidedModules(
      [
        new ProvidedModule(
          url: self::URL,
          read: static function (): string {
            self::$loads++;
            if (self::$failure !== NULL) {
              throw self::$failure;
            }
            return self::$source;
          },
          specifiers: [self::SPECIFIER],
        ),
      ],
      (new CacheableMetadata())->addCacheTags([self::TAG]),
    );
  }

}
