<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Config;

use Drupal\experience_builder\Entity\Pattern;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\experience_builder\Traits\GenerateComponentConfigTrait;
use Symfony\Component\Validator\ConstraintViolationInterface;

/**
 * Defines a class for testing Pattern config entity.
 *
 * @group experience_builder
 * @coversDefaultClass \Drupal\experience_builder\Entity\Pattern
 */
final class PatternTest extends KernelTestBase {

  use GenerateComponentConfigTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'experience_builder',
    'xb_test_sdc',
    // XB's dependencies (modules providing field types + widgets).
    'datetime',
    'file',
    'image',
    'options',
    'path',
    'link',
    'system',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->generateComponentConfig();
  }

  public function testComponentTreeKeyOrder(): void {
    $pattern = Pattern::create([
      'id' => 'test_pattern',
      'label' => 'Test pattern',
    ]);
    $pattern->set('component_tree', [
      [
        'uuid' => 'b7e2cf39-d62f-4ee8-99b2-27a89f1ac196',
        'component_id' => 'sdc.xb_test_sdc.props-no-slots',
        'component_version' => '95f4f1d5ee47663b',
        'parent_uuid' => '3a76bf4f-9306-43e6-ba8f-cb4b5b6459df',
        'slot' => 'the_body',
        'inputs' => [
          'heading' => 'Two layers deep.',
        ],
      ],
      [
        'uuid' => '4f785025-9bd9-4752-9dd6-068b957b03ee',
        'component_id' => 'sdc.xb_test_sdc.props-slots',
        'component_version' => 'ab4d3ddce315cf64',
        'inputs' => [
          'heading' => 'Hello, world!',
        ],
      ],
      [
        'uuid' => '5f1c5361-5658-467e-9c53-b0015d57945d',
        'component_id' => 'block.system_powered_by_block',
        'component_version' => '3332388cade78d20',
        'parent_uuid' => '4f785025-9bd9-4752-9dd6-068b957b03ee',
        'slot' => 'the_footer',
        'inputs' => [
          'label' => '',
          'label_display' => FALSE,
        ],
      ],
      [
        'uuid' => '3a76bf4f-9306-43e6-ba8f-cb4b5b6459df',
        'component_id' => 'sdc.xb_test_sdc.props-slots',
        'component_version' => 'ab4d3ddce315cf64',
        'parent_uuid' => '4f785025-9bd9-4752-9dd6-068b957b03ee',
        'slot' => 'the_body',
        'inputs' => [
          'heading' => 'Hello from the top of the body',
        ],
      ],
      [
        'uuid' => '5f71027b-d9d3-4f3d-8990-a6502c0ba676',
        'component_id' => 'sdc.xb_test_sdc.props-no-slots',
        'component_version' => '95f4f1d5ee47663b',
        'inputs' => [
          'heading' => 'two layers deep',
        ],
      ],
      [
        'uuid' => '93af433a-8ab0-4dd9-912a-73a99c882347',
        'component_id' => 'block.system_branding_block',
        'component_version' => '247a23298360adb2',
        'parent_uuid' => '4f785025-9bd9-4752-9dd6-068b957b03ee',
        'slot' => 'the_body',
        'inputs' => [
          'use_site_logo' => TRUE,
          'use_site_name' => TRUE,
          'use_site_slogan' => TRUE,
          'label' => '',
          'label_display' => FALSE,
        ],
      ],
    ]);
    self::assertSame(
      [
        '0' => [
          'uuid' => '4f785025-9bd9-4752-9dd6-068b957b03ee',
          'component_id' => 'sdc.xb_test_sdc.props-slots',
          'component_version' => 'ab4d3ddce315cf64',
          'inputs' => [
            'heading' => 'Hello, world!',
          ],
        ],
        '0:the_body:0' => [
          'uuid' => '3a76bf4f-9306-43e6-ba8f-cb4b5b6459df',
          'component_id' => 'sdc.xb_test_sdc.props-slots',
          'component_version' => 'ab4d3ddce315cf64',
          'parent_uuid' => '4f785025-9bd9-4752-9dd6-068b957b03ee',
          'slot' => 'the_body',
          'inputs' => [
            'heading' => 'Hello from the top of the body',
          ],
        ],
        '0:the_body:0:the_body:0' => [
          'uuid' => 'b7e2cf39-d62f-4ee8-99b2-27a89f1ac196',
          'component_id' => 'sdc.xb_test_sdc.props-no-slots',
          'component_version' => '95f4f1d5ee47663b',
          'parent_uuid' => '3a76bf4f-9306-43e6-ba8f-cb4b5b6459df',
          'slot' => 'the_body',
          'inputs' => [
            'heading' => 'Two layers deep.',
          ],
        ],
        '0:the_body:1' => [
          'uuid' => '93af433a-8ab0-4dd9-912a-73a99c882347',
          'component_id' => 'block.system_branding_block',
          'component_version' => '247a23298360adb2',
          'parent_uuid' => '4f785025-9bd9-4752-9dd6-068b957b03ee',
          'slot' => 'the_body',
          'inputs' => [
            'use_site_logo' => TRUE,
            'use_site_name' => TRUE,
            'use_site_slogan' => TRUE,
            'label' => '',
            'label_display' => FALSE,
          ],
        ],
        '0:the_footer:0' => [
          'uuid' => '5f1c5361-5658-467e-9c53-b0015d57945d',
          'component_id' => 'block.system_powered_by_block',
          'component_version' => '3332388cade78d20',
          'parent_uuid' => '4f785025-9bd9-4752-9dd6-068b957b03ee',
          'slot' => 'the_footer',
          'inputs' => [
            'label' => '',
            'label_display' => FALSE,
          ],
        ],
        '1' => [
          'uuid' => '5f71027b-d9d3-4f3d-8990-a6502c0ba676',
          'component_id' => 'sdc.xb_test_sdc.props-no-slots',
          'component_version' => '95f4f1d5ee47663b',
          'inputs' => [
            'heading' => 'two layers deep',
          ],
        ],
      ], $pattern->get('component_tree'),
    );
    // Sanity-check that the test pattern is valid.
    $violations = $pattern->getTypedData()->validate();
    self::assertCount(0, $violations, \implode(', ', \array_map(static fn (ConstraintViolationInterface $violation) => $violation->getMessage(), \iterator_to_array($violations))));
  }

}
