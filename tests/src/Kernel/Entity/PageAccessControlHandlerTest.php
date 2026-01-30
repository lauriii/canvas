<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Entity;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\canvas\Entity\Page;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\PageTrait;

/**
 * @group canvas
 */
final class PageAccessControlHandlerTest extends KernelTestBase {

  use PageTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas',
    ...self::PAGE_TEST_MODULES,
  ];

  /**
   * Tests access checks.
   *
   * @param array $permissions
   *   The permissions to grant to the account.
   * @param string $op
   *   The operation to check access for.
   * @param bool $published
   *   Whether the page should be published.
   * @param bool $expected_result
   *   The expected result.
   *
   * @dataProvider accessCheckProvider
   */
  public function testAccess(array $permissions, string $op, bool $published, bool $expected_result): void {
    $this->installPageEntitySchema();

    $access_handler = $this->container->get('entity_type.manager')->getAccessControlHandler(Page::ENTITY_TYPE_ID);
    self::assertNotNull($access_handler);

    $account = $this->createMock(AccountInterface::class);
    $account->expects($this->atLeastOnce())
      ->method('hasPermission')
      ->willReturnCallback(fn ($permission) => in_array($permission, $permissions, TRUE));

    if ($op === 'create') {
      $result = $access_handler->createAccess(NULL, $account);
    }
    else {
      $page = Page::create(['status' => $published]);
      $page->save();
      $result = $access_handler->access($page, $op, $account);
    }
    self::assertEquals(
      $expected_result,
      $result
    );
  }

  public static function accessCheckProvider(): array {
    return [
      'create: with create permission' => [[Page::CREATE_PERMISSION], 'create', TRUE, TRUE],
      'create: with edit permission' => [[Page::EDIT_PERMISSION], 'create', TRUE, FALSE],
      'create: with delete permission' => [[Page::DELETE_PERMISSION], 'create', TRUE, FALSE],
      'create: without create permission' => [['access content'], 'create', TRUE, FALSE],

      // Published page view access.
      'view published: with create permission' => [[Page::CREATE_PERMISSION], 'view', TRUE, TRUE],
      'view published: with edit permission' => [[Page::EDIT_PERMISSION], 'view', TRUE, TRUE],
      'view published: with delete permission' => [[Page::DELETE_PERMISSION], 'view', TRUE, TRUE],
      'view published: with access content' => [['access content'], 'view', TRUE, TRUE],
      'view published: without any permissions' => [[], 'view', TRUE, FALSE],

      // Unpublished page view access.
      'view unpublished: with create permission' => [[Page::CREATE_PERMISSION], 'view', FALSE, TRUE],
      'view unpublished: with edit permission' => [[Page::EDIT_PERMISSION], 'view', FALSE, TRUE],
      'view unpublished: with delete permission' => [[Page::DELETE_PERMISSION], 'view', FALSE, TRUE],
      'view unpublished: with access content' => [['access content'], 'view', FALSE, FALSE],
      'view unpublished: without any permissions' => [[], 'view', FALSE, FALSE],

      'update: with create permission' => [[Page::CREATE_PERMISSION], 'update', TRUE, FALSE],
      'update: with edit permission' => [[Page::EDIT_PERMISSION], 'update', TRUE, TRUE],
      'update: with delete permission' => [[Page::DELETE_PERMISSION], 'update', TRUE, FALSE],
      'update: without permission' => [['access content'], 'update', TRUE, FALSE],

      'delete: with create permission' => [[Page::CREATE_PERMISSION], 'delete', TRUE, FALSE],
      'delete: with edit permission' => [[Page::EDIT_PERMISSION], 'delete', TRUE, FALSE],
      'delete: with delete permission' => [[Page::DELETE_PERMISSION], 'delete', TRUE, TRUE],
      'delete: without permission' => [['access content'], 'delete', TRUE, FALSE],

      'view all revisions: with edit permission' => [[Page::EDIT_PERMISSION], 'view all revisions', TRUE, TRUE],
      'view all revisions: without permission' => [['access content'], 'view all revisions', TRUE, FALSE],

      'view revision: with edit permission' => [[Page::EDIT_PERMISSION], 'view revision', TRUE, TRUE],
      'view revision: without permission' => [['access content'], 'view revision', TRUE, FALSE],
    ];
  }

  /**
   * Tests permissions on a non-default revision.
   *
   * @param array $permissions
   *   The permissions to grant to the account.
   * @param string $op
   *   The operation to check access for.
   * @param bool $expected_result
   *   The expected result.
   *
   * @dataProvider revertAccessProvider
   */
  public function testRevertPermissionOnNonDefaultRevision(array $permissions, string $op, bool $expected_result): void {
    $this->installPageEntitySchema();

    // Create a page with an initial revision.
    $page = Page::create(['title' => 'Test Page']);
    $page->save();
    $original_vid = $page->getRevisionId();

    // Create a second revision that is not the default.
    $page->setNewRevision(TRUE);
    $page->set('title', 'Test Page - Revision 2');
    $page->save();

    // Load the non-default revision.
    $non_default_revision = \Drupal::entityTypeManager()
      ->getStorage(Page::ENTITY_TYPE_ID)
      ->loadRevision((int) $original_vid);

    $access_handler = $this->container->get('entity_type.manager')
      ->getAccessControlHandler(Page::ENTITY_TYPE_ID);

    $account = $this->createMock(AccountInterface::class);
    $account->expects($this->atLeastOnce())
      ->method('hasPermission')
      ->willReturnCallback(fn ($permission) => in_array($permission, $permissions, TRUE));

    \assert($non_default_revision instanceof EntityInterface);
    $result = $access_handler->access($non_default_revision, $op, $account);
    self::assertEquals($expected_result, $result);
  }

  public static function revertAccessProvider(): array {
    return [
      'view revision: with edit permission' => [[Page::EDIT_PERMISSION], 'view revision', TRUE],
      'view revision: without permission' => [['access content'], 'view revision', FALSE],

      'revert: with edit permission' => [[Page::EDIT_PERMISSION], 'revert', TRUE],
      'revert: without edit permission' => [['access content'], 'revert', FALSE],
    ];
  }

}
