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
use Drupal\experience_builder\AutoSave\AutoSaveManager;
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
   * @testWith [false, false, "live"]
   *           [false, true, "live"]
   *           [true, false, "live"]
   *           [true, true, "draft"]
   */
  public function testRenderComponent(bool $preview_requested, bool $auto_save_exists, string $expected_result): void {
    assert(in_array($expected_result, ['draft', 'live'], TRUE));

    $source = $this->component->getComponentSource();
    \assert($source instanceof JsComponent);

    // Create auto-save entry if that's expected by this test case.
    if ($auto_save_exists) {
      $this->container->get(AutoSaveManager::class)
        ->save(
          $source->getJavaScriptComponent(),
          $source->getJavaScriptComponent()->normalizeForClientSide()->values
        );
    }

    $js_component_id = $this->component->getSettings()['plugin_id'];
    $props = ['title' => 'Title'];
    $island = $source->renderComponent([
      'props' => $props,
    ], 'some-uuid', $preview_requested);

    $crawler = $this->crawlerForRenderArray($island);
    self::assertEquals('No access to view component with ID ' . $js_component_id, $crawler->text());

    // @todo Add an access control handler and a view permission: https://www.drupal.org/i/3508694
    $this->setUpCurrentUser(permissions: ['administer code components']);

    $island = $source->renderComponent([
      'props' => $props,
    ], 'some-uuid', $preview_requested);
    $crawler = $this->crawlerForRenderArray($island);

    $element = $crawler->filter('astro-island');
    self::assertCount(1, $element);

    // Note that ::renderComponent adds both xb_uuid and xb_slot_ids props but
    // they should not be present as props in the astro-island element.
    self::assertJsonStringEqualsJsonString(Json::encode(\array_map(static fn(mixed $value): array => [
      'raw',
      $value,
    ], $props)), $element->attr('props') ?? '');

    // Assert rendered code component's JS.
    $asset_wrapper = $this->container->get(StreamWrapperManagerInterface::class)->getViaScheme('assets');
    \assert($asset_wrapper instanceof StreamWrapperInterface);
    \assert(\method_exists($asset_wrapper, 'getDirectoryPath'));
    $directory_path = $asset_wrapper->getDirectoryPath();
    $js_hash = Crypt::hmacBase64('console.log("hey");', Settings::getHashSalt());
    $js_filename = match ($expected_result) {
      'live' => \sprintf('/%s/astro-island/%s.js', $directory_path, $js_hash),
      'draft' => "/xb/api/autosaves/js/js_component/$js_component_id",
    };
    self::assertEquals($js_filename, $element->attr('component-url'));
    $expected_asset_library = match ($expected_result) {
      'live' => 'experience_builder/astro_island.%s',
      'draft' => 'experience_builder/astro_island.%s.draft',
    };
    self::assertContains(\sprintf($expected_asset_library, $js_component_id), $island['#attached']['library']);

    // Assert rendered code component's CSS.
    $asset_resolver = \Drupal::service(AssetResolverInterface::class);
    \assert($asset_resolver instanceof AssetResolverInterface);
    $css_asset = $asset_resolver->getCssAssets(AttachedAssets::createFromRenderArray($island), FALSE);
    $css_filename = match($expected_result) {
      'live' => \sprintf(
        'assets://astro-island/%s.css',
        Crypt::hmacBase64('.test{display:none;}', Settings::getHashSalt()),
      ),
      'draft' => "xb/api/autosaves/css/js_component/$js_component_id",
    };
    self::assertEquals($css_filename, reset($css_asset)['data']);
    $preloads = \array_column($island['#attached']['html_head_link'], 0);
    $hrefs = \array_column($preloads, 'href');
    self::assertContains($js_filename, $hrefs);
  }

}
