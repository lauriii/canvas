<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Render;

// cspell:ignore razzler

use Drupal\Component\Serialization\Json;
use Drupal\Core\Render\AttachmentsInterface;
use Drupal\Core\Render\MainContent\HtmlRenderer;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\canvas\Render\ImportMapResponseAttachmentsProcessor;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests ImportMapResponseAttachmentsProcessor.
 *
 * @coversDefaultClass \Drupal\canvas\Render\ImportMapResponseAttachmentsProcessor
 * @group canvas
 */
#[RunTestsInSeparateProcesses]
final class ImportMapResponseAttachmentsProcessorTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'big_pipe',
  ];

  public function testImportMapResponseAttachmentsProcessor(): void {
    $renderer = $this->container->get('main_content_renderer.html');
    \assert($renderer instanceof HtmlRenderer);
    $main_content = [
      '#attached' => [
        'import_maps' => [
          ImportMapResponseAttachmentsProcessor::GLOBAL_IMPORTS => [
            'dazzler' => 'libs/dazzler.js',
          ],
          ImportMapResponseAttachmentsProcessor::SCOPED_IMPORTS => [
            'brick_maker.js' => [
              'bricks' => 'libs/bricks.js',
              'maker' => 'libs/maker.js',
            ],
            'chips.js' => ['dips' => 'libs/dips.js'],
          ],
        ],
      ],
      'bubble' => [
        '#attached' => [
          'import_maps' => [
            ImportMapResponseAttachmentsProcessor::GLOBAL_IMPORTS => [
              'razzler' => 'libs/razzler.js',
              // Duplicate another import from the other #attached. Drupal will
              // merge these into an array so we need to post-process them to
              // turn them back into a valid import map.
              'dazzler' => 'libs/dazzler.js',
            ],
            ImportMapResponseAttachmentsProcessor::SCOPED_IMPORTS => [
              'fromage.js' => ['brie' => 'libs/brie.js'],
            ],
          ],
        ],
        'bubble-bobble' => [
          '#attached' => [
            'import_maps' => [
              ImportMapResponseAttachmentsProcessor::SCOPED_IMPORTS => [
                'fromage.js' => ['brie' => 'libs/brie.js'],
              ],
            ],
          ],
        ],
      ],
    ];
    $response = $renderer->renderResponse($main_content, Request::create('/'), $this->container->get(RouteMatchInterface::class));
    \assert($response instanceof AttachmentsInterface);
    $processor = $this->container->get('html_response.attachments_processor');
    $processor->processAttachments($response);
    $attachments = $response->getAttachments();
    self::assertArrayNotHasKey('import_maps', $attachments);
    self::assertArrayHasKey('html_head', $attachments);
    self::assertCount(6, $attachments['html_head']);
    self::assertSame([
      'system_meta_content_type',
      'system_meta_generator',
      'MobileOptimized',
      'HandheldFriendly',
      'viewport',
      'canvas_import_map',
    ], \array_map(fn (array $a) => $a[1], $attachments['html_head']));
    [$element, $name] = \end($attachments['html_head']);
    self::assertEquals('canvas_import_map', $name);
    self::assertEquals('script', $element['#tag']);
    self::assertEquals('html_tag', $element['#type']);
    self::assertEquals(['type' => 'importmap'], $element['#attributes']);
    $map = Json::decode($element['#value']);
    self::assertEquals([
      ImportMapResponseAttachmentsProcessor::GLOBAL_IMPORTS => [
        'dazzler' => 'libs/dazzler.js',
        'razzler' => 'libs/razzler.js',
      ],
      ImportMapResponseAttachmentsProcessor::SCOPED_IMPORTS => [
        'brick_maker.js' => [
          'bricks' => 'libs/bricks.js',
          'maker' => 'libs/maker.js',
        ],
        'chips.js' => ['dips' => 'libs/dips.js'],
        'fromage.js' => ['brie' => 'libs/brie.js'],
      ],
    ], $map);
    self::assertArrayHasKey('html_head_link', $attachments);
    $preloads = \array_column($attachments['html_head_link'], 0);
    $hrefs = \array_column($preloads, 'href');
    self::assertContains('libs/dazzler.js', $hrefs);
    self::assertContains('libs/razzler.js', $hrefs);
    self::assertContains('libs/bricks.js', $hrefs);
    self::assertContains('libs/maker.js', $hrefs);
    self::assertContains('libs/dips.js', $hrefs);
    self::assertContains('libs/brie.js', $hrefs);
  }

}
