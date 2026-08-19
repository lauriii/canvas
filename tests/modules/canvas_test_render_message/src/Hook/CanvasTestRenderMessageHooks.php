<?php

declare(strict_types=1);

namespace Drupal\canvas_test_render_message\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Messenger\MessengerInterface;

/**
 * Adds a status message while a page renders.
 *
 * @see \Drupal\Tests\canvas\Kernel\ApiLayoutControllerGetTest::testStatusMessagesAddedWhileRendering()
 */
class CanvasTestRenderMessageHooks {

  public const MESSAGE = 'Added while rendering.';

  public function __construct(
    protected MessengerInterface $messenger,
  ) {}

  /**
   * Implements hook_page_attachments().
   *
   * @see \Drupal\Core\Render\MainContent\HtmlRenderer::prepare()
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$attachments): void {
    $this->messenger->addStatus(self::MESSAGE);
  }

}
