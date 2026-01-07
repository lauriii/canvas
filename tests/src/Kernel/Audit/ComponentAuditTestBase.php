<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Audit;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Page;
use Drupal\KernelTests\KernelTestBase;

/**
 * Defines a base class for component audit tests.
 */
abstract class ComponentAuditTestBase extends KernelTestBase {

  protected static $modules = [
    'canvas',
    'file',
    'image',
    'link',
    'options',
    'system',
    'media',
    'path',
    'user',
    'datetime',
    'canvas_test_sdc',
    'text',
    'filter',
  ];

  protected array $tree = [];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installEntitySchema('user');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $this->container->get(ComponentSourceManager::class)->generateComponents();
    $this->tree = [
      [
        'uuid' => 'my-component',
        'component_id' => 'sdc.canvas_test_sdc.my-cta',
        'inputs' => [
          'text' => 'Hey there',
          'href' => [
            'uri' => 'https://drupal.org/',
            'options' => [],
          ],
        ],
      ],
    ];
  }

}
