<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Controller;

use Drupal\canvas\Controller\AddPageController;
use Drupal\canvas\Entity\Page;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\PageTrait;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

#[Group('canvas')]
#[CoversClass(AddPageController::class)]
final class AddPageControllerTest extends KernelTestBase {

  use PageTrait;
  use RequestTrait;
  use UserCreationTrait;

  protected static $modules = [
    'system',
    'canvas',
    ...self::PAGE_TEST_MODULES,
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installPageEntitySchema();
    $this->installEntitySchema('user');
  }

  public function testWithoutPermission(): void {
    $url = Url::fromRoute('entity.canvas_page.add_page');

    $this->expectException(CacheableAccessDeniedHttpException::class);
    $this->expectExceptionMessage(
      \sprintf("The '%s' permission is required", Page::CREATE_PERMISSION)
    );

    $this->request(Request::create($this->getCsrfUrlString($url)));
  }

  public function testWithoutCsrf(): void {
    $this->createUser([Page::CREATE_PERMISSION]);

    $url = Url::fromRoute('entity.canvas_page.add_page');

    $this->expectException(CacheableAccessDeniedHttpException::class);
    $this->expectExceptionMessage("'csrf_token' URL query argument is invalid");

    $this->request(Request::create($url->toString()));
  }

  public function testWithPermissionAndCsrf(): void {
    $this->setUpCurrentUser([], [Page::CREATE_PERMISSION]);

    $url = Url::fromRoute('entity.canvas_page.add_page');
    $response = $this->request(Request::create($this->getCsrfUrlString($url)));
    $this->assertEquals(302, $response->getStatusCode());
    $this->assertStringContainsString(
      '/canvas/editor/canvas_page/',
      $response->headers->get('Location') ?: ''
    );
  }

  /**
   * Get the string URL for a CSRF protected route.
   *
   * @param \Drupal\Core\Url $url
   *   The URL.
   *
   * @return string
   *   The URL string.
   */
  private function getCsrfUrlString(Url $url): string {
    $context = new RenderContext();
    $renderer = $this->container->get('renderer');
    $url = $renderer->executeInRenderContext($context, function () use ($url) {
      return $url->toString();
    });
    $bubbleable_metadata = $context->pop();
    \assert($bubbleable_metadata instanceof BubbleableMetadata);
    $build = [
      '#plain_text' => $url,
    ];
    $bubbleable_metadata->applyTo($build);
    return (string) $renderer->renderInIsolation($build);
  }

}
