<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Controller;

use Drupal\canvas\Controller\ApiTextEditorSettingsController;
use Drupal\editor\Entity\Editor;
use Drupal\filter\Entity\FilterFormat;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[CoversClass(ApiTextEditorSettingsController::class)]
final class ApiTextEditorSettingsControllerTest extends CanvasKernelTestBase {

  use UserCreationTrait;
  use RequestTrait;

  private const string URL = '/canvas/api/v0/text-editor-settings';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    // `administer code components` grants Canvas UI access.
    // @see \Drupal\canvas\Access\CanvasUiAccessCheck
    $this->setUpCurrentUser([], ['administer code components']);
  }

  /**
   * Requests the endpoint and returns the decoded JSON response.
   */
  private function requestSettings(): array {
    $response = $this->request(Request::create(self::URL));
    self::assertInstanceOf(JsonResponse::class, $response);
    $content = $response->getContent();
    self::assertNotFalse($content);
    $data = \json_decode($content, TRUE);
    self::assertIsArray($data);
    return $data;
  }

  /**
   * Tests the delivered editor settings and assets for Canvas's own formats.
   */
  public function testEditorSettingsDelivery(): void {
    $data = $this->requestSettings();

    // The response is in the CanvasTemplateRenderer shape that
    // `processResponseAssets` loads client-side.
    self::assertSame(['html', 'css', 'js', 'settings', 'transforms'], \array_keys($data));

    // Both Canvas-shipped formats have CKEditor 5 editors, and their settings
    // must match what the editor module computes for a server-built form.
    $formats = $data['settings']['editor']['formats'] ?? [];
    self::assertEqualsCanonicalizing(
      ['canvas_html_block', 'canvas_html_inline'],
      \array_keys($formats),
    );
    $editor_manager = $this->container->get('plugin.manager.editor');
    $expected = $editor_manager->getAttachments(['canvas_html_block', 'canvas_html_inline']);
    self::assertEquals($expected['drupalSettings']['editor']['formats'], $formats);
    foreach ($formats as $format_id => $format_settings) {
      self::assertSame($format_id, $format_settings['format']);
      self::assertSame('ckeditor5', $format_settings['editor']);
      // The toolbar derives from the format's editor configuration.
      // @see config/install/editor.editor.canvas_html_block.yml
      self::assertContains('bold', $format_settings['editorSettings']['toolbar']['items']);
    }

    // The CKEditor 5 asset libraries are delivered as resolved script URLs.
    self::assertNotEmpty($data['js']);
    $sources = \array_column($data['js'], 'src');
    self::assertNotEmpty(\array_filter($sources, fn (string $src): bool => \str_contains($src, 'ckeditor5')));
  }

  /**
   * Tests that formats the user cannot use are not exposed.
   */
  public function testFormatUsePermissionGating(): void {
    // A format (with a CKEditor 5 editor) that requires its own permission.
    $block_format = FilterFormat::load('canvas_html_block');
    \assert($block_format instanceof FilterFormat);
    FilterFormat::create([
      'format' => 'secret_html',
      'name' => 'Secret HTML',
      'filters' => $block_format->get('filters'),
    ])->save();
    $block_editor = Editor::load('canvas_html_block');
    \assert($block_editor instanceof Editor);
    Editor::create([
      'format' => 'secret_html',
      'editor' => 'ckeditor5',
      'settings' => $block_editor->getSettings(),
      'image_upload' => ['status' => FALSE],
    ])->save();

    // The current user lacks `use text format secret_html`: neither its
    // editor settings nor its assets may be exposed.
    $formats = $this->requestSettings()['settings']['editor']['formats'] ?? [];
    self::assertArrayNotHasKey('secret_html', $formats);

    // With the use permission granted, the format's settings are delivered.
    $this->setUpCurrentUser([], [
      'administer code components',
      'use text format secret_html',
    ]);
    $formats = $this->requestSettings()['settings']['editor']['formats'] ?? [];
    self::assertArrayHasKey('secret_html', $formats);
    self::assertSame('ckeditor5', $formats['secret_html']['editor']);
  }

}
