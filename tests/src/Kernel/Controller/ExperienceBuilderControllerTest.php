<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Controller;

use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\experience_builder\Kernel\Traits\PageTrait;
use Drupal\Tests\experience_builder\Kernel\Traits\RequestTrait;
use Drupal\Tests\experience_builder\Kernel\Traits\XbUiAssertionsTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the Experience Builder UI mount for various entity types.
 *
 * @group experience_builder
 */
final class ExperienceBuilderControllerTest extends KernelTestBase {

  use PageTrait;
  use RequestTrait;
  use UserCreationTrait;
  use XbUiAssertionsTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'experience_builder',
    'system',
    'entity_test',
    ...self::PAGE_TEST_MODULES,
  ];

  protected function setUp(): void {
    parent::setUp();
    // Needed for date formats.
    $this->installConfig(['system']);
    $this->installEntitySchema('path_alias');
  }

  /**
   * Tests controller output when adding or editing an entity.
   *
   * @param string $entity_type
   *   The entity type.
   * @param array $permissions
   *   The permissions.
   * @param array $values
   *   The values.
   *
   * @dataProvider entityData
   */
  public function testController(string $entity_type, array $permissions, array $values): void {
    $this->installEntitySchema($entity_type);

    $this->setUpCurrentUser([], $permissions);

    $add_url = Url::fromRoute('experience_builder.experience_builder', [
      'entity_type' => $entity_type,
      'entity' => '',
    ])->toString();
    self::assertEquals("/xb/$entity_type", $add_url);
    $this->request(Request::create($add_url));
    $this->assertExperienceBuilderMount($entity_type);

    $storage = $this->container->get('entity_type.manager')->getStorage($entity_type);
    $sut = $storage->create($values);
    $sut->save();

    $edit_url = Url::fromRoute('experience_builder.experience_builder', [
      'entity_type' => $entity_type,
      'entity' => $sut->id(),
    ])->toString();
    self::assertEquals("/xb/$entity_type/{$sut->id()}", $edit_url);
    $this->request(Request::create($edit_url));

    $this->assertExperienceBuilderMount($entity_type, $sut);
  }

  public static function entityData(): array {
    return [
      'page' => [
        'xb_page',
        ['administer xb_page'],
        [
          'title' => 'Test page',
          'description' => 'This is a test page.',
          'components' => [],
        ],
      ],
      'entity_test' => [
        'entity_test',
        ['access administration pages'],
        [
          'name' => 'Test entity',
        ],
      ],
    ];
  }

}
