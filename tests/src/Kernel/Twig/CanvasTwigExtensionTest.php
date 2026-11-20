<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Twig;

use Drupal\canvas\Element\AstroIsland;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Source;

/**
 * Tests CanvasTwigExtension.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('Twig')]
final class CanvasTwigExtensionTest extends CanvasKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
  }

  /**
   * Tests extension.
   *
   * @legacy-covers \Drupal\canvas\Twig\CanvasTwigExtension
   * @legacy-covers \Drupal\canvas\Twig\CanvasPropVisitor
   */
  #[DataProvider('providerComponents')]
  public function testExtension(
    string $type,
    string $component_id,
    bool $props_handled_by_twig,
    string $slot_selector,
    array $render_array_additions,
    bool $is_preview,
  ): void {
    $heading = $this->randomMachineName();
    $uuid = $this->container->get(UuidInterface::class)->generate();
    (match ($type) {
      AstroIsland::PLUGIN_ID => fn ($component_id) => JavaScriptComponent::create([
        'machineName' => $component_id,
        'name' => $this->getRandomGenerator()->sentences(5),
        'status' => TRUE,
        'required' => [],
        'props' => [
          'heading' => [
            'type' => 'string',
            'title' => 'Heading',
            'examples' => ['A heading'],
          ],
        ],
        'slots' => [
          'the_body' => [
            'title' => 'Body',
            'description' => 'Body content',
            'examples' => [
              'Lorem ipsum',
            ],
          ],
        ],
        'js' => ['original' => '', 'compiled' => ''],
        'css' => ['original' => '', 'compiled' => ''],
        'dataDependencies' => [],
      ])->save(),
      default => fn() => NULL,
    })($component_id);
    $body = $this->getRandomGenerator()->sentences(10);
    $build = [
      '#type' => $type,
      '#component' => $component_id,
      '#props' => [
        'heading' => $heading,
        'canvas_uuid' => $uuid,
        'canvas_slot_ids' => ['the_body'],
        'canvas_is_preview' => $is_preview,
      ],
      '#slots' => [
        'the_body' => [
          '#markup' => $body,
        ],
      ],
    ] + $render_array_additions;
    $out = (string) $this->container->get(RendererInterface::class)->renderInIsolation($build);
    $crawler = new Crawler($out);
    if ($props_handled_by_twig) {
      $h1 = $crawler->filter(\sprintf('h1:contains("%s")', $heading));
      self::assertCount(1, $h1);
      $h1Text = $h1->html();

      if ($is_preview) {
        self::assertMatchesRegularExpression('/^<!-- canvas-prop-start-(.*)\/heading -->/', $h1Text);
        self::assertMatchesRegularExpression('/canvas-prop-end-(.*)\/heading -->$/', $h1Text);
      }
      else {
        self::assertDoesNotMatchRegularExpression('/^<!-- canvas-prop-start-(.*)\/heading -->/', $h1Text);
        self::assertDoesNotMatchRegularExpression('/canvas-prop-end-(.*)\/heading -->$/', $h1Text);
      }
    }

    $bodySlot = $crawler->filter($slot_selector);
    self::assertCount(1, $bodySlot);
    // Normalize whitespace.
    $bodyHtml = \trim(\preg_replace('/\s+/', ' ', $bodySlot->html()) ?? '');
    self::assertStringContainsString($body, $bodyHtml);

    if ($is_preview) {
      self::assertMatchesRegularExpression('/^<!-- canvas-slot-start-(.*)\/the_body -->/', $bodyHtml);
      self::assertMatchesRegularExpression('/canvas-slot-end-(.*)\/the_body -->$/', $bodyHtml);
    }
    else {
      self::assertDoesNotMatchRegularExpression('/^<!-- canvas-slot-start-(.*)\/the_body -->/', $bodyHtml);
      self::assertDoesNotMatchRegularExpression('/canvas-slot-end-(.*)\/the_body -->$/', $bodyHtml);
    }
  }

  /**
   * Tests that only templates Canvas renders components with are wrapped.
   *
   * Canvas passes `canvas_uuid`, `canvas_slot_ids` and `canvas_is_preview`
   * only to single-directory component templates and to the inline templates
   * it generates for slots, so no other template should compile the wrapper's
   * context checks.
   *
   * @legacy-covers \Drupal\canvas\Twig\CanvasPropVisitor
   * @see https://www.drupal.org/i/3569796
   */
  public function testWrapperNodesAreScopedToComponentTemplates(): void {
    $twig = $this->container->get('twig');
    self::assertInstanceOf(Environment::class, $twig);
    $code = '<div>{{ heading }}</div>';
    // Register the test template names with a loader, because compiling a
    // source requires its name to be resolvable to a cache key.
    $loader = $twig->getLoader();
    $twig->setLoader(new ChainLoader([
      new ArrayLoader([
        'canvas-test-plain.html.twig' => $code,
        '__string_template__canvas_test' => $code,
      ]),
      $loader,
    ]));

    // A template Canvas does not render components with must not be burdened
    // with the wrapper code.
    self::assertStringNotContainsString(
      'canvas_is_preview',
      $twig->compileSource(new Source($code, 'canvas-test-plain.html.twig')),
    );

    // Single-directory component templates receive props from Canvas.
    self::assertStringContainsString(
      'canvas_is_preview',
      $twig->compileSource($loader->getSourceContext('canvas_test_sdc:props-slots')),
    );

    // Inline templates are how Canvas renders slots and JavaScript components.
    self::assertStringContainsString(
      'canvas_is_preview',
      $twig->compileSource(new Source($code, '__string_template__canvas_test')),
    );

    // Known consequence of the scoping: Twig compiles each template on its own,
    // so a template that a component template pulls in with `{% include %}`,
    // `{% extends %}` or `{% use %}` gets no boundary markers, even though the
    // context does reach it at runtime.
    $build = [
      '#type' => 'inline_template',
      '#template' => "{% include 'canvas-test-plain.html.twig' %}",
      '#context' => [
        'heading' => 'Hello',
        'canvas_uuid' => 'test-uuid',
        'canvas_slot_ids' => [],
        'canvas_is_preview' => TRUE,
      ],
    ];
    $rendered = (string) $this->container->get(RendererInterface::class)->renderInIsolation($build);
    self::assertSame('<div>Hello</div>', \trim($rendered));

    $twig->setLoader($loader);
  }

  public static function providerComponents(): iterable {

    $sdc = [
      'component',
      'canvas_test_sdc:props-slots',
      TRUE,
      '.component--props-slots--body',
      [],
    ];

    yield 'SDC, preview' => [...$sdc, TRUE];
    yield 'SDC, live' => [...$sdc, FALSE];

    $js_component = [
      AstroIsland::PLUGIN_ID,
      'trousers',
      FALSE,
      'template[data-astro-template="the_body"]',
      ['#name' => 'trousers', '#component_url' => 'the/wrong/trousers.js'],
    ];

    yield 'JS Component, preview' => [...$js_component, TRUE];
    yield 'JS Component, live' => [...$js_component, FALSE];
  }

}
