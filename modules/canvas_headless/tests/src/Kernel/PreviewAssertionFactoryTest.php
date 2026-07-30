<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Kernel;

use Drupal\canvas_headless\PreviewAssertionFactory;
use Drupal\canvas_headless\PreviewAssertionFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the preview assertion factory's claims.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas_headless')]
class PreviewAssertionFactoryTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'language',
    'serialization',
    'consumers',
    'simple_oauth',
    'custom_elements',
    'canvas_headless',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // URL generation runs the path alias processor.
    $this->installEntitySchema('path_alias');

    // Generate a signing keypair for Simple OAuth.
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
    $public_key = $details['key'];
    file_put_contents($dir . '/private.key', $private_key);
    file_put_contents($dir . '/public.key', $public_key);
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
  }

  /**
   * Tests the minted assertion's header and claims.
   */
  public function testIssuedClaims(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(42);

    $jwt = $this->container->get(PreviewAssertionFactoryInterface::class)
      ->issue($account, '/home', 'rel:working-copy');
    $this->assertNotSame('', $jwt);

    $token = (new Parser(new JoseEncoder()))->parse($jwt);
    \assert($token instanceof UnencryptedToken);
    $claims = $token->claims();

    $this->assertSame(PreviewAssertionFactory::TYP_HEADER, $token->headers()->get('typ'));
    $this->assertSame('canvas-headless-preview-assertion+jwt', $token->headers()->get('typ'));
    $this->assertSame('RS256', $token->headers()->get('alg'));
    $this->assertSame('c7f2e9a4-3b1d-4e8f-9a6c-5d0b2f8e1a37', $claims->get('iss'));
    $this->assertContains('/oauth/token', $claims->get('aud'));
    $this->assertSame('42', $claims->get('sub'));
    $this->assertSame(PreviewAssertionFactory::CLIENT_ID, $claims->get('azp'));
    $this->assertSame('canvas_headless', $claims->get('azp'));
    $this->assertSame('/home', $claims->get('path'));
    $this->assertSame('activation', $claims->get('use'));
    $this->assertSame('rel:working-copy', $claims->get('resourceVersion'));
    $this->assertStringEndsWith('/canvas-headless/renew', $claims->get('renewUrl'));
    $this->assertNotEmpty($claims->get('jti'));

    // The lifetime equals the configured assertion expiration.
    $issued_at = $claims->get('iat');
    $expires_at = $claims->get('exp');
    $this->assertSame(60, $expires_at->getTimestamp() - $issued_at->getTimestamp());
  }

  /**
   * Tests the "use" claim's renewal marking.
   */
  public function testRenewalMarking(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(42);

    $jwt = $this->container->get(PreviewAssertionFactoryInterface::class)
      ->issue($account, '/home', 'rel:working-copy', TRUE);
    \assert($jwt !== '');
    $token = (new Parser(new JoseEncoder()))->parse($jwt);
    \assert($token instanceof UnencryptedToken);
    $this->assertSame('renewal', $token->claims()->get('use'));
  }

  /**
   * Tests that the audience is derived in the site default language.
   *
   * The assertion is minted during the editor's browser request and redeemed
   * at the token endpoint in a separate request; the grant recomputes the
   * audience and compares it by strict equality. Deriving it in a
   * request-language-dependent way let a language URL prefix leak into the
   * minted "aud" that the redemption context never reproduced, so every
   * exchange under a prefixed language failed. Both sides now derive it
   * through tokenEndpointAudience(), which pins the site default language so
   * the value never varies with the request. (The prefixed-request scenario
   * itself needs URL negotiation a kernel test cannot drive; this pins the
   * language-neutral derivation the fix relies on.)
   */
  public function testAudienceIsDerivedInDefaultLanguage(): void {
    ConfigurableLanguage::createFromLangcode('de')->save();
    $language_manager = $this->container->get(LanguageManagerInterface::class);
    self::assertSame('en', $language_manager->getDefaultLanguage()->getId());

    $expected = Url::fromRoute('oauth2_token.token', [], [
      'language' => $language_manager->getDefaultLanguage(),
    ])->toString();
    self::assertSame($expected, PreviewAssertionFactory::tokenEndpointAudience($language_manager));

    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(42);
    $jwt = $this->container->get(PreviewAssertionFactoryInterface::class)
      ->issue($account, '/home', 'rel:working-copy');
    self::assertNotSame('', $jwt);
    $token = (new Parser(new JoseEncoder()))->parse($jwt);
    \assert($token instanceof UnencryptedToken);
    self::assertContains($expected, $token->claims()->get('aud'));
  }

  /**
   * Tests that every assertion carries a unique jti.
   */
  public function testJtiIsUnique(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(42);
    $factory = $this->container->get(PreviewAssertionFactoryInterface::class);

    $first_jwt = $factory->issue($account, '/home', 'rel:working-copy');
    $second_jwt = $factory->issue($account, '/home', 'rel:working-copy');
    $this->assertNotSame('', $first_jwt);
    $this->assertNotSame('', $second_jwt);

    $parser = new Parser(new JoseEncoder());
    $first = $parser->parse($first_jwt);
    $second = $parser->parse($second_jwt);
    \assert($first instanceof UnencryptedToken && $second instanceof UnencryptedToken);
    $this->assertNotSame($first->claims()->get('jti'), $second->claims()->get('jti'));
  }

}
