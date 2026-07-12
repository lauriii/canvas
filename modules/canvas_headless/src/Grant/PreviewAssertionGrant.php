<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\Grant;

use Drupal\canvas_headless\PreviewAssertionFactory;
use Drupal\canvas_headless\PreviewUrlGeneratorInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\simple_oauth\Entities\ScopeEntity;
use Drupal\user\UserInterface;
use Drupal\user\UserStorageInterface;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\RegisteredClaims;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\RequiredConstraintsViolated;
use Lcobucci\JWT\Validation\Validator;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AbstractGrant;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * OAuth 2.0 JWT bearer grant for preview assertions (RFC 7523).
 *
 * Exchanges a Drupal-signed preview assertion for an access token bound to
 * the editor named in the assertion's "sub" claim. This is an assertion
 * (extension) grant per RFC 6749 §4.5, implementing the profile RFC 7523
 * defines: a signed assertion from a trusted issuer — here, Drupal itself —
 * authorizes issuance without any client secret; the assertion is the
 * credential.
 *
 * Validation, in order:
 * - the client exists and may use this grant (league core; the consumer is
 *   public, so no client secret is involved),
 * - the JWT signature verifies against Simple OAuth's public key,
 * - the "typ" header is "canvas-headless-preview-assertion+jwt" (an access
 *   token or any other JWT species can never be replayed as an assertion),
 * - "iss" equals this site's UUID: an assertion minted by a separately
 *   installed site is refused even if it was signed with this site's
 *   keypair (a clone of this installation keeps the UUID and passes —
 *   clones are fenced by distinct per-environment keypairs),
 * - "aud" targets the token endpoint and the time claims hold (30 s leeway),
 * - the lifetime ("exp" − "iat") does not exceed a hard cap: RFC 7523 §3
 *   expects assertions to be short-lived, and the cap holds even if the
 *   issuing side's expiration configuration is set absurdly high,
 * - "azp" names the requesting client: the assertion is bound to the
 *   consumer it was minted for,
 * - a renewal assertion ("use" claim "renewal") additionally requires PKCE
 *   proof of the running app session: a code_verifier whose S256 challenge
 *   was registered at the session's previous redemption. Renewal assertions
 *   are relayed into the embedded app over postMessage and pass through
 *   script context, so possession of the assertion alone must not redeem —
 *   a script that intercepts one (an XSS in the frontend app) holds no
 *   verifier, which lives server-side in the app's httpOnly session state.
 *   Activation assertions travel in URLs and are redeemed by the app server
 *   immediately, never touching script context, so they carry no proof
 *   requirement — and they are the only way to bootstrap a challenge
 *   registration, which is what keeps an intercepting script from
 *   registering a challenge of its own. The proof is verified here but
 *   spent only at the commit point below, under a lock held across
 *   everything in between,
 * - the "jti" has never been redeemed before — single use, tracked in an
 *   expirable key-value store per RFC 7523 §3 clause 7, serialized through
 *   a lock so parallel redemptions of one assertion cannot race,
 * - the "sub" user exists, is active, and still holds the preview
 *   permission: minting checks it, redemption re-checks it, so revoking
 *   the permission takes effect immediately instead of after the
 *   assertion's remaining lifetime.
 *
 * Only once all of that holds and the token exists is the proven challenge
 * spent and its successor registered. Any failure in between leaves the
 * current challenge intact, so a session whose redemption was rejected for
 * any reason can still renew with the verifier it already holds.
 *
 * The issued token carries the module's preview scope, whose granularity
 * computes the view-only permission ceiling — Simple OAuth's intersection
 * policy resolves the token's permissions to the editor's own, capped to
 * what modules declare preview-safe.
 */
class PreviewAssertionGrant extends AbstractGrant {

  /**
   * Name of the scope every preview token carries.
   */
  public const SCOPE = 'canvas_headless';

