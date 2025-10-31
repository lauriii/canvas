<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\canvas_ai\CanvasAiPageBuilderHelper;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests for the CanvasAiPageBuilderHelper.
 *
 * @group canvas_ai
 */
final class CanvasAiPageBuilderHelperTest extends KernelTestBase {

  /**
   * The CanvasAiPageBuilderHelper service.
   *
   * @var \Drupal\canvas_ai\CanvasAiPageBuilderHelper
   */
  protected CanvasAiPageBuilderHelper $canvasAiPageBuilderHelper;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'canvas',
    'canvas_ai',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Get the service from the container.
    $this->canvasAiPageBuilderHelper = $this->container->get('canvas_ai.page_builder_helper');
  }

  /**
   * Tests the convertCurrentLayoutToTree method.
   */
  public function testConvertCurrentLayoutToTree(): void {
    $input = [
      "regions" => [
        "header" => [
          "nodePathPrefix" => [0],
          "components" => [
            [
              "name" => "sdc.starshot_demo.starshot-heading",
              "uuid" => "678e9ee1-dc49-4495-b7cb-9bdd5625a59b",
              "nodePath" => [0, 0],
            ],
          ],
        ],
        "content" => [
          "nodePathPrefix" => [1],
          "components" => [
            [
              "name" => "sdc.canvas_test_sdc.two_column",
              "uuid" => "2f957795-e30a-46a0-acfe-868adc0685bf",
              "nodePath" => [1, 0],
              "slots" => [
                "2f957795-e30a-46a0-acfe-868adc0685bf/column_one" => [
                  "components" => [
                    [
                      "name" => "sdc.canvas_test_sdc.image",
                      "uuid" => "837173ae-5940-4c48-a304-31c6d81901b5",
                      "nodePath" => [1, 0, 0, 0],
                    ],
                  ],
                ],
                "2f957795-e30a-46a0-acfe-868adc0685bf/column_two" => [
                  "components" => [
                    [
                      "name" => "sdc.canvas_test_sdc.druplicon",
                      "uuid" => "4e45ef4c-501c-4612-b02b-1911e88a4592",
                      "nodePath" => [1, 0, 1, 0],
                    ],
                  ],
                ],
              ],
            ],
          ],
        ],
        "footer" => [
          "nodePathPrefix" => [2],
          "components" => [],
        ],
      ],
    ];

    $expected_output = [
      "header" => [
        "678e9ee1-dc49-4495-b7cb-9bdd5625a59b" => [],
      ],
      "content" => [
        "2f957795-e30a-46a0-acfe-868adc0685bf" => [
          "column_one" => [
            "837173ae-5940-4c48-a304-31c6d81901b5" => [],
          ],
          "column_two" => [
            "4e45ef4c-501c-4612-b02b-1911e88a4592" => [],
          ],
        ],
      ],
      "footer" => [],
    ];

    $result = $this->canvasAiPageBuilderHelper->convertCurrentLayoutToTree($input);
    $this->assertEquals($expected_output, $result);
  }

  /**
   * Tests the createExpectedPageLayout method.
   */
  public function testCreateExpectedPageLayout(): void {
    // Build the full current layout (not the tree), as expected by
    // createExpectedPageLayout().
    $current_layout = [
      "regions" => [
        "header" => [
          "nodePathPrefix" => [0],
          "components" => [
            [
              "name" => "sdc.starshot_demo.starshot-heading",
              "uuid" => "678e9ee1-dc49-4495-b7cb-9bdd5625a59b",
              "nodePath" => [0, 0],
            ],
          ],
        ],
        "content" => [
          "nodePathPrefix" => [1],
          "components" => [
            [
              "name" => "sdc.canvas_test_sdc.two_column",
              "uuid" => "2f957795-e30a-46a0-acfe-868adc0685bf",
              "nodePath" => [1, 0],
              "slots" => [
                "2f957795-e30a-46a0-acfe-868adc0685bf/column_one" => [
                  "components" => [
                    [
                      "name" => "sdc.canvas_test_sdc.image",
                      "uuid" => "837173ae-5940-4c48-a304-31c6d81901b5",
                      "nodePath" => [1, 0, 0, 0],
                      "slots" => [
                        "837173ae-5940-4c48-a304-31c6d81901b5/inner_slot" => [
                          "components" => [
                            [
                              "name" => "sdc.canvas_test_sdc.druplicon",
                              "uuid" => "7fd447a9-f1b3-4b9c-ae23-ee4b174f7b84",
                              "nodePath" => [1, 0, 1, 0, 0, 0],
                            ],
                          ],
                        ],
                      ],
                    ],
                  ],
                ],
                "2f957795-e30a-46a0-acfe-868adc0685bf/column_two" => [
                  "components" => [
                    [
                      "name" => "sdc.canvas_test_sdc.druplicon",
                      "uuid" => "4e45ef4c-501c-4612-b02b-1911e88a4592",
                      "nodePath" => [1, 0, 1, 0],
                    ],
                  ],
                ],
              ],
            ],
          ],
        ],
        "footer" => [
          "nodePathPrefix" => [2],
          "components" => [],
        ],
      ],
    ];

    $yaml = <<<YAML
operations:
  - target: 'content'
    reference_uuid: '7fd447a9-f1b3-4b9c-ae23-ee4b174f7b84'
    placement: 'above'
    components:
      - sdc.canvas_test_sdc.heading:
          uuid: '6a2f1ad8-0a1d-4fcb-9b7f-93fb3c1b9a7f'
          props:
            text: "Above existing component"
            element: "h2"

  - target: 'content'
    reference_uuid: '7fd447a9-f1b3-4b9c-ae23-ee4b174f7b84'
    placement: 'below'
    components:
      - sdc.canvas_test_sdc.heading:
          uuid: 'c42ef28c-86c4-4704-81d7-62d234d98b95'
          props:
            text: "Below existing component"
            element: "h2"

  - target: 'footer'
    reference_uuid: ''
    placement: 'inside'
    components:
      - sdc.canvas_test_sdc.heading:
          uuid: 'bb3c1b59-ff84-4e33-bfc4-2abbd1cb4d8f'
          props:
            text: "Some text"
            element: "h1"
YAML;

    $operations = Yaml::parse($yaml);

    $result = $this->canvasAiPageBuilderHelper->createExpectedPageLayout($current_layout, $operations);

    $expected = [
      "header" => [
        "678e9ee1-dc49-4495-b7cb-9bdd5625a59b" => [],
      ],
      "content" => [
        "2f957795-e30a-46a0-acfe-868adc0685bf" => [
          "column_one" => [
            "837173ae-5940-4c48-a304-31c6d81901b5" => [
              "inner_slot" => [
                "6a2f1ad8-0a1d-4fcb-9b7f-93fb3c1b9a7f" => [],
                "7fd447a9-f1b3-4b9c-ae23-ee4b174f7b84" => [],
                "c42ef28c-86c4-4704-81d7-62d234d98b95" => [],
              ],
            ],
          ],
          "column_two" => [
            "4e45ef4c-501c-4612-b02b-1911e88a4592" => [],
          ],
        ],
      ],
      "footer" => [
        "bb3c1b59-ff84-4e33-bfc4-2abbd1cb4d8f" => [],
      ],
    ];

    $this->assertEquals($expected, $result);
  }

  /**
   * Data provider for testing generateVerboseContextForOrchestrator.
   */
  public static function generateVerboseContextProvider(): array {
    return [
      'empty values' => [
        [
          'entity_type' => '',
          'selected_component' => '',
          'page_title' => 'Untitled page',
          'page_description' => '',
        ],
        'User has not created any entities',
      ],
      'node with component' => [
        [
          'entity_type' => 'node',
          'selected_component' => 'hero_banner',
          'page_title' => 'What is drupal canvas?',
          'page_description' => 'Drupal canvas is a visual page-builder tool for Drupal CMS',
        ],
        'User is now in the code component editor, viewing a code component with id hero_banner',
      ],
      'node without component' => [
        [
          'entity_type' => 'node',
          'selected_component' => '',
          'page_title' => 'What is drupal canvas?',
          'page_description' => 'Drupal canvas is a visual page-builder tool for Drupal CMS',
        ],
        'The user is currently working on a \'node\' entity',
      ],
      'canvas page' => [
        [
          'entity_type' => 'canvas_page',
          'selected_component' => '',
          'page_title' => 'What is drupal canvas?',
          'page_description' => 'Drupal canvas is a visual page-builder tool for Drupal CMS',
        ],
        'The user is currently working on a canvas_page entity. User has not selected any particular component from the page. Page title: What is drupal canvas?. Page description: Drupal canvas is a visual page-builder tool for Drupal CMS',
      ],
      'canvas page with selected component and empty fields' => [
        [
          'entity_type' => 'canvas_page',
          'selected_component' => '',
          'active_component_uuid' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
          'page_title' => 'Untitled page',
          'page_description' => '',
        ],
        'The user is currently working on a canvas_page entity. User has selected a component in the page with uuid f47ac10b-58cc-4372-a567-0e02b2c3d479. Page title is empty. GENERATE THE TITLE FOR THE PAGE. Page description is empty. GENERATE THE DESCRIPTION FOR THE PAGE.',
      ],
    ];
  }

  /**
   * Tests the generateVerboseContextForOrchestrator method.
   *
   * @dataProvider generateVerboseContextProvider
   */
  public function testGenerateVerboseContextForOrchestrator(array $prompt, string $expected): void {
    $result = $this->canvasAiPageBuilderHelper->generateVerboseContextForOrchestrator($prompt);
    $this->assertEquals($expected, $result);
  }

}
