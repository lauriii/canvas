<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Controller;

use Drupal\canvas\Entity\IconLibrary;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Theme\Icon\Plugin\IconPackManagerInterface;
use Drupal\file\Entity\File;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\canvas\Kernel\Traits\VfsPublicStreamUrlTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Tests the icons listing and icon library upload HTTP API.
 *
 * @see \Drupal\canvas\Controller\ApiIconsController
 */
#[Group('canvas')]
#[RunTestsInSeparateProcesses]
final class ApiIconsControllerTest extends CanvasKernelTestBase {

  use RequestTrait;
  use UserCreationTrait;
  use VfsPublicStreamUrlTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...self::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'canvas_test_icons',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('path_alias');
    $this->createUser();
    $user = $this->createUser([IconLibrary::ADMIN_PERMISSION]);
    self::assertNotFalse($user);
    $this->setCurrentUser($user);
  }

  /**
   * Tests the icons listing endpoint.
   */
  public function testList(): void {
    $response = $this->request(Request::create('/canvas/api/v0/icons'));
    self::assertSame(200, $response->getStatusCode());

    $data = self::decodeResponse($response);
    self::assertArrayHasKey('packs', $data);
    self::assertArrayHasKey('canvas_test', $data['packs']);
    $pack = $data['packs']['canvas_test'];
    self::assertSame('canvas_test', $pack['id']);
    self::assertSame('Canvas test icons', $pack['label']);
    self::assertSame(14, $pack['iconCount']);
    self::assertCount(14, $pack['icons']);
    foreach ($pack['icons'] as $icon) {
      self::assertArrayHasKey('id', $icon);
      self::assertArrayHasKey('name', $icon);
      self::assertArrayHasKey('label', $icon);
      self::assertArrayHasKey('svg', $icon);
      self::assertStringStartsWith('<svg', $icon['svg']);
    }

    self::assertInstanceOf(CacheableJsonResponse::class, $response);
    $cache_tags = $response->getCacheableMetadata()->getCacheTags();
    self::assertContains('icon_pack_plugin', $cache_tags);
    self::assertContains('icon_pack_collector', $cache_tags);
    self::assertContains('config:icon_library_list', $cache_tags);
    self::assertContains('config:canvas.settings', $cache_tags);
    self::assertContains('url.query_args:scope', $response->getCacheableMetadata()->getCacheContexts());
  }

  /**
   * Tests that the site-wide allow-list filters the default listing.
   */
  public function testListRespectsAllowedPacks(): void {
    // A second pack, provided by a Canvas-managed icon library.
    $library = IconLibrary::create(['id' => 'demo', 'label' => 'Demo icons']);
    $library->save();
    $svg = \file_get_contents(\dirname(__DIR__, 3) . '/modules/canvas_test_icons/icons/star.svg');
    self::assertIsString($svg);
    $response = $this->request(self::createUploadRequest('demo', 'star.svg', $svg));
    self::assertSame(201, $response->getStatusCode());
    // As the CLI does after uploading, commit the asset to the entity: only
    // referenced assets become icons.
    $library->setAssets([['name' => 'star.svg', 'uri' => 'public://canvas/icons/demo/star.svg']]);
    $library->save();

    // With the default empty allow-list, every installed pack is offered.
    $data = self::decodeResponse($this->request(Request::create('/canvas/api/v0/icons')));
    self::assertArrayHasKey('canvas_test', $data['packs']);
    self::assertArrayHasKey('demo', $data['packs']);

    // A non-empty allow-list restricts the default listing to those packs.
    $this->config('canvas.settings')->set('icons.allowed_packs', ['canvas_test'])->save();
    $data = self::decodeResponse($this->request(Request::create('/canvas/api/v0/icons')));
    self::assertArrayHasKey('canvas_test', $data['packs']);
    self::assertArrayNotHasKey('demo', $data['packs']);

    // scope=all bypasses the allow-list for brand kit administrators, so CLI
    // pulls always see the complete catalog.
    $data = self::decodeResponse($this->request(Request::create('/canvas/api/v0/icons', 'GET', ['scope' => 'all'])));
    self::assertArrayHasKey('canvas_test', $data['packs']);
    self::assertArrayHasKey('demo', $data['packs']);
  }

  /**
   * Tests that scope=all requires the brand kit administration permission.
   */
  public function testListScopeAllRequiresAdminPermission(): void {
    // A user who passes the route's access check but does not hold the brand
    // kit administration permission.
    $user = $this->createUser([JavaScriptComponent::ADMIN_PERMISSION]);
    self::assertNotFalse($user);
    $this->setCurrentUser($user);

    $this->expectException(AccessDeniedHttpException::class);
    $this->request(Request::create('/canvas/api/v0/icons', 'GET', ['scope' => 'all']));
  }

  /**
   * Tests that an unknown scope value is rejected.
   */
  public function testListRejectsUnknownScope(): void {
    $request = Request::create('/canvas/api/v0/icons', 'GET', ['scope' => 'bogus']);
    // In non-production environments the OpenAPI request validator rejects the
    // unknown value first; bypass it to exercise the controller's own guard.
    $request->headers->set('X-NO-OPENAPI-VALIDATION', '1');
    $this->expectException(BadRequestHttpException::class);
    $this->request($request);
  }

  /**
   * Tests uploading a valid SVG file into an icon library.
   */
  public function testUploadValidSvg(): void {
    $library = IconLibrary::create(['id' => 'demo', 'label' => 'Demo icons']);
    $library->save();

    $svg = \file_get_contents(\dirname(__DIR__, 3) . '/modules/canvas_test_icons/icons/star.svg');
    self::assertIsString($svg);

    $response = $this->request(self::createUploadRequest('demo', 'star.svg', $svg));
    self::assertSame(201, $response->getStatusCode());
    $data = self::decodeResponse($response);
    self::assertSame('public://canvas/icons/demo/star.svg', $data['uri']);
    self::assertIsInt($data['fid']);
    self::assertIsString($data['url']);
    self::assertFileExists('public://canvas/icons/demo/star.svg');

    $file = File::load($data['fid']);
    self::assertNotNull($file);
    self::assertTrue($file->isPermanent());

    // An uploaded file only becomes an icon once the entity references it —
    // the CLI commits the asset list to the entity after uploading.
    $icon_pack_manager = $this->container->get(IconPackManagerInterface::class);
    \assert($icon_pack_manager instanceof IconPackManagerInterface);
    $definitions = $icon_pack_manager->getDefinitions() ?? [];
    self::assertSame([], $definitions['demo']['icons'] ?? []);
    $library->setAssets([['name' => 'star.svg', 'uri' => 'public://canvas/icons/demo/star.svg']]);
    $library->save();
    $definitions = $icon_pack_manager->getDefinitions() ?? [];
    self::assertArrayHasKey('demo', $definitions);
    self::assertArrayHasKey('demo:star', $definitions['demo']['icons']);

    // Re-uploading the same filename replaces the file instead of creating a
    // new one, making CLI re-pushes idempotent.
    $response = $this->request(self::createUploadRequest('demo', 'star.svg', $svg));
    self::assertSame(201, $response->getStatusCode());
    $data2 = self::decodeResponse($response);
    self::assertSame($data['uri'], $data2['uri']);
    self::assertSame($data['fid'], $data2['fid']);
  }

  /**
   * Tests that malicious SVG files are rejected without writing a file.
   */
  #[DataProvider('providerMaliciousUpload')]
  public function testUploadMaliciousSvg(string $filename, string $svg): void {
    IconLibrary::create(['id' => 'demo', 'label' => 'Demo icons'])->save();

    $response = $this->request(self::createUploadRequest('demo', $filename, $svg));
    self::assertSame(422, $response->getStatusCode());
    $data = self::decodeResponse($response);
    self::assertArrayHasKey('errors', $data);
    self::assertNotSame([], $data['errors']);
    self::assertFileDoesNotExist('public://canvas/icons/demo/' . $filename);
  }

  public static function providerMaliciousUpload(): array {
    return [
      'script element' => [
        'script.svg',
        '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
      ],
      'onload attribute' => [
        'onload.svg',
        '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>',
      ],
      'external image href' => [
        'external.svg',
        '<svg xmlns="http://www.w3.org/2000/svg"><image href="https://evil.example/x.png"/></svg>',
      ],
      'DOCTYPE XXE' => [
        'xxe.svg',
        '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"><text>&xxe;</text></svg>',
      ],
      'javascript href' => [
        'js-href.svg',
        '<svg xmlns="http://www.w3.org/2000/svg"><a href="javascript:alert(1)"><text>x</text></a></svg>',
      ],
    ];
  }

  /**
   * Tests that an invalid filename is rejected.
   */
  public function testUploadInvalidFilename(): void {
    IconLibrary::create(['id' => 'demo', 'label' => 'Demo icons'])->save();

    $response = $this->request(self::createUploadRequest('demo', 'star.png', '<svg xmlns="http://www.w3.org/2000/svg"/>'));
    self::assertSame(422, $response->getStatusCode());
    $data = self::decodeResponse($response);
    self::assertNotSame([], $data['errors']);
  }

  /**
   * Tests that a user without update access to the library cannot upload.
   */
  public function testUploadRequiresUpdateAccess(): void {
    IconLibrary::create(['id' => 'demo', 'label' => 'Demo icons'])->save();
    $user = $this->createUser();
    self::assertNotFalse($user);
    $this->setCurrentUser($user);

    $this->expectException(AccessDeniedHttpException::class);
    $this->request(self::createUploadRequest('demo', 'star.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'));
  }

  private static function createUploadRequest(string $icon_library_id, string $filename, string $content): Request {
    return Request::create(
      '/canvas/api/v0/icon-libraries/' . $icon_library_id . '/assets',
      'POST',
      server: [
        'CONTENT_TYPE' => 'application/octet-stream',
        'HTTP_CONTENT_DISPOSITION' => 'attachment; filename="' . $filename . '"',
      ],
      content: $content,
    );
  }

}
