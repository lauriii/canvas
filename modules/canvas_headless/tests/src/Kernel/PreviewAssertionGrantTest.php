<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Kernel;

use Drupal\canvas_headless\Grant\PreviewAssertionGrant;
use Drupal\canvas_headless\PreviewAssertionFactory;
use Drupal\canvas_headless\PreviewAssertionFactoryInterface;
use Drupal\canvas_headless\PreviewUrlGeneratorInterface;
use Drupal\consumers\Entity\Consumer;
use Drupal\Core\Url;
use Drupal\simple_oauth\Authentication\TokenAuthUser;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\simple_oauth\Kernel\AuthorizedRequestBase;
use Drupal\user\UserInterface;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Tests the preview assertion grant's validation chain at the token endpoint.
 *
 * Every test drives the real /oauth/token route through the HTTP kernel, so
 * the whole chain runs: league's client validation, the grant's assertion
 * validation, single-use enforcement, the user re-checks, and stock token
 * issuance.
 *
 * Note this cannot use CanvasKernelTestBase because the Simple OAuth token
 * endpoint needs the fixtures its own kernel test base provides (signing
 * keys, entity schemas, and a baseline consumer). The canvas module itself
 * must still be installed: the container cannot compile without it because
 * canvas_headless's ExternalComponentSync service references canvas
 * services.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas_headless')]
class PreviewAssertionGrantTest extends AuthorizedRequestBase {

  use RequestTrait;

