<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Element;

use Drupal\canvas\Element\RenderSafeComponentContainer;
use Drupal\canvas_test_sdc\RenderCrashLazyBuilder;
use Drupal\Core\Render\RendererInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * @legacy-covers \Drupal\canvas\Element\RenderSafeComponentContainer
 */
#[Group('canvas')]
final class RenderSafeComponentContainerTest extends CanvasKernelTestBase {

  private readonly RendererInterface $renderer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->renderer = $this->container->get(RendererInterface::class);
  }

  /**
   * A crash deferred through a placeholder must still be contained.
   *
   * Placeholder replacement happens at the render root, after the container's
   * initial (guarded) render has returned. A component whose preview crashes
   * from a placeholder — the common case for views/blocks/cached sub-renders —
   * must not be allowed to take down the whole render.
   *
   * @see https://www.drupal.org/i/3541431
   */
  public function testPlaceholderDeferredCrashIsContained(): void {
    $build = [
      '#type' => RenderSafeComponentContainer::PLUGIN_ID,
      '#component' => [
        '#lazy_builder' => [RenderCrashLazyBuilder::class . '::build', []],
        '#create_placeholder' => TRUE,
      ],
      '#component_context' => 'Preview rendering component Test.',
      '#component_uuid' => 'test-uuid',
      '#is_preview' => TRUE,
    ];

    // Before the fix this throws the uncaught recursion error out of the render
    // root, breaking Canvas entirely.
    $html = (string) $this->renderer->renderInIsolation($build);

    // The crash is turned into a per-component fallback and reported back to
    // the caller via #render_crashed, exactly like a synchronous crash.
    self::assertArrayHasKey('#render_crashed', $build);
    self::assertStringContainsString('Component failed to render', $html);
  }

  /**
   * A placeholder-deferred crash stays isolated to its own component.
   */
  public function testPlaceholderDeferredCrashKeepsSiblingsVisible(): void {
    $build = [
      'before' => ['#markup' => '<span class="sibling">BEFORE</span>'],
      'crash' => [
        '#type' => RenderSafeComponentContainer::PLUGIN_ID,
        '#component' => [
          '#lazy_builder' => [RenderCrashLazyBuilder::class . '::build', []],
          '#create_placeholder' => TRUE,
        ],
        '#component_context' => 'Preview rendering component Test.',
        '#component_uuid' => 'test-uuid',
        '#is_preview' => TRUE,
      ],
      'after' => ['#markup' => '<span class="sibling">AFTER</span>'],
    ];

    $html = (string) $this->renderer->renderInIsolation($build);

    self::assertStringContainsString('BEFORE', $html);
    self::assertStringContainsString('AFTER', $html);
    self::assertStringContainsString('Component failed to render', $html);
  }

  /**
   * A synchronous render crash in a preview is still contained.
   */
  public function testSynchronousCrashIsContained(): void {
    $build = [
      '#type' => RenderSafeComponentContainer::PLUGIN_ID,
      // Render the crashing component directly (no placeholder), so it throws
      // synchronously during the initial render.
      '#component' => RenderCrashLazyBuilder::build(),
      '#component_context' => 'Preview rendering component Test.',
      '#component_uuid' => 'test-uuid',
      '#is_preview' => TRUE,
    ];

    $html = (string) $this->renderer->renderInIsolation($build);

    self::assertArrayHasKey('#render_crashed', $build);
    self::assertStringContainsString('Component failed to render', $html);
  }

  /**
   * A component that renders successfully keeps its bubbleable metadata.
   */
  public function testSuccessfulRenderPreservesCacheability(): void {
    $build = [
      '#type' => RenderSafeComponentContainer::PLUGIN_ID,
      '#component' => [
        '#markup' => 'Hello',
        '#cache' => [
          'tags' => ['canvas_test:foo'],
          'contexts' => ['languages:language_interface'],
          'max-age' => 3600,
        ],
        '#attached' => ['library' => ['core/drupal']],
      ],
      '#component_context' => 'Preview rendering component Test.',
      '#component_uuid' => 'test-uuid',
      '#is_preview' => TRUE,
    ];

    $html = (string) $this->renderer->renderInIsolation($build);

    self::assertStringContainsString('Hello', $html);
    self::assertArrayNotHasKey('#render_crashed', $build);
    // The component's cacheability and attached assets bubbled up to the
    // container, and from there to the render root.
    self::assertContains('canvas_test:foo', $build['#cache']['tags']);
    self::assertContains('languages:language_interface', $build['#cache']['contexts']);
    self::assertSame(3600, $build['#cache']['max-age']);
    self::assertContains('core/drupal', $build['#attached']['library']);
  }

}
