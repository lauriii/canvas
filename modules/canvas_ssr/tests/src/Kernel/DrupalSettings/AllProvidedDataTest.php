<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel\DrupalSettings;

use Drupal\canvas_ssr\DrupalSettings\AllProvidedData;
use Drupal\Tests\canvas_ssr\Kernel\CanvasSsrKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests when the provided data is built afresh.
 *
 * @legacy-covers \Drupal\canvas_ssr\DrupalSettings\AllProvidedData
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('JavaScriptComponents')]
final class AllProvidedDataTest extends CanvasSsrKernelTestBase {

  /**
   * Tests a value is built afresh once its tag is invalidated.
   *
   * As when a request pushes and then renders, or a process that keeps its
   * services serves the next request.
   */
  public function testValuesAreForgottenWhenStale(): void {
    $data = $this->container->get(AllProvidedData::class);
    \assert($data instanceof AllProvidedData);
    $site_name = static fn(): string => $data->siteData()['v0.branding']->getValue()['siteName'];

    $this->config('system.site')->set('name', 'Before')->save();
    self::assertSame('Before', $site_name());

    // Saving config invalidates its tag.
    $this->config('system.site')->set('name', 'After')->save();
    self::assertSame('After', $site_name());

    // A tag none of the values has leaves them.
    $value = $data->siteData()['v0.branding'];
    $data->invalidateTags(['node_list']);
    self::assertSame($value, $data->siteData()['v0.branding']);

    // The end of a request forgets them all.
    $data->destruct();
    self::assertNotSame($value, $data->siteData()['v0.branding']);
  }

}
