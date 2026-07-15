<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Kernel;

use Drupal\canvas_headless\PreviewAssertionFactory;
use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests the assertion minting and standalone renewal endpoints.
 *
 * Note this cannot use CanvasKernelTestBase because the endpoints under
 * test are independent of the canvas module: booting the full Canvas stack
 * would only add overhead without exercising anything these routes touch.
 * The canvas module itself must still be installed: the container cannot
 * compile without it because canvas_headless's ExternalComponentSync
 * service references canvas services.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas_headless')]
class AssertionControllerTest extends KernelTestBase {

  use RequestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'field',
    'node',
    'serialization',
    'consumers',
    'simple_oauth',
    'canvas_headless',
  ];

  /**
   * A user holding the preview permission.
   */
  protected UserInterface $editor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    // The consumers module queries for the default consumer on every
    // request.
    $this->installEntitySchema('consumer');
    $this->installSchema('node', ['node_access']);

    // Generate a signing keypair for Simple OAuth: the minting endpoints
    // sign assertions with it.
    $dir = $this->siteDirectory . '/keys';
    mkdir($dir, 0777, TRUE);
    $resource = openssl_pkey_new([
      'private_key_bits' => 2048,
      'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    $this->assertNotFalse($resource);
    openssl_pkey_export($resource, $private_key);
    $details = openssl_pkey_get_details($resource);
    $this->assertNotFalse($details);
    file_put_contents($dir . '/private.key', $private_key);
    file_put_contents($dir . '/public.key', $details['key']);
    $this->config('simple_oauth.settings')
      ->set('private_key', $dir . '/private.key')
      ->set('public_key', $dir . '/public.key')
      ->save();

    $this->config('canvas_headless.settings')
      ->set('frontends', [['url' => 'http://localhost:3000']])
      ->set('assertion_expiration', 60)
      ->save();
    $this->config('system.site')
      ->set('uuid', 'c7f2e9a4-3b1d-4e8f-9a6c-5d0b2f8e1a37')
      ->save();

    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();

    // Burn uid 1, which bypasses all permission checks.
    $this->createUser();
    $editor = $this->createUser([
      'access canvas headless preview',
      'access content',
    ]);
    \assert($editor instanceof UserInterface);
    $this->editor = $editor;
  }

  /**
   * Tests that anonymous users cannot mint assertions.
   */
  public function testMintIsDeniedForAnonymous(): void {
    $this->expectException(AccessDeniedHttpException::class);
    $this->request(self::mintRequest(['path' => '/some-path']));
  }

  /**
   * Tests that the preview permission gates minting.
   */
  public function testMintIsDeniedWithoutPermission(): void {
    $no_permission_user = $this->createUser(['access content']);
    \assert($no_permission_user instanceof UserInterface);
    $this->setCurrentUser($no_permission_user);
    $this->expectException(AccessDeniedHttpException::class);
    $this->request(self::mintRequest(['path' => '/some-path']));
  }

  /**
   * Tests minting for an explicit entry path (the renewal shape).
   */
  public function testMintForPath(): void {
    $this->setCurrentUser($this->editor);
    $response = $this->request(self::mintRequest(['path' => '/some-path']));

    self::assertSame(200, $response->getStatusCode());
    $claims = self::decodeAssertion($response->getContent());
    self::assertSame('/some-path', $claims->claims()->get('path'));
    self::assertSame((string) $this->editor->id(), $claims->claims()->get('sub'));
    self::assertSame(PreviewAssertionFactory::TYP_HEADER, $claims->headers()->get('typ'));
  }

  /**
   * Tests the renewal marking of minted assertions.
   *
   * The host's in-place renewal lane mints with renewal=1; those assertions
   * are relayed through the app's script context and the grant demands PKCE
   * session proof to redeem them. Everything else — activation and the
   * recovery lane included — mints activation assertions.
   */
  public function testMintRenewalMarking(): void {
    $this->setCurrentUser($this->editor);

    $response = $this->request(self::mintRequest(['path' => '/some-path']));
    self::assertSame('activation', self::decodeAssertion($response->getContent())->claims()->get('use'));

    $response = $this->request(self::mintRequest(['path' => '/some-path', 'renewal' => '1']));
    self::assertSame('renewal', self::decodeAssertion($response->getContent())->claims()->get('use'));
  }

  /**
   * Tests minting for an entity (the activation shape).
   *
   * The Canvas editor only knows what entity it is editing; the endpoint
   * resolves — and access-checks — the entity's canonical path server-side.
   */
  public function testMintForEntity(): void {
    $node = Node::create([
      'type' => 'article',
      'title' => 'Published article',
      'uid' => $this->editor->id(),
      'status' => TRUE,
    ]);
    $node->save();

    $this->setCurrentUser($this->editor);
    $response = $this->request(self::mintRequest([
      'entity_type' => 'node',
      'entity' => (string) $node->id(),
    ]));

    self::assertSame(200, $response->getStatusCode());
    $claims = self::decodeAssertion($response->getContent());
    self::assertSame('/node/' . $node->id(), $claims->claims()->get('path'));
  }

  /**
   * Tests that entity resolution refuses unknown entity types.
   */
  public function testMintRejectsUnknownEntityType(): void {
    $this->setCurrentUser($this->editor);
    $this->expectException(BadRequestHttpException::class);
    $this->expectExceptionMessage('Unknown entity type.');
    $this->request(self::mintRequest(['entity_type' => 'no_such_type', 'entity' => '1']));
  }

  /**
   * Tests that entity resolution refuses nonexistent entities.
   */
  public function testMintRejectsMissingEntity(): void {
    $this->setCurrentUser($this->editor);
    $this->expectException(NotFoundHttpException::class);
    $this->expectExceptionMessage('The entity does not exist.');
    $this->request(self::mintRequest(['entity_type' => 'node', 'entity' => '999']));
  }

  /**
   * Tests that entity resolution enforces view access.
   */
  public function testMintRejectsEntityWithoutViewAccess(): void {
    // An unpublished node owned by someone else: the editor holds neither
    // "view any unpublished content" nor ownership, so view access fails.
    $other_user = $this->createUser();
    \assert($other_user instanceof UserInterface);
    $node = Node::create([
      'type' => 'article',
      'title' => 'Unpublished article',
      'uid' => $other_user->id(),
      'status' => FALSE,
    ]);
    $node->save();

    $this->setCurrentUser($this->editor);
    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('The entity may not be viewed.');
    $this->request(self::mintRequest([
      'entity_type' => 'node',
      'entity' => (string) $node->id(),
    ]));
  }

  /**
   * Tests that the entry path must be a relative path.
   *
   * An absolute or protocol-relative URL would turn the standalone renewal
   * redirect into an open redirect; the same validation guards minting.
   */
  #[DataProvider('providerInvalidPaths')]
  public function testMintRejectsInvalidPaths(string $path): void {
    $this->setCurrentUser($this->editor);
    $this->expectException(BadRequestHttpException::class);
    $this->expectExceptionMessage('The path query parameter must be a relative path.');
    $this->request(self::mintRequest(['path' => $path]));
  }

  /**
   * Data provider of paths that must be refused.
   *
   * @return array<string, array{string}>
   *   Invalid path cases.
   */
  public static function providerInvalidPaths(): array {
    return [
      'empty' => [''],
      'not absolute' => ['some-path'],
      'protocol-relative URL' => ['//evil.example.com'],
      'backslash' => ['/some\\path'],
    ];
  }

  /**
   * Tests that the standalone renewal route redirects back into the app.
   */
  public function testRenewRedirectsIntoTheApp(): void {
    $this->setCurrentUser($this->editor);
    $url = Url::fromRoute('canvas_headless.renew', [], ['query' => ['path' => '/some-path']])->toString();
    $response = $this->request(Request::create($url));

    self::assertTrue($response->isRedirection());
    $location = (string) $response->headers->get('Location');
    self::assertStringStartsWith('http://localhost:3000/api/draft?assertion=', $location);

    // The redirect carries a real assertion whose session enters at the
    // requested path.
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    self::assertIsString($query['assertion']);
    $claims = self::decodeAssertion($query['assertion']);
    self::assertSame('/some-path', $claims->claims()->get('path'));
  }

  /**
   * Tests that the renewal route requires the preview permission.
   */
  public function testRenewIsDeniedForAnonymous(): void {
    $url = Url::fromRoute('canvas_headless.renew', [], ['query' => ['path' => '/some-path']])->toString();
    $this->expectException(AccessDeniedHttpException::class);
    $this->request(Request::create($url));
  }

  /**
   * Tests that the renewal route refuses non-relative paths.
   */
  public function testRenewRejectsInvalidPaths(): void {
    $this->setCurrentUser($this->editor);
    $url = Url::fromRoute('canvas_headless.renew', [], ['query' => ['path' => '//evil.example.com']])->toString();
    $this->expectException(BadRequestHttpException::class);
    $this->expectExceptionMessage('The path query parameter must be a relative path.');
    $this->request(Request::create($url));
  }

  /**
   * Builds a POST request to the assertion minting endpoint.
   *
   * @param array<string, string> $query
   *   Query parameters identifying the session entry point.
   */
  private static function mintRequest(array $query): Request {
    $url = Url::fromRoute('canvas_headless.assertion', [], ['query' => $query])->toString();
    return Request::create($url, 'POST');
  }

  /**
   * Parses the JSON response body (or a raw JWT) into an assertion token.
   *
   * @param string|false $content
   *   A response body containing {"assertion": …}, or a serialized JWT.
   */
  private static function decodeAssertion(string|false $content): UnencryptedToken {
    self::assertIsString($content);
    $assertion = $content;
    if (str_starts_with($content, '{')) {
      $body = \json_decode($content, TRUE);
      self::assertIsArray($body);
      self::assertIsString($body['assertion']);
      $assertion = $body['assertion'];
    }
    \assert($assertion !== '');
    $token = (new Parser(new JoseEncoder()))->parse($assertion);
    \assert($token instanceof UnencryptedToken);
    return $token;
  }

}
