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
   * Tests prop boundaries around prints through value-preserving filters.
   *
   * @legacy-covers \Drupal\canvas\Twig\CanvasPropVisitor
   */
  #[DataProvider('providerFilteredPrints')]
  public function testFilteredPropPrintBoundaries(string $template, bool $has_boundaries): void {
    $out = (string) $this->container->get('twig')
      ->createTemplate($template)
      ->render([
        'text' => 'Hello',
        'canvas_uuid' => 'the-uuid',
        'canvas_is_preview' => TRUE,
      ]);
    $marked = '<p><!-- canvas-prop-start-the-uuid/text -->Hello<!-- canvas-prop-end-the-uuid/text --></p>';
    if ($has_boundaries) {
      self::assertSame($marked, $out);
    }
    else {
      self::assertStringNotContainsString('canvas-prop-start-', $out);
    }
  }

  public static function providerFilteredPrints(): iterable {
    yield 'bare variable' => ['<p>{{ text }}</p>', TRUE];
    yield 'default' => ["<p>{{ text|default('') }}</p>", TRUE];
    yield 'raw' => ['<p>{{ text|raw }}</p>', TRUE];
    yield 'chained value-preserving filters' => ["<p>{{ text|default('x')|e }}</p>", TRUE];
    yield 'value-changing filter' => ['<p>{{ text|upper }}</p>', FALSE];
    yield 'HTML escaping strategy' => ["<p>{{ text|e('html') }}</p>", TRUE];
    yield 'other escaping strategy' => ["<p>{{ text|e('js') }}</p>", FALSE];
    yield 'value-changing filter after default' => ["<p>{{ text|default('')|upper }}</p>", FALSE];
    yield 'attribute value' => ['<p title="{{ text|default(\'\') }}">x</p>', FALSE];
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