  /**
   * The Oauth2Grant plugin id the preview scope enables.
   *
   * A scope's grant_types configuration is keyed by Oauth2Grant plugin id,
   * not by the RFC 7523 grant_type URI getIdentifier() returns, so the
   * scope's per-grant enable toggle is checked against this.
   */
  public const GRANT_PLUGIN_ID = 'canvas_headless_preview_assertion';

  /**
   * Leeway for clock skew when validating assertion time claims.
   */
  private const LEEWAY = 'PT30S';

  /**
   * Hard cap on an assertion's lifetime ("exp" − "iat"), in seconds.
   *
   * A validator-side bound, deliberately generous next to the shipped
   * 60-second assertion_expiration: it exists so a misconfigured issuer
   * cannot turn single-use assertions into long-lived credentials, not to
   * second-guess reasonable configuration.
   */
  private const MAX_LIFETIME_SECONDS = 300;

  /**
   * Hard cap on an issued access token's lifetime, in seconds.
   *
   * The consumer entity's access_token_expiration field feeds the TTL this
   * grant is called with, and hook_install()'s 900 seconds is only an
   * initial value: the field is editable and has no maximum, so a modified
   * (or pre-existing, adopted) consumer could otherwise stretch preview
   * tokens into long-lived credentials. The 15-minute exposure bound is
   * part of this module's design, so the grant enforces it at issuance
   * regardless of what the consumer says. Public so the consumer form can
   * tell administrators about the cap next to the expiration field.
   */
  public const MAX_TOKEN_TTL_SECONDS = 900;

  /**
   * How long a registered session challenge outlives its access token.
   *
   * A session's next renewal happens before the current token expires, so
   * the challenge registered at issuance only needs to survive the token
   * plus scheduling slack. After that, an unrenewed session re-enters
   * through the recovery lane (an activation assertion), which registers a
   * fresh challenge.
   */
  private const CHALLENGE_TTL_SLACK_SECONDS = 300;

