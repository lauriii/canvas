<?php

declare(strict_types=1);

namespace Drupal\canvas_headless;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Lcobucci\JWT\Builder;
use Lcobucci\JWT\JwtFacade;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;

/**
 * Mints signed preview assertions (RFC 7523 JWTs).
 *
 * The assertion is the authorization grant of the preview flow: it asserts
 * that a specific editor, authenticated in Drupal, initiated a preview of a
 * specific path moments ago. The frontend app exchanges it at /oauth/token
 * (grant_type urn:ietf:params:oauth:grant-type:jwt-bearer) for an access
 * token bound to that editor — see
 * \Drupal\canvas_headless\Grant\PreviewAssertionGrant.
 *
 * Signed RS256 with the same keypair Simple OAuth uses for access tokens.
 * The "typ" header keeps the two token species mutually unmixable: the
 * grant rejects anything but "canvas-headless-preview-assertion+jwt", and
 * the resource server's JWT parsing never yields a valid access token from
 * an assertion.
 *
 * Binding claims, chosen to survive multi-origin topologies (a browser may
 * reach Drupal over one URL while the app's server calls another, so
 * absolute-URL claims would be mint/redeem-asymmetric): "iss" is the site
 * UUID — unique per installation and origin-independent, it refuses
 * assertions from a separately installed site even when that site signs
 * with this site's keypair. It does not fence clones of one installation
 * (clones keep the UUID; config sync requires it) — distinct
 * per-environment signing keypairs do. "azp" binds the assertion to the
 * consumer it was minted for; "aud" is the token endpoint as a path,
 * binding purpose.
 */
class PreviewAssertionFactory implements PreviewAssertionFactoryInterface {

  /**
   * The "typ" header value that marks a JWT as a preview assertion.
   */
  public const TYP_HEADER = 'canvas-headless-preview-assertion+jwt';

  /**
   * The client_id of the consumer the module provisions.
   *
   * Minted into every assertion as the "azp" (authorized party) claim and
   * verified by the grant against the requesting client, so an assertion
   * can only ever be redeemed by the client it was minted for.
   */
  public const CLIENT_ID = 'canvas_headless';

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected FileSystemInterface $fileSystem,
    protected UuidInterface $uuid,
    protected LanguageManagerInterface $languageManager,
  ) {}

  /**
   * The token endpoint audience an assertion is minted for and validated at.
   *
   * Generated in the site default language so the value does not depend on
   * the request language. An assertion is minted during the editor's browser
   * request — which may carry a language URL prefix — and redeemed at the
   * token endpoint in a separate request; the grant compares the minted "aud"
   * against its own recomputed value by strict equality, so both sides must
   * derive the same string regardless of the language either request runs in.
   *
   * @return non-empty-string
   *   The token endpoint audience.
   */
  public static function tokenEndpointAudience(LanguageManagerInterface $language_manager): string {
    $audience = Url::fromRoute('oauth2_token.token', [], [
      'language' => $language_manager->getDefaultLanguage(),
    ])->toString();
    \assert($audience !== '');
    return $audience;
  }

  /**
   * {@inheritdoc}
   */
  public function issue(AccountInterface $user, string $path, string $resource_version, bool $renewal = FALSE, array $preview_context = []): string {
    $expiration = (int) $this->configFactory->get('canvas_headless.settings')->get('assertion_expiration');
    $audience = self::tokenEndpointAudience($this->languageManager);
    // The absolute URL of the standalone renewal route, as seen by the
    // *editor's browser*: assertions are minted during a browser request, so
    // the current request's scheme and host are exactly the origin the
    // browser reaches Drupal on — even in multi-origin topologies where the
    // app's server-side calls use a different one. Carried as a signed
    // claim, it needs no frontend configuration and cannot be tampered with.
    $renew_url = Url::fromRoute('canvas_headless.renew', [], ['absolute' => TRUE])->toString();
    // The site UUID, not a URL: unique per installation and independent of
    // the request origin, it survives multi-origin topologies and fences
    // separately installed sites that share a keypair. Clones of one
    // installation keep the UUID — those are fenced by per-environment
    // keypairs, not by this claim. The grant validates it strictly.
    $issuer = (string) $this->configFactory->get('system.site')->get('uuid');
    $jti = $this->uuid->generate();
    \assert($issuer !== '' && $audience !== '' && $jti !== '');

    $token = (new JwtFacade())->issue(
      new Sha256(),
      InMemory::file($this->getKeyPath()),
      function (Builder $builder, \DateTimeImmutable $now) use ($audience, $expiration, $issuer, $jti, $path, $preview_context, $renew_url, $renewal, $resource_version, $user): Builder {
        $builder = $builder
          ->withHeader('typ', self::TYP_HEADER)
          ->issuedBy($issuer)
          ->permittedFor($audience)
          ->relatedTo((string) $user->id())
          ->identifiedBy($jti)
          ->expiresAt($now->modify(\sprintf('+%d seconds', $expiration)))
          ->withClaim('azp', self::CLIENT_ID)
          // Which lane the assertion serves. Activation assertions travel in
          // URLs and are redeemed server-side; renewal assertions are relayed
          // into the embedded app over postMessage and pass through script
          // context, so the grant demands PKCE proof of the running session
          // before redeeming them — a script that intercepts one cannot turn
          // it into a token.
          ->withClaim('use', $renewal ? 'renewal' : 'activation')
          ->withClaim('path', $path)
          ->withClaim('resourceVersion', $resource_version)
          ->withClaim('renewUrl', $renew_url);
        return $preview_context === []
          ? $builder
          : $builder->withClaim('previewContext', $preview_context);
      },
    );

    return $token->toString();
  }

  /**
   * Resolves a configured Simple OAuth key to a filesystem path.
   *
   * The mint side (this factory, private key) and the redeem side (the grant
   * plugin, public key) resolve their keys the same way, so both call this:
   * a stream-wrapper URI resolves through realpath(), and a plain filesystem
   * path that realpath() cannot resolve is passed through unchanged.
   *
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param string $path
   *   The configured key path or stream-wrapper URI.
   *
   * @return non-empty-string
   *   The resolved path.
   */
  public static function resolveKeyPath(FileSystemInterface $file_system, string $path): string {
    $realpath = $file_system->realpath($path);
    $key_path = \is_string($realpath) && $realpath !== '' ? $realpath : $path;
    \assert($key_path !== '');
    return $key_path;
  }

  /**
   * Resolves the Simple OAuth private key to a filesystem path.
   *
   * @return non-empty-string
   *   The resolved path.
   */
  protected function getKeyPath(): string {
    return self::resolveKeyPath(
      $this->fileSystem,
      (string) $this->configFactory->get('simple_oauth.settings')->get('private_key'),
    );
  }

}
