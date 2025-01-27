<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Functional;

use Drupal\Core\Url;
use Drupal\Tests\ApiRequestTrait;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use GuzzleHttp\RequestOptions;

/**
 * @covers \Drupal\experience_builder\Controller\ApiContentCreateXbPage
 * @group experience_builder
 * @internal
 */
final class XbContentEntityHttpApiTest extends BrowserTestBase {

  use ApiRequestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'experience_builder',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  public function test(): void {
    $url = Url::fromUri('base:/xb/api/content-create/xb_page');
    $request_options = [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
      ],
    ];

    // Authenticated but unauthorized: 403 due to missing CSRF token.
    $user = $this->createUser([]);
    assert($user instanceof UserInterface);
    $this->drupalLogin($user);
    $response = $this->makeApiRequest('POST', $url, $request_options);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame(
      ['message' => 'X-CSRF-Token request header is missing'],
      json_decode((string) $response->getBody(), TRUE)
    );

    // Authenticated but unauthorized: 403 due to missing permission.
    $request_options['headers']['X-CSRF-Token'] = $this->drupalGet('session/token');
    $response = $this->makeApiRequest('POST', $url, $request_options);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame(
      ['message' => "The 'administer xb_page' permission is required."],
      json_decode((string) $response->getBody(), TRUE)
    );

    // Authenticated, authorized, with CSRF token: 201.
    // @phpstan-ignore-next-line
    Role::load('authenticated')->grantPermission('administer xb_page')->save();
    $response = $this->makeApiRequest('POST', $url, $request_options);
    $this->assertSame(201, $response->getStatusCode());
    $this->assertSame(
      '{"entity_type":"xb_page","entity_id":"1"}',
      (string) $response->getBody()
    );
  }

}