  /**
   * @param non-empty-string $publicKeyPath
   *   Path to Simple OAuth's public key.
   * @param non-empty-string $audience
   *   The expected "aud" claim (the token endpoint path).
   * @param non-empty-string $expectedIssuer
   *   The expected "iss" claim (the site UUID).
   */
  public function __construct(
    private readonly KeyValueStoreExpirableInterface $usedAssertions,
    private readonly KeyValueStoreExpirableInterface $sessionChallenges,
    private readonly LockBackendInterface $lock,
    private readonly UserStorageInterface $userStorage,
    private readonly string $publicKeyPath,
    private readonly string $audience,
    private readonly string $expectedIssuer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getIdentifier(): string {
    return 'urn:ietf:params:oauth:grant-type:jwt-bearer';
  }

  /**
   * {@inheritdoc}
   */
  public function respondToAccessTokenRequest(
    ServerRequestInterface $request,
    ResponseTypeInterface $responseType,
    \DateInterval $accessTokenTTL,
  ): ResponseTypeInterface {
    $client = $this->validateClient($request);

    $jwt = $this->getRequestParameter('assertion', $request);
    if (!\is_string($jwt) || $jwt === '') {
      throw OAuthServerException::invalidRequest('assertion');
    }

    $assertion = $this->validateAssertion($jwt);

    // Bind the assertion to the client redeeming it.
    if ($assertion->claims()->get('azp') !== $client->getIdentifier()) {
      throw OAuthServerException::invalidGrant('The assertion was issued to a different client.');
    }

    // Renewal assertions require PKCE proof of the running app session.
    // This verifies the proof and holds a lock over it, but consumes
    // nothing: the challenge is spent only at the commit point below, once
    // the redemption is known to succeed. Verified before the jti so that a
    // party which intercepted the assertion but holds no verifier is turned
    // away having spent neither credential, leaving the assertion intact for
    // the app server that received it over the same relay.
    $proof_key = $this->verifySessionProof($assertion, $request, $client->getIdentifier());

    try {
      $this->consumeAssertion($assertion);

      $user = $this->userStorage->load((int) $assertion->claims()->get(RegisteredClaims::SUBJECT));
      if (!$user instanceof UserInterface || $user->isBlocked()) {
        throw OAuthServerException::invalidGrant('The asserted user is unknown or inactive.');
      }
      if (!$user->hasPermission(PreviewUrlGeneratorInterface::PREVIEW_PERMISSION)) {
        throw OAuthServerException::invalidGrant('The asserted user may not use the Canvas headless preview.');
      }

      $scope = $this->scopeRepository->getScopeEntityByIdentifier(self::SCOPE);
      if ($scope === NULL) {
        throw OAuthServerException::serverError(\sprintf('The "%s" scope is missing.', self::SCOPE));
      }
      // Honor the scope's per-grant enable toggle the way finalizeScopes()
      // does for every stock grant, so disabling the grant on the scope
      // actually stops preview tokens from being minted.
      if ($scope instanceof ScopeEntity && !$scope->getScopeObject()->isGrantTypeEnabled(self::GRANT_PLUGIN_ID)) {
        throw OAuthServerException::invalidScope(self::SCOPE);
      }

      $accessTokenTTL = self::capTokenTTL($accessTokenTTL);

      // Validate the successor challenge before spending the current one, so
      // a malformed registration cannot leave the session unable to renew.
      $next_challenge = $this->validateNextSessionChallenge($request);

      $accessToken = $this->issueAccessToken($accessTokenTTL, $client, (string) $user->id(), [$scope]);

      // The commit point. Every check has passed and the token exists, so
      // now — and only now — the proven challenge is spent and the
      // successor takes its place. Any earlier failure above leaves the
      // current challenge untouched, so the session renews again with the
      // verifier it still holds. Both operations run while the lock taken in
      // verifySessionProof() is still held, so the check and this rotation
      // are one atomic step against a concurrent redemption.
      if ($proof_key !== NULL) {
        $this->sessionChallenges->delete($proof_key);
      }
      if ($next_challenge !== NULL) {
        $this->registerSessionChallenge($client->getIdentifier(), $assertion, $next_challenge, $accessTokenTTL);
      }

      $responseType->setAccessToken($accessToken);
    }
    finally {
      if ($proof_key !== NULL) {
        $this->lock->release(self::challengeLockName($proof_key));
      }
    }

    return $responseType;
  }

  /**
   * Verifies PKCE proof of the running app session for renewal assertions.
   *
   * The verifier lives in the app's server-held (httpOnly) session state
   * and never enters script context; the challenge registered at the
   * previous redemption is what the verifier must hash to. Activation
   * assertions carry no proof requirement — they are redeemed server-side
   * straight from the activation URL — and redeeming one is the only way a
   * challenge gets registered, so a script inside the app can neither
   * redeem a relayed renewal assertion nor bootstrap a challenge it knows
   * the verifier for.
   *
   * On success the challenge's lock is *retained*, and the caller must
   * release it. The challenge itself is left in place: it is spent at the
   * caller's commit point, once the redemption is known to succeed. Spending
   * it here instead would strand a session whenever a later check failed —
   * replay a used assertion with the current verifier and the jti check,
   * running afterwards, rejects the request having already destroyed the
   * challenge the app server still holds the only verifier for.
   *
   * Holding the lock across the caller's remaining checks is what keeps that
   * deferral safe: verification and rotation together are one atomic step,
   * so two concurrent renewals presenting the same verifier cannot both pass
   * and each register a successor. Failing to acquire the lock means another
   * request is mid-redemption, which for a single-use credential is the same
   * answer as already-spent — exactly as consumeAssertion() treats the jti.
   *
   * @return string|null
   *   The key of the proven challenge, whose lock is held; NULL for
   *   activation assertions, where no lock was taken.
   */
  private function verifySessionProof(UnencryptedToken $assertion, ServerRequestInterface $request, string $client_id): ?string {
    $use = $assertion->claims()->get('use');
    if ($use === 'activation') {
      return NULL;
    }
    if ($use !== 'renewal') {
      throw OAuthServerException::invalidGrant('The assertion "use" claim must be "activation" or "renewal".');
    }

    $verifier = $this->getRequestParameter('code_verifier', $request);
    // RFC 7636 §4.1 verifier shape.
    if (!\is_string($verifier) || preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier) !== 1) {
      throw OAuthServerException::invalidGrant('A renewal assertion requires the session code_verifier.');
    }

    $key = self::challengeKey($client_id, (string) $assertion->claims()->get(RegisteredClaims::SUBJECT), self::challenge($verifier));
    if (!$this->lock->acquire(self::challengeLockName($key))) {
      throw OAuthServerException::invalidGrant('The code_verifier does not prove a running preview session.');
    }
    if (!$this->sessionChallenges->has($key)) {
      $this->lock->release(self::challengeLockName($key));
      throw OAuthServerException::invalidGrant('The code_verifier does not prove a running preview session.');
    }
    return $key;
  }

