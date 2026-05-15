<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\PropSource;

use Drupal\canvas\MissingHostEntityException;
use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\canvas\PropSource\HostEntityPropSource;
use Drupal\canvas\PropSource\PropSource;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * @see \Drupal\canvas\PropSource\HostEntityPropSource
 */
#[CoversClass(HostEntityPropSource::class)]
#[Group('canvas')]
#[Group('canvas_data_model')]
#[RunTestsInSeparateProcesses]
class HostEntityPropSourceTest extends PropSourceTestBase {

  public function testEvaluateThrowsOnNullHost(): void {
    $this->expectException(MissingHostEntityException::class);
    (new HostEntityPropSource())->evaluate(NULL, FALSE);
  }

  public function testEvaluateReturnsHostEntity(): void {
    $entity = $this->container->get(EntityRepositoryInterface::class)
      ->loadEntityByUuid('media', self::IMAGE_MEDIA_UUID1);
    self::assertNotNull($entity);
    $result = (new HostEntityPropSource())->evaluate($entity, FALSE);
    self::assertSame($entity, $result->value);
  }

  public function testCalculateDependenciesIsEmpty(): void {
    $source = new HostEntityPropSource();
    self::assertSame([], $source->calculateDependencies());
    $entity = $this->container->get(EntityRepositoryInterface::class)
      ->loadEntityByUuid('media', self::IMAGE_MEDIA_UUID1);
    self::assertSame([], $source->calculateDependencies($entity));
  }

  public function testParseRoundTrip(): void {
    $source = PropSource::parse(['sourceType' => 'host-entity']);
    self::assertInstanceOf(HostEntityPropSource::class, $source);
    self::assertSame(['sourceType' => 'host-entity'], $source->toArray());
    self::assertSame(PropSource::HostEntity->value, $source->getSourceType());

    // JSON serialization round-trip.
    $json = (string) $source;
    self::assertSame(Json::encode(['sourceType' => 'host-entity']), $json);
    $reparsed = PropSource::parse(Json::decode($json));
    self::assertInstanceOf(HostEntityPropSource::class, $reparsed);
    self::assertSame($source->toArray(), $reparsed->toArray());
  }

  public function testEvaluateResultCacheability(): void {
    $entity = $this->container->get(EntityRepositoryInterface::class)
      ->loadEntityByUuid('media', self::IMAGE_MEDIA_UUID1);
    self::assertNotNull($entity);
    self::assertEquals(
      new EvaluationResult($entity, CacheableMetadata::createFromObject($entity)),
      (new HostEntityPropSource())->evaluate($entity, FALSE),
    );
  }

  public function testAsChoice(): void {
    self::assertSame('host-entity', (new HostEntityPropSource())->asChoice());
  }

  public function testLabel(): void {
    $source = new HostEntityPropSource();

    self::assertSame(
      'This user',
      (string) $source->label(EntityDataDefinition::createFromDataType('entity:user:user')),
    );

    NodeType::create(['name' => 'Article', 'type' => 'article'])->save();
    self::assertSame(
      'This Article content item',
      (string) $source->label(EntityDataDefinition::createFromDataType('entity:node:article')),
    );
  }

  public function testParseAssertsCorrectSourceType(): void {
    $this->expectException(\AssertionError::class);
    HostEntityPropSource::parse(['sourceType' => 'static']);
  }

}
