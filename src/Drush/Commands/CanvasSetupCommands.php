<?php

declare(strict_types=1);

namespace Drupal\canvas\Drush\Commands;

use Drupal\canvas_oauth\MediaScopesHelper;
use Drupal\Component\Utility\Crypt;
use Drupal\consumers\Entity\ConsumerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\field\FieldStorageConfigInterface;
use Drupal\media\MediaTypeInterface;
use Drupal\simple_oauth\Entity\Oauth2Scope;
use Drupal\simple_oauth\Service\Exception\ExtensionNotLoadedException;
use Drupal\simple_oauth\Service\Exception\FilesystemValidationException;
use Drupal\simple_oauth\Service\KeyGeneratorServiceInterface;
use Drupal\user\RoleInterface;
use Drupal\user\UserInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Takes a Drupal site from nothing to ready for Canvas code components.
 *
 * Automates the Drupal side of the setup `modules/canvas_oauth/README.md`
 * describes by hand: installing canvas_oauth, generating the OAuth signing
 * keys somewhere the web server cannot serve them, provisioning the consumer
 * the `canvas` CLI logs in against, and granting the developer's account the
 * permissions the scopes that CLI requests resolve to.
 *
 * Every step no-ops when its outcome already exists, so a re-run after a
 * failure fast-forwards to the first step that still has work to do. Nothing
 * is recorded outside the site: the site's own configuration is the state.
 *
 * @see https://www.drupal.org/project/canvas
 */
