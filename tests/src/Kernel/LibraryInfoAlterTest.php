<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\Core\Render\HtmlResponse;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\experience_builder\Controller\ExperienceBuilderController;
use Drupal\experience_builder\Entity\Page;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * @covers \Drupal\experience_builder\Hook\ReduxIntegratedFieldWidgetsHooks::transformsLibraryInfoAlter()
 * @group experience_builder
 */
final class LibraryInfoAlterTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'experience_builder',
    'system',
    'xb_test_page',
    'media',
    'user',
    'image',
    'file',
    'path_alias',
    'path',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $this->installConfig(['system']);

  }

  /**
   * Tests that libraries with xb.transform prefix are dynamically added.
   */
  public function testTransformMounting(): void {
    $this->setUpCurrentUser([], [Page::CREATE_PERMISSION]);
    $page = Page::create([
      'title' => 'Test page',
      'description' => 'This is a test page.',
      'components' => [],
    ]);
    $page->save();
    $context = new RenderContext();
    $renderer = $this->container->get(RendererInterface::class);
    \assert($renderer instanceof RendererInterface);
    $out = $renderer->executeInRenderContext($context, fn () => $this->container->get(ExperienceBuilderController::class)(Page::ENTITY_TYPE_ID, $page));
    \assert($out instanceof HtmlResponse);
    $attachments = $out->getAttachments();
    self::assertEquals([
      'experience_builder/xb.transform.mainProperty',
      'experience_builder/xb.transform.firstRecord',
      'experience_builder/xb.transform.dateTime',
      'experience_builder/xb.transform.mediaSelection',
      'experience_builder/xb.transform.cast',
      'experience_builder/xb.transform.link',
      'xb_test_page/xb.transform.diaclone',
    ], array_values(array_filter(
      $attachments['library'],
      fn (string $lib) => str_contains($lib, '/xb.transform.'),
    )));
  }

}
