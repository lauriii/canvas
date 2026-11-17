<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Unit\JsModules;

use Drupal\canvas_ssr\JsModules\AllProvidedModules;
use Drupal\canvas_ssr\JsModules\ProvidedModule;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(AllProvidedModules::class)]
#[Group('canvas_ssr')]
final class AllProvidedModulesTest extends UnitTestCase {

  /**
   * Tests the first provider to claim a module URL or a specifier wins it.
   *
   * In the container, provider order is tag priority, highest first. The
   * merge just gets an ordered iterable.
   */
  public function testFirstProviderWinsEachModuleUrlAndSpecifier(): void {
    $provided = new AllProvidedModules([
      new AllProvidedModulesStaticProvider([
        new ProvidedModule(url: '/pkg/foo.js', read: static fn() => 'first', specifiers: ['foo']),
      ]),
      new AllProvidedModulesStaticProvider([
        new ProvidedModule(url: '/pkg/foo.js', read: static fn() => 'shadowed'),
        new ProvidedModule(url: '/pkg/other.js', read: static fn() => 'other', specifiers: ['foo', 'other']),
        // Nothing to load, so importing it fails.
        new ProvidedModule(url: '/pkg/gone.js', read: static fn() => NULL),
      ]),
    ]);

    self::assertSame(['/pkg/foo.js' => 'first', '/pkg/other.js' => 'other'], $provided->sources());
    self::assertSame(['foo' => '/pkg/foo.js', 'other' => '/pkg/other.js'], $provided->specifiers());
  }

  public function testProvidedModulesMustHaveModuleUrls(): void {
    $this->expectException(\InvalidArgumentException::class);
    new ProvidedModule(url: 'preact', read: static fn() => NULL);
  }

  /**
   * Tests the merge is dropped by the cache tags the providers declared.
   */
  public function testProviderCacheTagsInvalidateTheMerge(): void {
    $provider = new AllProvidedModulesMutableProvider(
      [new ProvidedModule(url: '/assets/lib.js', read: static fn() => 'pushed', specifiers: ['lib'])],
      ['config:canvas.asset_library.global'],
    );
    $provided = new AllProvidedModules([$provider]);

    // With no merge yet, invalidating doesn't ask the providers. Asking just
    // to learn their tags would cost every request that invalidates a tag.
    $provided->invalidateTags(['config:canvas.asset_library.global']);
    self::assertSame(0, $provider->asked);

    self::assertSame(['/assets/lib.js' => 'pushed'], $provided->sources());
    $specifiers = $provided->specifiers();
    self::assertSame(['lib' => '/assets/lib.js'], $specifiers);
    self::assertSame(['/assets/lib.js' => 'pushed'], $provided->sources());
    self::assertSame(1, $provider->asked, 'The providers are asked once.');

    // A push rewrites the manifest. The merge holds until its tag is
    // invalidated.
    $provider->modules = [new ProvidedModule(url: '/assets/lib2.js', read: static fn() => 're-pushed', specifiers: ['lib'])];
    $provided->invalidateTags(['node_list', 'config:system.site']);
    self::assertSame(['/assets/lib.js' => 'pushed'], $provided->sources());
    self::assertSame($specifiers, $provided->specifiers());
    self::assertSame(1, $provider->asked);

    $provided->invalidateTags(['node_list', 'config:canvas.asset_library.global']);
    self::assertSame(['/assets/lib2.js' => 're-pushed'], $provided->sources());
    self::assertSame(['lib' => '/assets/lib2.js'], $provided->specifiers());
    self::assertSame(2, $provider->asked);
  }

}
