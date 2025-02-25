<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\MemoryCache\MemoryCacheInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Url;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\experience_builder\Entity\PageRegion;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\JsComponent;
use Drupal\Tests\experience_builder\TestSite\XBTestSetup;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

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
    $this->container->get('module_installer')->install(['system', 'block']);
    $this->container->get('theme_installer')->install(['stark']);
    $this->container->get('config.factory')->getEditable('system.theme')->set('default', 'stark')->save();

    (new XBTestSetup())->setup();
    $this->setUpCurrentUser([], ['access administration pages', 'administer url aliases']);
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
    $root = $this->cssSelect('main div[data-xb-region][data-xb-uuid="content"]');
    $this->assertNotEmpty($root);
    $this->assertCount(0, $root[0]);
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
    $root = $this->cssSelect('main div[data-xb-region="content"]');

    $this->assertNotEmpty($root);
    $this->assertGreaterThan(0, $root[0]->count());
    preg_match_all('/(xb-start-)(.*?)[\/ \t](.*?)(-->)(.*?)/', $root[0]->asXML() ?: '', $slot_and_component_comments);
    $this->assertSame(['c4074d1f-149a-4662-aaf3-615151531cf6'], $slot_and_component_comments[2]);
  }

  public function test(): void {
    // Load the test data from the layout controller.
    $content = $this->parentRequest(Request::create('/xb/api/layout/node/1'))->getContent();
    $this->assertIsString($content);
    $model = json_decode($content, TRUE)['model'];
    $this->request(Request::create('/xb/api/layout/node/1', method: 'POST', content: $this->filterLayoutForPost($content)));

    // Check that each level is structured correctly.
    $root = $this->cssSelect('main div[data-xb-region][data-xb-uuid="content"]');
    $this->assertNotEmpty($root);
    $this->assertGreaterThan(0, $root[0]->count());
    preg_match_all('/(xb-start-)(.*?)[\/ \t](.*?)(-->)(.*?)/', $root[0]->asXML() ?: '', $slot_and_component_comments);
    $this->assertCount(6, $slot_and_component_comments[2]);
    $this->assertSame(array_keys($model), $slot_and_component_comments[2]);
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

    // Check that regions exist and are wrapped.
    $crawler = new Crawler($this->content);
    self::assertCount(1, $crawler->filter('[data-xb-uuid="content"]'));
    self::assertCount(1, $crawler->filter('[data-xb-uuid="highlighted"]'));
  }

  public function testWithDraftCodeComponent(): void {
    // @todo Add an access control handler and a view permission.
    $this->setUpCurrentUser([], [
      'access administration pages',
      'administer url aliases',
      'administer code components',
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
    // But store an overridden version in autosave (draft).
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

    $this->request(Request::create('/xb/api/layout/node/1', method: 'POST', content: \json_encode($json, JSON_THROW_ON_ERROR)));
    // Check that regions exist and are wrapped.
    $crawler = new Crawler($this->content);
    $content_region = $crawler->filter('[data-xb-uuid="content"]');
    self::assertCount(1, $content_region);
    $element = $content_region->filter('astro-island');
    self::assertCount(1, $element);

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

}
