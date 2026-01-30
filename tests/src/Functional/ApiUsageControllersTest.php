<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\canvas\Controller\ApiUsageControllers;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Entity\Page;
use Drupal\Core\Url;
use Drupal\Tests\canvas\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\user\UserInterface;

/**
 * @covers \Drupal\canvas\Controller\ApiUsageControllers
 * @group canvas
 * @internal
 */
class ApiUsageControllersTest extends HttpApiTestBase {

  use ContribStrictConfigSchemaTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'canvas',
    'canvas_test_sdc',
    'canvas_broken_sdcs',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  protected readonly UserInterface $httpApiUser;

  protected function setUp(): void {
    parent::setUp();
    $user = $this->createUser([
      'administer themes',
      Page::EDIT_PERMISSION,
      Component::ADMIN_PERMISSION,
      JavaScriptComponent::ADMIN_PERMISSION,
    ]);
    \assert($user instanceof UserInterface);
    $this->httpApiUser = $user;

    $this->drupalLogin($this->httpApiUser);

    $page = Page::create([
      'title' => 'Test page using a component',
      'components' => [
        'uuid' => '16176e0b-8197-40e3-ad49-48f1b6e9a7f9',
        'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
        'component_version' => 'b1e991f726a2a266',
        'inputs' => [
          'heading' => 'world',
        ],
      ],
    ]);
    self::assertCount(0, $page->validate());
    $page->save();
    \assert($page instanceof Page);
  }

  /**
   * @covers ::component()
   */
  public function testComponentUsage(): void {
    $response = $this->makeApiRequest('GET', Url::fromUri('base:/canvas/api/v0/usage/component/sdc.canvas_test_sdc.card'), []);
    $this->assertFalse(json_decode((string) $response->getBody()));

    $response = $this->makeApiRequest('GET', Url::fromUri('base:/canvas/api/v0/usage/component/sdc.canvas_test_sdc.props-no-slots'), []);
    $this->assertTrue(json_decode((string) $response->getBody()));

    $response = $this->makeApiRequest('GET', Url::fromUri('base:/canvas/api/v0/usage/component/invalid.component'), []);
    $this->assertSame(404, $response->getStatusCode());
  }

  /**
   * @covers ::componentDetails()
   */
  public function testComponentDetailsUsage(): void {
    $json = $this->assertExpectedResponse('GET', Url::fromUri('base:/canvas/api/v0/usage/component/sdc.canvas_test_sdc.props-no-slots/details'), [], 200, NULL, NULL, 'UNCACHEABLE (request policy)', 'UNCACHEABLE (no cacheability)');
    $this->assertSame(
      [
        'content' => [
          0 => [
            'title' => 'Test page using a component',
            'type' => 'canvas_page',
            'bundle' => 'canvas_page',
            'id' => '1',
            'revision_id' => '1',
          ],
        ],
      ], $json);

    $json = $this->assertExpectedResponse('GET', Url::fromUri('base:/canvas/api/v0/usage/component/sdc.canvas_test_sdc.card/details'), [], 200, NULL, NULL, 'UNCACHEABLE (request policy)', 'UNCACHEABLE (no cacheability)');
    $this->assertSame([], $json);
  }

  /**
   * @covers ::componentsList()
   */
  public function testComponentListUsage(): void {
    $components = Component::loadMultiple();
    $to_delete = count($components) - ApiUsageControllers::MAX_PER_PAGE;
    \assert($to_delete > 0);
    // Delete some Components, to end up at 50 exactly for testing purposes (to
    // make sure no `next` link is generated).
    array_map(fn (Component $c) => $c->delete(), array_slice($components, ApiUsageControllers::MAX_PER_PAGE));

    $listing_url = Url::fromRoute('canvas.api.usage.component.list')->setOption('absolute', FALSE);
    $body = $this->assertExpectedResponse('GET', $listing_url, [], 200, NULL, NULL, 'UNCACHEABLE (request policy)', 'UNCACHEABLE (no cacheability)');
    \assert(is_array($body));
    $this->assertCount(50, $body['data']);
    $expected_usage = array_fill_keys(array_keys(Component::loadMultiple()), FALSE);
    $expected_usage['sdc.canvas_test_sdc.props-no-slots'] = TRUE;
    ksort($expected_usage);
    $this->assertSame($expected_usage, $body['data']);

    \assert(is_array($body['links']));
    $this->assertNull($body['links']['prev']);
    $this->assertNull($body['links']['next']);

    // Create another component to test the next link is generated.
    JavaScriptComponent::create([
      'machineName' => 'test_component_extra',
      'name' => 'Test component extra',
      'status' => TRUE,
      'props' => [],
      'slots' => [],
      'js' => ['original' => '', 'compiled' => ''],
      'css' => [
        'original' => '',
        // Whitespace only CSS should be ignored.
        'compiled' => "\n  \n",
      ],
      'dataDependencies' => [],
    ])->save();

    // This has triggered re-discovery, so the components we deleted are back,
    // as they are probably SDC components. We need to delete the same
    // components again.
    array_map(fn (Component $c) => $c->delete(), array_slice($components, ApiUsageControllers::MAX_PER_PAGE));

    $body = $this->assertExpectedResponse('GET', $listing_url, [], 200, NULL, NULL, 'UNCACHEABLE (request policy)', 'UNCACHEABLE (no cacheability)');
    \assert(is_array($body));
    $this->assertNull($body['links']['prev']);
    $this->assertSame($listing_url->setRouteParameters(['page' => 1])->setOption('absolute', FALSE)->toString(), $body['links']['next']);
    // This is just for test purposes which will stop double prefixing in the URL.
    // As URL::fromUserInput() will add the base path again and assertExpectedResponse expects a URL object.
    $next_url = $body['links']['next'];
    if (base_path() !== '/') {
      $next_url = preg_replace('#^/[^/]+#', '', $next_url);
    }
    \assert(is_string($next_url));
    $body = $this->assertExpectedResponse('GET', Url::fromUserInput($next_url), [], 200, NULL, NULL, 'UNCACHEABLE (request policy)', 'UNCACHEABLE (no cacheability)');
    \assert(is_array($body));
    $this->assertSame($listing_url->setRouteParameters(['page' => 0])->setOption('absolute', FALSE)->toString(), $body['links']['prev']);
    $this->assertNull($body['links']['next']);
    $this->assertCount(1, $body['data']);
    $this->assertSame([
      array_keys($components)[ApiUsageControllers::MAX_PER_PAGE - 1] => FALSE,
    ], $body['data']);
  }

}
