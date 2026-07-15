<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Controller;

use Drupal\canvas\Controller\ApiAutocompleteController;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[CoversClass(ApiAutocompleteController::class)]
final class ApiAutocompleteControllerTest extends CanvasKernelTestBase {

  use UserCreationTrait;
  use RequestTrait;
  use GenerateComponentConfigTrait;

  private const string URL = '/canvas/api/v0/autocomplete';

  /**
   * A component with an `entity_reference` (to user) `heading` prop.
   *
   * @see \Drupal\canvas_test_entity_reference_shape_alter\Hook\EntityReferenceHooks::storablePropShapeAlter()
   */
  private const string COMPONENT_ID = 'sdc.canvas_test_entity_reference_shape_alter.props-no-slots';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_test_entity_reference_shape_alter',
  ];

  /**
   * The active version of the tested component.
   */
  private string $version;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->generateComponentConfig();
    $component = Component::load(self::COMPONENT_ID);
    \assert($component instanceof ComponentInterface);
    self::assertSame('entity_reference', $component->getSettings()['prop_field_definitions']['heading']['field_type']);
    $this->version = $component->getActiveVersion();

    // `administer code components` grants Canvas UI access.
    // @see \Drupal\canvas\Access\CanvasUiAccessCheck
    $this->setUpCurrentUser([], ['administer code components', 'access user profiles', 'access content']);
  }

  /**
   * Builds an autocomplete request with the given query parameters.
   */
  private function requestAutocomplete(array $query): Response {
    return $this->request(Request::create(self::URL, 'GET', $query + [
      'component' => self::COMPONENT_ID,
      'version' => $this->version,
      'prop' => 'heading',
    ]));
  }

  /**
   * Tests matching, scoping, and entity access filtering.
   */
  public function testMatches(): void {
    $alpha_tester = $this->createUser([], 'Alpha Tester');
    $alpha_zulu = $this->createUser([], 'Alpha Zulu');
    // A blocked user must not appear: the selection handler applies entity
    // access, and the current user cannot view blocked users.
    // @see \Drupal\user\Plugin\EntityReferenceSelection\UserSelection::buildEntityQuery()
    $this->createUser([], 'Alpha Blocked', FALSE, ['status' => 0]);
    \assert($alpha_tester !== FALSE && $alpha_zulu !== FALSE);

    $response = $this->requestAutocomplete(['q' => 'Alpha']);
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    self::assertSame([
      'results' => [
        ['id' => (string) $alpha_tester->id(), 'label' => 'Alpha Tester'],
        ['id' => (string) $alpha_zulu->id(), 'label' => 'Alpha Zulu'],
      ],
    ], self::decodeResponse($response));

    // No matches.
    $response = $this->requestAutocomplete(['q' => 'Zebra']);
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    self::assertSame(['results' => []], self::decodeResponse($response));

    // An empty query string yields no matches.
    $response = $this->requestAutocomplete(['q' => '']);
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    self::assertSame(['results' => []], self::decodeResponse($response));
  }

  /**
   * Tests the error paths.
   *
   * The exceptions thrown by the controller are converted to JSON error
   * responses in production, but kernel tests request with `$catch = FALSE`,
   * so assert the exceptions themselves.
   *
   * @param class-string<\Throwable> $expected_exception
   *
   * @see \Drupal\canvas\EventSubscriber\ApiExceptionSubscriber
   */
  #[DataProvider('providerErrors')]
  public function testErrors(array $query_overrides, string $expected_exception, string $expected_message): void {
    // Data providers run before ::setUp(), so component versions — which are
    // content-hash based — must be resolved here.
    if (($query_overrides['version'] ?? NULL) === '::MY_CTA_ACTIVE_VERSION::') {
      $my_cta = Component::load('sdc.canvas_test_sdc.my-cta');
      \assert($my_cta instanceof ComponentInterface);
      $query_overrides['version'] = $my_cta->getActiveVersion();
    }
    $this->expectException($expected_exception);
    $this->expectExceptionMessage($expected_message);
    $this->requestAutocomplete($query_overrides + ['q' => 'Alpha']);
  }

  public static function providerErrors(): \Generator {
    yield 'unknown component: 404' => [
      ['component' => 'sdc.canvas_test_sdc.nonexistent'],
      NotFoundHttpException::class,
      'The component `sdc.canvas_test_sdc.nonexistent` does not exist.',
    ];
    yield 'unknown version: 404' => [
      ['version' => 'deadbeef'],
      NotFoundHttpException::class,
      'The requested version `deadbeef` is not available.',
    ];
    yield 'unknown prop: 404' => [
      ['prop' => 'nonexistent'],
      NotFoundHttpException::class,
      \sprintf('The `%s` component does not have a `nonexistent` prop.', self::COMPONENT_ID),
    ];
    yield 'prop not stored as an entity reference: 400' => [
      // The `text` prop of my-cta is stored as a `string` field.
      ['component' => 'sdc.canvas_test_sdc.my-cta', 'version' => '::MY_CTA_ACTIVE_VERSION::', 'prop' => 'text'],
      BadRequestHttpException::class,
      'The `text` prop is not stored as an `entity_reference` field, so autocompletion is not supported for it.',
    ];
  }

}
