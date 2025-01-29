<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Plugin\ExperienceBuilder\ComponentSource;

use Drupal\Component\Serialization\Json;
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
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['system']);

    // @todo Add an access control handler and a view permission.
    $this->setUpCurrentUser(permissions: ['administer code components']);

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
      'source_code_js' => 'console.log("hey");',
      'source_code_css' => '.test { display: none; }',
      'compiled_js' => 'console.log("hey");',
      'compiled_css' => '.test { display: none; }',
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
    $props = ['title' => 'Title'];
    $crawler = $this->crawlerForRenderArray($source->renderComponent([
      'props' => $props,
    ], 'some-uuid'));

    $element = $crawler->filter('astro-island');
    self::assertCount(1, $element);

    // Note that ::renderComponent adds both xb_uuid and xb_slot_ids props but
    // they should not be present as props in the astro-island element.
    self::assertJsonStringEqualsJsonString(Json::encode($props), $element->attr('props') ?? '');
  }

}
