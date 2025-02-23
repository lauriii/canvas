<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Render;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Render\AttachmentsInterface;
use Drupal\Core\Render\MainContent\HtmlRenderer;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\KernelTests\KernelTestBase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests ImportMapResponseAttachmentsProcessor.
 *
 * @coversDefaultClass \Drupal\experience_builder\Render\ImportMapResponseAttachmentsProcessor
 * @group experience_builder
 */
final class ImportMapResponseAttachmentsProcessorTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'experience_builder',
    'big_pipe',
  ];

  public function testImportMapResponseAttachmentsProcessor(): void {
    $renderer = $this->container->get('main_content_renderer.html');
    \assert($renderer instanceof HtmlRenderer);
    $main_content = [
      '#attached' => [
        'import_maps' => [
          ['dazzle.js' => ['dazzler' => 'libs/dazzler.js']],
          [
            'brick_maker.js' => [
              'bricks' => 'libs/bricks.js',
              'maker' => 'libs/maker.js',
            ],
          ],
          ['chips.js' => ['dips' => 'libs/dips.js']],
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
    [$element, $name] = \reset($attachments['html_head']);
    self::assertEquals('xb_import_map', $name);
    self::assertEquals('script', $element['#tag']);
    self::assertEquals('html_tag', $element['#type']);
    self::assertEquals(['type' => 'importmap'], $element['#attributes']);
    $map = Json::decode($element['#value']);
    self::assertEquals([
      'scopes' => [
        'dazzle.js' => ['dazzler' => 'libs/dazzler.js'],
        'brick_maker.js' => [
          'bricks' => 'libs/bricks.js',
          'maker' => 'libs/maker.js',
        ],
        'chips.js' => ['dips' => 'libs/dips.js'],
      ],
    ], $map);
  }

}
