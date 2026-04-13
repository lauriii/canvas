<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_oauth\Kernel;

use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\Core\Url;
use Drupal\Tests\canvas\Kernel\Traits\MockFileUploadTrait;
use Drupal\Tests\canvas\Kernel\Traits\PredictableImageStyleItokTestTrait;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\canvas\Traits\CreateTestJsComponentTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\simple_oauth\Kernel\AuthorizedRequestBase;
use Drupal\image\Entity\ImageStyle;
use Drupal\canvas\Entity\AssetLibrary;
use Drupal\canvas\Entity\BrandKit;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\Pattern;
use Drupal\consumers\Entity\Consumer;
use Drupal\simple_oauth\Entity\Oauth2Scope;
use Drupal\simple_oauth\Exception\OAuthUnauthorizedHttpException;
use Drupal\simple_oauth\Oauth2ScopeInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Tests API endpoints where the Canvas OAuth authentication provider is applied.
 */
#[Group('canvas_oauth')]
class CanvasOauthAuthenticationProviderHttpTest extends AuthorizedRequestBase {

  use CreateTestJsComponentTrait;
  use MediaTypeCreationTrait;
  use MockFileUploadTrait;
  use RequestTrait;
  use PredictableImageStyleItokTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'canvas',
    'file',
    'media',
    'path',
    'canvas_oauth',
  ];

  protected Page $page;

  private string $testImagePath;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setupPredictableItok();
    // Parent class setup creates a redirect uri, which triggers the path_alias
    // storage to check if that's used as an alias. So we need to install it
    // manually instead of using the $modules array.
    $this->installModule('path_alias');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    // Set a default theme so Component::normalizeForClientSide() can resolve
    // theme ancestors when listing Component config entities.
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->createTestCodeComponent();
    AssetLibrary::create([
      'id' => AssetLibrary::GLOBAL_ID,
      'label' => 'Global',
    ])->save();
    BrandKit::create([
      'id' => BrandKit::GLOBAL_ID,
      'label' => 'Global brand kit',
      'fonts' => NULL,
    ])->save();
    Pattern::create([
      'id' => 'test-pattern',
      'label' => 'Test pattern',
      'status' => TRUE,
      'component_tree' => [],
    ])->save();
    $this->page = Page::create([
      'uuid' => '80395cae-8381-4298-8bed-8fa319b0a443',
      'title' => 'Test page',
      'status' => TRUE,
      'components' => [],
    ]);
    $this->page->save();
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
  }

  /**
   * Sets up the environment for media upload tests.
   *
   * Installs additional entity schemas, config, and mocks the file system
   * so that file uploads work in kernel tests.
   */
  private function setUpMediaUpload(): void {
    $this->installEntitySchema('media');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['field', 'system']);
    // The media upload response resolves image derivatives which requires
    // the canvas_parametrized_width image style. We don't really need to test
    // the actual image style: we can avoid installing a dozen of modules just
    // by ensuring an image style with that ID exists, as we only care about the
    // Oauth authentication in this test.
    // @see \Drupal\Tests\canvas\Kernel\CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES
    // @see \Drupal\Tests\canvas\Kernel\Controller\ApiMediaControllersPostTest for proper test coverage of the operation itself.
    ImageStyle::create([
      'name' => 'canvas_parametrized_width',
      'label' => 'Drupal Canvas parametrized width',
    ])->save();

    $this->createMediaType('image', [
      'id' => 'image',
      'label' => 'Image',
    ]);

    $this->mockFileSystemForUploads();

    $source = \dirname(__DIR__, 5) . '/tests/fixtures/images/gracie-big.jpg';
    $temp_dir = $this->container->get('file_system')->getTempDirectory();
    $this->testImagePath = $temp_dir . '/canvas-oauth-test-upload-' . \uniqid() . '.jpg';
    \copy($source, $this->testImagePath);
  }

  /**
   * Data provider for testing routes with authenticated HTTP requests.
   *
   * @return array<string, array{
   *   0: string,
   *   1: array<string, mixed>,
   *   2: array<string>,
   *   3: string,
   *   4: array<string, mixed>
   *   }> Array of test cases where:
   *   - Index 0: Route name
   *   - Index 1: Route parameters
   *   - Index 2: Required permissions
   *   - Index 3: HTTP method
   *   - Index 4: Request body data for POST/PATCH
   */
  public static function dataProviderRoutes(): array {
    return [
      'INDEX components' => ['canvas.api.config.list', ['canvas_config_entity_type_id' => Component::ENTITY_TYPE_ID], [], 'GET', []],
      'INDEX js components' => ['canvas.api.config.list', ['canvas_config_entity_type_id' => JavaScriptComponent::ENTITY_TYPE_ID], [], 'GET', []],
      'GET js component' => ['canvas.api.config.get', ['canvas_config_entity_type_id' => JavaScriptComponent::ENTITY_TYPE_ID, 'canvas_config_entity' => 'test-code-component'], [], 'GET', []],
      'GET asset library' => ['canvas.api.config.get', ['canvas_config_entity_type_id' => AssetLibrary::ENTITY_TYPE_ID, 'canvas_config_entity' => AssetLibrary::GLOBAL_ID], [], 'GET', []],
      'GET brand kit' => ['canvas.api.config.get', ['canvas_config_entity_type_id' => BrandKit::ENTITY_TYPE_ID, 'canvas_config_entity' => BrandKit::GLOBAL_ID], [], 'GET', []],
      'POST js component' => [
        'canvas.api.config.post',
        ['canvas_config_entity_type_id' => JavaScriptComponent::ENTITY_TYPE_ID],
        ['administer code components'],
        'POST',
        [
          'machineName' => 'new-test-code-component',
          'name' => 'New test code component',
          'status' => FALSE,
          'sourceCodeJs' => '// JS source',
          'sourceCodeCss' => '/* CSS source */',
          'compiledJs' => '// Compiled JS',
          'compiledCss' => '/* Compiled CSS */',
          'importedJsComponents' => [],
          'dataDependencies' => [],
        ],
      ],
      'PATCH js component' => [
        'canvas.api.config.patch',
        ['canvas_config_entity_type_id' => JavaScriptComponent::ENTITY_TYPE_ID, 'canvas_config_entity' => 'test-code-component'],
        ['administer code components'],
        'PATCH',
        [
          'name' => 'Updated test code component',
        ],
      ],
      'PATCH asset library' => [
        'canvas.api.config.patch',
        ['canvas_config_entity_type_id' => AssetLibrary::ENTITY_TYPE_ID, 'canvas_config_entity' => AssetLibrary::GLOBAL_ID],
        ['administer code components'],
        'PATCH',
        [
          'js' => [
            'original' => '// Updated JS',
            'compiled' => '// Updated compiled JS',
          ],
        ],
      ],
      'PATCH brand kit' => [
        'canvas.api.config.patch',
        ['canvas_config_entity_type_id' => BrandKit::ENTITY_TYPE_ID, 'canvas_config_entity' => BrandKit::GLOBAL_ID],
        ['administer brand kit'],
        'PATCH',
        ['label' => 'Global brand kit'],
      ],
      'DELETE js component' => [
        'canvas.api.config.delete',
        ['canvas_config_entity_type_id' => JavaScriptComponent::ENTITY_TYPE_ID, 'canvas_config_entity' => 'test-code-component'],
        ['administer code components'],
        'DELETE',
        [],
      ],
      'INDEX pages' => [
        'canvas.api.content.list',
        ['entity_type' => Page::ENTITY_TYPE_ID],
        [Page::EDIT_PERMISSION],
        'GET',
        [],
      ],
      'GET page' => [
        'canvas.api.content.get',
        [Page::ENTITY_TYPE_ID => 1],
        ['access content'],
        'GET',
        [],
      ],
      'PATCH page' => [
        'canvas.api.content.patch',
        [Page::ENTITY_TYPE_ID => 1],
        [Page::EDIT_PERMISSION],
        'PATCH',
        [
          'title' => 'Edited page',
          'status' => TRUE,
          'path' => '/edited-path',
          'components' => [
            [
              'uuid' => '09365c2d-1ee1-47fd-b5a3-7e4f34866186',
              'component_version' => '36a8cee6a86c3d8d',
              'component_id' => 'js.test-code-component',
              'inputs' => [
                'heading' => 'Welcome',
                'content' => '',
              ],
            ],
          ],
        ],
      ],
      'DELETE page' => [
        'canvas.api.content.delete',
        [Page::ENTITY_TYPE_ID => 1],
        [Page::DELETE_PERMISSION],
        'DELETE',
        [],
      ],
    ];
  }

  /**
   * Tests a route with a user with no permissions.
   *
   * This verifies that cookie-based authentication keeps working as expected
   * when the request doesn't contain an OAuth2 access token.
   */
  #[DataProvider('dataProviderRoutes')]
  public function testRouteWithUserWithNoPermissions(string $route_name, array $parameters, array $required_permissions, string $method, array $data): void {
    // Create a user with the minimum permissions: we use Page:CREATE_PERMISSION
    // for allowing `$user` to use Canvas, but not altering the
    // `$required_permissions` argument.
    /** @var \Drupal\Core\Session\AccountInterface $user */
    // @phpstan-ignore-next-line varTag.nativeType
    $user = $this->createUser([Page::CREATE_PERMISSION]);
    $this->setCurrentUser($user);
    $request = $this->createRequest($route_name, $parameters, $method, $data);
    if (!empty($required_permissions) && !(count($required_permissions) === 1 && $required_permissions[0] === 'access content')) {
      // Expect an exception because the user has no permissions.
      $exception_class = $method === 'GET' ? CacheableAccessDeniedHttpException::class : AccessDeniedHttpException::class;
      $this->expectException($exception_class);
      $this->expectExceptionMessage(\sprintf("The '%s' permission is required.", $required_permissions[0]));
    }
    $response = $this->request($request);
    if (empty($required_permissions)) {
      self::assertTrue($response->isSuccessful());
    }
  }

  /**
   * Tests a route with a user with appropriate permissions.
   *
   * This verifies that cookie-based authentication keeps working as expected
   * when the request doesn't contain an OAuth2 access token.
   */
  #[DataProvider('dataProviderRoutes')]
  public function testRouteWithUserWithPermissions(string $route_name, array $parameters, array $required_permissions, string $method, array $data): void {
    /** @var \Drupal\Core\Session\AccountInterface $user */
    // We need some Canvas-enabled content permission in every case for accessing
    // Canvas URLs.
    // @phpstan-ignore-next-line varTag.nativeType
    $user = $this->createUser([Page::CREATE_PERMISSION, ...$required_permissions]);
    $this->setCurrentUser($user);
    $request = $this->createRequest($route_name, $parameters, $method, $data);
    $response = $this->request($request);
    self::assertTrue($response->isSuccessful());
  }

  /**
   * Tests a route with an invalid access token.
   */
  #[DataProvider('dataProviderRoutes')]
  public function testRouteWithInvalidToken(string $route_name, array $parameters, array $required_permissions, string $method, array $data): void {
    $request = $this->createRequest($route_name, $parameters, $method, $data);
    $this->expectException(OAuthUnauthorizedHttpException::class);
    $this->expectExceptionMessage("The resource owner or authorization server denied the request");
    // Set an invalid access token.
    $request->headers->set('Authorization', 'Bearer wicked-witch-of-the-west');
    $this->request($request);
  }

  /**
   * Data provider for testing uncovered routes with authenticated HTTP requests.
   *
   * It's enough to test with one config entity type that's not covered. The goal
   * of this test is to verify that the authentication provider is NOT applied
   * to an API endpoint unless we allow it. The logic that evaluates this is
   * being directly tested with more test cases in
   * `\Drupal\Tests\canvas_oauth\Kernel\CanvasOauthAuthenticationProviderTest::testAppliesToRoutedRequest`.
   *
   * @return array<string, array{
   *   0: string,
   *   1: array<string, mixed>,
   *   2: array<string>,
   *   3: string,
   *   4: array<string, mixed>
   *   }> Array of test cases where:
   *   - Index 0: Route name
   *   - Index 1: Route parameters
   *   - Index 2: Required permissions
   *   - Index 3: HTTP method
   *   - Index 4: Request body data for POST/PATCH
   */
  public static function dataProviderRoutesNotCovered(): array {
    return [
      'INDEX patterns' => ['canvas.api.config.list', ['canvas_config_entity_type_id' => Pattern::ENTITY_TYPE_ID], [], 'GET', []],
      'GET pattern' => ['canvas.api.config.get', ['canvas_config_entity_type_id' => Pattern::ENTITY_TYPE_ID, 'canvas_config_entity' => 'test-pattern'], [], 'GET', []],
      'POST pattern' => [
        'canvas.api.config.post',
        ['canvas_config_entity_type_id' => Pattern::ENTITY_TYPE_ID],
        ['administer patterns'],
        'POST',
        [],
      ],
      'PATCH pattern' => [
        'canvas.api.config.patch',
        ['canvas_config_entity_type_id' => Pattern::ENTITY_TYPE_ID, 'canvas_config_entity' => 'test-pattern'],
        ['administer patterns'],
        'PATCH',
        [],
      ],
      'DELETE pattern' => [
        'canvas.api.config.delete',
        ['canvas_config_entity_type_id' => Pattern::ENTITY_TYPE_ID, 'canvas_config_entity' => 'test-pattern'],
        ['administer patterns'],
        'DELETE',
        [],
      ],
    ];
  }

  /**
   * Tests a route that is not covered by this module's auth provider.
   */
  #[DataProvider('dataProviderRoutesNotCovered')]
  public function testNotCoveredRoute(string $route_name, array $parameters, array $required_permissions, string $method, array $data): void {
    // Request an access token for scopes that get created with the required
    // permissions.
    // In case no permissions are required, we still need to pass a permission
    // for a scope to be created, and some Canvas-enabled content permission for
    // accessing any Canvas URL.
    $access_token = $this->requestAccessToken([Page::CREATE_PERMISSION, ...$required_permissions]);
    $request = $this->createRequest($route_name, $parameters, $method, $data);
    $request->headers->set('Authorization', 'Bearer ' . $access_token);
    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('The used authentication method is not allowed on this route.');
    $this->request($request);
  }

  /**
   * Creates a request for the given route.
   *
   * @param string $route_name
   *   The route name.
   * @param array $parameters
   *   The parameters for the request.
   * @param string $method
   *   The HTTP method.
   * @param array $data
   *   The data to send in the request body.
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   The request.
   */
  private function createRequest(string $route_name, array $parameters, string $method, array $data): Request {
    $request = Request::create(
      Url::fromRoute($route_name, $parameters)->toString(),
      $method,
      content: json_encode($data) ?: NULL,
    );
    if (\in_array($method, ['POST', 'PATCH'], TRUE)) {
      $request->headers->set('Content-Type', 'application/json');
    }
    return $request;
  }

  /**
   * Requests OAuth2 access token with scopes created for the given permissions.
   *
   * @param array $permissions
   *   The required permissions. For each permission a scope is created, and the
   *   access token is requested for these scopes.
   *
   * @return string
   *   The access token.
   */
  private function requestAccessToken(array $permissions): string {
    $scopes = $this->createScopes($permissions);
    $client = $this->createClient($scopes);
    $parameters = [
      'grant_type' => 'client_credentials',
      'client_id' => $client->getClientId(),
      'client_secret' => $this->clientSecret,
      // The `scope` parameter is a space-separated list of scope names.
      'scope' => implode(' ', \array_map(fn($scope) => $scope->getName(), $scopes)),
    ];
    $request = Request::create($this->url->toString(), 'POST', $parameters);
    $response = $this->request($request);
    $parsed_response = $this->assertValidTokenResponse($response);
    return $parsed_response['access_token'];
  }

  /**
   * Creates OAuth2 scopes for the given permissions.
   *
   * @param array $permissions
   *   The permissions. For each permission a scope is created where the
   *   permission is configured as the scope's permission.
   *
   * @return array
   *   The scopes.
   */
  private function createScopes(array $permissions): array {
    $scopes = [];
    foreach ($permissions as $index => $permission) {
      $scope = Oauth2Scope::create([
        'name' => 'canvas:scope' . ($index + 1),
        'grant_types' => [
          'client_credentials' => [
            'status' => TRUE,
          ],
        ],
        'umbrella' => FALSE,
        'granularity_id' => Oauth2ScopeInterface::GRANULARITY_PERMISSION,
        'granularity_configuration' => [
          'permission' => $permission,
        ],
      ]);
      $scope->save();
      $scopes[] = $scope;
    }
    return $scopes;
  }

  /**
   * Creates an OAuth2 client with the given scopes enabled for the client.
   *
   * The client is configured with the client credentials grant type enabled.
   *
   * @param array $scopes
   *   The scopes.
   *
   * @return \Drupal\consumers\Entity\Consumer
   *   The client.
   */
  private function createClient(array $scopes): Consumer {
    $client = Consumer::create([
      'client_id' => 'canvas_oauth_client',
      'is_default' => FALSE,
      'label' => 'Canvas OAuth Client',
      'grant_types' => [
        'client_credentials',
      ],
      'scopes' => \array_map(fn($scope) => $scope->id(), $scopes),
      'secret' => $this->clientSecret,
      'user_id' => $this->user,
    ]);
    $client->save();
    return $client;
  }

  /**
   * Creates a media upload request.
   */
  private function createMediaUploadRequest(): Request {
    $request = Request::create(
      Url::fromRoute('canvas.api.media.upload', ['media_type' => 'image'])->toString(),
      'POST',
      parameters: [],
      files: [
        'file' => new UploadedFile($this->testImagePath, 'gracie-big.jpg', 'image/jpeg', NULL, test: TRUE),
      ],
      server: ['CONTENT_TYPE' => 'multipart/form-data'],
    );
    return $request;
  }

  /**
   * Tests media upload route with an invalid access token.
   */
  public function testMediaUploadRouteWithInvalidToken(): void {
    $this->setUpMediaUpload();
    $request = $this->createMediaUploadRequest();
    $request->headers->set('Authorization', 'Bearer wicked-witch-of-the-west');
    $this->expectException(OAuthUnauthorizedHttpException::class);
    $this->expectExceptionMessage('The resource owner or authorization server denied the request');
    $this->request($request);
  }

  /**
   * Tests media upload route with a valid access token and permissions.
   */
  public function testMediaUploadRouteWithValidToken(): void {
    $this->setUpMediaUpload();
    $access_token = $this->requestAccessToken([Page::CREATE_PERMISSION, 'create image media']);
    $request = $this->createMediaUploadRequest();
    $request->headers->set('Authorization', 'Bearer ' . $access_token);
    $response = $this->request($request);
    self::assertTrue($response->isSuccessful());
  }

}
