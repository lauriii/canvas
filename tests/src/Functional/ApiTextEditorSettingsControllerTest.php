<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\canvas\Controller\ApiTextEditorSettingsController;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Url;
use Drupal\Tests\canvas\Traits\ContribStrictConfigSchemaTestTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the text editor settings endpoint against the OpenAPI spec.
 *
 * The functional environment runs the OpenAPI request and response
 * validators, so this guards the endpoint's spec entry (including the
 * `ajaxPageState` query parameter every real client request carries, which a
 * kernel test does not validate).
 *
 * @internal
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[CoversClass(ApiTextEditorSettingsController::class)]
final class ApiTextEditorSettingsControllerTest extends HttpApiTestBase {

  use ContribStrictConfigSchemaTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'canvas',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests the delivered editor settings for a real client request.
   */
  public function testEditorSettingsDelivery(): void {
    $account = $this->createUser(['administer code components']);
    \assert($account !== FALSE);
    $this->drupalLogin($account);

    // The exact query shape the Canvas UI sends: `ajaxPageState` with a
    // compressed libraries value.
    // @see ui/src/services/addAjaxPageState.ts
    $url = Url::fromUri('base:/canvas/api/v0/text-editor-settings', [
      'query' => [
        'ajaxPageState' => (string) \json_encode([
          'libraries' => UrlHelper::compressQueryParameter('core/drupal,core/drupalSettings'),
          'theme' => 'stark',
          'theme_token' => NULL,
        ]),
      ],
    ]);
    // The response is a plain JsonResponse rendered by
    // CanvasTemplateRenderer: uncacheable by the page cache request policy
    // (authenticated) and carrying no cacheability for the dynamic page
    // cache. Client-side, the settings query is cached for the session.
    $body = $this->assertExpectedResponse('GET', $url, [], 200, NULL, NULL, 'UNCACHEABLE (request policy)', 'UNCACHEABLE (no cacheability)');
    self::assertIsArray($body);

    // Canvas's own formats are use-allowed for every user; their editor
    // settings derive from the shipped editor configuration.
    // @see config/install/editor.editor.canvas_html_block.yml
    $formats = $body['settings']['editor']['formats'] ?? [];
    self::assertArrayHasKey('canvas_html_block', $formats);
    self::assertArrayHasKey('canvas_html_inline', $formats);
    self::assertSame('ckeditor5', $formats['canvas_html_block']['editor']);
    self::assertContains('bold', $formats['canvas_html_block']['editorSettings']['toolbar']['items']);

    // The CKEditor 5 asset libraries are delivered as resolved script URLs.
    self::assertNotEmpty($body['js']);
  }

}
