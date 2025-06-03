<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Plugin\ExperienceBuilder\ComponentSource;

use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Url;
use Drupal\experience_builder\Controller\ApiAutoSaveController;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Entity\ComponentInterface;
use Drupal\experience_builder\Entity\Page;
use Drupal\experience_builder\Plugin\ComponentPluginManager;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\Fallback;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\SingleDirectoryComponent;
use Drupal\Tests\experience_builder\Kernel\ApiLayoutControllerTestBase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests that the fallback plugin retains recoverable user input.
 *
 * @coversDefaultClass \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\Fallback
 * @group experience_builder
 */
final class FallbackInputTest extends ApiLayoutControllerTestBase {

  protected static $modules = [
    // Required modules.
    'system',
    'user',
    'block',
    // Entity-types used by the page entity.
    'path_alias',
    'file',
    'media',
    'path',
    // Field types we need.
    'image',
    'link',
    'options',
    // Needed to install XB's default config.
    'filter',
    'ckeditor5',
    'editor',
    // Our module!
    'experience_builder',
    // Test components we can force fallback and recovery on.
    'xb_test_sdc',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Install and configure the default theme.
    $this->container->get('theme_installer')->install(['stark']);
    $this->container->get('config.factory')->getEditable('system.theme')->set('default', 'stark')->save();

    // Add some entity-types required by the page entity.
    $this->installEntitySchema('file');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('media');
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);

    // Make sure the global asset library is created.
    $this->installConfig('experience_builder');

    // Login as someone who can edit the page layout.
    $this->setUpCurrentUser([], [
      'access administration pages',
      'administer url aliases',
      Page::CREATE_PERMISSION,
      Page::EDIT_PERMISSION,
      'access content',
    ]);

