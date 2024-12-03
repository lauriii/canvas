<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Extension;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\KernelTests\KernelTestBase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Tests XbTwigExtension.
 *
 * @group experience_builder
 * @group Twig
 */
final class XbTwigExtensionTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'experience_builder',
    'xb_test_sdc',
  ];

  /**
   * @covers \Drupal\experience_builder\Extension\XbTwigExtension
   * @covers \Drupal\experience_builder\Extension\XbIncludeEmbedVisitor
   * @covers \Drupal\experience_builder\Extension\XbPropVisitor
   */
  public function testExtension(): void {
    $heading = $this->randomMachineName();
    $uuid = $this->container->get(UuidInterface::class)->generate();
    $body = $this->getRandomGenerator()->sentences(10);
    $build = [
      '#type' => 'component',
      '#component' => 'xb_test_sdc:props-slots',
      '#props' => [
        'heading' => $heading,
        'xb_uuid' => $uuid,
        'xb_slot_ids' => ['the_body'],
      ],
      '#slots' => [
        'the_body' => [
          '#markup' => $body,
        ],
      ],
    ];
    $out = (string) $this->container->get(RendererInterface::class)->renderInIsolation($build);
    $crawler = new Crawler($out);
    $h1 = $crawler->filter(\sprintf('h1:contains("%s")', $heading));
    self::assertCount(1, $h1);
    self::assertStringStartsWith(\sprintf('<!-- xb-start-%s -->', $uuid), $out);
    self::assertStringEndsWith(\sprintf('<!-- xb-end-%s -->', $uuid), $out);

    $h1Text = $h1->html();
    self::assertStringStartsWith('<!-- xb-prop-start-heading -->', $h1Text);
    self::assertStringEndsWith('<!-- xb-prop-end-heading -->', $h1Text);

    $bodySlot = $crawler->filter('.component--props-slots--body');
    self::assertCount(1, $bodySlot);
    // Normalize whitespace.
    $bodyHtml = \trim(\preg_replace('/\s+/', ' ', $bodySlot->html()) ?: '');
    self::assertStringContainsString($body, $bodyHtml);
    self::assertStringStartsWith('<!-- xb-slot-start-the_body -->', $bodyHtml);
    self::assertStringEndsWith('<!-- xb-slot-end-the_body -->', $bodyHtml);
  }

}
