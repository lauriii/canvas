<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Plugin\ExperienceBuilder\ComponentSource;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Asset\AssetResolverInterface;
use Drupal\Core\Asset\AttachedAssets;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\Url;
use Drupal\experience_builder\Element\AstroIsland;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Entity\ComponentInterface;
use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\JsComponent;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\experience_builder\Traits\CrawlerTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Tests JsComponent
 *
 * @covers \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\JsComponent
 * @group experience_builder
 * @group JavaScriptComponents
 */
final class JsComponentTest extends KernelTestBase {

  use UserCreationTrait;
  use CrawlerTrait;

  /**
   * Test component
   */
  protected ComponentInterface $component;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'experience_builder',
    'user',
    'system',
    'media',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['system']);

    $js_component = JavaScriptComponent::create([
      'machineName' => $this->randomMachineName(),
      'name' => $this->getRandomGenerator()->sentences(5),
      'status' => TRUE,
      'props' => [
        'title' => [
          'type' => 'string',
          'title' => 'Title',
          'examples' => ['A title'],
        ],
      ],
      'required' => ['title'],
      'slots' => [],
      'css' => [
        'original' => '.test { display: none; }',
        'compiled' => '.test{display:none;}',
      ],
      'js' => [
        'original' => 'console.log( "hey" );',
        'compiled' => 'console.log("hey");',
      ],
    ]);
    $js_component->save();
    $component = Component::load(JsComponent::componentIdFromJavascriptComponentId((string) $js_component->id()));
    \assert($component instanceof ComponentInterface);
    $this->component = $component;
  }

  /**
   * Covers ::renderComponent.
   */
  public function testRenderComponent(): void {
    $source = $this->component->getComponentSource();
    \assert($source instanceof JsComponent);
    $js_component_id = $this->component->getSettings()['plugin_id'];
    $props = ['title' => 'Title'];
    $island = $source->renderComponent([
      'props' => $props,
    ], 'some-uuid');

    $crawler = $this->crawlerForRenderArray($island);
    self::assertEquals('No access to view component with ID ' . $js_component_id, $crawler->text());

    // @todo Add an access control handler and a view permission: https://www.drupal.org/i/3508694
    $this->setUpCurrentUser(permissions: ['administer code components']);

    $island = $source->renderComponent([
      'props' => $props,
    ], 'some-uuid');
    $crawler = $this->crawlerForRenderArray($island);

    $element = $crawler->filter('astro-island');
    self::assertCount(1, $element);

    // Note that ::renderComponent adds both xb_uuid and xb_slot_ids props but
    // they should not be present as props in the astro-island element.
    self::assertJsonStringEqualsJsonString(Json::encode(\array_map(static fn(mixed $value): array => [
      'raw',
      $value,
    ], $props)), $element->attr('props') ?? '');

    $asset_wrapper = $this->container->get(StreamWrapperManagerInterface::class)->getViaScheme('assets');
    \assert($asset_wrapper instanceof StreamWrapperInterface);
    \assert(\method_exists($asset_wrapper, 'getDirectoryPath'));
    $directory_path = $asset_wrapper->getDirectoryPath();
    $js_hash = Crypt::hmacBase64('console.log("hey");', Settings::getHashSalt());
    $js_filename = \sprintf('/%s/astro-island/%s.js', $directory_path, $js_hash);
    self::assertEquals($js_filename, $element->attr('component-url'));
    self::assertContains(\sprintf('experience_builder/astro_island.%s', $js_component_id), $island['#attached']['library']);

    $asset_resolver = \Drupal::service(AssetResolverInterface::class);
    \assert($asset_resolver instanceof AssetResolverInterface);
    $css_asset = $asset_resolver->getCssAssets(AttachedAssets::createFromRenderArray($island), FALSE);
    $css_hash = Crypt::hmacBase64('.test{display:none;}', Settings::getHashSalt());
    self::assertEquals(\sprintf('assets://astro-island/%s.css', $css_hash), reset($css_asset)['data']);

    // Test rendering the auto-saved JavaScriptComponent.
    $island = $source->renderComponent([
      'props' => $props,
    ], 'some-uuid', TRUE);
    $crawler = $this->crawlerForRenderArray($island);

    $element = $crawler->filter('astro-island');
    self::assertCount(1, $element);
    self::assertContains(\sprintf('experience_builder/astro_island.%s.draft', $js_component_id), $island['#attached']['library']);
    self::assertEquals(Url::fromRoute('experience_builder.api.config.auto-save.get.js', [
      'xb_config_entity_type_id' => JavaScriptComponent::ENTITY_TYPE_ID,
      'xb_config_entity' => $js_component_id,
    ])->toString(), $element->attr('component-url'));
  }

}
