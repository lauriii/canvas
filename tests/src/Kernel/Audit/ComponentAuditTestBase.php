<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Audit;

use Drupal\experience_builder\Entity\Page;
use Drupal\experience_builder\Plugin\ComponentPluginManager;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldTypePropExpression;
use Drupal\experience_builder\PropSource\StaticPropSource;
use Drupal\KernelTests\KernelTestBase;

/**
 * Defines a base class for component audit tests.
 */
abstract class ComponentAuditTestBase extends KernelTestBase {

  protected static $modules = [
    'experience_builder',
    'file',
    'image',
    'link',
    'options',
    'system',
    'media',
    'path',
    'user',
    'xb_test_sdc',
    'text',
  ];

  protected array $tree = [];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installEntitySchema('user');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $this->container->get(ComponentPluginManager::class)->getDefinitions();
    $this->tree = [
      [
        'uuid' => 'my-component',
        'component_id' => 'sdc.xb_test_sdc.my-cta',
        'inputs' => [
          'text' => StaticPropSource::generate(
            expression: new FieldTypePropExpression('string', 'value'),
            cardinality: 1,
          )->withValue('Hey there')->toArray(),
          'href' => StaticPropSource::generate(
            expression: new FieldTypePropExpression('uri', 'value'),
            cardinality: 1,
          )->withValue('https://drupal.org/')->toArray(),
        ],
      ],
    ];
  }

}
