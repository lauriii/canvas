<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Config;

use Drupal\Core\Cache\MemoryCache\MemoryCacheInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\Routing\AccessAwareRouterInterface;
use Drupal\Core\Routing\RequestContext;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\experience_builder\Entity\Page;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests XbConfigOverrides.
 *
 * @group experience_builder
 * @coversDefaultClass \Drupal\experience_builder\Config\XbConfigOverrides
 */
final class XbConfigOverridesTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'experience_builder',
    'user',
    'system',
    'media',
    'path',
    'path_alias',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['system']);
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('xb_page');
  }

  public function testXbConfigOverrides(): void {
    $page = Page::create([
      'title' => 'My page',
    ]);
    $page->save();
    $this->setUpCurrentUser([], [
      'access administration pages',
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

    // Create another item with no autosave draft.
    $code_component2 = JavaScriptComponent::create([
      'machineName' => 'another_one',
      'name' => 'Another one',
      'status' => TRUE,
      'props' => [
        'name' => [
          'type' => 'string',
          'title' => 'Name',
          'examples' => ['Bobby'],
        ],
      ],
      'slots' => [],
      'js' => [
        'original' => 'console.log("Another one?")',
        'compiled' => 'console.log("Another one?")',
      ],
      'css' => [
        'original' => '',
        'compiled' => '',
      ],
    ]);
    $code_component2->save();

    $path = '/xb/api/layout/xb_page/' . $page->id();
    $get = Request::create($path);
    $this->matchRequest($get, $path);

    $this->clearOverridesCache();
    // The GET route doesn't use overrides, so we should get the saved props.
    self::assertEquals(['name'], \array_keys(JavaScriptComponent::load($code_component->id())?->getProps() ?? []));
    // The second component has no auto-save entry so should return as is.
    self::assertEquals(['name'], \array_keys(JavaScriptComponent::load($code_component2->id())?->getProps() ?? []));

    $post = Request::create($path, method: 'POST', content: \json_encode([], JSON_THROW_ON_ERROR));
    $post->headers->set('Accept', 'application/json');
    $post->headers->set('Content-Type', 'application/json');
    $this->matchRequest($post, $path);

    $this->clearOverridesCache();
    // The POST route does use overrides, so we should get the updated props.
    self::assertEquals(['name', 'voice'], \array_keys(JavaScriptComponent::load($code_component->id())?->getProps() ?? []));
    // The second component has no auto-save entry so should return as is.
    self::assertEquals(['name'], \array_keys(JavaScriptComponent::load($code_component2->id())?->getProps() ?? []));

    // Assert we can load unrelated config.
    $this->container->get(ConfigFactoryInterface::class)->get('user.role.anonymous');
  }

  protected function matchRequest(Request $request, string $path): void {
    $request->setSession(new Session(new MockArraySessionStorage()));
    $router = $this->container->get(AccessAwareRouterInterface::class);
    $request_stack = $this->container->get(RequestStack::class);
    $current_path = $this->container->get(CurrentPathStack::class);
    $request_context = $this->container->get(RequestContext::class);
    $current_path->setPath($path, $request);
    $request_context->fromRequest($request);
    $request->attributes->add($router->matchRequest($request));
    $request_stack->push($request);
  }

  protected function clearOverridesCache(): void {
    // Invalidate any static caches.
    $cache = $this->container->get(MemoryCacheInterface::class);
    \assert($cache instanceof MemoryCacheInterface);
    $cache->invalidateTags([\sprintf('entity.memory_cache:%s', JavaScriptComponent::ENTITY_TYPE_ID)]);
    $this->container->get(ConfigFactoryInterface::class)->reset();
  }

}
