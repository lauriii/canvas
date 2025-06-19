<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Extension;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\experience_builder\Element\AstroIsland;
use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Tests XbTwigExtension.
 *
 * @group experience_builder
 * @group Twig
 */
final class XbTwigExtensionTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'experience_builder',
    'xb_test_sdc',
    'user',
    'system',
    'block',
    // XB's dependencies (modules providing field types + widgets).
    'datetime',
    'file',
    'image',
    'media',
    'options',
    'path',
    'link',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['system']);
  }

  /**
   * @covers \Drupal\experience_builder\Extension\XbTwigExtension
   * @covers \Drupal\experience_builder\Extension\XbPropVisitor
   * @dataProvider providerComponents
   */
  public function testExtension(
    string $type,
    string $component_id,
    bool $props_handled_by_twig,
    string $slot_selector,
    array $render_array_additions = [],
    bool $is_preview = FALSE,
  ): void {
    $heading = $this->randomMachineName();
    $uuid = $this->container->get(UuidInterface::class)->generate();
    (match ($type) {
      AstroIsland::PLUGIN_ID => fn ($component_id) => JavaScriptComponent::create([
        'machineName' => $component_id,
        'name' => $this->getRandomGenerator()->sentences(5),
        'status' => TRUE,
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
        'css' => [],
        'js' => [],
      ])->save(),
      default => fn() => NULL,
    })($component_id);
    $body = $this->getRandomGenerator()->sentences(10);
    $build = [
      '#type' => $type,
      '#component' => $component_id,
      '#props' => [
        'heading' => $heading,
        'xb_uuid' => $uuid,
        'xb_slot_ids' => ['the_body'],
        'xb_is_preview' => $is_preview,
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
        self::assertMatchesRegularExpression('/^<!-- xb-prop-start-(.*)\/heading -->/', $h1Text);
        self::assertMatchesRegularExpression('/xb-prop-end-(.*)\/heading -->$/', $h1Text);
      }
      else {
        self::assertDoesNotMatchRegularExpression('/^<!-- xb-prop-start-(.*)\/heading -->/', $h1Text);
        self::assertDoesNotMatchRegularExpression('/xb-prop-end-(.*)\/heading -->$/', $h1Text);
      }
    }

    $bodySlot = $crawler->filter($slot_selector);
    self::assertCount(1, $bodySlot);
    // Normalize whitespace.
    $bodyHtml = \trim(\preg_replace('/\s+/', ' ', $bodySlot->html()) ?: '');
    self::assertStringContainsString($body, $bodyHtml);

    if ($is_preview) {
      self::assertMatchesRegularExpression('/^<!-- xb-slot-start-(.*)\/the_body -->/', $bodyHtml);
      self::assertMatchesRegularExpression('/xb-slot-end-(.*)\/the_body -->$/', $bodyHtml);
    }
    else {
      self::assertDoesNotMatchRegularExpression('/^<!-- xb-slot-start-(.*)\/the_body -->/', $bodyHtml);
      self::assertDoesNotMatchRegularExpression('/xb-slot-end-(.*)\/the_body -->$/', $bodyHtml);
    }
  }

  public static function providerComponents(): iterable {

    $sdc = [
      'component',
      'xb_test_sdc:props-slots',
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
