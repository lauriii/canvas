<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Kernel;

use Drupal\canvas_headless\PreviewAssertionFactory;
use Drupal\consumers\Entity\Consumer;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the install-time provisioning and uninstall-time cleanup.
 *
 * Kernel tests do not run install hooks, so the hooks are called directly;
 * what is under test is their consumer bookkeeping, not module install
 * plumbing.
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
    'canvas_headless',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('consumer');
    include_once __DIR__ . '/../../../canvas_headless.install';
  }

  /**
   * Tests that uninstalling removes the consumer the module created.
   */
  public function testProvisionedConsumerIsDeletedOnUninstall(): void {
    canvas_headless_install();

    $storage = $this->container->get('entity_type.manager')->getStorage('consumer');
    $consumers = $storage->loadByProperties(['client_id' => PreviewAssertionFactory::CLIENT_ID]);
    self::assertCount(1, $consumers);
    $consumer = reset($consumers);
    self::assertSame(
      $consumer->uuid(),
      $this->container->get('state')->get('canvas_headless.provisioned_consumer_uuid'),
    );

    canvas_headless_uninstall();

    self::assertSame([], $storage->loadByProperties(['client_id' => PreviewAssertionFactory::CLIENT_ID]));
    self::assertNull($this->container->get('state')->get('canvas_headless.provisioned_consumer_uuid'));
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

    $storage = $this->container->get('entity_type.manager')->getStorage('consumer');
    self::assertCount(1, $storage->loadByProperties(['client_id' => PreviewAssertionFactory::CLIENT_ID]));
    self::assertNull($this->container->get('state')->get('canvas_headless.provisioned_consumer_uuid'));

    canvas_headless_uninstall();

    $consumers = $storage->loadByProperties(['client_id' => PreviewAssertionFactory::CLIENT_ID]);
    self::assertCount(1, $consumers);
    $consumer = reset($consumers);
    self::assertSame('Pre-existing consumer', $consumer->label());
  }

}