final class CanvasSetupCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * The command name, also quoted in the failures it reports.
   */
  private const COMMAND = 'canvas:setup-code-components';

  /**
   * The scopes `canvas login` requests when the site serves no discovery doc.
   *
   * Canvas serves none today, so these are the scopes every login asks for.
   * Requesting one that does not exist fails the whole login with
   * `invalid_scope`, which is why `canvas:media:image:create` makes an image
   * media type a precondition rather than a nicety.
   *
   * @see packages/cli/src/config.ts
   * @see \Drupal\simple_oauth\Repositories\ScopeRepository::getScopeEntityByIdentifier()
   */
  private const LOGIN_SCOPES = [
    'canvas:js_component',
    'canvas:asset_library',
    'canvas:media:image:create',
    'canvas:media:view',
  ];

  /**
   * The role carrying the permissions those scopes resolve to.
   */
  private const ROLE_ID = 'canvas_code_components';

  /**
   * The port the CLI's login callback server listens on by default.
   *
   * @see packages/cli/src/services/auth.ts
   */
  private const DEFAULT_CALLBACK_PORT = 4444;

  /**
   * The npm package the printed next steps tell the developer to run.
   */
  private const CLI_PACKAGE = '@drupal-canvas/cli@latest';

  /**
   * The npm package that scaffolds a component library.
   */
  private const CREATE_PACKAGE = '@drupal-canvas/create@latest';

  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ModuleInstallerInterface $moduleInstaller,
    private readonly ModuleExtensionList $moduleExtensionList,
  ) {
    parent::__construct();
  }

  /**
   * Prepares this site for code components pushed from the Canvas CLI.
   *
   * @param array $options
   *   Command options. Every prompted value has one, so the whole command runs
   *   unattended under --no-interaction.
   *
   * @return int
   *   The command exit code.
   */
  #[CLI\Command(name: self::COMMAND)]
  #[CLI\Option(name: 'client-id', description: 'OAuth client ID the CLI logs in with.')]
  #[CLI\Option(name: 'label', description: 'Human-readable label for the OAuth consumer.')]
  #[CLI\Option(name: 'site-url', description: 'Site URL to print in the next steps.')]
  #[CLI\Option(name: 'account', description: 'Username of the Drupal account that will log in from the CLI.')]
  #[CLI\Option(name: 'key-dir', description: 'Absolute directory for the OAuth signing keys. Must be outside the docroot.')]
  #[CLI\Option(name: 'port', description: "Port the CLI's login callback server listens on.")]
  #[CLI\Option(name: 'ci', description: 'Also provision a confidential client_credentials consumer, and print its secret once.')]
  #[CLI\Usage(name: 'drush canvas:setup-code-components', description: 'Set the site up, prompting only for what cannot be defaulted.')]
  #[CLI\Usage(name: 'drush canvas:setup-code-components --no-interaction', description: 'Accept every default. Suitable for CI and scripted demos.')]
  #[CLI\Usage(name: 'drush canvas:setup-code-components --client-id=my-cli --key-dir=/srv/keys --ci', description: 'Name the consumer, place the keys explicitly, and provision a CI consumer too.')]
  public function setupCodeComponents(
    array $options = [
      'client-id' => self::OPT,
      'label' => self::OPT,
      'site-url' => self::OPT,
      'account' => self::OPT,
      'key-dir' => self::OPT,
      'port' => self::OPT,
      'ci' => FALSE,
    ],
  ): int {
    $io = $this->io();

    $this->assertSimpleOauthAvailable();
    $this->ensureCanvasOauthInstalled();

    // Prompting happens after the modules are installed, both because the
    // account default cannot be resolved before then and so that abandoning a
    // prompt leaves the site in a state a re-run fast-forwards through.
    $client_id = $options['client-id'] ?? $io->ask('OAuth client ID', 'canvas-cli');
    $label = $options['label'] ?? $io->ask('Consumer label', 'Canvas CLI');
    $account_name = $options['account'] ?? $io->ask('Drupal account that will log in from the CLI', self::defaultAccountName());
    $key_dir = $options['key-dir'] ?? $io->ask('Directory for the OAuth signing keys (outside the docroot)', self::defaultKeyDirectory());
    $site_url = $options['site-url'] ?? $io->ask('Site URL', self::defaultSiteUrl());
    $port = (int) ($options['port'] ?? self::DEFAULT_CALLBACK_PORT);

    $account = self::loadAccount((string) $account_name);
    $site_url = rtrim((string) $site_url, '/');

    $this->ensureImageMediaType();
    $this->ensureKeys((string) $key_dir);
    $this->ensureLoginConsumer((string) $client_id, (string) $label, $port);
    $this->ensurePermissions($account);
    if ($options['ci'] === TRUE) {
      $this->ensureCiConsumer($client_id . '-ci', $label . ' (CI)', $account);
    }

    $this->printNextSteps($site_url, (string) $client_id, (string) $account_name, $port);

    return self::EXIT_SUCCESS;
  }

  /**
   * Fails unless the Simple OAuth module is present in the codebase.
   */
  private function assertSimpleOauthAvailable(): void {
    if ($this->moduleExtensionList->exists('simple_oauth')) {
      return;
    }
    throw new \RuntimeException("Canvas' external API authenticates with the Simple OAuth module, which is not in this codebase.\nRun this, then run me again:\n\n  composer require drupal/simple_oauth:^6\n");
  }

  /**
   * Installs canvas_oauth, which pulls in simple_oauth and consumers.
   */
  private function ensureCanvasOauthInstalled(): void {
    if ($this->moduleHandler->moduleExists('canvas_oauth')) {
      $this->step('Module canvas_oauth is already installed.');
      return;
    }
    $this->moduleInstaller->install(['canvas_oauth']);
    $this->step('Installed canvas_oauth, with simple_oauth and consumers.');
  }

  /**
   * Ensures a media type the `canvas:media:image:create` scope can be built on.
   *
   * The CLI asks for that scope by name on every login, and canvas_oauth only
   * creates it for a media type whose machine name and source plugin are both
   * `image`. Without one, login dies at the authorize step with
   * `invalid_scope`, so a site that has no image media type gets one here.
   */
  private function ensureImageMediaType(): void {
    $storage = self::entityTypeManager()->getStorage('media_type');
    $media_type = $storage->load('image');
    if ($media_type instanceof MediaTypeInterface) {
      $source_id = $media_type->getSource()->getPluginId();
      if ($source_id !== 'image') {
        throw new \RuntimeException(\sprintf("The media type `image` uses the `%s` source, so canvas_oauth cannot create the `canvas:media:image:create` scope the CLI requests, and login will fail with invalid_scope.\nRename that media type, or add one whose machine name is `image` and whose source is Image, at:\n\n  %s/admin/structure/media/add\n", $source_id, self::defaultSiteUrl()));
      }
      $this->step('Media type `image` already exists.');
    }
    else {
      $media_type = $storage->create(['id' => 'image', 'label' => 'Image', 'source' => 'image']);
      \assert($media_type instanceof MediaTypeInterface);
      $media_type->save();
      self::createSourceField($media_type);
      $this->step('Created the `image` media type, which the canvas:media:image:create scope needs.');
    }

    // Scopes are only generated when canvas_oauth is installed, so a media
    // type added afterwards, including the one just created, needs this.
    self::mediaScopesHelper()->ensureMediaImageScopes();
  }

  /**
   * Creates and displays a media type's source field, as MediaTypeForm does.
   *
   * @see \Drupal\media\MediaTypeForm::save()
   */
  private static function createSourceField(MediaTypeInterface $media_type): void {
    $source = $media_type->getSource();
    $source_field = $source->createSourceField($media_type);
    $storage_definition = $source_field->getFieldStorageDefinition();
    \assert($storage_definition instanceof FieldStorageConfigInterface);
    if ($storage_definition->isNew()) {
      $storage_definition->save();
    }
    $source_field->save();
    $media_type->set('source_configuration', ['source_field' => $source_field->getName()])->save();

    $display_repository = \Drupal::service(EntityDisplayRepositoryInterface::class);
    if ($source_field->isDisplayConfigurable('form')) {
      $display = $display_repository->getFormDisplay('media', (string) $media_type->id());
      $source->prepareFormDisplay($media_type, $display);
      $display->save();
    }
    if ($source_field->isDisplayConfigurable('view')) {
      $display = $display_repository->getViewDisplay('media', (string) $media_type->id());
      foreach (\array_keys($display->getComponents()) as $name) {
        $display->removeComponent($name);
      }
      $source->prepareViewDisplay($media_type, $display);
      $display->save();
    }
  }

  /**
   * Ensures an RSA key pair exists somewhere the web server cannot serve it.
   *
   * The key that signs every access token is the most sensitive thing this
   * command produces. Putting it under the docroot is not a smaller problem
   * than not having it at all, so an unsafe directory is a hard failure rather
   * than a warning: on nginx there is no .htaccess to fall back on.
   */
  private function ensureKeys(string $key_dir): void {
    if (!\extension_loaded('openssl')) {
      throw new \RuntimeException("PHP's openssl extension is not loaded, so the OAuth signing keys cannot be generated. Enable it, then run me again.\n");
    }
    if (!str_starts_with($key_dir, '/')) {
      throw new \RuntimeException(\sprintf("The key directory must be an absolute path, got `%s`. Run:\n\n  drush %s --key-dir=%s/keys\n", $key_dir, self::COMMAND, \dirname(DRUPAL_ROOT)));
    }
    $key_dir = rtrim($key_dir, '/');
    if (self::isInsideDocroot($key_dir)) {
      throw new \RuntimeException(\sprintf("Refusing to put the OAuth signing keys at `%s`: it is inside the docroot (%s), where the web server can serve the private key. Pick a directory outside it:\n\n  drush %s --key-dir=%s/keys\n", $key_dir, DRUPAL_ROOT, self::COMMAND, \dirname(DRUPAL_ROOT)));
    }

    if (!is_dir($key_dir) && !@mkdir($key_dir, 0700, TRUE) && !is_dir($key_dir)) {
      throw new \RuntimeException(\sprintf("Could not create the key directory `%s`. Create it yourself, then run me again:\n\n  mkdir -p %s && chmod 700 %s\n", $key_dir, $key_dir, $key_dir));
    }
    @chmod($key_dir, 0700);
    if (!is_writable($key_dir)) {
      throw new \RuntimeException(\sprintf("The key directory `%s` is not writable by the user running this command. Fix that, then run me again:\n\n  chmod 700 %s\n", $key_dir, $key_dir));
    }
    // Wherever this directory landed, it must survive being inside somebody's
    // checkout without ever being committed.
    file_put_contents($key_dir . '/.gitignore', "*\n");

    $private_key = $key_dir . '/private.key';
    $public_key = $key_dir . '/public.key';
    $settings = self::configFactory()->getEditable('simple_oauth.settings');
    $previous_private_key = (string) $settings->get('private_key');

    if ($settings->get('private_key') === $private_key && $settings->get('public_key') === $public_key && file_exists($private_key) && file_exists($public_key)) {
      $this->step(\sprintf('OAuth signing keys are already in place at %s.', $key_dir));
      return;
    }

    self::generateKeyPair($key_dir);
    $settings
      ->set('private_key', $private_key)
      ->set('public_key', $public_key)
      ->save();
    $this->step(\sprintf('Generated the OAuth signing keys at %s, private key mode 0600.', $key_dir));

    $warning = self::replacedKeysWarning($previous_private_key, $private_key);
    if ($warning !== NULL) {
      $this->io()->warning($warning);
    }
  }

  /**
   * Writes a fresh key pair, reusing simple_oauth's own generator.
   *
   * That generator writes both keys at mode 0600, refuses the public files
   * directory, and drops a deny-all .htaccess beside them.
   */
  private static function generateKeyPair(string $key_dir): void {
    $generator = \Drupal::service('simple_oauth.key.generator');
    \assert($generator instanceof KeyGeneratorServiceInterface);
    try {
      $generator->generateKeys($key_dir);
    }
    catch (FilesystemValidationException | ExtensionNotLoadedException $e) {
      throw new \RuntimeException(\sprintf("Could not generate the OAuth signing keys in `%s`: %s\n", $key_dir, $e->getMessage()), 0, $e);
    }
  }

  /**
   * Says what replacing the site's previous keys just cost, or NULL if nothing.
   */
  private static function replacedKeysWarning(string $previous_private_key, string $private_key): ?string {
    if ($previous_private_key === '' || $previous_private_key === $private_key) {
      return NULL;
    }
    $resolved = \Drupal::service(FileSystemInterface::class)->realpath($previous_private_key);
    $was_web_reachable = \is_string($resolved) && self::isInsideDocroot($resolved);
    return \sprintf(
      'Replaced the keys this site was using (%s)%s. Every access token issued before now is invalid, so run `canvas login` again.%s',
      $previous_private_key,
      $was_web_reachable ? ', which were inside the docroot and served over HTTP' : '',
      $was_web_reachable ? \sprintf("\nDelete them:\n\n  rm %s %s", $resolved, \dirname((string) $resolved) . '/public.key') : '',
    );
  }

  /**
   * Ensures the public consumer `canvas login` authenticates against.
   *
   * Public rather than confidential: the CLI proves possession of the
   * authorization code with PKCE and sends no client secret, so a confidential
   * consumer would reject the token exchange with invalid_client.
   *
   * @see \Drupal\simple_oauth\Repositories\ClientRepository::validateClient()
   */
  private function ensureLoginConsumer(string $client_id, string $label, int $port): void {
    $unchanged = self::ensureConsumer($client_id, [
      'label' => $label,
      'confidential' => FALSE,
      'pkce' => TRUE,
      'grant_types' => ['authorization_code', 'refresh_token'],
      'redirect' => [\sprintf('http://localhost:%d/callback', $port)],
      'authorization_code_scopes' => self::canvasScopeIds(),
    ]);
    $this->step($unchanged
      ? \sprintf('Consumer `%s` is already configured for canvas login.', $client_id)
      : \sprintf('Configured consumer `%s` for canvas login: authorization code with PKCE, callback on port %d.', $client_id, $port)
    );
  }

  /**
   * Ensures a confidential consumer for unattended client_credentials runs.
   */
  private function ensureCiConsumer(string $client_id, string $label, UserInterface $account): void {
    if (self::loadConsumer($client_id) !== NULL) {
      $this->step(\sprintf('Consumer `%s` already exists. Its secret is hashed and cannot be shown again; set a new one at /admin/config/services/consumer if you need it.', $client_id));
      return;
    }
    // Shown once below and never again: the field hashes the value on save.
    // @see \Drupal\Core\Field\Plugin\Field\FieldType\PasswordItem::preSave()
    $secret = Crypt::randomBytesBase64(32);
    self::ensureConsumer($client_id, [
      'label' => $label,
      'confidential' => TRUE,
      'pkce' => FALSE,
      'secret' => $secret,
      'grant_types' => ['client_credentials'],
      'user_id' => $account->id(),
      'scopes' => self::canvasScopeIds(),
    ]);
    $this->step(\sprintf('Configured consumer `%s` for client_credentials, acting as `%s`.', $client_id, $account->getAccountName()));
    // Deliberately printed here and nowhere else: never handed to the logger,
    // never written to config, and not recoverable from the site afterwards.
    $this->io()->writeln([
      '',
      '  CI credentials, shown once:',
      '',
      '    CANVAS_CLIENT_ID=' . $client_id,
      '    CANVAS_CLIENT_SECRET=' . $secret,
      '',
      '  Put them in your secret store now. They are not printed again.',
    ]);
  }

  /**
   * Creates or updates a consumer, leaving an already-correct one untouched.
   *
   * @param string $client_id
   *   The client ID identifying the consumer.
   * @param array $values
   *   Field values the consumer must end up with.
   *
   * @return bool
   *   TRUE when the consumer already matched and nothing was written.
   */
  private static function ensureConsumer(string $client_id, array $values): bool {
    $consumer = self::loadConsumer($client_id);
    if ($consumer === NULL) {
      self::entityTypeManager()->getStorage('consumer')
        ->create(['client_id' => $client_id, 'is_default' => FALSE, 'third_party' => FALSE] + $values)
        ->save();
      return FALSE;
    }

    // A hashed secret never compares equal to its plaintext, so an existing
    // consumer's secret is left alone rather than silently rotated out from
    // under whatever is already using it.
    unset($values['secret']);
    // Setting a value normalizes it through the field item, so comparing the
    // normalized before and after is what tells us whether anything moved.
    $before = [];
    foreach (\array_keys($values) as $field) {
      $before[$field] = $consumer->get($field)->getValue();
    }
    $changed = FALSE;
    foreach ($values as $field => $value) {
      $consumer->set($field, $value);
      // Loose comparison: storage returns numeric strings where the desired
      // values are integers and booleans.
      if ($consumer->get($field)->getValue() != $before[$field]) {
        $changed = TRUE;
      }
    }
    if ($changed) {
      $consumer->save();
      return FALSE;
    }
    // Nothing was written, so drop the copy those set() calls mutated: the
    // site is left exactly as this command found it.
    self::entityTypeManager()->getStorage('consumer')->resetCache([(int) $consumer->id()]);
    return TRUE;
  }

  /**
   * Grants the account the permissions the requested scopes resolve to.
   *
   * Under the authorization code grant Simple OAuth intersects a scope's
   * permissions with the logged-in user's, so a token is only ever as capable
   * as the account behind it.
   *
   * @see \Drupal\simple_oauth\Access\Oauth2AccessPolicy::alterPermissions()
   */
  private function ensurePermissions(UserInterface $account): void {
    $scope_storage = self::entityTypeManager()->getStorage('oauth2_scope');
    $permissions = [];
    foreach (self::LOGIN_SCOPES as $scope_name) {
      $scope = $scope_storage->load(Oauth2Scope::scopeToMachineName($scope_name));
      if ($scope instanceof Oauth2Scope) {
        $permissions = [...$permissions, ...$scope->getPermissions()];
      }
    }
    $permissions = array_values(array_unique($permissions));

    $missing = array_values(array_filter($permissions, static fn (string $permission): bool => !$account->hasPermission($permission)));
    if ($missing === []) {
      $this->step(\sprintf('Account `%s` already holds every permission those scopes resolve to.', $account->getAccountName()));
      return;
    }

    $role_storage = self::entityTypeManager()->getStorage('user_role');
    $role = $role_storage->load(self::ROLE_ID) ?? $role_storage->create([
      'id' => self::ROLE_ID,
      'label' => 'Canvas code components',
    ]);
    \assert($role instanceof RoleInterface);
    foreach ($permissions as $permission) {
      $role->grantPermission($permission);
    }
    $role->save();
    if (!$account->hasRole(self::ROLE_ID)) {
      $account->addRole(self::ROLE_ID);
      $account->save();
    }
    $this->step(\sprintf('Gave `%s` the %s role, for: %s.', $account->getAccountName(), self::ROLE_ID, implode(', ', $missing)));
  }

  /**
   * Prints the next steps, filled in with the values just configured.
   */
  private function printNextSteps(string $site_url, string $client_id, string $account_name, int $port): void {
    $this->io()->writeln([
      '',
      'This site is ready for Canvas code components.',
      '',
      \sprintf('  Site URL   %s', $site_url),
      \sprintf('  Client ID  %s', $client_id),
      \sprintf('  Log in as  %s', $account_name),
      '',
      'Start a component library:',
      '',
      \sprintf('  npx %s my-components --site-url %s', self::CREATE_PACKAGE, $site_url),
      '  cd my-components',
      \sprintf('  npx %s login --site-url %s --client-id %s%s', self::CLI_PACKAGE, $site_url, $client_id, $port === self::DEFAULT_CALLBACK_PORT ? '' : ' --port ' . $port),
      \sprintf('  npx %s scaffold --name hello-world', self::CLI_PACKAGE),
      \sprintf('  npx %s push', self::CLI_PACKAGE),
      '',
      \sprintf('Then open %s/canvas and find "Hello World" in the component library.', $site_url),
      '',
    ]);
  }

  /**
   * Loads the account that will log in, failing with the command that adds it.
   */
  private static function loadAccount(string $name): UserInterface {
    $accounts = self::entityTypeManager()->getStorage('user')->loadByProperties(['name' => $name]);
    $account = reset($accounts);
    if (!$account instanceof UserInterface) {
      throw new \RuntimeException(\sprintf("No Drupal account is named `%s`. Create it, then run me again:\n\n  drush user:create %s\n", $name, $name));
    }
    return $account;
  }

  /**
   * Loads a consumer by its client ID.
   */
  private static function loadConsumer(string $client_id): ?ConsumerInterface {
    $consumers = self::entityTypeManager()->getStorage('consumer')->loadByProperties(['client_id' => $client_id]);
    $consumer = reset($consumers);
    return $consumer instanceof ConsumerInterface ? $consumer : NULL;
  }

  /**
   * Every scope canvas_oauth defines, so later pushes are not scope-limited.
   *
   * @return array<int, array{scope_id: string}>
   *   Scope field values, one per scope canvas_oauth defines.
   */
  private static function canvasScopeIds(): array {
    $ids = [];
    foreach (self::entityTypeManager()->getStorage('oauth2_scope')->loadMultiple() as $id => $scope) {
      \assert($scope instanceof Oauth2Scope);
      if (str_starts_with($scope->getName(), 'canvas:')) {
        $ids[] = ['scope_id' => (string) $id];
      }
    }
    usort($ids, static fn (array $a, array $b): int => $a['scope_id'] <=> $b['scope_id']);
    return $ids;
  }

  /**
   * The account the developer most likely logs in as.
   */
  private static function defaultAccountName(): string {
    $account = self::entityTypeManager()->getStorage('user')->load(1);
    $name = $account instanceof UserInterface ? $account->getAccountName() : '';
    return $name !== '' ? $name : 'admin';
  }

  /**
   * A key directory beside the docroot rather than inside it.
   */
  private static function defaultKeyDirectory(): string {
    $parent = \dirname(DRUPAL_ROOT);
    if ($parent === DRUPAL_ROOT || $parent === '' || $parent === '.') {
      throw new \RuntimeException(\sprintf("This site's docroot (%s) has no parent directory to hold the OAuth signing keys, and they must not live under the docroot. Name one explicitly:\n\n  drush %s --key-dir=/absolute/path\n", DRUPAL_ROOT, self::COMMAND));
    }
    return $parent . '/keys';
  }

  /**
   * The site URL, in a form Node's HTTP client will actually talk to.
   *
   * DDEV serves HTTPS with a certificate signed by a locally trusted CA that
   * Node does not read, so an https://*.ddev.site URL fails the CLI's token
   * exchange. `@drupal-canvas/create` rewrites it the same way.
   *
   * @see packages/create/src/lib/site-url.ts
   */
  private static function defaultSiteUrl(): string {
    $url = \Drupal::request()->getSchemeAndHttpHost();
    $host = parse_url($url, PHP_URL_HOST);
    if (str_starts_with($url, 'https://') && \is_string($host) && str_ends_with($host, '.ddev.site')) {
      return 'http://' . substr($url, \strlen('https://'));
    }
    return $url;
  }

  /**
   * Whether a path sits under the docroot, and so may be web-reachable.
   */
  private static function isInsideDocroot(string $path): bool {
    $docroot = realpath(DRUPAL_ROOT);
    if ($docroot === FALSE) {
      return FALSE;
    }
    $resolved = realpath($path);
    // A directory that does not exist yet is judged on the path it would take.
    $candidate = $resolved === FALSE ? $path : $resolved;
    return $candidate === $docroot || str_starts_with($candidate, $docroot . '/');
  }

  /**
   * Reports one completed step.
   */
  private function step(string $message): void {
    $this->io()->writeln('  ' . $message);
  }

  /**
   * The entity type manager, resolved from the container as it stands now.
   *
   * Installing canvas_oauth rebuilds the container, so anything injected into
   * this command's constructor is a stale object that has never heard of the
   * entity types that installation added.
   *
   * @see \Drupal\canvas\PropShape\PropShape::moduleHandler()
   */
  private static function entityTypeManager(): EntityTypeManagerInterface {
    return \Drupal::entityTypeManager();
  }

  /**
   * The config factory, resolved from the container as it stands now.
   *
   * @see self::entityTypeManager()
   */
  private static function configFactory(): ConfigFactoryInterface {
    return \Drupal::configFactory();
  }

  /**
   * Canvas_oauth's media scope helper, only loadable once it is installed.
   *
   * @see self::entityTypeManager()
   */
  private static function mediaScopesHelper(): MediaScopesHelper {
    return \Drupal::classResolver(MediaScopesHelper::class);
  }

}
