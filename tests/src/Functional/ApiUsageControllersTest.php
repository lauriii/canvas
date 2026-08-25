<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\canvas\Controller\ApiUsageControllers;
use Drupal\canvas\Entity\Color;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Entity\Page;
use Drupal\Core\Url;
use Drupal\Tests\canvas\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Api Usage Controllers.
 *
 * @internal
 * @legacy-covers \Drupal\canvas\Controller\ApiUsageControllers
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
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
      // Needed for `deletable` to reflect a color's usage rather than a
      // missing permission.
      Color::ADMIN_PERMISSION,
    ]);
    \assert($user instanceof UserInterface);
    $this->httpApiUser = $user;

    $this->drupalLogin($this->httpApiUser);

    $page = Page::create([
      'title' => 'Test page using a component',
      'components' => [
        'uuid' => '16176e0b-8197-40e3-ad49-48f1b6e9a7f9',
        'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
        'component_version' => 'd34b93534777207a',
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
   * Tests component usage.
   *
   * @legacy-covers \Drupal\canvas\Controller\ApiUsageControllers::component
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
   * Tests component details usage.
   *
   * @legacy-covers \Drupal\canvas\Controller\ApiUsageControllers::componentDetails
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
   * Tests component list usage.
   *
   * @legacy-covers \Drupal\canvas\Controller\ApiUsageControllers::componentsList
   */
  public function testComponentListUsage(): void {
    $components = Component::loadMultiple();
    $to_delete = count($components) - ApiUsageControllers::MAX_PER_PAGE;
    \assert($to_delete > 0);
    // Delete some Components, to end up at 50 exactly for testing purposes (to
    // make sure no `next` link is generated).
    \array_map(fn (Component $c) => $c->delete(), array_slice($components, ApiUsageControllers::MAX_PER_PAGE));

    $listing_url = Url::fromRoute('canvas.api.usage.component.list')->setOption('absolute', FALSE);
    $body = $this->assertExpectedResponse('GET', $listing_url, [], 200, NULL, NULL, 'UNCACHEABLE (request policy)', 'UNCACHEABLE (no cacheability)');
    \assert(\is_array($body));
    $this->assertCount(50, $body['data']);
    $expected_usage = array_fill_keys(\array_keys(Component::loadMultiple()), FALSE);
    $expected_usage['sdc.canvas_test_sdc.props-no-slots'] = TRUE;
    ksort($expected_usage);
    $this->assertSame($expected_usage, $body['data']);

    \assert(\is_array($body['links']));
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
    \array_map(fn (Component $c) => $c->delete(), array_slice($components, ApiUsageControllers::MAX_PER_PAGE));

    $body = $this->assertExpectedResponse('GET', $listing_url, [], 200, NULL, NULL, 'UNCACHEABLE (request policy)', 'UNCACHEABLE (no cacheability)');
    \assert(\is_array($body));
    $this->assertNull($body['links']['prev']);
    $this->assertSame($listing_url->setRouteParameters(['page' => 1])->setOption('absolute', FALSE)->toString(), $body['links']['next']);
    // This is just for test purposes which will stop double prefixing in the URL.
    // As URL::fromUserInput() will add the base path again and assertExpectedResponse expects a URL object.
    $next_url = $body['links']['next'];
    if (base_path() !== '/') {
      $next_url = preg_replace('#^/[^/]+#', '', $next_url);
    }
    \assert(\is_string($next_url));
    $body = $this->assertExpectedResponse('GET', Url::fromUserInput($next_url), [], 200, NULL, NULL, 'UNCACHEABLE (request policy)', 'UNCACHEABLE (no cacheability)');
    \assert(\is_array($body));
    $this->assertSame($listing_url->setRouteParameters(['page' => 0])->setOption('absolute', FALSE)->toString(), $body['links']['prev']);
    $this->assertNull($body['links']['next']);
    $this->assertCount(1, $body['data']);
    // The final page holds the single component that sorts after the first
    // full page. Derive it from the current set rather than assuming
    // `Component::loadMultiple()` returns the same order the API paginates by.
    $expected_last_page = array_fill_keys(\array_keys(Component::loadMultiple()), FALSE);
    $expected_last_page['sdc.canvas_test_sdc.props-no-slots'] = TRUE;
    ksort($expected_last_page);
    $this->assertSame(
      \array_slice($expected_last_page, ApiUsageControllers::MAX_PER_PAGE, NULL, TRUE),
      $body['data']
    );
  }

  /**
   * Builds a component tree storing a color reference in the given prop.
   *
   * @param \Drupal\canvas\Entity\Color $color
   *   The color to reference.
   * @param string $prop_name
   *   Either `background_color`, the component's color prop, or `heading`, a
   *   plain string prop that merely holds the same characters.
   *
   * @return array<string, mixed>
   *   A single component instance.
   */
  private static function treeUsingColorInProp(Color $color, string $prop_name): array {
    $component = Component::load('sdc.canvas_test_sdc.color-valid');
    \assert($component instanceof Component);
    return [
      'uuid' => \Drupal::service('uuid')->generate(),
      'component_id' => $component->id(),
      'component_version' => $component->getActiveVersion(),
      'inputs' => [
        'heading' => 'Heading',
        'background_color' => '#ff0000ff',
        $prop_name => Color::REFERENCE_PREFIX . $color->id(),
      ],
    ];
  }

  /**
   * Tests color details endpoint.
   *
   * @legacy-covers \Drupal\canvas\Controller\ApiUsageControllers::colorDetails
   */
  public function testColorDetailsUsage(): void {
    $color = Color::create([
      'name' => 'Test Red',
      'cssVariable' => '--color-test-red',
      'value' => [
        'colorSpace' => 'srgb',
        'components' => [1.0, 0.0, 0.0],
        'hex' => '#ff0000',
      ],
    ]);
    $color->save();

    // Unused: no usage keys, and deletion is allowed.
    $json = $this->assertExpectedResponse('GET', Url::fromUri('base:/canvas/api/v0/usage/color/' . $color->id() . '/details'), [], 200, NULL, NULL, 'UNCACHEABLE (request policy)', 'UNCACHEABLE (no cacheability)');
    $this->assertSame(['deletable' => TRUE], $json);

    // A page storing the same characters in `heading`, a plain string prop, is
    // not a usage: only a prop whose JSON schema says it is a color counts.
    Page::create([
      'title' => 'Page with a color-like heading',
      'components' => self::treeUsingColorInProp($color, 'heading'),
    ])->save();

    $json = $this->assertExpectedResponse('GET', Url::fromUri('base:/canvas/api/v0/usage/color/' . $color->id() . '/details'), [], 200, NULL, NULL, 'UNCACHEABLE (request policy)', 'UNCACHEABLE (no cacheability)');
    $this->assertSame(['deletable' => TRUE], $json);

    // Save a page referencing the color from its color prop.
    Page::create([
      'title' => 'Page using color in details test',
      'components' => self::treeUsingColorInProp($color, 'background_color'),
    ])->save();

    $json = $this->assertExpectedResponse('GET', Url::fromUri('base:/canvas/api/v0/usage/color/' . $color->id() . '/details'), [], 200, NULL, NULL, 'UNCACHEABLE (request policy)', 'UNCACHEABLE (no cacheability)');
    $this->assertIsArray($json);
    $this->assertArrayHasKey('current', $json);
    $this->assertCount(1, $json['current']);
    $this->assertSame('Page using color in details test', $json['current'][0]['title']);
    $this->assertSame('canvas_page', $json['current'][0]['type']);
    $this->assertSame('background_color', $json['current'][0]['usages'][0]['prop_name']);
    $this->assertArrayNotHasKey('prior', $json);
    $this->assertArrayNotHasKey('config', $json);
    // In use in the latest revision, so it can no longer be deleted.
    $this->assertFalse($json['deletable']);
  }

}
