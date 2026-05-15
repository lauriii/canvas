<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Controller;

use Drupal\canvas\Controller\SiteDataController;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

#[RunTestsInSeparateProcesses]
#[CoversClass(SiteDataController::class)]
#[Group('canvas')]
class SiteDataControllerTest extends CanvasKernelTestBase {

  use UserCreationTrait;
  use RequestTrait;

  private const string URL = '/canvas/api/v0/site-data';

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->setUpCurrentUser([], [JavaScriptComponent::ADMIN_PERMISSION]);
    \Drupal::configFactory()->getEditable('system.site')
      ->set('name', 'Test Canvas Site')
      ->set('slogan', 'Test Slogan')
      ->save();
  }

  public function testGetReturns200WithSiteData(): void {
    $response = $this->request(Request::create(self::URL));

    self::assertSame(200, $response->getStatusCode());

    $data = static::decodeResponse($response);
    self::assertIsString($data['baseUrl']);
    self::assertNotEmpty($data['baseUrl']);
    self::assertSame('Test Canvas Site', $data['branding']['siteName']);
    self::assertSame('Test Slogan', $data['branding']['siteSlogan']);
    self::assertIsString($data['branding']['homeUrl']);
    self::assertIsString($data['themeAssets']['logo']['url']);
    self::assertIsString($data['themeAssets']['favicon']['url']);
    self::assertIsString($data['themeAssets']['favicon']['mimeType']);
    // JSON:API not enabled in kernel tests.
    self::assertNull($data['jsonapiSettings']);
    self::assertArrayNotHasKey('breadcrumbs', $data);
    self::assertArrayNotHasKey('mainEntity', $data);
    self::assertArrayNotHasKey('pageTitle', $data);
  }

  public function testGetDeniedForAnonymousUser(): void {
    $this->setUpCurrentUser();

    $this->expectException(CacheableAccessDeniedHttpException::class);
    $this->request(Request::create(self::URL));
  }

}
