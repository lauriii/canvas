<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Entity\ComponentInterface;
use Drupal\node\Entity\Node;
use Drupal\Tests\system\Functional\Cache\AssertPageCacheContextsAndTagsTrait;
use Drupal\Tests\experience_builder\Traits\CreateTestJsComponentTrait;

/**
 * The functional test equivalent of FieldForComponentSuggesterTest.
 *
 * @covers \Drupal\experience_builder\Controller\ApiComponentsController
 * @group experience_builder
 * @internal
 */
class PropSourceEndpointTest extends FunctionalTestBase {

  use AssertPageCacheContextsAndTagsTrait;
  use CreateTestJsComponentTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'experience_builder',
    'sdc_test_all_props',
    'xb_test_sdc',
    // Validate that a single invalid SDC doesn't break the component list.
    'xb_broken_sdcs',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected $profile = 'standard';

  public function test(): void {
    $this->createMyCtaComponentFromSdc();
    $this->disableToolsMenuBlockComponent();
    // Ensure that we have a busted SDC component.
    self::assertInstanceOf(ComponentInterface::class, Component::load('sdc.xb_broken_sdcs.invalid-filter'));

    $page = $this->getSession()->getPage();
    $node = Node::create([
      'type' => 'article',
      'title' => 'XB Needs This For The Time Being',
    ]);
    $node->save();
    $this->drupalLogin($this->rootUser);
    $this->drupalGet('xb/api/v0/config/component');

    $expected_tags = [
      'CACHE_MISS_IF_UNCACHEABLE_HTTP_METHOD:form',
      'announcements_feed:feed',
      'comment_list',
      'config:component_list',
      'config:core.extension',
      'config:experience_builder.js_component.my-cta',
      'config:search.settings',
      'config:system.menu.account',
      'config:system.menu.admin',
      'config:system.menu.footer',
      'config:system.menu.main',
      'config:system.site',
      'config:system.theme',
      'config:views.view.comments_recent',
      'config:views.view.content_recent',
      'config:views.view.who_s_new',
      'config:views.view.who_s_online',
      'local_task',
      'node:1',
      'node_list',
      'user:0',
      'user:1',
      'user_list',
      AutoSaveManager::CACHE_TAG,
    ];

    $expected_contexts = [
      'languages:language_content',
      'route',
      'url.path',
      'url.query_args',
      'user.node_grants:view',
      'user.roles:authenticated',
      // The user_login_block is rendered as the anonymous user because for the
      // authenticated user it is empty.
      // @see \Drupal\experience_builder\Controller\ApiComponentsController::getCacheableClientSideInfo()
      'user.roles:anonymous',
    ];

    $this->assertSame(200, $this->getSession()->getStatusCode(), match($this->getSession()->getStatusCode()) {
      // Show the fatal error message in the failing test output.
      // @see \Drupal\experience_builder\EventSubscriber\ApiExceptionSubscriber
      500 => json_decode($page->getContent())->message,
      default => $page->getContent(),
    });
    $this->assertCacheTags($expected_tags);
    $this->assertCacheContexts($expected_contexts);
    $this->assertSession()->responseHeaderEquals('X-Drupal-Cache-Max-Age', '3600');

    // Ensure the response is cached by Dynamic Page Cache (because this is a
    // complex response), but not by Page Cache (because it should not be
    // available to anonymous users).
    $this->assertSession()->responseHeaderEquals('X-Drupal-Dynamic-Cache', 'MISS');
    $this->assertSession()->responseHeaderEquals('X-Drupal-Cache', 'UNCACHEABLE (request policy)');
    $this->drupalGet('xb/api/v0/config/component');
    $this->assertSession()->responseHeaderEquals('X-Drupal-Dynamic-Cache', 'HIT');
    $this->assertSession()->responseHeaderEquals('X-Drupal-Cache', 'UNCACHEABLE (request policy)');

    $data = Json::decode($page->getText());
    self::assertArrayNotHasKey('block.system_menu_block.tools', $data);
    foreach ($data as $id => $component) {
      $this->assertArrayHasKey('id', $component);
      $this->assertSame($id, $component['id']);
      $this->assertArrayHasKey('name', $component);
      $this->assertArrayHasKey('category', $component);
      $this->assertArrayHasKey('source', $component);
      $this->assertArrayHasKey('library', $component, $id);
      $this->assertArrayHasKey('default_markup', $component);
      $this->assertArrayHasKey('css', $component);
      $this->assertArrayHasKey('js_header', $component);
      $this->assertArrayHasKey('js_footer', $component);
    }
    $this->assertStringStartsWith('<!-- xb-start-', $data['block.system_menu_block.main']['default_markup']);
    $this->assertStringContainsString('--><nav role="navigation"', $data['block.system_menu_block.main']['default_markup']);

    // Stark has no SDCs.
    $this->assertSame('stark', $this->config('system.theme')->get('default'));
    $this->assertArrayNotHasKey('sdc.olivero.teaser', $data);

    $data = array_intersect_key(
      $data,
      [
        'sdc.xb_test_sdc.image' => TRUE,
        'sdc.xb_test_sdc.my-hero' => TRUE,
        'sdc.sdc_test_all_props.all-props' => TRUE,
        'js.my-cta' => TRUE,
      ],
    );
    $this->assertCount(4, $data);

    // Olivero does have an SDC, and it's enabled, but it is omitted because the
    // default theme is Stark.
    $this->assertInstanceOf(Component::class, Component::load('sdc.olivero.teaser'));
    $this->assertTrue(Component::load('sdc.olivero.teaser')->status());
    $this->assertSame('olivero', Component::load('sdc.olivero.teaser')->get('provider'));

    // Change the default theme from Stark to Olivero, and observe the impact on
    // the list of Components returned.
    \Drupal::configFactory()->getEditable('system.theme')->set('default', 'olivero')->save();
    $this->rebuildAll();
    $this->drupalGet('xb/api/v0/config/component');
    $data = Json::decode($page->getText());
    $this->assertSession()->responseHeaderEquals('X-Drupal-Dynamic-Cache', 'MISS');
    $this->assertSession()->responseHeaderEquals('X-Drupal-Cache', 'UNCACHEABLE (request policy)');
    // Olivero does have an SDC!
    $this->assertSame('olivero', $this->config('system.theme')->get('default'));
    $this->assertArrayHasKey('sdc.olivero.teaser', $data);
    // Repeated request is again a Dynamic Page Cache hit.
    $this->drupalGet('xb/api/v0/config/component');
    $this->assertSession()->responseHeaderEquals('X-Drupal-Dynamic-Cache', 'HIT');
    $this->assertSession()->responseHeaderEquals('X-Drupal-Cache', 'UNCACHEABLE (request policy)');
  }

  private function disableToolsMenuBlockComponent(): void {
    Component::load('block.system_menu_block.tools')?->disable()->save();
  }

}
