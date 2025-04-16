<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\MemoryCache\MemoryCacheInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Url;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\experience_builder\Entity\PageRegion;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\JsComponent;
use Drupal\file\FileInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\experience_builder\TestSite\XBTestSetup;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * @covers \Drupal\experience_builder\Controller\ApiLayoutController::post()
 * @group experience_builder
 */
final class ApiLayoutControllerPostTest extends ApiLayoutControllerTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('module_installer')->install(['system', 'block', 'user']);
    $this->container->get('theme_installer')->install(['stark']);
    $this->container->get('config.factory')->getEditable('system.theme')->set('default', 'stark')->save();

    (new XBTestSetup())->setup();
    $this->setUpCurrentUser([], [
      'access administration pages',
      'administer url aliases',
      PageRegion::ADMIN_PERMISSION,
    ]);
  }

  public function testEmpty(): void {
    $this->request(Request::create('/xb/api/layout/node/1', method: 'POST', content: json_encode([
      'layout' => [
        [
          'nodeType' => 'region',
          'name' => 'Content',
          'components' => [],
          'id' => 'content',
        ],
      ],
      'model' => [],
      'entity_form_fields' => [],
    ], JSON_THROW_ON_ERROR)));

    // Check that the root level is structured correctly.
    $root = $this->getRegion('content');
    $this->assertNotNull($root);
    $this->assertEquals('', $root);
  }

  public function testMissingSlot(): void {
    $this->request(Request::create('/xb/api/layout/node/1', method: 'POST', content: json_encode([
      'layout' => [
        [
          'nodeType' => 'region',
          'name' => 'Content',
          'components' => [
            [
              'nodeType' => 'component',
              'slots' => [
                [
                  'components' => [],
                  'id' => 'c4074d1f-149a-4662-aaf3-615151531cf6/content',
                  'name' => 'content',
                  'nodeType' => 'slot',
                ],
              ],
              'type' => 'sdc.experience_builder.one_column',
              'uuid' => 'c4074d1f-149a-4662-aaf3-615151531cf6',
            ],
          ],
          'id' => 'content',
        ],
      ],
      'model' => [
        'c4074d1f-149a-4662-aaf3-615151531cf6' => [
          'resolved' => [
            'width' => 'full',
          ],
          'source' => [
            'width' => [
              'sourceType' => 'static:field_item:list_string',
              'expression' => 'ℹ︎list_string␟value',
              'sourceTypeSettings' => [
                'storage' => [
                  'allowed_values' => [
                    [
                      'value' => 'full',
                      'label' => 'full',
                    ],
                    [
                      'value' => 'wide',
                      'label' => 'wide',
                    ],
                    [
                      'value' => 'normal',
                      'label' => 'normal',
                    ],
                    [
                      'value' => 'narrow',
                      'label' => 'narrow',
                    ],
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
      'entity_form_fields' => [],
    ], JSON_THROW_ON_ERROR)));

    // Check that the root level is structured correctly.
    $root = $this->getRegion('content');
    $this->assertNotNull($root);
    $slot_and_component_comments = $this->getComponentInstances($root);
    $this->assertSame(['c4074d1f-149a-4662-aaf3-615151531cf6'], $slot_and_component_comments);
  }

  public function test(): void {
    // Load the test data from the layout controller.
    $content = $this->parentRequest(Request::create('/xb/api/layout/node/1'))->getContent();
    $this->assertIsString($content);
    $json = json_decode($content, TRUE);
    $model = $json['model'];
    $this->request(Request::create('/xb/api/layout/node/1', method: 'POST', content: $this->filterLayoutForPost($content)));
    $autoSave = $this->container->get(AutoSaveManager::class);
    \assert($autoSave instanceof AutoSaveManager);
    $node = Node::load(1);
    \assert($node instanceof NodeInterface);
    self::assertTrue($autoSave->getAutoSaveData($node)->isEmpty());

    // Check that each level is structured correctly.
    $contentRegion = $this->getRegion('content');
    $this->assertNotNull($contentRegion);
    $slot_and_component_comments = $this->getComponentInstances($contentRegion);
    $this->assertCount(6, $slot_and_component_comments);
    $this->assertSame(array_keys($model), $slot_and_component_comments);

    // Add a new component to the content region.
    $uuid = '173c4899-a5f7-442a-b008-ea8c925735be';
    $json['model'][$uuid] = self::getNewHeadingComponentModel();
    unset($json['isNew'], $json['isPublished'], $json['html']);
    $json['layout'][0]['components'][] = [
      'nodeType' => 'component',
      'uuid' => $uuid,
      'type' => 'sdc.experience_builder.heading',
      'slots' => [],
    ];
    $this->request(Request::create('/xb/api/layout/node/1', method: 'POST', content: \json_encode($json, JSON_THROW_ON_ERROR)));
    \assert($autoSave instanceof AutoSaveManager);
    self::assertFalse($autoSave->getAutoSaveData($node)->isEmpty());

    // Now re-fetch the layout to confirm we don't update the hash if an auto-save
    // entry already exists.
    $content = $this->parentRequest(Request::create('/xb/api/layout/node/1'))->getContent();
    self::assertIsString($content);
    $json = json_decode($content, TRUE);
    self::assertFalse($autoSave->getAutoSaveData($node)->isEmpty());
    self::assertArrayHasKey($uuid, $json['model']);
  }

  public function testWithGlobal(): void {
    $regions = PageRegion::createFromBlockLayout('stark');
    foreach ($regions as $region) {
      $region->save();
    }

    // Load the test data from the layout controller.
    $content = $this->parentRequest(Request::create('/xb/api/layout/node/1'))->getContent();
    $this->assertIsString($content);
    $json = json_decode($content, TRUE);
    $highlightedRegion = \array_filter($json['layout'], static fn (array $region) => ($region['id'] ?? NULL) === 'highlighted');
    self::assertCount(1, $highlightedRegion);
    self::assertGreaterThanOrEqual(1, \count(\reset($highlightedRegion)['components']));
    $this->request(Request::create('/xb/api/layout/node/1', method: 'POST', content: $this->filterLayoutForPost($content)));
    $autoSave = $this->container->get(AutoSaveManager::class);
    \assert($autoSave instanceof AutoSaveManager);
    $node = Node::load(1);
    \assert($node instanceof NodeInterface);
    self::assertTrue($autoSave->getAutoSaveData($node)->isEmpty());
    foreach ($regions as $region) {
      self::assertTrue($autoSave->getAutoSaveData($region)->isEmpty());
    }

    // Check that regions exist and are wrapped.
    $contentRegion = $this->getRegion('content');
    $this->assertNotNull($contentRegion);
    $highlighted = $this->getRegion('highlighted');
    $this->assertNotNull($highlighted);

    // Add a new component to a global region.
    $uuid = '173c4899-a5f7-442a-b008-ea8c925735be';
    $json['model'][$uuid] = self::getNewHeadingComponentModel();
    unset($json['isNew'], $json['isPublished'], $json['html']);
    $json['layout'][\key($highlightedRegion)]['components'][] = [
      'nodeType' => 'component',
      'uuid' => $uuid,
      'type' => 'sdc.experience_builder.heading',
      'slots' => [],
    ];
    $this->request(Request::create('/xb/api/layout/node/1', method: 'POST', content: \json_encode($json, JSON_THROW_ON_ERROR)));
    $autoSave = $this->container->get(AutoSaveManager::class);
    \assert($autoSave instanceof AutoSaveManager);
    self::assertTrue($autoSave->getAutoSaveData($node)->isEmpty());
    foreach ($regions as $region) {
      \assert($region instanceof PageRegion);
      self::assertEquals($region->get('region') !== 'highlighted', $autoSave->getAutoSaveData($region)->isEmpty());
    }
  }

  public function testWithoutPageRegionPermission(): void {
    $this->setUpCurrentUser([], [
      'access administration pages',
      'administer url aliases',
    ]);

    $regions = PageRegion::createFromBlockLayout('stark');
    foreach ($regions as $region) {
      $region->save();
    }

    // Load the test data from the layout controller.
    $content = $this->parentRequest(Request::create('/xb/api/layout/node/1'))->getContent();
    $this->assertIsString($content);
    $json = json_decode($content, TRUE);
    $highlightedRegion = \array_filter($json['layout'], static fn (array $region) => ($region['id'] ?? NULL) === 'highlighted');
    self::assertEmpty($highlightedRegion);
    $this->request(Request::create('/xb/api/layout/node/1', method: 'POST', content: $this->filterLayoutForPost($content)));
    $autoSave = $this->container->get(AutoSaveManager::class);
    \assert($autoSave instanceof AutoSaveManager);
    $node = Node::load(1);
    \assert($node instanceof NodeInterface);
    self::assertTrue($autoSave->getAutoSaveData($node)->isEmpty());
    foreach ($regions as $region) {
      self::assertTrue($autoSave->getAutoSaveData($region)->isEmpty());
    }

    // Check that content region exist and is wrapped.
    $contentRegion = $this->getRegion('content');
    $this->assertNotNull($contentRegion);
    // But not the highlighted region, as we don't have access to it.
    $highlighted = $this->getRegion('highlighted');
    $this->assertNull($highlighted);

    // Add a new component instance to a ("global") region.
    $uuid = '173c4899-a5f7-442a-b008-ea8c925735be';
    $json['model'][$uuid] = self::getNewHeadingComponentModel();
    unset($json['isNew'], $json['isPublished'], $json['html']);
    $json['layout'][1] = [
      'nodeType' => 'region',
      'id' => 'highlighted',
      'name' => 'Highlighted',
    ];
    $json['layout'][1]['components'][] = [
      'nodeType' => 'component',
      'uuid' => $uuid,
      'type' => 'sdc.experience_builder.heading',
      'slots' => [],
    ];

    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('Access denied for region highlighted');

    $this->request(Request::create('/xb/api/layout/node/1', method: 'POST', content: \json_encode($json, JSON_THROW_ON_ERROR)));
  }

  public function testWithDraftCodeComponent(): void {
    $this->setUpCurrentUser([], [
      'access administration pages',
      'administer url aliases',
    ]);

    // Create the saved (published) javascript component.
    $saved_component_values = [
      'machineName' => 'hey_there',
      'name' => 'Hey there',
      'status' => TRUE,
      'props' => [
        'name' => [
          'type' => 'string',
          'title' => 'Name',
          'examples' => ['Garry'],
        ],
      ],
      'slots' => [],
      'js' => [
        'original' => 'console.log("Hey there")',
        'compiled' => 'console.log("Hey there")',
      ],
      'css' => [
        'original' => '',
        'compiled' => '',
      ],
    ];
    $code_component = JavaScriptComponent::create($saved_component_values);
    $code_component->save();
    $saved_component_values['props']['voice'] = [
      'type' => 'string',
      'enum' => [
        'polite',
        'shouting',
        'toddler on a sugar high',
      ],
      'title' => 'Voice',
      'examples' => ['polite'],
    ];
    $saved_component_values['name'] = 'Here comes the';
    // But store an overridden version in auto-save (draft).
    /** @var \Drupal\experience_builder\AutoSave\AutoSaveManager $autoSave */
    $autoSave = $this->container->get(AutoSaveManager::class);
    $autoSave->save($code_component, $saved_component_values);

    // Load the test data from the layout controller.
    $content = $this->parentRequest(Request::create('/xb/api/layout/node/1'))->getContent() ?: '';
    $this->assertJson($content);
    $json = json_decode($content, TRUE, JSON_THROW_ON_ERROR);

    // Add the code component into the layout.
    $uuid = 'ccf36def-3f87-4b7d-bc20-8f8594274818';
    $json['layout'][0]['components'][] = [
      'nodeType' => 'component',
      'uuid' => $uuid,
      'type' => JsComponent::componentIdFromJavascriptComponentId((string) $code_component->id()),
      'slots' => [],
    ];
    $props = [
      'name' => 'Hot stepper',
      'voice' => 'shouting',
    ];
    $json['model'][$uuid] = [
      'resolved' => $props,
      'source' => [
        'name' => [
          'sourceType' => 'static:field_item:string',
          'expression' => 'ℹ︎string␟value',
        ],
        'voice' => [
          'sourceType' => 'static:field_item:list_string',
          'expression' => 'ℹ︎list_string␟value',
          'sourceTypeSettings' => [
            'storage' => [
              'allowed_values' => [
                ['value' => 'polite', 'label' => 'polite'],
                ['value' => 'shouting', 'label' => 'shouting'],
                ['value' => 'toddler on a sugar high', 'label' => 'toddler on a sugar high'],
              ],
            ],
          ],
        ],
      ],
    ];

    // Invalidate any static caches.
    $cache = $this->container->get(MemoryCacheInterface::class);
    \assert($cache instanceof MemoryCacheInterface);
    $cache->invalidateTags([\sprintf('entity.memory_cache:%s', JavaScriptComponent::ENTITY_TYPE_ID)]);
    $this->container->get(ConfigFactoryInterface::class)->reset();

    unset($json['isNew'], $json['isPublished'], $json['html']);
    $this->request(Request::create('/xb/api/layout/node/1', method: 'POST', content: \json_encode($json, JSON_THROW_ON_ERROR)));
    // Check that regions exist and are wrapped.
    $content_region = $this->getRegion('content');
    self::assertNotNull($content_region);

    $crawler = new Crawler($this->content);
    $element = $crawler->filter('astro-island');
    self::assertNotFalse(str_contains($content_region, 'astro-island'));
    self::assertNotFalse(str_contains($content_region, $uuid));
    self::assertEquals($uuid, $element->attr('uid'));

    // Should see the new (draft) props.
    self::assertJsonStringEqualsJsonString(Json::encode(\array_map(static fn(mixed $value): array => [
      'raw',
      $value,
    ], $props)), $element->attr('props') ?? '');
    // And the new component label.
    self::assertJsonStringEqualsJsonString(Json::encode([
      'name' => 'Here comes the',
      'value' => 'preact',
    ]), $element->attr('opts') ?? '');
    self::assertEquals(Url::fromRoute('experience_builder.api.config.auto-save.get.js', [
      'xb_config_entity_type_id' => JavaScriptComponent::ENTITY_TYPE_ID,
      'xb_config_entity' => 'hey_there',
    ])->toString(), $element->attr('component-url'));
  }

  private static function getNewHeadingComponentModel(): array {
    return [
      'resolved' => [
        'text' => 'This is a random heading.',
        'style' => 'primary',
        'element' => 'h1',
      ],
      'source' => [
        'text' => [
          'sourceType' => 'static:field_item:string',
          'expression' => 'ℹ︎string␟value',
        ],
        'style' => [
          'sourceType' => 'static:field_item:list_string',
          'expression' => 'ℹ︎list_string␟value',
          'sourceTypeSettings' => [
            'storage' => [
              'allowed_values' => [
                [
                  'value' => 'primary',
                  'label' => 'primary',
                ],
                [
                  'value' => 'secondary',
                  'label' => 'secondary',
                ],
              ],
            ],
          ],
        ],
        'element' => [
          'sourceType' => 'static:field_item:list_string',
          'expression' => 'ℹ︎list_string␟value',
          'sourceTypeSettings' => [
            'storage' => [
              'allowed_values' => [
                [
                  'value' => 'div',
                  'label' => 'div',
                ],
                [
                  'value' => 'h1',
                  'label' => 'h1',
                ],
                [
                  'value' => 'h2',
                  'label' => 'h2',
                ],
                [
                  'value' => 'h3',
                  'label' => 'h3',
                ],
                [
                  'value' => 'h4',
                  'label' => 'h4',
                ],
                [
                  'value' => 'h5',
                  'label' => 'h5',
                ],
                [
                  'value' => 'h6',
                  'label' => 'h6',
                ],
              ],
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * @testWith ["image-optional-with-example", "<img src=\"https://example.com/cat.jpg\" alt=\"Boring placeholder\" />"]
   *           ["image-optional-without-example", ""]
   *           ["image-required-with-example", "<img src=\"!!REFERENCED_MEDIA!!\" alt=\"The bones equal dollars\" />"]
   *           ["image-optional-with-example-and-additional-prop", "<h1><!-- xb-prop-start-166c9eee-35e9-4795-8c6f-24537728e95e/heading -->Heading the right direction?<!-- xb-prop-end-166c9eee-35e9-4795-8c6f-24537728e95e/heading --></h1><img src=\"/XB/MODULE/PATH/tests/modules/xb_test_sdc/components/image-optional-with-example-and-additional-prop/gracie.jpg\" alt=\"A good dog\" width=\"601\" height=\"402\"></img>"]
   *
   * Note: `image-required-without-example` is not tested because it does not meet the requirement.
   * @see \Drupal\Tests\experience_builder\Kernel\Config\ComponentTest::testComponentAutoCreate()
   */
  public function testImageComponentPermutations(string $sdc, string $expected_preview_html): void {
    $content = $this->parentRequest(Request::create('/xb/api/layout/node/1'))->getContent();
    $this->assertIsString($content);
    $json = json_decode($content, TRUE);

    $component = Component::load('sdc.xb_test_sdc.' . $sdc);
    $this->assertInstanceOf(Component::class, $component);

    $client_side = $component->getComponentSource()->getClientSideInfo($component);

    // Add the given SDC to the layout.
    $uuid = '166c9eee-35e9-4795-8c6f-24537728e95e';
    $json['layout'][0]['components'][] = [
      'nodeType' => 'component',
      'uuid' => $uuid,
      'type' => $component->id(),
      'slots' => [],
    ];
    $reference_media = \Drupal::entityTypeManager()->getStorage('media')->loadByProperties(
      ['name' => 'The bones are their money'],
    );
    self::assertCount(1, $reference_media);
    $reference_media = \reset($reference_media);
    // Populate its client model, and take advantage of the fact that the client
    // model is allowed to be invalid when previewing: no validation may occur,
    // to ensure even invalid explicit inputs for component instances result in
    // a best-effort preview. So, include the superset of all SDC's explicit
    // input, but never provide a value for the image.
    $json['model'][$uuid] = [
      'resolved' => [
        'heading' => 'Heading the right direction?',
        // Resolved will default to the default resolved values.
        // @see addNewComponentToLayout reducer in typescript code.
        'image' => $client_side['field_data']['image']['default_values']['resolved'] ?? NULL,
      ],
      'source' => [
        'heading' => [
          'expression' => 'ℹ︎string␟value',
          'sourceType' => 'static:field_item:string',
        ],
        'image' => [
          'sourceType' => 'static:field_item:entity_reference',
          'expression' => 'ℹ︎entity_reference␟{src↝entity␜␜entity:media:image␝field_media_image␞␟entity␜␜entity:file␝uri␞␟url,alt↝entity␜␜entity:media:image␝field_media_image␞␟alt,width↝entity␜␜entity:media:image␝field_media_image␞␟width,height↝entity␜␜entity:media:image␝field_media_image␞␟height}',
          'sourceTypeSettings' => [
            'storage' => ['target_type' => 'media'],
            'instance' => [
              'handler' => 'default:media',
              'handler_settings' => [
                'target_bundles' => ['image' => 'image'],
              ],
            ],
          ],
          'value' => \str_contains($sdc, 'required') ? $reference_media->id() : NULL,
        ],
      ],
    ];

    $module_path = \Drupal::service('extension.list.module')->getPath('experience_builder');
    $expected_preview_html = str_replace('XB/MODULE/PATH', $module_path, $expected_preview_html);
    \assert($reference_media->field_media_image->entity instanceof FileInterface);
    $expected_preview_html = str_replace('!!REFERENCED_MEDIA!!', $reference_media->field_media_image->entity->createFileUrl(), $expected_preview_html);

    unset($json['html'], $json['isPublished'], $json['isNew']);
    $this->request(Request::create('/xb/api/layout/node/1', method: 'POST', content: json_encode($json, JSON_THROW_ON_ERROR)));
    // Ensure the component is rendered using the expected markup.
    $this->assertRaw('<!-- xb-start-166c9eee-35e9-4795-8c6f-24537728e95e -->' . $expected_preview_html . '<!-- xb-end-166c9eee-35e9-4795-8c6f-24537728e95e -->');
  }

}
