<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Config;

use Drupal\canvas\Entity\ComponentTreeEntityInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Config\ImportStorageTransformer;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\StorageInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\DataProviderWithComponentTreeTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * Tests exporting and importing config-defined component trees.
 *
 * @see \Drupal\canvas\EventSubscriber\ComponentTreeConfigEntityTransformer
 *
 * Not tested here: translations. That is highly dependent on the config entity
 * type:
 * - Patterns are not translatable.
 * - ContentTemplates are translatable, but allow more prop sources than any
 *   other component tree (EntityFieldPropSource etc).
 * - PageRegions are translatable, but do not allow additional prop sources.
 *
 * Hence this focuses on testing the foundations; translation-specifics must be
 * tested in each config entity's test coverage.
 *
 * @see \Drupal\Tests\canvas\Kernel\Config\ContentTemplateTest::testTranslationLifeCycleInDepth()
 */
abstract class ConfigWithComponentTreeTestBase extends CanvasKernelTestBase {

  use DataProviderWithComponentTreeTrait;
  use GenerateComponentConfigTrait;

  /**
   * The config entity with a component tree being tested.
   */
  protected ComponentTreeEntityInterface&ConfigEntityInterface $entity;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    // The two only modules Drupal truly requires.
    'system',
    'user',
    // The module being tested.
    'canvas',
    // Modules providing used Components (and their ComponentSource plugins).
    'block',
    'canvas_test_sdc',
    // Canvas's dependencies (modules providing field types + widgets).
    'field',
    'file',
    'image',
    'link',
    'media',
    'node',
    'options',
    'text',
    'filter',
    'ckeditor5',
    'editor',
    'datetime',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->generateComponentConfig();
  }

  /**
   * Tests the DX of config-defined component trees: sequence keys are crucial.
   *
   * Tests the sequence keys:
   * - at runtime, in the entity object  (i.e. immediately after creation)
   * - in active storage (i.e. after saving the config entity)
   * - export (which requires a transformation to make the keys intelligible and
   *   tamper-resistant)
   * - import of untampered export (which naturally should work)
   * - import of tampered export (which illustrates the protection against merge
   *   conflict resolution gone wrong, or worse)
   *
   * @see \Drupal\KernelTests\Core\Config\ConfigExportStorageTest::testExportStorage())
   * @see \Drupal\KernelTests\Core\Config\ImportStorageTransformerTest
   * @see \Drupal\Tests\config\Functional\TransformedConfigExportImportUITest
   *
   * @see \Drupal\Tests\canvas\Kernel\Config\ContentTemplateTest::testTreeKeyOrderingDx()
   */
  #[TestWith([
    [
      [
        'parent_uuid' => '3a76bf4f-9306-43e6-ba8f-cb4b5b6459df',
        'slot' => 'the_body',
        'uuid' => 'b7e2cf39-d62f-4ee8-99b2-27a89f1ac196',
        'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
        'component_version' => 'b1e991f726a2a266',
        'inputs' => [
          'heading' => 'Two layers deep.',
        ],
      ],
      [
        'uuid' => '4f785025-9bd9-4752-9dd6-068b957b03ee',
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => '85a5c0c7dd53e0bb',
        'inputs' => [
          'heading' => 'Hello, world!',
        ],
      ],
      [
        'parent_uuid' => '4f785025-9bd9-4752-9dd6-068b957b03ee',
        'slot' => 'the_footer',
        'uuid' => '5f1c5361-5658-467e-9c53-b0015d57945d',
        'component_id' => 'block.system_powered_by_block',
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => [
          'label' => '',
          'label_display' => '0',
        ],
      ],
      [
        'uuid' => '3a76bf4f-9306-43e6-ba8f-cb4b5b6459df',
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => '85a5c0c7dd53e0bb',
        'parent_uuid' => '4f785025-9bd9-4752-9dd6-068b957b03ee',
        'slot' => 'the_body',
        'inputs' => [
          'heading' => 'Hello from the top of the body',
        ],
      ],
      [
        'uuid' => '5f71027b-d9d3-4f3d-8990-a6502c0ba676',
        'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
        'component_version' => 'b1e991f726a2a266',
        'inputs' => [
          'heading' => 'two layers deep',
        ],
      ],
      [
        'uuid' => '93af433a-8ab0-4dd9-912a-73a99c882347',
        'component_id' => 'block.system_branding_block',
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'parent_uuid' => '4f785025-9bd9-4752-9dd6-068b957b03ee',
        'slot' => 'the_body',
        'inputs' => [
          'label' => '',
          'label_display' => '0',
          'use_site_logo' => TRUE,
          'use_site_name' => TRUE,
          'use_site_slogan' => TRUE,
        ],
      ],
    ],
    [
      '4f785025-9bd9-4752-9dd6-068b957b03ee' => [
        'uuid' => '4f785025-9bd9-4752-9dd6-068b957b03ee',
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => '85a5c0c7dd53e0bb',
        'inputs' => [
          'heading' => 'Hello, world!',
        ],
      ],
      '3a76bf4f-9306-43e6-ba8f-cb4b5b6459df' => [
        'parent_uuid' => '4f785025-9bd9-4752-9dd6-068b957b03ee',
        'slot' => 'the_body',
        'uuid' => '3a76bf4f-9306-43e6-ba8f-cb4b5b6459df',
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => '85a5c0c7dd53e0bb',
        'inputs' => [
          'heading' => 'Hello from the top of the body',
        ],
      ],
      'b7e2cf39-d62f-4ee8-99b2-27a89f1ac196' => [
        'parent_uuid' => '3a76bf4f-9306-43e6-ba8f-cb4b5b6459df',
        'slot' => 'the_body',
        'uuid' => 'b7e2cf39-d62f-4ee8-99b2-27a89f1ac196',
        'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
        'component_version' => 'b1e991f726a2a266',
        'inputs' => [
          'heading' => 'Two layers deep.',
        ],
      ],
      '93af433a-8ab0-4dd9-912a-73a99c882347' => [
        'parent_uuid' => '4f785025-9bd9-4752-9dd6-068b957b03ee',
        'slot' => 'the_body',
        'uuid' => '93af433a-8ab0-4dd9-912a-73a99c882347',
        'component_id' => 'block.system_branding_block',
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => [
          'label' => '',
          'label_display' => '0',
          'use_site_logo' => TRUE,
          'use_site_name' => TRUE,
          'use_site_slogan' => TRUE,
        ],
      ],
      '5f1c5361-5658-467e-9c53-b0015d57945d' => [
        'parent_uuid' => '4f785025-9bd9-4752-9dd6-068b957b03ee',
        'slot' => 'the_footer',
        'uuid' => '5f1c5361-5658-467e-9c53-b0015d57945d',
        'component_id' => 'block.system_powered_by_block',
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => [
          'label' => '',
          'label_display' => '0',
        ],
      ],
      '5f71027b-d9d3-4f3d-8990-a6502c0ba676' => [
        'uuid' => '5f71027b-d9d3-4f3d-8990-a6502c0ba676',
        'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
        'component_version' => 'b1e991f726a2a266',
        'inputs' => [
          'heading' => 'two layers deep',
        ],
      ],
    ],
    [
      '0:4f785025-9bd9-4752-9dd6-068b957b03ee',
      '0:the_body:0:3a76bf4f-9306-43e6-ba8f-cb4b5b6459df',
      '0:the_body:0:the_body:0:b7e2cf39-d62f-4ee8-99b2-27a89f1ac196',
      '0:the_body:1:93af433a-8ab0-4dd9-912a-73a99c882347',
      '0:the_footer:0:5f1c5361-5658-467e-9c53-b0015d57945d',
      '1:5f71027b-d9d3-4f3d-8990-a6502c0ba676',
    ],
  ], 'Simple case')]
  #[TestWith([
    [
      [
        'uuid' => '4f785025-9bd9-4752-9dd6-068b957b03ee',
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => '85a5c0c7dd53e0bb',
        'inputs' => [
          'heading' => 'Outer slot',
        ],
      ],
      [
        'uuid' => '33a67161-a77b-4192-a575-d9d96635399c',
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => '85a5c0c7dd53e0bb',
        'parent_uuid' => '4f785025-9bd9-4752-9dd6-068b957b03ee',
        'slot' => 'the_body',
        'inputs' => [
          'heading' => 'Level 1 slot',
        ],
      ],
      [
        'uuid' => '1955e628-73ae-4334-a354-06fcbda376d6',
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => '85a5c0c7dd53e0bb',
        'parent_uuid' => '33a67161-a77b-4192-a575-d9d96635399c',
        'slot' => 'the_body',
        'inputs' => [
          'heading' => 'Level 2 slot',
        ],
      ],
      [
        'uuid' => '5f1c5361-5658-467e-9c53-b0015d57945d',
        'component_id' => 'block.system_powered_by_block',
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'parent_uuid' => '1955e628-73ae-4334-a354-06fcbda376d6',
        'slot' => 'the_body',
        'inputs' => [
          'label' => '',
          'label_display' => '0',
        ],
      ],
      [
        'uuid' => '3a76bf4f-9306-43e6-ba8f-cb4b5b6459df',
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => '85a5c0c7dd53e0bb',
        'parent_uuid' => '1955e628-73ae-4334-a354-06fcbda376d6',
        'slot' => 'the_body',
        'inputs' => [
          'heading' => 'Just after the powered by block',
        ],
      ],
      [
        'uuid' => 'b16e28d2-ec29-480c-9944-ca72eac5d16f',
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => '85a5c0c7dd53e0bb',
        'parent_uuid' => '1955e628-73ae-4334-a354-06fcbda376d6',
        'slot' => 'the_body',
        'inputs' => [
          'heading' => 'Last one in the body in level 2 slot',
        ],
      ],
      [
        'uuid' => '5a039deb-db16-42fd-a91d-8b5a189afbc3',
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => '85a5c0c7dd53e0bb',
        'parent_uuid' => '4f785025-9bd9-4752-9dd6-068b957b03ee',
        'slot' => 'the_body',
        'inputs' => [
          'heading' => 'Level 1 slot #2',
        ],
      ],
      [
        'uuid' => '8dc67694-59c6-4efe-92e9-d8e3f9d03f51',
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => '85a5c0c7dd53e0bb',
        'parent_uuid' => '83e58222-88ff-40d7-ad70-4d0efa5b9172',
        'slot' => 'the_footer',
        'inputs' => [
          'heading' => '1 of 6 in the footer',
        ],
      ],
      [
        'uuid' => 'b6e8eba3-7f41-4115-9d24-67223909dcd4',
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => '85a5c0c7dd53e0bb',
        'parent_uuid' => '83e58222-88ff-40d7-ad70-4d0efa5b9172',
        'slot' => 'the_footer',
        'inputs' => [
          'heading' => '2 of 6 in the footer',
        ],
      ],
      [
        'uuid' => '36b6338a-12b4-485f-a4f6-209f438e6804',
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => '85a5c0c7dd53e0bb',
        'parent_uuid' => '83e58222-88ff-40d7-ad70-4d0efa5b9172',
        'slot' => 'the_footer',
        'inputs' => [
          'heading' => '3 of 6 in the footer',
        ],
      ],
      [
        'uuid' => 'ac1e278a-2f0f-4166-a98d-1d390b3d0aa8',
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => '85a5c0c7dd53e0bb',
        'parent_uuid' => '83e58222-88ff-40d7-ad70-4d0efa5b9172',
        'slot' => 'the_footer',
        'inputs' => [
          'heading' => '4 of 6 in the footer',
        ],
      ],
      [
        'uuid' => '09309f76-377f-456c-ab29-b5a10eecab48',
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => '85a5c0c7dd53e0bb',
        'parent_uuid' => '83e58222-88ff-40d7-ad70-4d0efa5b9172',
        'slot' => 'the_footer',
        'inputs' => [
          'heading' => '5 of 6 in the footer',
        ],
      ],
      [
        'uuid' => '294a32af-0bcc-4e45-9044-ac51d9b9a7df',
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => '85a5c0c7dd53e0bb',
        'parent_uuid' => '83e58222-88ff-40d7-ad70-4d0efa5b9172',
        'slot' => 'the_footer',
        'inputs' => [
          'heading' => '6 of 6 in the footer',
        ],
      ],
      // Note this is the parent slot of the preceding items, but should be
      // sorted above them.
      [
        'uuid' => '83e58222-88ff-40d7-ad70-4d0efa5b9172',
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => '85a5c0c7dd53e0bb',
        'parent_uuid' => '5a039deb-db16-42fd-a91d-8b5a189afbc3',
        'slot' => 'the_body',
        'inputs' => [
          'heading' => 'Level 2 slot #2',
        ],
      ],
    ],
    [
      '4f785025-9bd9-4752-9dd6-068b957b03ee' => [
        'uuid' => '4f785025-9bd9-4752-9dd6-068b957b03ee',
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => '85a5c0c7dd53e0bb',
        'inputs' => [
          'heading' => 'Outer slot',
        ],
      ],
      '33a67161-a77b-4192-a575-d9d96635399c' => [
        'parent_uuid' => '4f785025-9bd9-4752-9dd6-068b957b03ee',
        'slot' => 'the_body',
        'uuid' => '33a67161-a77b-4192-a575-d9d96635399c',
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => '85a5c0c7dd53e0bb',
        'inputs' => [
          'heading' => 'Level 1 slot',
        ],
      ],
      '1955e628-73ae-4334-a354-06fcbda376d6' => [
        'parent_uuid' => '33a67161-a77b-4192-a575-d9d96635399c',
        'slot' => 'the_body',
        'uuid' => '1955e628-73ae-4334-a354-06fcbda376d6',
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => '85a5c0c7dd53e0bb',
        'inputs' => [
          'heading' => 'Level 2 slot',
        ],
      ],
      '5f1c5361-5658-467e-9c53-b0015d57945d' => [
        'parent_uuid' => '1955e628-73ae-4334-a354-06fcbda376d6',
        'slot' => 'the_body',
        'uuid' => '5f1c5361-5658-467e-9c53-b0015d57945d',
        'component_id' => 'block.system_powered_by_block',
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => [
          'label' => '',
          'label_display' => '0',
        ],
      ],
      '3a76bf4f-9306-43e6-ba8f-cb4b5b6459df' => [
        'parent_uuid' => '1955e628-73ae-4334-a354-06fcbda376d6',
        'slot' => 'the_body',
        'uuid' => '3a76bf4f-9306-43e6-ba8f-cb4b5b6459df',
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => '85a5c0c7dd53e0bb',
        'inputs' => [
          'heading' => 'Just after the powered by block',
        ],
      ],
      'b16e28d2-ec29-480c-9944-ca72eac5d16f' => [
        'parent_uuid' => '1955e628-73ae-4334-a354-06fcbda376d6',
        'slot' => 'the_body',
        'uuid' => 'b16e28d2-ec29-480c-9944-ca72eac5d16f',
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => '85a5c0c7dd53e0bb',
        'inputs' => [
          'heading' => 'Last one in the body in level 2 slot',
        ],
      ],
      '5a039deb-db16-42fd-a91d-8b5a189afbc3' => [
        'parent_uuid' => '4f785025-9bd9-4752-9dd6-068b957b03ee',
        'slot' => 'the_body',
        'uuid' => '5a039deb-db16-42fd-a91d-8b5a189afbc3',
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => '85a5c0c7dd53e0bb',
        'inputs' => [
          'heading' => 'Level 1 slot #2',
        ],
      ],
      '83e58222-88ff-40d7-ad70-4d0efa5b9172' => [
        'parent_uuid' => '5a039deb-db16-42fd-a91d-8b5a189afbc3',
        'slot' => 'the_body',
        'uuid' => '83e58222-88ff-40d7-ad70-4d0efa5b9172',
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => '85a5c0c7dd53e0bb',
        'inputs' => [
          'heading' => 'Level 2 slot #2',
        ],
      ],
      '8dc67694-59c6-4efe-92e9-d8e3f9d03f51' => [
        'parent_uuid' => '83e58222-88ff-40d7-ad70-4d0efa5b9172',
        'slot' => 'the_footer',
        'uuid' => '8dc67694-59c6-4efe-92e9-d8e3f9d03f51',
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => '85a5c0c7dd53e0bb',
        'inputs' => [
          'heading' => '1 of 6 in the footer',
        ],
      ],
      'b6e8eba3-7f41-4115-9d24-67223909dcd4' => [
        'parent_uuid' => '83e58222-88ff-40d7-ad70-4d0efa5b9172',
        'slot' => 'the_footer',
        'uuid' => 'b6e8eba3-7f41-4115-9d24-67223909dcd4',
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => '85a5c0c7dd53e0bb',
        'inputs' => [
          'heading' => '2 of 6 in the footer',
        ],
      ],
      '36b6338a-12b4-485f-a4f6-209f438e6804' => [
        'parent_uuid' => '83e58222-88ff-40d7-ad70-4d0efa5b9172',
        'slot' => 'the_footer',
        'uuid' => '36b6338a-12b4-485f-a4f6-209f438e6804',
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => '85a5c0c7dd53e0bb',
        'inputs' => [
          'heading' => '3 of 6 in the footer',
        ],
      ],
      'ac1e278a-2f0f-4166-a98d-1d390b3d0aa8' => [
        'parent_uuid' => '83e58222-88ff-40d7-ad70-4d0efa5b9172',
        'slot' => 'the_footer',
        'uuid' => 'ac1e278a-2f0f-4166-a98d-1d390b3d0aa8',
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => '85a5c0c7dd53e0bb',
        'inputs' => [
          'heading' => '4 of 6 in the footer',
        ],
      ],
      '09309f76-377f-456c-ab29-b5a10eecab48' => [
        'parent_uuid' => '83e58222-88ff-40d7-ad70-4d0efa5b9172',
        'slot' => 'the_footer',
        'uuid' => '09309f76-377f-456c-ab29-b5a10eecab48',
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => '85a5c0c7dd53e0bb',
        'inputs' => [
          'heading' => '5 of 6 in the footer',
        ],
      ],
      '294a32af-0bcc-4e45-9044-ac51d9b9a7df' => [
        'parent_uuid' => '83e58222-88ff-40d7-ad70-4d0efa5b9172',
        'slot' => 'the_footer',
        'uuid' => '294a32af-0bcc-4e45-9044-ac51d9b9a7df',
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => '85a5c0c7dd53e0bb',
        'inputs' => [
          'heading' => '6 of 6 in the footer',
        ],
      ],
    ],
    [
      '0:4f785025-9bd9-4752-9dd6-068b957b03ee',
      '0:the_body:0:33a67161-a77b-4192-a575-d9d96635399c',
      '0:the_body:0:the_body:0:1955e628-73ae-4334-a354-06fcbda376d6',
      '0:the_body:0:the_body:0:the_body:0:5f1c5361-5658-467e-9c53-b0015d57945d',
      '0:the_body:0:the_body:0:the_body:1:3a76bf4f-9306-43e6-ba8f-cb4b5b6459df',
      '0:the_body:0:the_body:0:the_body:2:b16e28d2-ec29-480c-9944-ca72eac5d16f',
      '0:the_body:1:5a039deb-db16-42fd-a91d-8b5a189afbc3',
      '0:the_body:1:the_body:0:83e58222-88ff-40d7-ad70-4d0efa5b9172',
      '0:the_body:1:the_body:0:the_footer:0:8dc67694-59c6-4efe-92e9-d8e3f9d03f51',
      '0:the_body:1:the_body:0:the_footer:1:b6e8eba3-7f41-4115-9d24-67223909dcd4',
      '0:the_body:1:the_body:0:the_footer:2:36b6338a-12b4-485f-a4f6-209f438e6804',
      '0:the_body:1:the_body:0:the_footer:3:ac1e278a-2f0f-4166-a98d-1d390b3d0aa8',
      '0:the_body:1:the_body:0:the_footer:4:09309f76-377f-456c-ab29-b5a10eecab48',
      '0:the_body:1:the_body:0:the_footer:5:294a32af-0bcc-4e45-9044-ac51d9b9a7df',
    ],
  ], 'Complex nesting')]
  #[TestWith([
    [
      [
        'uuid' => '9a0f0c96-aa92-4b10-a895-58ce3f33c023',
        'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
        'component_version' => 'b1e991f726a2a266',
        'inputs' => [
          'heading' => 'Top level component, no children',
        ],
      ],
      [
        'uuid' => '6792ad62-fbec-4ddc-8dd8-fff2f2dab881',
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => '85a5c0c7dd53e0bb',
        'inputs' => [
          'heading' => 'Top level, Has 1 child',
        ],
      ],
      [
        'uuid' => 'cd7d0b31-21c1-4544-9c7b-9949d040f866',
        'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
        'component_version' => 'b1e991f726a2a266',
        'inputs' => [
          'heading' => 'Level 1 child',
        ],
        'slot' => 'the_body',
        'parent_uuid' => '6792ad62-fbec-4ddc-8dd8-fff2f2dab881',
      ],
    ],
    [
      '9a0f0c96-aa92-4b10-a895-58ce3f33c023' => [
        'uuid' => '9a0f0c96-aa92-4b10-a895-58ce3f33c023',
        'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
        'component_version' => 'b1e991f726a2a266',
        'inputs' => [
          'heading' => 'Top level component, no children',
        ],
      ],
      '6792ad62-fbec-4ddc-8dd8-fff2f2dab881' => [
        'uuid' => '6792ad62-fbec-4ddc-8dd8-fff2f2dab881',
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => '85a5c0c7dd53e0bb',
        'inputs' => [
          'heading' => 'Top level, Has 1 child',
        ],
      ],
      'cd7d0b31-21c1-4544-9c7b-9949d040f866' => [
        'parent_uuid' => '6792ad62-fbec-4ddc-8dd8-fff2f2dab881',
        'slot' => 'the_body',
        'uuid' => 'cd7d0b31-21c1-4544-9c7b-9949d040f866',
        'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
        'component_version' => 'b1e991f726a2a266',
        'inputs' => [
          'heading' => 'Level 1 child',
        ],
      ],
    ],
    [
      '0:9a0f0c96-aa92-4b10-a895-58ce3f33c023',
      '1:6792ad62-fbec-4ddc-8dd8-fff2f2dab881',
      '1:the_body:0:cd7d0b31-21c1-4544-9c7b-9949d040f866',
    ],
  ], 'Top level sort with last item only having one child.')]
  #[TestWith([
    [
      [
        'uuid' => 'cd7d0b31-21c1-4544-9c7b-9949d040f867',
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => '85a5c0c7dd53e0bb',
        'inputs' => [
          'heading' => 'Level 1, Has no children',
        ],
        'slot' => 'the_body',
        'parent_uuid' => '6792ad62-fbec-4ddc-8dd8-fff2f2dab880',
      ],
      [
        'uuid' => 'cd7d0b31-21c1-4544-9c7b-9949d040f890',
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => '85a5c0c7dd53e0bb',
        'inputs' => [
          'heading' => 'Level 1, Has 1 child',
        ],
        'slot' => 'the_body',
        'parent_uuid' => '6792ad62-fbec-4ddc-8dd8-fff2f2dab880',
      ],
      [
        'uuid' => '9a0f0c96-aa92-4b10-a895-58ce3f33c078',
        'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
        'component_version' => 'b1e991f726a2a266',
        'inputs' => [
          'heading' => 'Level 2, no children',
        ],
        'slot' => 'the_body',
        'parent_uuid' => 'cd7d0b31-21c1-4544-9c7b-9949d040f890',
      ],
      [
        'uuid' => '9a0f0c96-aa92-4b10-a895-58ce3f33c022',
        'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
        'component_version' => 'b1e991f726a2a266',
        'inputs' => [
          'heading' => 'Top level component, no children',
        ],
      ],
      [
        'uuid' => '6792ad62-fbec-4ddc-8dd8-fff2f2dab880',
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => '85a5c0c7dd53e0bb',
        'inputs' => [
          'heading' => 'Top level, Has 2 children',
        ],
      ],
    ],
    [
      '9a0f0c96-aa92-4b10-a895-58ce3f33c022' => [
        'uuid' => '9a0f0c96-aa92-4b10-a895-58ce3f33c022',
        'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
        'component_version' => 'b1e991f726a2a266',
        'inputs' => [
          'heading' => 'Top level component, no children',
        ],
      ],
      '6792ad62-fbec-4ddc-8dd8-fff2f2dab880' => [
        'uuid' => '6792ad62-fbec-4ddc-8dd8-fff2f2dab880',
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => '85a5c0c7dd53e0bb',
        'inputs' => [
          'heading' => 'Top level, Has 2 children',
        ],
      ],
      'cd7d0b31-21c1-4544-9c7b-9949d040f867' => [
        'parent_uuid' => '6792ad62-fbec-4ddc-8dd8-fff2f2dab880',
        'slot' => 'the_body',
        'uuid' => 'cd7d0b31-21c1-4544-9c7b-9949d040f867',
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => '85a5c0c7dd53e0bb',
        'inputs' => [
          'heading' => 'Level 1, Has no children',
        ],
      ],
      'cd7d0b31-21c1-4544-9c7b-9949d040f890' => [
        'parent_uuid' => '6792ad62-fbec-4ddc-8dd8-fff2f2dab880',
        'slot' => 'the_body',
        'uuid' => 'cd7d0b31-21c1-4544-9c7b-9949d040f890',
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => '85a5c0c7dd53e0bb',
        'inputs' => [
          'heading' => 'Level 1, Has 1 child',
        ],
      ],
      '9a0f0c96-aa92-4b10-a895-58ce3f33c078' => [
        'parent_uuid' => 'cd7d0b31-21c1-4544-9c7b-9949d040f890',
        'slot' => 'the_body',
        'uuid' => '9a0f0c96-aa92-4b10-a895-58ce3f33c078',
        'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
        'component_version' => 'b1e991f726a2a266',
        'inputs' => [
          'heading' => 'Level 2, no children',
        ],
      ],
    ],
    [
      '0:9a0f0c96-aa92-4b10-a895-58ce3f33c022',
      '1:6792ad62-fbec-4ddc-8dd8-fff2f2dab880',
      '1:the_body:0:cd7d0b31-21c1-4544-9c7b-9949d040f867',
      '1:the_body:1:cd7d0b31-21c1-4544-9c7b-9949d040f890',
      '1:the_body:1:the_body:0:9a0f0c96-aa92-4b10-a895-58ce3f33c078',
    ],
  ], 'It is possible to list the deepest-in-the-tree component instances first; all that should matter is the order within each level (each parent_uuid + slot pair)')]
  public function testTreeKeyOrderingDx(array $tree_input, array $expected_sorted_output, array $expected_component_tree_keys_in_export_storage): void {
    $tree_input = self::populateActiveComponentVersionPlaceholders($tree_input);
    $expected_sorted_output = self::populateActiveComponentVersionPlaceholders($expected_sorted_output);

    // Keys depend on the storage:
    // - active storage: "at rest in Drupal" storage
    // - export storage: "at rest when exported to YAML for config sync" storage
    $expected_component_tree_keys_in_active_storage = \array_keys($expected_sorted_output);
    // Values are the same in both active + export.
    $expected_component_tree_values = \array_values($expected_sorted_output);

    // Set the given component tree on the config entity, validate and save.
    $this->entity->setComponentTree($tree_input);
    self::assertEntityIsValid($this->entity);
    $this->entity->save();
    $config_name = $this->entity->getConfigDependencyName();

    // Runtime: the precise original order is used, even if for example the
    // first list component tree instance is actually placed in a slot.
    // TRICKY: the expected keys are the same, just ordered differently, but
    // still correctly. Exporting and saving will result in the order changing
    // to list the first component instance in the root level first.
    self::assertEqualsCanonicalizing($expected_sorted_output, $this->entity->get('component_tree'));
    // If the given instance order happens to exactly match the tree-based
    // ordering, it will be a complete match, otherwise just a set match.
    $happens_to_be_in_depth_first_order = \array_column($tree_input, 'uuid') === $expected_component_tree_keys_in_active_storage;
    if ($happens_to_be_in_depth_first_order) {
      self::assertSame($expected_sorted_output, $this->entity->get('component_tree'));
    }
    else {
      self::assertNotSame($expected_sorted_output, $this->entity->get('component_tree'));
    }

    // Active storage: identical to runtime.
    $active_storage = $this->container->get('config.storage');
    self::assertInstanceOf(StorageInterface::class, $active_storage);
    self::assertSame(StorageInterface::DEFAULT_COLLECTION, $active_storage->getCollectionName());
    self::assertSame([], $active_storage->getAllCollectionNames());
    // @phpstan-ignore-next-line offsetAccess.nonOffsetAccessible
    self::assertSame($this->entity->get('component_tree'), $active_storage->read($config_name)['component_tree']);

    // Export config: keys should now contain position information to improve DX
    // and be tamper-resistant.
    $export_storage = $this->container->get('config.storage.export');
    self::assertInstanceOf(StorageInterface::class, $export_storage);
    self::assertSame(StorageInterface::DEFAULT_COLLECTION, $export_storage->getCollectionName());
    self::assertSame([], $export_storage->getAllCollectionNames());
    self::assertSame(
      \array_combine($expected_component_tree_keys_in_export_storage, $expected_component_tree_values),
      // @phpstan-ignore-next-line offsetAccess.nonOffsetAccessible
      $export_storage->read($config_name)['component_tree'],
    );

    $import_storage_transformer = $this->container->get(ImportStorageTransformer::class);
    self::assertInstanceOf(ImportStorageTransformer::class, $import_storage_transformer);

    // Test re-importing an untampered config export.
    $untampered_export = new MemoryStorage();
    $this->copyConfig($export_storage, $untampered_export);
    $import_storage = $import_storage_transformer->transform($untampered_export);
    self::assertSame(
      $expected_sorted_output,
      // @phpstan-ignore-next-line offsetAccess.nonOffsetAccessible
      $import_storage->read($config_name)['component_tree'],
    );

    // Test re-importing an TAMPERED config export.
    $tampered_export = new MemoryStorage();
    $this->copyConfig($export_storage, $tampered_export);
    $raw = $tampered_export->read($config_name);
    // @phpstan-ignore-next-line offsetAccess.nonOffsetAccessible
    $raw['component_tree'] = \array_reverse($raw['component_tree'], preserve_keys: TRUE);
    $tampered_export->write($config_name, $raw);
    $import_storage = $import_storage_transformer->transform($tampered_export);
    self::assertSame(
      $expected_sorted_output,
      // @phpstan-ignore-next-line offsetAccess.nonOffsetAccessible
      $import_storage->read($config_name)['component_tree'],
    );
  }

}
