<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Kernel;

use Drupal\canvas_headless\Cache\FrontendCacheTagInvalidator;
use Drupal\canvas_headless\PreviewAssertionFactory;
use Drupal\Component\Utility\Crypt;
use Drupal\consumers\Entity\Consumer;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the install-time provisioning and uninstall-time cleanup.
 *
 * Kernel tests do not run install hooks, so the hooks are called directly;
 * what is under test is their consumer bookkeeping, not module install
 * plumbing. The status report, which is the safety net for a missing
 * consumer, is covered here too.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas_headless')]
class ConsumerProvisioningTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...self::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
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
    $this->installEntitySchema('user');
    $this->installEntitySchema('consumer');
    // simple_oauth 6.1.2+ deletes tokens on consumer deletion, which requires
    // the oauth2_token table to exist even in tests that do not exercise it.
    $this->installEntitySchema('oauth2_token');
    include_once __DIR__ . '/../../../canvas_headless.install';
  }

  /**
   * Tests that uninstalling removes the consumer the module created.
   */
  public function testProvisionedConsumerIsDeletedOnUninstall(): void {
    canvas_headless_install();

    $storage = $this->container->get(EntityTypeManagerInterface::class)->getStorage('consumer');
    $consumers = $storage->loadByProperties(['client_id' => PreviewAssertionFactory::CLIENT_ID]);
    self::assertCount(1, $consumers);
    $consumer = reset($consumers);
    self::assertSame(
      $consumer->uuid(),
      $this->container->get('state')->get('canvas_headless.provisioned_consumer_uuid'),
    );

    // The cache invalidation state of the frontends is forgotten too.
    $states = $this->container->get('keyvalue')->get(FrontendCacheTagInvalidator::STATE_COLLECTION);
    $states->set('a', ['url' => 'https://www.example.com']);

    canvas_headless_uninstall();

    self::assertSame([], $storage->loadByProperties(['client_id' => PreviewAssertionFactory::CLIENT_ID]));
    self::assertNull($this->container->get('state')->get('canvas_headless.provisioned_consumer_uuid'));
    self::assertSame([], $states->getAll());
  }

  /**
   * Tests that an adopted consumer survives uninstall.
   *
   * Installation adopts a pre-existing consumer with the module's client_id
   * instead of creating a second one. That consumer belongs to the site,
   * not to this module, so uninstalling must leave it in place.
   */
  public function testAdoptedConsumerSurvivesUninstall(): void {
    Consumer::create([
      'client_id' => PreviewAssertionFactory::CLIENT_ID,
      'label' => 'Pre-existing consumer',
      'confidential' => FALSE,
    ])->save();

    canvas_headless_install();

    $storage = $this->container->get(EntityTypeManagerInterface::class)->getStorage('consumer');
    self::assertCount(1, $storage->loadByProperties(['client_id' => PreviewAssertionFactory::CLIENT_ID]));
    self::assertNull($this->container->get('state')->get('canvas_headless.provisioned_consumer_uuid'));

    canvas_headless_uninstall();

    $consumers = $storage->loadByProperties(['client_id' => PreviewAssertionFactory::CLIENT_ID]);
    self::assertCount(1, $consumers);
    $consumer = reset($consumers);
    self::assertSame('Pre-existing consumer', $consumer->label());
  }

  /**
   * Tests the status report entries.
   */
  public function testStatusReport(): void {
    // The date formats, and the frontends.
    $this->installConfig(['system', 'canvas_headless']);

    $requirements = $this->requirements();
    self::assertSame(['canvas_headless_consumer'], \array_keys($requirements));
    self::assertSame(RequirementSeverity::Error, $requirements['canvas_headless_consumer']['severity']);
    canvas_headless_install();
    self::assertSame([], \array_keys($this->requirements()));

    $this->config('canvas_headless.settings')
      ->set('frontends', [
        ['url' => 'https://failing.example/app', 'components' => []],
        ['url' => 'https://missed.example', 'components' => []],
        ['url' => 'https://ok.example', 'components' => []],
        ['url' => 'https://never.example', 'components' => []],
      ])
      ->save();
    $state = [
      'missed_at' => NULL,
      'pending' => [],
      'failures' => 0,
      'last_error' => NULL,
      'last_failure' => NULL,
      'last_success' => 1700000000,
      'retry_after' => 0,
    ];
    $states = $this->container->get('keyvalue')->get(FrontendCacheTagInvalidator::STATE_COLLECTION);
    $states->set(Crypt::hashBase64('https://ok.example'), ['url' => 'https://ok.example'] + $state);
    // Not reported: the frontend never read an announcement, so it may have
    // no such route.
    $states->set(Crypt::hashBase64('https://never.example'), ['url' => 'https://never.example', 'missed_at' => 1700000100.0, 'failures' => 9, 'last_error' => 'HTTP 404', 'last_failure' => 1700000100, 'last_success' => NULL] + $state);
    self::assertSame([], \array_keys($this->requirements()));

    $states->set(Crypt::hashBase64('https://failing.example/app'), ['url' => 'https://failing.example/app', 'missed_at' => 1700000100.0, 'failures' => 3, 'last_error' => 'HTTP 500', 'last_failure' => 1700000100] + $state);
    // The frontend read an announcement made before it missed another.
    $states->set(Crypt::hashBase64('https://missed.example'), ['url' => 'https://missed.example', 'missed_at' => 1700000100.0, 'last_failure' => 1700000100] + $state);
    $requirements = $this->requirements();
    self::assertSame(['canvas_headless_cache_invalidation'], \array_keys($requirements));
    $requirement = $requirements['canvas_headless_cache_invalidation'];
    self::assertSame(RequirementSeverity::Warning, $requirement['severity']);
    $description = (string) $this->container->get('renderer')->renderInIsolation($requirement['description']);
    self::assertStringContainsString('https://failing.example/app: HTTP 500 (', $description);
    self::assertStringContainsString('https://missed.example: An announcement did not reach the frontend (', $description);
    self::assertStringContainsString('Each frontend is told to expire everything once it is reachable.', $description);
    self::assertStringNotContainsString('ok.example', $description);
    self::assertStringNotContainsString('never.example', $description);
  }

  /**
   * Returns the status report entries of the module.
   *
   * @return array<string, array<string, mixed>>
   *   The requirements.
   */
  private function requirements(): array {
    return $this->container->get(ModuleHandlerInterface::class)->invoke('canvas_headless', 'runtime_requirements');
  }

}
