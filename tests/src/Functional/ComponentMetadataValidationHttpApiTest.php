<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\Core\Url;
use Drupal\user\UserInterface;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests target-aware Code Component metadata validation.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
class ComponentMetadataValidationHttpApiTest extends HttpApiTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['canvas'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  protected UserInterface $codeComponentAdmin;

  protected UserInterface $limitedUser;

  protected function setUp(): void {
    parent::setUp();
    $admin = $this->createUser([JavaScriptComponent::ADMIN_PERMISSION]);
    $limited = $this->createUser(['access content']);
    \assert($admin instanceof UserInterface);
    \assert($limited instanceof UserInterface);
    $this->codeComponentAdmin = $admin;
    $this->limitedUser = $limited;
  }

  /**
   * Tests authoritative validation without saving.
   */
  public function testValidationDoesNotSave(): void {
    $url = Url::fromUri('base:/canvas/api/v0/code-components/validate');

    $response = $this->makeApiRequest('POST', $url, []);
    $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());

    $this->drupalLogin($this->limitedUser);
    $response = $this->makeApiRequest('POST', $url, [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $this->drupalGet('session/token'),
      ],
      RequestOptions::JSON => self::validPayload('limited'),
    ]);
    $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());

    $this->drupalLogin($this->codeComponentAdmin);
    $payload = self::validPayload('new_component');
    $response = $this->makeApiRequest('POST', $url, [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $this->drupalGet('session/token'),
      ],
      RequestOptions::JSON => $payload,
    ]);
    $this->assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), (string) $response->getBody());
    $this->assertNull(JavaScriptComponent::load('new_component'));

    $stored_payload = self::validPayload('stored_component');
    $stored_payload['props'] = [];
    $stored_payload['slots'] = [];
    $stored_payload['dataDependencies'] = [];
    $stored = JavaScriptComponent::createFromClientSide($stored_payload);
    $stored->save();
    $payload = self::validPayload('stored_component');
    $payload['name'] = 'Changed only in memory';
    $response = $this->makeApiRequest('POST', $url, [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $this->drupalGet('session/token'),
      ],
      RequestOptions::JSON => $payload,
    ]);
    $this->assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), (string) $response->getBody());
    $unchanged = JavaScriptComponent::load('stored_component');
    $this->assertInstanceOf(JavaScriptComponent::class, $unchanged);
    $this->assertSame('stored_component', $unchanged->label());

    $payload['props'] = [
      'invalid' => [
        'title' => 'Invalid',
        'type' => 'unsupported',
      ],
    ];
    $response = $this->makeApiRequest('POST', $url, [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $this->drupalGet('session/token'),
      ],
      RequestOptions::JSON => $payload,
    ]);
    $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    $body = json_decode((string) $response->getBody(), associative: TRUE, flags: JSON_THROW_ON_ERROR);
    $this->assertArrayHasKey('errors', $body);

    $payload = self::validPayload('target_specific_rules');
    $payload['props'] = [
      'text' => [
        'title' => 'Text',
        'type' => 'string',
        'examples' => [''],
      ],
      'image' => [
        'title' => 'Image',
        'type' => 'object',
        '$ref' => 'json-schema-definitions://canvas.module/image',
        'examples' => [
          ['src' => '/images/example.png'],
        ],
      ],
    ];
    $response = $this->makeApiRequest('POST', $url, [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $this->drupalGet('session/token'),
      ],
      RequestOptions::JSON => $payload,
    ]);
    $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    $this->assertStringContainsString('cannot be used as a default', (string) $response->getBody());
    $this->assertStringContainsString('must be a fully-qualified URL', (string) $response->getBody());

    $payload = self::validPayload('missing_extension_reference');
    $payload['props'] = [
      'extension' => [
        'title' => 'Extension value',
        'type' => 'object',
        '$ref' => 'json-schema-definitions://missing.module/value',
      ],
    ];
    $response = $this->makeApiRequest('POST', $url, [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $this->drupalGet('session/token'),
      ],
      RequestOptions::JSON => $payload,
    ]);
    $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    $body = json_decode((string) $response->getBody(), associative: TRUE, flags: JSON_THROW_ON_ERROR);
    $this->assertSame('JSON Schema reference "json-schema-definitions://missing.module/value" could not be resolved on the target site.', $body['errors'][0]['detail']);
    $this->assertNull(JavaScriptComponent::load('missing_extension_reference'));

    $unchanged = JavaScriptComponent::load('stored_component');
    $this->assertInstanceOf(JavaScriptComponent::class, $unchanged);
    $this->assertSame('stored_component', $unchanged->label());
  }

  /**
   * Creates a complete normalized API payload.
   */
  private static function validPayload(string $machine_name): array {
    return [
      'machineName' => $machine_name,
      'name' => $machine_name,
      'status' => TRUE,
      'required' => [],
      'props' => new \stdClass(),
      'slots' => new \stdClass(),
      'sourceCodeJs' => '',
      'sourceCodeCss' => '',
      'compiledJs' => '',
      'compiledCss' => '',
      'importedJsComponents' => [],
      'dataDependencies' => new \stdClass(),
    ];
  }

}
