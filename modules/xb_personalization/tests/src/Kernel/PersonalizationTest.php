<?php

declare(strict_types=1);

namespace Drupal\Tests\xb_personalization\Kernel;

use Drupal\Core\Recipe\Recipe;
use Drupal\Core\Recipe\RecipeRunner;
use Drupal\Core\Render\HtmlResponse;
use Drupal\Core\Session\AccountInterface;
use Drupal\FunctionalTests\Core\Recipe\RecipeTestTrait;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\experience_builder\Kernel\Traits\RequestTrait;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\experience_builder\Traits\CrawlerTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @see \Drupal\Tests\experience_builder\Kernel\ApiAutoSaveControllerTest
 * @group experience_builder
 * @group xb_personalization
 * @covers \Drupal\experience_builder\EventSubscriber\RecipeSubscriber
 */
final class PersonalizationTest extends KernelTestBase {

  use ContribStrictConfigSchemaTestTrait;
  use RecipeTestTrait;
  use CrawlerTrait;
  use RequestTrait;
  use UserCreationTrait;

  private const string FIXTURES_DIR = __DIR__ . '/../../../../../tests/fixtures/recipes';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $recipe = Recipe::createFromDirectory(self::FIXTURES_DIR . '/test_site_personalization');
    RecipeRunner::processRecipe($recipe);

    $permissions = [
      'access content',
    ];
    $account = $this->createUser($permissions);
    self::assertInstanceOf(AccountInterface::class, $account);
    $this->setCurrentUser($account);
  }

  public function testPersonalization(): void {
    $response = $this->makeHtmlRequest('/');
    $this->assertHtmlResponseCacheability($response);
    $contents = $response->getContent();
    assert(is_string($contents));
    $crawler = new Crawler($contents);
    self::assertCount(1, $crawler->filter('h1.my-hero__heading:contains("Best bikes in the market")'));

    $response = $this->makeHtmlRequest('/?utm_campaign=HALLOWEEN');
    $this->assertHtmlResponseCacheability($response);
    $contents = $response->getContent();
    assert(is_string($contents));
    $crawler = new Crawler($contents);
    self::assertCount(1, $crawler->filter('h1.my-hero__heading:contains("Halloween season is here")'));
  }

  protected function makeHtmlRequest(string $path): HtmlResponse {
    $request = Request::create($path);
    $response = $this->request($request);
    self::assertInstanceOf(HtmlResponse::class, $response);
    return $response;
  }

  protected function assertHtmlResponseCacheability(HtmlResponse $response): void {
    self::assertEquals(Response::HTTP_OK, $response->getStatusCode());
    $cache_tags = $response->getCacheableMetadata()->getCacheTags();
    sort($cache_tags);
    self::assertSame([
      'config:block_list',
      'config:experience_builder.component.p13n.case',
      'config:experience_builder.component.p13n.switch',
      'config:experience_builder.component.sdc.xb_test_sdc.heading',
      'config:experience_builder.component.sdc.xb_test_sdc.my-hero',
      'config:experience_builder.component.sdc.xb_test_sdc.two_column',
      'http_response',
      'rendered',
      'xb_page:1',
      'xb_page_view',
    ], $cache_tags);
    $cache_contexts = $response->getCacheableMetadata()->getCacheContexts();
    sort($cache_contexts);
    self::assertSame([
      'languages:language_interface',
      'route.name',
      'theme',
      'url.query_args:_wrapper_format',
      'url.query_args:utm_campaign',
      'url.site',
      'user.permissions',
      'user.roles:authenticated',
    ], $cache_contexts);
  }

}