    // Force generation of component config entities.
    $this->container->get(ComponentPluginManager::class)->getDefinitions();
  }

  /**
   * @covers ::requiresExplicitInput
   * @covers ::getExplicitInput
   * @covers ::inputToClientModel
   * @covers ::clientModelToInput
   *
   * @testWith [true]
   *           [false]
   */
  public function testFallbackInputCanBeRecovered(bool $publish = FALSE): void {
    $component_to_recover = Component::load('sdc.xb_test_sdc.props-slots');
    \assert($component_to_recover instanceof ComponentInterface);
    $component_to_edit = Component::load('sdc.experience_builder.heading');
    \assert($component_to_edit instanceof ComponentInterface);
    // Create a tree containing two components, one that will be forced to a
    // fallback and then be recovered. One that we will edit.
    $component_to_recover_uuid = '5821b0f4-162b-4a39-88b6-157b39b9b4f6';
    $component_to_edit_uuid = '20de2945-f515-49b6-b986-407d973860b9';
    $tree = [
      [
        'uuid' => $component_to_recover_uuid,
        'component_id' => $component_to_recover->id(),
        'inputs' => [
          'heading' => [
            'sourceType' => 'static:field_item:string',
            'value' => 'This is a component',
            'expression' => 'ℹ︎string␟value',
          ],
        ],
      ],
      [
        'uuid' => $component_to_edit_uuid,
        'component_id' => $component_to_edit->id(),
        'inputs' => [
          'text' => [
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'value' => 'Original heading text',
          ],
          'style' => [
            'value' => 'primary',
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
            'value' => 'h2',
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
      ],
    ];
    // Create a test entity.
    $page = Page::create([
      'title' => $this->randomMachineName(),
      'components' => $tree,
    ]);
    $page->save();
    $api_endpoint_uri = \sprintf('/xb/api/v0/layout/%s/%d', Page::ENTITY_TYPE_ID, $page->id());
    // Load the original data.
    $response = $this->parentRequest(Request::create($api_endpoint_uri));
    $data = self::decodeResponse($response);

    // Make sure our components are there both in the preview and in the model.
    $crawler = new Crawler($data['html']);
    self::assertCount(1, $crawler->filter('h2:contains("Original heading text")'));
    self::assertCount(1, $crawler->filter('h1:contains("This is a component")'));
    self::assertCount(2, $data['model']);

    // Uninstall xb_test_sdc to trigger the first component moving to the
    // fallback source.
    $this->container->get(ModuleInstallerInterface::class)->uninstall(['xb_test_sdc']);
    // Restore the container.
    // @phpstan-ignore-next-line
    $this->container = \Drupal::getContainer();

    /** @var \Drupal\experience_builder\Entity\ComponentInterface $component_to_recover */
    $component_to_recover = Component::load($component_to_recover->id());
    self::assertEquals(Fallback::PLUGIN_ID, $component_to_recover->getComponentSource()->getPluginId());

    // Load the fallback data.
    $response = $this->parentRequest(Request::create($api_endpoint_uri));
    $data = self::decodeResponse($response);

    // We should still see two items in the model (inputs).
    self::assertCount(2, $data['model']);

    // But only one of them should be in the preview now as the fallback inputs
    // have no outcome on the preview.
    $crawler = new Crawler($data['html']);
    self::assertCount(1, $crawler->filter('h2:contains("Original heading text")'));
    self::assertCount(0, $crawler->filter('h1:contains("This is a component")'));

    // Now perform a patch update to the non fallback component.
    $new_model = $data['model'][$component_to_edit_uuid];
    $new_model['source']['text']['value'] = 'New heading text';
    $response = $this->request(Request::create($api_endpoint_uri, method: 'PATCH', content: \json_encode([
      'model' => $new_model,
      'componentType' => 'sdc.experience_builder.heading',
      'componentInstanceUuid' => $component_to_edit_uuid,
    ], JSON_THROW_ON_ERROR)));
    self::assertEquals(Response::HTTP_OK, $response->getStatusCode());
    $data = self::decodeResponse($response);

    // We should still see two items in the model (inputs).
    self::assertCount(2, $data['model']);

    // We should see the updated property in the component preview.
    $crawler = new Crawler($data['html']);
    self::assertCount(1, $crawler->filter('h2:contains("New heading text")'));
    self::assertCount(0, $crawler->filter('h1:contains("This is a component")'));

    if ($publish) {
      /** @var \Drupal\experience_builder\Controller\ApiAutoSaveController $auto_save_controller */
      $auto_save_controller = $this->container->get(ApiAutoSaveController::class);
      $data = $auto_save_controller->get();
      $content = $data->getContent();
      \assert(\is_string($content));
      $request = Request::create(
        Url::fromRoute('experience_builder.api.auto-save.post')->toString(),
        content: $content
      );
      $response = $auto_save_controller->post($request);
      self::assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }
    else {
      $this->markTestSkipped('@todo Remove this in https://drupal.org/i/3524298');
    }

    // Now reinstall xb_test_sdc which should force a 'recovery' of the fallback
    // component. We do this via ::enableModules to avoid issues with stale
    // stale containers etc.
    $this->enableModules(['xb_test_sdc']);
    // Restore the container.
    // @phpstan-ignore-next-line
    $this->container = \Drupal::getContainer();

    // Rebuild component entities.
    $component_plugin_manager = $this->container->get(ComponentPluginManager::class);
    $component_plugin_manager->clearCachedDefinitions();
    $component_plugin_manager->getDefinitions();
    /** @var \Drupal\experience_builder\Entity\ComponentInterface $component_to_recover */
    $component_to_recover = Component::load($component_to_recover->id());
    self::assertFalse($component_to_recover->status());
    self::assertEquals(SingleDirectoryComponent::SOURCE_PLUGIN_ID, $component_to_recover->getComponentSource()->getPluginId());

    // Fetch the data again.
    $response = $this->parentRequest(Request::create($api_endpoint_uri));
    $data = self::decodeResponse($response);

    // Make sure our components are there both in the preview and in the model.
    $crawler = new Crawler($data['html']);
    self::assertCount(2, $data['model']);
    self::assertCount(1, $crawler->filter('h2:contains("New heading text")'));
    self::assertCount(1, $crawler->filter('h1:contains("This is a component")'));
  }

}
