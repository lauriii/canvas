<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Drush;

use Drupal\canvas\Drush\Commands\CanvasSetupCommands;
use Drupal\consumers\Entity\ConsumerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\media\MediaTypeInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\RoleInterface;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Tests the command that prepares a site for Canvas code components.
 *
 * The canvas_oauth module is deliberately not in the module list: installing
 * it is the command's own first step.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(CanvasSetupCommands::class)]
#[Group('canvas')]
#[Group('canvas_oauth')]
final class CanvasSetupCommandsTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...self::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    // The image media type the command creates gets a configurable source
    // field.
    'field',
  ];

  /**
   * A directory outside the docroot, as the command insists on.
   */
  private string $keyDirectory;

  /**
   * The account the CLI will log in as.
   */
  private UserInterface $account;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    // Saving a role resolves every permission, and the filter module's
    // permissions build a URL, which needs the alias storage to exist.
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['user', 'file', 'image', 'media']);

    // Not the root user: uid 1 holds every permission implicitly, which would
    // hide whether the command actually grants any.
    User::create(['uid' => 1, 'name' => 'root'])->save();
    $this->account = User::create(['name' => 'developer', 'status' => TRUE]);
    $this->account->save();

    $this->keyDirectory = sys_get_temp_dir() . '/canvas-setup-' . bin2hex(random_bytes(6));
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (is_dir($this->keyDirectory)) {
      $files = glob($this->keyDirectory . '/{,.}[!.,!..]*', GLOB_BRACE);
      foreach ($files === FALSE ? [] : $files as $file) {
        @unlink($file);
      }
      @rmdir($this->keyDirectory);
    }
    parent::tearDown();
  }

  /**
   * The whole command, unattended, on a site where nothing has been set up.
   */
  public function testNonInteractiveSetup(): void {
    $output = $this->runSetup();

    // The modules the external API authenticates with are installed.
    $this->assertTrue(\Drupal::moduleHandler()->moduleExists('canvas_oauth'));
    $this->assertTrue(\Drupal::moduleHandler()->moduleExists('simple_oauth'));
    $this->assertTrue(\Drupal::moduleHandler()->moduleExists('consumers'));

    // The scope `canvas login` requests for media exists, which it only can
    // once a media type with the image source does.
    $media_type = \Drupal::entityTypeManager()->getStorage('media_type')->load('image');
    $this->assertInstanceOf(MediaTypeInterface::class, $media_type);
    $this->assertSame('image', $media_type->getSource()->getPluginId());
    $this->assertNotNull(\Drupal::entityTypeManager()->getStorage('oauth2_scope')->load('canvas_media_image_create'));

    // The keys exist outside the docroot, unreadable by anyone but their
    // owner, in a directory that cannot be committed.
    $settings = \Drupal::config('simple_oauth.settings');
    $this->assertSame($this->keyDirectory . '/private.key', $settings->get('private_key'));
    $this->assertSame($this->keyDirectory . '/public.key', $settings->get('public_key'));
    $this->assertFileExists($this->keyDirectory . '/private.key');
    $this->assertSame('0600', substr(\sprintf('%o', fileperms($this->keyDirectory . '/private.key')), -4));
    $this->assertStringNotContainsString(realpath(DRUPAL_ROOT) . '/', realpath($this->keyDirectory) . '/');
    $this->assertSame("*\n", file_get_contents($this->keyDirectory . '/.gitignore'));

    // The consumer matches what the CLI's login flow sends: a public client
    // using PKCE, with the loopback callback registered verbatim.
    $consumer = $this->loadConsumer('cli-test');
    $this->assertSame('Test CLI', $consumer->label());
    $this->assertFalse((bool) $consumer->get('confidential')->value);
    $this->assertTrue((bool) $consumer->get('pkce')->value);
    $this->assertSame(['authorization_code', 'refresh_token'], array_column($consumer->get('grant_types')->getValue(), 'value'));
    $this->assertSame(['http://localhost:4444/callback'], array_column($consumer->get('redirect')->getValue(), 'value'));
    $this->assertContains('canvas_js_component', array_column($consumer->get('authorization_code_scopes')->getValue(), 'scope_id'));

    // No client secret is generated for the login consumer, because the CLI
    // never sends one.
    $this->assertTrue($consumer->get('secret')->isEmpty());

    // The account can actually use the scopes it will be granted.
    $account = User::load((int) $this->account->id());
    $this->assertInstanceOf(UserInterface::class, $account);
    $this->assertTrue($account->hasPermission('administer code components'));
    $this->assertTrue($account->hasPermission('view media'));
    $this->assertTrue($account->hasPermission('create image media'));

    // The next steps carry the values just configured, not placeholders.
    $this->assertStringContainsString('npx @drupal-canvas/cli@latest login --site-url https://example.com --client-id cli-test', $output);
    $this->assertStringContainsString('npx @drupal-canvas/create@latest my-components --site-url https://example.com', $output);
    $this->assertStringContainsString('npx @drupal-canvas/cli@latest push', $output);
    $this->assertStringContainsString('https://example.com/canvas', $output);
  }

  /**
   * A second run changes nothing, and a partial one resumes.
   *
   * No state is recorded anywhere: the site's own configuration is the state.
   */
  public function testReRunIsIdempotentAndResumable(): void {
    $this->runSetup();
    $private_key = file_get_contents($this->keyDirectory . '/private.key');
    $consumer = $this->loadConsumer('cli-test')->toArray();

    $output = $this->runSetup();

    $this->assertStringContainsString('Module canvas_oauth is already installed.', $output);
    $this->assertStringContainsString('Media type `image` already exists.', $output);
    $this->assertStringContainsString('OAuth signing keys are already in place', $output);
    $this->assertStringContainsString('Consumer `cli-test` is already configured for canvas login.', $output);
    $this->assertStringContainsString('already holds every permission', $output);

    // Regenerating the keys would invalidate every token the site has issued,
    // so a re-run must leave them exactly as they were.
    $this->assertSame($private_key, file_get_contents($this->keyDirectory . '/private.key'));
    $this->assertSame($consumer, $this->loadConsumer('cli-test')->toArray());

    // Losing one outcome makes the next run redo exactly that step.
    $this->loadConsumer('cli-test')->delete();
    $output = $this->runSetup();
    $this->assertStringContainsString('OAuth signing keys are already in place', $output);
    $this->assertStringContainsString('Configured consumer `cli-test` for canvas login', $output);
    $this->assertSame('cli-test', $this->loadConsumer('cli-test')->getClientId());
  }

  /**
   * Every unmet precondition names the command that meets it.
   */
  public function testPreconditionFailuresNameTheFix(): void {
    User::create(['name' => 'blocked', 'status' => FALSE])->save();
    $cases = [
      // A key directory under the docroot is refused, not warned about.
      'where the web server can serve the private key' => ['key-dir' => DRUPAL_ROOT . '/sites/default/files/keys'],
      // A relative directory would resolve against whoever ran the command.
      'must be an absolute path' => ['key-dir' => 'keys'],
      'drush user:create nobody' => ['account' => 'nobody'],
      'drush user:unblock blocked' => ['account' => 'blocked'],
      'not a port the CLI can listen on' => ['port' => 'abc'],
      'not usable as an OAuth client ID' => ['client-id' => 'a client id'],
      'not an http or https URL' => ['site-url' => 'ftp://example.com'],
    ];
    foreach ($cases as $expected => $options) {
      try {
        $this->runSetup($options);
        $this->fail(\sprintf('Expected a failure mentioning "%s".', $expected));
      }
      catch (\RuntimeException $e) {
        $this->assertStringContainsString($expected, $e->getMessage());
      }
    }
    // Nothing was half-written on the way to those failures.
    $this->assertFileDoesNotExist($this->keyDirectory . '/private.key');
    $this->assertEmpty(\Drupal::entityTypeManager()->getStorage('consumer')->loadByProperties(['client_id' => 'cli-test']));
  }

  /**
   * A scope the CLI requests but the site lacks is a failure, not a warning.
   */
  public function testMissingScopeFails(): void {
    $this->runSetup();
    \Drupal::entityTypeManager()->getStorage('oauth2_scope')->load('canvas_asset_library')?->delete();

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('logging in would fail with invalid_scope');
    $this->runSetup();
  }

  /**
   * A role the site already uses for something else is not quietly widened.
   */
  public function testForeignRoleIsNotWidened(): void {
    $this->runSetup();
    $role = Role::load('canvas_code_components');
    $this->assertInstanceOf(RoleInterface::class, $role);
    $role->grantPermission('administer site configuration')->save();

    // A second account that does not hold the permissions yet, so the command
    // reaches the role step rather than returning early.
    User::create(['name' => 'other', 'status' => TRUE])->save();
    try {
      $this->runSetup(['account' => 'other']);
      $this->fail('Expected the pre-existing role to be refused.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('administer site configuration', $e->getMessage());
      $this->assertStringContainsString('drush user:role:add', $e->getMessage());
    }
    $this->assertFalse(User::load(1)?->hasRole('canvas_code_components'));
  }

  /**
   * The CI consumer is confidential, and its secret is printed exactly once.
   */
  public function testCiConsumerPrintsItsSecretOnce(): void {
    $output = $this->runSetup(['ci' => TRUE]);

    $consumer = $this->loadConsumer('cli-test-ci');
    $this->assertTrue((bool) $consumer->get('confidential')->value);
    $this->assertSame(['client_credentials'], array_column($consumer->get('grant_types')->getValue(), 'value'));
    $this->assertSame($this->account->id(), $consumer->get('user_id')->target_id);

    $this->assertSame(1, preg_match('/CANVAS_CLIENT_SECRET=(\S+)/', $output, $matches));
    $secret = $matches[1];
    // Stored hashed, so the plaintext exists only in that one line of output.
    $this->assertNotSame($secret, $consumer->get('secret')->value);
    $this->assertTrue(\Drupal::service('password')->check($secret, $consumer->get('secret')->value));

    $this->assertStringNotContainsString($secret, $this->runSetup(['ci' => TRUE]));

    // A consumer that exists but has drifted is corrected rather than reported
    // ready, and its secret survives the correction.
    $consumer = $this->loadConsumer('cli-test-ci');
    $consumer->set('confidential', FALSE)->set('grant_types', ['authorization_code'])->save();
    $output = $this->runSetup(['ci' => TRUE]);
    $this->assertStringContainsString('Corrected consumer `cli-test-ci`', $output);
    $consumer = $this->loadConsumer('cli-test-ci');
    $this->assertTrue((bool) $consumer->get('confidential')->value);
    $this->assertSame(['client_credentials'], array_column($consumer->get('grant_types')->getValue(), 'value'));
    $this->assertTrue(\Drupal::service('password')->check($secret, $consumer->get('secret')->value));
  }

  /**
   * Runs the command unattended, returning everything it printed.
   *
   * @param array $options
   *   Options overriding the defaults every test shares.
   *
   * @return string
   *   The command's output.
   */
  private function runSetup(array $options = []): string {
    $container = \Drupal::getContainer();
    $command = new CanvasSetupCommands(
      $container->get(ModuleHandlerInterface::class),
      $container->get(ModuleInstallerInterface::class),
      $container->get(ModuleExtensionList::class),
    );
    $output = new BufferedOutput();
    $command->setInput(new ArrayInput([]));
    $command->setOutput($output);
    $command->setupCodeComponents($options + [
      'client-id' => 'cli-test',
      'label' => 'Test CLI',
      'site-url' => 'https://example.com',
      'account' => 'developer',
      'key-dir' => $this->keyDirectory,
      'port' => NULL,
      'ci' => FALSE,
    ]);
    return $output->fetch();
  }

  /**
   * Loads a consumer by client ID, failing the test when it is missing.
   */
  private function loadConsumer(string $client_id): ConsumerInterface {
    $consumers = \Drupal::entityTypeManager()->getStorage('consumer')->loadByProperties(['client_id' => $client_id]);
    $consumer = reset($consumers);
    $this->assertInstanceOf(ConsumerInterface::class, $consumer);
    return $consumer;
  }

}
