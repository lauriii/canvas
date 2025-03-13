<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Url;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\experience_builder\Entity\Page;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\JsComponent;
use Drupal\user\UserInterface;
use GuzzleHttp\RequestOptions;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Defines a functional test for API layout controller.
 *
 * @group experience_builder
 */
final class ApiLayoutControllerTest extends HttpApiTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'experience_builder',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  public function testWithDraftCodeComponent(): void {
    // @todo Add an access control handler and a view permission.
    $account = $this->createUser([
      'access administration pages',
      'administer url aliases',
      'administer code components',
      'administer xb_page',
    ]);
    \assert($account instanceof UserInterface);
    $this->drupalLogin($account);

    $page = Page::create([
      'title' => 'Test page',
    ]);
    $page->save();

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
        'original' => '.foo{color:red}',
        'compiled' => '.foo{color:red}',
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
    // Auto-save entries should match the format sent by the client.
    $saved_component_values['source_code_js'] = $saved_component_values['js']['original'];
    $saved_component_values['compiled_js'] = $saved_component_values['js']['compiled'];
    $saved_component_values['source_code_css'] = $saved_component_values['css']['original'];
    $saved_component_values['compiled_css'] = $saved_component_values['css']['compiled'];
    unset($saved_component_values['js'], $saved_component_values['css']);
    $autoSave->save($code_component, $saved_component_values);

    // Load the test data from the layout controller.
    $content = $this->drupalGet('/xb/api/layout/xb_page/1');
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

    $request_options = [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
      ],
      RequestOptions::BODY => Json::encode($json),
    ];
    $request_options['headers']['X-CSRF-Token'] = $this->drupalGet('session/token');
    $response = $this->makeApiRequest('POST', Url::fromRoute('experience_builder.api.layout.post', [
      'entity_type' => 'xb_page',
      'entity' => $page->id(),
    ]), $request_options);

    $body = (string) $response->getBody();
    $json = \json_decode($body, TRUE, JSON_THROW_ON_ERROR);
    $crawler = new Crawler($json['html']);
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
    // And we should have our style tag.
    $links = \array_map(static fn (mixed $link) => \parse_url((string) $link, \PHP_URL_PATH), $crawler->filter('link[rel="stylesheet"]')->extract(['href']));
    $draft_css_url = Url::fromRoute('experience_builder.api.config.auto-save.get.css', [
      'xb_config_entity_type_id' => JavaScriptComponent::ENTITY_TYPE_ID,
      'xb_config_entity' => 'hey_there',
    ])->toString();
    self::assertContains($draft_css_url, $links);

    // Updating the auto-save should invalidate the config cache and cause the
    // new value to be used on a subsequent POST.
    $saved_component_values['name'] = 'Rodney';
    $autoSave->save($code_component, $saved_component_values);

    $response = $this->makeApiRequest('POST', Url::fromRoute('experience_builder.api.layout.post', [
      'entity_type' => 'xb_page',
      'entity' => $page->id(),
    ]), $request_options);

    $body = (string) $response->getBody();
    $json = \json_decode($body, TRUE, JSON_THROW_ON_ERROR);
    $crawler = new Crawler($json['html']);
    $content_region = $crawler->filter('[data-xb-uuid="content"]');
    self::assertCount(1, $content_region);
    $element = $content_region->filter('astro-island');
    self::assertCount(1, $element);
    self::assertJsonStringEqualsJsonString(Json::encode([
      'name' => 'Rodney',
      'value' => 'preact',
    ]), $element->attr('opts') ?? '');
  }

}
