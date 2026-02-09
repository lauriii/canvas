<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Audit;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Page;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;

/**
 * Defines a base class for component audit tests.
 */
abstract class ComponentAuditTestBase extends CanvasKernelTestBase {

  protected static $modules = [
    'node',
  ];

  protected array $tree = [];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installEntitySchema('user');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $this->container->get(ComponentSourceManager::class)->generateComponents();
    $tested_component = Component::load('sdc.canvas_test_sdc.my-cta');
    \assert($tested_component instanceof Component);
    $this->tree = [
      [
        'uuid' => $this->container->get('uuid')->generate(),
        'component_id' => $tested_component->id(),
        'component_version' => $tested_component->getActiveVersion(),
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
