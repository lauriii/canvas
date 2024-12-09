<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Entity;

use Drupal\Core\Session\AccountInterface;
use Drupal\experience_builder\Entity\Page;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\experience_builder\Kernel\Traits\PageTrait;

/**
 * @group experience_builder
 */
final class PageAccessControlHandlerTest extends KernelTestBase {

  use PageTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'experience_builder',
    ...self::PAGE_TEST_MODULES,
  ];

  /**
   * Tests access checks.
   *
   * @param array $permissions
   *   The permissions to grant to the account.
   * @param string $op
   *   The operation to check access for.
   * @param bool $expected_result
   *   The expected result.
   *
   * @dataProvider accessCheckProvider
   */
  public function testAccess(array $permissions, string $op, bool $expected_result): void {
    $this->installPageEntitySchema();

    $access_handler = $this->container->get('entity_type.manager')->getAccessControlHandler('xb_page');
    self::assertNotNull($access_handler);

    $account = $this->createMock(AccountInterface::class);
    $account->expects($this->atLeastOnce())
      ->method('hasPermission')
      ->willReturnCallback(fn ($permission) => in_array($permission, $permissions, TRUE));

    if ($op === 'create') {
      self::assertEquals(
        $expected_result,
        $access_handler->createAccess(NULL, $account)
      );
    }
    else {
      $page = Page::create([]);
      $page->save();
      self::assertEquals(
        $expected_result,
        $access_handler->access($page, $op, $account)
      );
    }
  }

  public static function accessCheckProvider(): array {
    return [
      'create: with admin permission' => [['administer xb_page'], 'create', TRUE],
      'create: without permission' => [['access content'], 'create', FALSE],

      'view: with admin permission' => [['administer xb_page'], 'view', TRUE],
      'view: with permission' => [['access content'], 'view', TRUE],
      'view: without any permissions' => [[], 'view', FALSE],

      'update: with admin permission' => [['administer xb_page'], 'update', TRUE],
      'update: without permission' => [['access content'], 'update', FALSE],

      'delete: with admin permission' => [['administer xb_page'], 'delete', TRUE],
      'delete: without permission' => [['access content'], 'delete', FALSE],
    ];
  }

}
