<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\PropSource;

use Drupal\canvas\MissingHostEntityException;
use Drupal\canvas\PropSource\HostEntityUrlPropSource;
use Drupal\canvas\PropSource\PropSource;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\Exception\UndefinedLinkTemplateException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * @see \Drupal\canvas\PropSource\HostEntityUrlPropSource
 */
#[CoversClass(HostEntityUrlPropSource::class)]
#[Group('canvas')]
#[Group('canvas_data_model')]
#[RunTestsInSeparateProcesses]
class HostEntityUrlPropSourceTest extends PropSourceTestBase {

  /**
   * @param array{sourceType: string, absolute?: boolean} $what_to_parse
   * @param array $expected_array_representation
   * @param string $entity_type_id
   * @param string $entity_uuid
   * @param string|null $expected_url
   * @param class-string<\Throwable>|null $expected_exception
   */
  #[TestWith([
    ['sourceType' => PropSource::HostEntityUrl->value],
    ['sourceType' => PropSource::HostEntityUrl->value, 'absolute' => TRUE],
    'media',
    self::IMAGE_MEDIA_UUID1,
    '/media/1/edit',
    NULL,
  ])]
  #[TestWith([
    ['sourceType' => PropSource::HostEntityUrl->value],
    ['sourceType' => PropSource::HostEntityUrl->value, 'absolute' => TRUE],
    'file',
    self::FILE_UUID1,
    NULL,
    UndefinedLinkTemplateException::class,
  ])]
  #[TestWith([
    ['sourceType' => PropSource::HostEntityUrl->value],
    ['sourceType' => PropSource::HostEntityUrl->value, 'absolute' => TRUE],
    'media',
    'not-a-real-uuid',
    NULL,
    MissingHostEntityException::class,
  ])]
  #[TestWith([
    ['sourceType' => PropSource::HostEntityUrl->value],
    ['sourceType' => PropSource::HostEntityUrl->value, 'absolute' => TRUE],
    'node',
    'with-alias',
    '/awesome-page',
    NULL,
  ])]
  #[TestWith([
    ['sourceType' => PropSource::HostEntityUrl->value],
    ['sourceType' => PropSource::HostEntityUrl->value, 'absolute' => TRUE],
    'node',
    'without-alias',
    '/node/1',
    NULL,
  ])]
  #[TestWith([
    ['sourceType' => PropSource::HostEntityUrl->value, 'absolute' => FALSE],
    ['sourceType' => PropSource::HostEntityUrl->value, 'absolute' => FALSE],
    'node',
    'with-alias',
    '/awesome-page',
    NULL,
  ])]
  public function test(array $what_to_parse, array $expected_array_representation, string $entity_type_id, string $entity_uuid, ?string $expected_url, ?string $expected_exception): void {
    $source = HostEntityUrlPropSource::parse($what_to_parse);
    // Unless otherwise specified, $source->absolute should default to TRUE.
    self::assertSame($what_to_parse['absolute'] ?? TRUE, $source->absolute);

    self::assertArrayHasKey('absolute', $expected_array_representation);
    self::assertSame($expected_array_representation, $source->toArray());
    $expected_json_representation = Json::encode($expected_array_representation);
    self::assertSame($expected_json_representation, (string) $source);

    // Confirm that the array representation can be parsed back.
    $source = PropSource::parse($expected_array_representation);
    self::assertInstanceOf(HostEntityUrlPropSource::class, $source);
    self::assertSame(PropSource::HostEntityUrl->value, $source->getSourceType());
    self::assertSame($expected_array_representation['absolute'], $source->absolute);
    self::assertSame([], $source->calculateDependencies());
    self::assertSame(
      \sprintf('host-entity-url:%s:canonical', $source->absolute ? 'absolute' : 'relative'),
      $source->asChoice(),
    );
    self::assertSame(
      $source->absolute ? 'Absolute URL' : 'Relative URL',
      (string) $source->label(),
    );

    $this->installConfig('node');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->createContentType(['type' => 'page']);
    $this->createNode([
      'type' => 'page',
      'uuid' => 'without-alias',
    ]);
    $this->createNode([
      'type' => 'page',
      'uuid' => 'with-alias',
      'path' => ['alias' => '/awesome-page'],
    ]);

    $entity = $this->container->get(EntityRepositoryInterface::class)
      ->loadEntityByUuid($entity_type_id, $entity_uuid);

    if ($source->absolute) {
      $expected_url = $GLOBALS['base_url'] . $expected_url;
    }
    if ($expected_exception) {
      $this->expectException($expected_exception);
    }
    self::assertSame($expected_url, $source->evaluate($entity, TRUE)->value);
  }

}
