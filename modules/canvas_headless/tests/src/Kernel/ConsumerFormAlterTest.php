<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Kernel;

use Drupal\canvas_headless\PreviewAssertionFactory;
use Drupal\consumers\Entity\Consumer;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the lock-down of the module's consumer edit form.
 *
 * The form alter disables or hides the fields the preview assertion
 * exchange is keyed to; every other consumer's form must stay untouched.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas_headless')]
class ConsumerFormAlterTest extends CanvasKernelTestBase {

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
    $this->installEntitySchema('file');
    // The user_id widget's autocomplete route generation consults aliases.
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('consumer');
  }

  /**
   * Builds the processed edit form for a consumer.
   */
  private function buildEditForm(Consumer $consumer): array {
    return $this->container->get('entity.form_builder')->getForm($consumer, 'edit');
  }

  /**
   * Tests that the module's consumer has its coupled fields locked.
   */
  public function testModuleConsumerFormIsLocked(): void {
    $consumer = Consumer::create([
      'client_id' => PreviewAssertionFactory::CLIENT_ID,
      'label' => 'Canvas Headless preview',
      'confidential' => FALSE,
      'grant_types' => ['canvas_headless_preview_assertion'],
      'access_token_expiration' => 900,
    ]);
    $consumer->save();

    $form = $this->buildEditForm($consumer);

    self::assertTrue($form['client_id']['#disabled']);
    self::assertFalse($form['client_id']['generate']['#access']);
    self::assertTrue($form['grant_types']['#disabled']);
    self::assertTrue($form['confidential']['#disabled']);
    self::assertFalse($form['new_secret']['#access']);
    self::assertFalse($form['third_party']['#access']);
    // The expiration field stays editable but explains the issuance cap.
    self::assertArrayNotHasKey('#disabled', $form['access_token_expiration']);
    self::assertStringContainsString('15 minutes', (string) $form['access_token_expiration']['widget'][0]['value']['#description']);
  }

  /**
   * Tests that a consumer with another client_id keeps a fully open form.
   */
  public function testOtherConsumerFormStaysOpen(): void {
    $consumer = Consumer::create([
      'client_id' => 'some_other_client',
      'label' => 'Another consumer',
      'confidential' => FALSE,
      'grant_types' => ['client_credentials'],
    ]);
    $consumer->save();

    $form = $this->buildEditForm($consumer);

    self::assertArrayNotHasKey('#disabled', $form['client_id']);
    self::assertNotFalse($form['client_id']['generate']['#access'] ?? TRUE);
    self::assertArrayNotHasKey('#disabled', $form['grant_types']);
    self::assertArrayNotHasKey('#disabled', $form['confidential']);
    self::assertNotFalse($form['new_secret']['#access'] ?? TRUE);
    self::assertNotFalse($form['third_party']['#access'] ?? TRUE);
    self::assertStringNotContainsString('Drupal Canvas Headless', (string) ($form['access_token_expiration']['widget'][0]['value']['#description'] ?? ''));
  }

}