  /**
   * Validates the challenge the app server will present at its next renewal.
   *
   * Optional: an app that never renews in place (relying on the recovery
   * lane's full reloads) simply never sends one. Only the S256 method is
   * accepted — a plain-method challenge would BE the verifier, defeating
   * the point of keeping the verifier out of script context.
   *
   * Validation is separated from registration so a malformed successor is
   * refused before the current challenge is spent, leaving the running
   * session able to renew again.
   *
   * @return string|null
   *   The validated challenge, or NULL when the request registers none.
   */
  private function validateNextSessionChallenge(ServerRequestInterface $request): ?string {
    $challenge = $this->getRequestParameter('code_challenge', $request);
    if ($challenge === NULL) {
      return NULL;
    }
    if ($this->getRequestParameter('code_challenge_method', $request) !== 'S256') {
      throw OAuthServerException::invalidRequest('code_challenge_method', 'Only the S256 code challenge method is supported.');
    }
    // The base64url form of a SHA-256 digest is exactly 43 characters.
    if (preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge) !== 1) {
      throw OAuthServerException::invalidRequest('code_challenge', 'The code challenge must be the base64url-encoded SHA-256 hash of the verifier.');
    }
    return $challenge;
  }

  /**
   * Registers a validated successor challenge.
   *
   * The challenge only needs to outlive the token it accompanies: renewal
   * happens before the token expires, and an expired session re-enters
   * through the recovery lane, registering afresh.
   */
  private function registerSessionChallenge(string $client_id, UnencryptedToken $assertion, string $challenge, \DateInterval $token_ttl): void {
    $now = new \DateTimeImmutable();
    $ttl = $now->add($token_ttl)->getTimestamp() - $now->getTimestamp() + self::CHALLENGE_TTL_SLACK_SECONDS;
    $sub = (string) $assertion->claims()->get(RegisteredClaims::SUBJECT);
    $this->sessionChallenges->setWithExpire(self::challengeKey($client_id, $sub, $challenge), TRUE, $ttl);
  }

  /**
   * The lock name guarding one challenge's verification and rotation.
   */
  private static function challengeLockName(string $challenge_key): string {
    return 'canvas_headless_challenge:' . $challenge_key;
  }

  /**
   * Computes the S256 challenge for a verifier (RFC 7636 §4.2).
   */
  private static function challenge(string $verifier): string {
    return rtrim(strtr(base64_encode(hash('sha256', $verifier, TRUE)), '+/', '-_'), '=');
  }

  /**
   * Builds the key-value key of a registered session challenge.
   *
   * Keyed by client, user, and the challenge itself, so an editor's
   * concurrent sessions (two browser windows, two devices) register side by
   * side instead of clobbering each other.
   */
  private static function challengeKey(string $client_id, string $sub, string $challenge): string {
    return $client_id . ':' . $sub . ':' . $challenge;
  }

  /**
   * Clamps the consumer-provided token TTL to the module's hard cap.
   *
   * A DateInterval has no absolute length until anchored to a date, so the
   * comparison anchors both to the same instant.
   */
  private static function capTokenTTL(\DateInterval $accessTokenTTL): \DateInterval {
    $now = new \DateTimeImmutable();
    $seconds = $now->add($accessTokenTTL)->getTimestamp() - $now->getTimestamp();
    if ($seconds <= self::MAX_TOKEN_TTL_SECONDS) {
      return $accessTokenTTL;
    }
    return new \DateInterval(\sprintf('PT%dS', self::MAX_TOKEN_TTL_SECONDS));
  }

  /**
   * Parses and validates the assertion JWT.
   *
   * @param non-empty-string $jwt
   *   The serialized assertion.
   */
  private function validateAssertion(string $jwt): UnencryptedToken {
    try {
      $token = (new Parser(new JoseEncoder()))->parse($jwt);
    }
    catch (\Throwable) {
      throw OAuthServerException::invalidGrant('The assertion is malformed.');
    }
    if (!$token instanceof UnencryptedToken) {
      throw OAuthServerException::invalidGrant('The assertion is malformed.');
    }

    if ($token->headers()->get('typ') !== PreviewAssertionFactory::TYP_HEADER) {
      throw OAuthServerException::invalidGrant('The assertion is not a preview assertion.');
    }

    $clock = new class implements ClockInterface {

      public function now(): \DateTimeImmutable {
        return new \DateTimeImmutable();
      }

    };

    try {
      (new Validator())->assert(
        $token,
        new SignedWith(new Sha256(), InMemory::file($this->publicKeyPath)),
        new LooseValidAt($clock, new \DateInterval(self::LEEWAY)),
        new PermittedFor($this->audience),
        new IssuedBy($this->expectedIssuer),
      );
    }
    catch (RequiredConstraintsViolated $exception) {
      throw OAuthServerException::invalidGrant($exception->violations()[0]->getMessage());
    }

    $required_claims = [
      RegisteredClaims::SUBJECT,
      RegisteredClaims::ID,
      RegisteredClaims::ISSUED_AT,
      RegisteredClaims::EXPIRATION_TIME,
      'azp',
      'use',
      'path',
      'resourceVersion',
    ];
    foreach ($required_claims as $claim) {
      if (!$token->claims()->has($claim)) {
        throw OAuthServerException::invalidGrant(\sprintf('The assertion is missing the "%s" claim.', $claim));
      }
    }

    /** @var \DateTimeImmutable $issued_at */
    $issued_at = $token->claims()->get(RegisteredClaims::ISSUED_AT);
    /** @var \DateTimeImmutable $expires_at */
    $expires_at = $token->claims()->get(RegisteredClaims::EXPIRATION_TIME);
    if ($expires_at->getTimestamp() - $issued_at->getTimestamp() > self::MAX_LIFETIME_SECONDS) {
      throw OAuthServerException::invalidGrant('The assertion lifetime exceeds the allowed maximum.');
    }

    return $token;
  }

  /**
   * Enforces single use of the assertion via its jti claim.
   *
   * The check-then-mark on the key-value store is not atomic by itself
   * (core's setWithExpireIfNotExists() is also check-then-set), so it runs
   * under a lock keyed on the jti: of two parallel redemptions of the same
   * assertion, exactly one proceeds. Failing to acquire the lock means the
   * other request is mid-redemption, which for a single-use credential is
   * the same as already-used.
   */
  private function consumeAssertion(UnencryptedToken $assertion): void {
    $jti = (string) $assertion->claims()->get(RegisteredClaims::ID);
    $lock_name = 'canvas_headless_jti:' . $jti;

    if (!$this->lock->acquire($lock_name)) {
      throw OAuthServerException::invalidGrant('The assertion has already been used.');
    }
    try {
      if ($this->usedAssertions->has($jti)) {
        throw OAuthServerException::invalidGrant('The assertion has already been used.');
      }

      // Remember the jti for as long as the assertion could still validate
      // (remaining lifetime plus leeway); afterwards expiry rejects it
      // anyway.
      /** @var \DateTimeImmutable $expires_at */
      $expires_at = $assertion->claims()->get(RegisteredClaims::EXPIRATION_TIME);
      $ttl = max(1, $expires_at->getTimestamp() - time()) + 60;
      $this->usedAssertions->setWithExpire($jti, TRUE, $ttl);
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

}