  /**
   * The site UUID minted into (and expected from) assertions.
   */
  private const SITE_UUID = 'c7f2e9a4-3b1d-4e8f-9a6c-5d0b2f8e1a37';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'custom_elements',
    'canvas_headless',
  ];

  /**
   * The editor whose Drupal presence the assertions assert.
   */
  protected UserInterface $editor;

  /**
   * {@inheritdoc}
   */
  protected function bootKernel(): void {
    parent::bootKernel();
    // AuthorizedRequestBase generates a URL before this class's setUp() runs.
    $this->installEntitySchema('path_alias');
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Installs canvas_headless.settings and the canvas_headless scope.
    $this->installConfig(['canvas_headless']);
    // The langcode rides along for the container rebuilds some tests
    // trigger via enableModules(): the kernel reads system.site's langcode
    // when compiling the container, and this write creates the config.
    $this->config('system.site')
      ->set('uuid', self::SITE_UUID)
      ->set('langcode', 'en')
      ->save();

    // Provision the module's public consumer the way hook_install() does;
    // install hooks do not run for modules enabled in kernel tests.
    Consumer::create([
      'client_id' => PreviewAssertionFactory::CLIENT_ID,
      'label' => 'Canvas Headless preview',
      'confidential' => FALSE,
      'is_default' => FALSE,
      'third_party' => FALSE,
      'grant_types' => ['canvas_headless_preview_assertion'],
      'access_token_expiration' => 900,
    ])->save();

    $editor = $this->createUser(['access canvas headless preview']);
    \assert($editor instanceof UserInterface);
    $this->editor = $editor;
  }

  /**
   * Tests that a valid assertion exchanges for a token bound to the editor.
   */
  public function testExchangeIssuesUserBoundToken(): void {
    $response = $this->exchange($this->mintAssertion());

    self::assertSame(200, $response->getStatusCode());
    $body = \json_decode((string) $response->getContent(), TRUE);
    self::assertIsArray($body);
    self::assertSame('Bearer', $body['token_type']);
    self::assertNotEmpty($body['access_token']);
    self::assertLessThanOrEqual(900, $body['expires_in']);

    // The issued token is bound to the editor and carries only the module's
    // scope — the two facts the whole design rests on.
    $tokens = $this->container->get('entity_type.manager')
      ->getStorage('oauth2_token')
      ->loadByProperties(['bundle' => 'access_token']);
    self::assertCount(1, $tokens);
    /** @var \Drupal\simple_oauth\Entity\Oauth2TokenInterface $token */
    $token = reset($tokens);
    self::assertSame((int) $this->editor->id(), (int) $token->get('auth_user_id')->target_id);
    self::assertSame(
      [PreviewAssertionGrant::SCOPE],
      array_column($token->get('scopes')->getValue(), 'scope_id'),
    );
  }

  /**
   * Tests that an administrative editor's token stays within the ceiling.
   *
   * An editor holding an administrative role carries the isAdmin flag, which
   * would otherwise short-circuit every permission check on the issued token
   * and defeat the view-only ceiling. PreviewCeilingAccessPolicy replaces the
   * admin flag with the ceiling's permissions, so the token authenticates
   * with exactly the preview-safe (view-only) permissions and no more.
   */
  public function testAdministrativeEditorStaysWithinCeiling(): void {
    $admin_role = $this->createAdminRole('admin', 'admin');
    \assert(\is_string($admin_role));
    $this->editor->addRole($admin_role)->save();

    $response = $this->exchange($this->mintAssertion());
    self::assertSame(200, $response->getStatusCode());

    $tokens = $this->container->get('entity_type.manager')
      ->getStorage('oauth2_token')
      ->loadByProperties(['bundle' => 'access_token']);
    /** @var \Drupal\simple_oauth\Entity\Oauth2TokenInterface $token */
    $token = reset($tokens);
    $account = new TokenAuthUser(
      $this->container->get('permission_checker'),
      $token,
      $this->container->get('psr7.http_message_factory'),
      $this->container->get('request_stack'),
    );

    // A preview-safe permission resolves; write and administrative
    // permissions the admin role would otherwise grant do not.
    self::assertTrue($account->hasPermission('access content'));
    self::assertFalse($account->hasPermission('administer nodes'));
    self::assertFalse($account->hasPermission('administer users'));
    self::assertFalse($account->hasPermission('access canvas headless preview'));
  }

  /**
   * Tests the grant-side hard cap on the issued token's lifetime.
   *
   * Hook_install() provisions the consumer with a 900-second expiration,
   * but the consumer entity is editable and its expiration field has no
   * maximum. The 15-minute exposure bound is the grant's to enforce, not
   * the consumer's to offer: a consumer configured for a week must still
   * yield a 15-minute preview token.
   */
  public function testTokenLifetimeIsCapped(): void {
    $consumers = $this->container->get('entity_type.manager')
      ->getStorage('consumer')
      ->loadByProperties(['client_id' => PreviewAssertionFactory::CLIENT_ID]);
    /** @var \Drupal\consumers\Entity\Consumer $consumer */
    $consumer = reset($consumers);
    $consumer->set('access_token_expiration', 604800)->save();

    $response = $this->exchange($this->mintAssertion());
    self::assertSame(200, $response->getStatusCode());
    $body = \json_decode((string) $response->getContent(), TRUE);
    self::assertIsArray($body);
    self::assertLessThanOrEqual(900, $body['expires_in']);
  }

  /**
   * Tests that disabling the grant on the scope stops token issuance.
   *
   * The scope ships with the preview assertion grant enabled; an admin
   * disabling it expects preview tokens to stop being minted, the same
   * control Simple OAuth honors for every stock grant through
   * finalizeScopes().
   */
  public function testDisablingGrantOnScopeRefusesIssuance(): void {
    /** @var \Drupal\simple_oauth\Entity\Oauth2Scope $entity */
    $entity = $this->container->get('entity_type.manager')
      ->getStorage('oauth2_scope')
      ->load(PreviewAssertionGrant::SCOPE);
    $grant_types = $entity->get('grant_types');
    $grant_types[PreviewAssertionGrant::GRANT_PLUGIN_ID]['status'] = FALSE;
    $entity->set('grant_types', $grant_types)->save();

    self::assertGrantError($this->exchange($this->mintAssertion()), PreviewAssertionGrant::SCOPE, 'invalid_scope');
  }

  /**
   * Tests that a redeemed assertion cannot be redeemed again.
   */
  public function testAssertionIsSingleUse(): void {
    $assertion = $this->mintAssertion();
    self::assertSame(200, $this->exchange($assertion)->getStatusCode());
    self::assertGrantError($this->exchange($assertion), 'The assertion has already been used.');
  }

  /**
   * Tests that modifying any claim breaks the signature.
   */
  public function testTamperedAssertionIsRefused(): void {
    $tampered = self::tamperClaim($this->mintAssertion(), 'sub', '1');
    self::assertGrantError($this->exchange($tampered), 'Token signature mismatch');
  }

  /**
   * Tests that other JWT species are refused before signature checks.
   *
   * Correctly signed, but the "typ" header is not the preview assertion
   * type: token confusion (an access token replayed as an assertion) must
   * fail on the explicit type label, per RFC 8725 §3.11.
   */
  public function testWrongTokenTypeIsRefused(): void {
    $jwt = $this->craftAssertion(['typ' => 'JWT']);
    self::assertGrantError($this->exchange($jwt), 'The assertion is not a preview assertion.');
  }

  /**
   * Tests that assertions from another installation are refused.
   *
   * Signed with this site's own keypair, but naming a different site UUID
   * as the issuer — the shared-keypair scenario between separately
   * installed sites.
   */
  public function testForeignIssuerIsRefused(): void {
    $jwt = $this->craftAssertion(['iss' => '00000000-aaaa-bbbb-cccc-dddddddddddd']);
    self::assertGrantError($this->exchange($jwt), 'The token was not issued by the given issuers');
  }

  /**
   * Tests that assertions signed with a foreign keypair are refused.
   */
  public function testForeignSigningKeyIsRefused(): void {
    $resource = openssl_pkey_new([
      'private_key_bits' => 2048,
      'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    self::assertNotFalse($resource);
    openssl_pkey_export($resource, $foreign_key);
    $jwt = $this->craftAssertion([], $foreign_key);
    self::assertGrantError($this->exchange($jwt), 'Token signature mismatch');
  }

  /**
   * Tests that expired assertions are refused.
   */
  public function testExpiredAssertionIsRefused(): void {
    $now = new \DateTimeImmutable();
    $jwt = $this->craftAssertion([
      'iat' => $now->modify('-5 minutes'),
      'exp' => $now->modify('-4 minutes'),
    ]);
    self::assertGrantError($this->exchange($jwt), 'The token is expired');
  }

  /**
   * Tests the hard cap on assertion lifetime.
   *
   * A misconfigured issuer's oversized assertion_expiration must not turn
   * single-use assertions into long-lived credentials: the grant refuses
   * any assertion whose lifetime exceeds five minutes, regardless of how
   * the minting side is configured.
   */
  public function testOversizedLifetimeIsRefused(): void {
    $now = new \DateTimeImmutable();
    $jwt = $this->craftAssertion(['exp' => $now->modify('+10 minutes')]);
    self::assertGrantError($this->exchange($jwt), 'The assertion lifetime exceeds the allowed maximum.');
  }

  /**
   * Tests that assertions minted for another audience are refused.
   */
  public function testForeignAudienceIsRefused(): void {
    $jwt = $this->craftAssertion(['aud' => '/not-the-token-endpoint']);
    self::assertGrantError($this->exchange($jwt), 'The token is not allowed to be used by this audience');
  }

  /**
   * Tests that an assertion minted for another client cannot be redeemed.
   */
  public function testForeignAuthorizedPartyIsRefused(): void {
    $jwt = $this->craftAssertion(['azp' => 'some_other_consumer']);
    self::assertGrantError($this->exchange($jwt), 'The assertion was issued to a different client.');
  }

  /**
   * Tests that structurally incomplete assertions are refused.
   */
  public function testMissingClaimIsRefused(): void {
    $jwt = $this->craftAssertion(['resourceVersion' => NULL]);
    self::assertGrantError($this->exchange($jwt), 'The assertion is missing the "resourceVersion" claim.');
  }

  /**
   * Tests that assertions for blocked accounts are refused.
   */
  public function testBlockedUserIsRefused(): void {
    $assertion = $this->mintAssertion();
    $this->editor->block()->save();
    self::assertGrantError($this->exchange($assertion), 'The asserted user is unknown or inactive.');
  }

  /**
   * Tests that the preview permission is re-checked at redemption.
   *
   * The factory itself does not check the permission (the generator and the
   * routes do), so an assertion minted directly through it for a user
   * without the permission must still be refused at the token endpoint —
   * this is also what makes permission revocation take effect immediately.
   */
  public function testPermissionIsRecheckedAtRedemption(): void {
    $no_permission_user = $this->createUser();
    \assert($no_permission_user instanceof UserInterface);
    $assertion = $this->container->get(PreviewAssertionFactoryInterface::class)
      ->issue($no_permission_user, '/node/1', 'rel:working-copy');
    self::assertGrantError($this->exchange($assertion), 'The asserted user may not use the Canvas headless preview.');
  }

  /**
   * Tests that garbage assertions are refused.
   */
  public function testMalformedAssertionIsRefused(): void {
    self::assertGrantError($this->exchange('not-a-jwt'), 'The assertion is malformed.');
  }

  /**
   * Tests that a renewal assertion is worthless without session proof.
   *
   * Renewal assertions pass through the embedded app's script context, so
   * possession alone must not redeem: a script that intercepts one from the
   * postMessage relay holds no code_verifier and gets nothing.
   */
  public function testRenewalAssertionRequiresSessionProof(): void {
    self::assertGrantError(
      $this->exchange($this->mintAssertion(TRUE)),
      'A renewal assertion requires the session code_verifier.',
    );
  }

  /**
   * Tests the full PKCE renewal chain.
   *
   * Activation registers a challenge; renewal proves it with the verifier
   * and registers the next one; the consumed challenge no longer proves
   * anything (rotation).
   */
  public function testRenewalChain(): void {
    $verifier_1 = str_repeat('a', 43);
    $verifier_2 = str_repeat('b', 43);

    // Activation: no proof needed, next challenge registered.
    $response = $this->exchange($this->mintAssertion(), extra: [
      'code_challenge' => self::challenge($verifier_1),
      'code_challenge_method' => 'S256',
    ]);
    self::assertSame(200, $response->getStatusCode());

    // Renewal: proven by the verifier, rotating to the next challenge.
    $response = $this->exchange($this->mintAssertion(TRUE), extra: [
      'code_verifier' => $verifier_1,
      'code_challenge' => self::challenge($verifier_2),
      'code_challenge_method' => 'S256',
    ]);
    self::assertSame(200, $response->getStatusCode());

    // The consumed verifier proves nothing anymore; the rotated one does.
    self::assertGrantError(
      $this->exchange($this->mintAssertion(TRUE), extra: ['code_verifier' => $verifier_1]),
      'The code_verifier does not prove a running preview session.',
    );
    $response = $this->exchange($this->mintAssertion(TRUE), extra: ['code_verifier' => $verifier_2]);
    self::assertSame(200, $response->getStatusCode());
  }

  /**
   * Tests that a wrong verifier does not redeem a renewal assertion.
   */
  public function testRenewalWithWrongVerifierIsRefused(): void {
    $response = $this->exchange($this->mintAssertion(), extra: [
      'code_challenge' => self::challenge(str_repeat('a', 43)),
      'code_challenge_method' => 'S256',
    ]);
    self::assertSame(200, $response->getStatusCode());

    self::assertGrantError(
      $this->exchange($this->mintAssertion(TRUE), extra: ['code_verifier' => str_repeat('x', 43)]),
      'The code_verifier does not prove a running preview session.',
    );
  }

  /**
   * Tests that a failed redemption does not spend the session challenge.
   *
   * The challenge is verified early but spent only once the redemption is
   * known to succeed. Were it deleted at verification time, this sequence
   * would strand the session: replaying an already-redeemed assertion with
   * the *current* verifier would destroy the challenge on the way to the
   * jti check that rejects the request — and the app server, holding the
   * only verifier that matched it, could never renew again.
   */
  public function testFailedRedemptionDoesNotSpendTheChallenge(): void {
    $verifier_1 = str_repeat('a', 43);
    $verifier_2 = str_repeat('b', 43);

    // Activation registers challenge 1.
    $response = $this->exchange($this->mintAssertion(), extra: [
      'code_challenge' => self::challenge($verifier_1),
      'code_challenge_method' => 'S256',
    ]);
    self::assertSame(200, $response->getStatusCode());

    // A renewal rotates from verifier 1 to verifier 2.
    $renewal = $this->mintAssertion(TRUE);
    $response = $this->exchange($renewal, extra: [
      'code_verifier' => $verifier_1,
      'code_challenge' => self::challenge($verifier_2),
      'code_challenge_method' => 'S256',
    ]);
    self::assertSame(200, $response->getStatusCode());

    // Replay that same (now jti-consumed) assertion with the live verifier.
    // The jti check refuses it — and must do so without having spent the
    // challenge that verifier 2 proves.
    self::assertGrantError(
      $this->exchange($renewal, extra: ['code_verifier' => $verifier_2]),
      'The assertion has already been used.',
    );

    // The session is still alive: a fresh assertion with verifier 2 renews.
    $response = $this->exchange($this->mintAssertion(TRUE), extra: ['code_verifier' => $verifier_2]);
    self::assertSame(200, $response->getStatusCode());
  }

  /**
   * Tests that other late failures also leave the challenge intact.
   *
   * A disabled scope is rejected after the proof is verified and after the
   * jti is consumed. The assertion is spent, but the challenge must not be,
   * so re-enabling the scope lets the session renew with the verifier it
   * still holds.
   */
  public function testLateFailureDoesNotSpendTheChallenge(): void {
    $verifier = str_repeat('a', 43);
    $response = $this->exchange($this->mintAssertion(), extra: [
      'code_challenge' => self::challenge($verifier),
      'code_challenge_method' => 'S256',
    ]);
    self::assertSame(200, $response->getStatusCode());

    /** @var \Drupal\simple_oauth\Entity\Oauth2Scope $entity */
    $entity = $this->container->get('entity_type.manager')
      ->getStorage('oauth2_scope')
      ->load(PreviewAssertionGrant::SCOPE);
    $grant_types = $entity->get('grant_types');
    $grant_types[PreviewAssertionGrant::GRANT_PLUGIN_ID]['status'] = FALSE;
    $entity->set('grant_types', $grant_types)->save();

    self::assertGrantError(
      $this->exchange($this->mintAssertion(TRUE), extra: ['code_verifier' => $verifier]),
      PreviewAssertionGrant::SCOPE,
      'invalid_scope',
    );

    $grant_types[PreviewAssertionGrant::GRANT_PLUGIN_ID]['status'] = TRUE;
    $entity->set('grant_types', $grant_types)->save();

    $response = $this->exchange($this->mintAssertion(TRUE), extra: ['code_verifier' => $verifier]);
    self::assertSame(200, $response->getStatusCode());
  }

  /**
   * Tests that a malformed successor challenge leaves the current one alive.
   *
   * The successor is validated before the current challenge is spent, so a
   * bad registration refuses the request without ending the session.
   */
  public function testInvalidSuccessorChallengeDoesNotSpendTheChallenge(): void {
    $verifier = str_repeat('a', 43);
    $response = $this->exchange($this->mintAssertion(), extra: [
      'code_challenge' => self::challenge($verifier),
      'code_challenge_method' => 'S256',
    ]);
    self::assertSame(200, $response->getStatusCode());

    $response = $this->exchange($this->mintAssertion(TRUE), extra: [
      'code_verifier' => $verifier,
      'code_challenge' => 'too-short',
      'code_challenge_method' => 'S256',
    ]);
    self::assertSame(400, $response->getStatusCode());

    // The verifier still proves the session.
    $response = $this->exchange($this->mintAssertion(TRUE), extra: ['code_verifier' => $verifier]);
    self::assertSame(200, $response->getStatusCode());
  }

  /**
   * Tests that a failed proof attempt does not burn the assertion.
   *
   * The app server and an intercepting script hold the same relayed
   * assertion. The script's attempt without proof is refused *before* the
   * single-use jti is consumed, so the app server's legitimate renewal
   * with the same assertion still succeeds.
   */
  public function testFailedProofDoesNotBurnTheAssertion(): void {
    $verifier = str_repeat('a', 43);
    $response = $this->exchange($this->mintAssertion(), extra: [
      'code_challenge' => self::challenge($verifier),
      'code_challenge_method' => 'S256',
    ]);
    self::assertSame(200, $response->getStatusCode());

    $assertion = $this->mintAssertion(TRUE);
    self::assertGrantError($this->exchange($assertion), 'A renewal assertion requires the session code_verifier.');
    $response = $this->exchange($assertion, extra: ['code_verifier' => $verifier]);
    self::assertSame(200, $response->getStatusCode());
  }

  /**
   * Tests that an unknown "use" claim value is refused.
   */
  public function testUnknownUseClaimIsRefused(): void {
    $jwt = $this->craftAssertion(['use' => 'whatever']);
    self::assertGrantError($this->exchange($jwt), 'The assertion "use" claim must be "activation" or "renewal".');
  }

  /**
   * Tests that only the S256 challenge method is accepted.
   *
   * A plain-method challenge would BE the verifier, so accepting it would
   * defeat keeping the verifier out of script context.
   */
  public function testPlainChallengeMethodIsRefused(): void {
    $response = $this->exchange($this->mintAssertion(), extra: [
      'code_challenge' => str_repeat('a', 43),
      'code_challenge_method' => 'plain',
    ]);
    self::assertSame(400, $response->getStatusCode());
    $body = \json_decode((string) $response->getContent(), TRUE);
    self::assertIsArray($body);
    self::assertSame('invalid_request', $body['error']);
  }

  /**
   * Computes the S256 challenge for a verifier (RFC 7636 §4.2).
   */
  private static function challenge(string $verifier): string {
    return rtrim(strtr(base64_encode(hash('sha256', $verifier, TRUE)), '+/', '-_'), '=');
  }

  /**
   * Tests that an unknown client cannot redeem a valid assertion.
   */
  public function testUnknownClientIsRefused(): void {
    $response = $this->exchange($this->mintAssertion(), 'no_such_client');
    self::assertSame(401, $response->getStatusCode());
    $body = \json_decode((string) $response->getContent(), TRUE);
    self::assertIsArray($body);
    self::assertSame('invalid_client', $body['error']);
  }

  /**
   * Tests that a bearer token cannot call the assertion minting endpoint.
   *
   * The Drupal session is the preview flow's revocation boundary, so the
   * minting routes accept only cookie authentication (_auth). A bearer
   * token that could mint fresh assertions would chain itself into new
   * tokens and outlive the session.
   *
   * The scenario needs canvas_headless_test, which declares the preview
   * permission itself preview-safe: the token then *holds* the route's
   * permission, so the request clears the access check and the denial can
   * only come from core's provider filter refusing the authentication
   * method. Without the test module, the ceiling's withholding of the
   * permission would produce a denial that proves nothing about the route
   * restriction; with it, removing _auth makes this request succeed.
   */
  public function testBearerTokenCannotMintAssertions(): void {
    $request = Request::create(
      Url::fromRoute('canvas_headless.assertion', [], ['query' => ['path' => '/some-path']])->toString(),
      'POST',
    );
    $request->headers->set('Authorization', 'Bearer ' . $this->obtainAccessToken());
    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('The used authentication method is not allowed on this route.');
    $this->request($request);
  }

  /**
   * Tests that a bearer token cannot call the standalone renewal route.
   *
   * @see testBearerTokenCannotMintAssertions()
   */
  public function testBearerTokenCannotRenew(): void {
    $request = Request::create(
      Url::fromRoute('canvas_headless.renew', [], ['query' => ['path' => '/some-path']])->toString(),
    );
    $request->headers->set('Authorization', 'Bearer ' . $this->obtainAccessToken());
    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('The used authentication method is not allowed on this route.');
    $this->request($request);
  }

  /**
   * Redeems a fresh assertion and returns an issued, permission-rich token.
   *
   * Enables canvas_headless_test first, so the token's ceiling includes the
   * preview permission the minting routes require.
   */
  private function obtainAccessToken(): string {
    $this->enableModules(['canvas_headless_test']);
    $response = $this->exchange($this->mintAssertion());
    self::assertSame(200, $response->getStatusCode());
    $body = \json_decode((string) $response->getContent(), TRUE);
    self::assertIsArray($body);
    self::assertIsString($body['access_token']);

    // Prove the premise: authenticated with this token, the preview
    // permission itself resolves — so a denial on the routes below can only
    // mean the authentication method was refused.
    $tokens = $this->container->get('entity_type.manager')
      ->getStorage('oauth2_token')
      ->loadByProperties(['bundle' => 'access_token']);
    /** @var \Drupal\simple_oauth\Entity\Oauth2TokenInterface $token */
    $token = reset($tokens);
    $account = new TokenAuthUser(
      $this->container->get('permission_checker'),
      $token,
      $this->container->get('psr7.http_message_factory'),
      $this->container->get('request_stack'),
    );
    self::assertTrue($account->hasPermission(PreviewUrlGeneratorInterface::PREVIEW_PERMISSION));

    return $body['access_token'];
  }

  /**
   * Mints a valid assertion through the module's own factory.
   */
  private function mintAssertion(bool $renewal = FALSE): string {
    return $this->container->get(PreviewAssertionFactoryInterface::class)
      ->issue($this->editor, '/node/1', 'rel:working-copy', $renewal);
  }

  /**
   * Posts an assertion to the token endpoint, RFC 7523 §2.1 wire format.
   *
   * @param string $assertion
   *   The serialized assertion.
   * @param string $client_id
   *   The redeeming client.
   * @param array<string, string> $extra
   *   Additional token request parameters (PKCE).
   */
  private function exchange(string $assertion, string $client_id = PreviewAssertionFactory::CLIENT_ID, array $extra = []): Response {
    $request = Request::create($this->url->toString(), 'POST', [
      'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
      'assertion' => $assertion,
      'client_id' => $client_id,
    ] + $extra);
    return $this->request($request);
  }

  /**
   * Asserts an RFC 6749 error response with the given hint.
   */
  private static function assertGrantError(Response $response, string $hint, string $error = 'invalid_grant'): void {
    self::assertSame(400, $response->getStatusCode());
    $body = \json_decode((string) $response->getContent(), TRUE);
    self::assertIsArray($body);
    self::assertSame($error, $body['error']);
    self::assertStringContainsString($hint, (string) ($body['hint'] ?? ''), (string) $response->getContent());
  }

  /**
   * Builds an assertion with full control over claims and the signing key.
   *
   * Defaults mirror what PreviewAssertionFactory mints; overrides let each
   * test break exactly one property. A NULL override omits the claim.
   *
   * @param array<string, mixed> $overrides
   *   Claim (and "typ" header) overrides.
   * @param string|null $signing_key
   *   A PEM private key to sign with; Simple OAuth's key when NULL.
   */
  private function craftAssertion(array $overrides = [], ?string $signing_key = NULL): string {
    $now = new \DateTimeImmutable();
    $values = $overrides + [
      'typ' => PreviewAssertionFactory::TYP_HEADER,
      'iss' => self::SITE_UUID,
      'aud' => Url::fromRoute('oauth2_token.token')->toString(),
      'sub' => (string) $this->editor->id(),
      'azp' => PreviewAssertionFactory::CLIENT_ID,
      'jti' => $this->container->get('uuid')->generate(),
      'iat' => $now,
      'exp' => $now->modify('+60 seconds'),
      'use' => 'activation',
      'path' => '/node/1',
      'resourceVersion' => 'rel:working-copy',
    ];

    $builder = Builder::new(new JoseEncoder(), ChainedFormatter::withUnixTimestampDates())
      ->withHeader('typ', $values['typ']);
    if ($values['iss'] !== NULL) {
      $builder = $builder->issuedBy($values['iss']);
    }
    if ($values['aud'] !== NULL) {
      $builder = $builder->permittedFor($values['aud']);
    }
    if ($values['sub'] !== NULL) {
      $builder = $builder->relatedTo($values['sub']);
    }
    if ($values['jti'] !== NULL) {
      $builder = $builder->identifiedBy($values['jti']);
    }
    if ($values['iat'] !== NULL) {
      $builder = $builder->issuedAt($values['iat'])->canOnlyBeUsedAfter($values['iat']);
    }
    if ($values['exp'] !== NULL) {
      $builder = $builder->expiresAt($values['exp']);
    }
    foreach (['azp', 'use', 'path', 'resourceVersion'] as $claim) {
      if ($values[$claim] !== NULL) {
        $builder = $builder->withClaim($claim, $values[$claim]);
      }
    }

    $key = $signing_key ?? $this->privateKey;
    \assert(\is_string($key) && $key !== '');
    return $builder
      ->getToken(new Sha256(), InMemory::plainText($key))
      ->toString();
  }

  /**
   * Replaces a claim in a serialized JWT without re-signing it.
   */
  private static function tamperClaim(string $assertion, string $claim, mixed $value): string {
    [$header, $payload, $signature] = explode('.', $assertion);
    $decoded_payload = base64_decode(strtr($payload, '-_', '+/'), TRUE);
    self::assertIsString($decoded_payload);
    $decoded = \json_decode($decoded_payload, TRUE);
    self::assertIsArray($decoded);
    $decoded[$claim] = $value;
    $payload = rtrim(strtr(base64_encode((string) \json_encode($decoded)), '+/', '-_'), '=');
    return "$header.$payload.$signature";
  }

}
