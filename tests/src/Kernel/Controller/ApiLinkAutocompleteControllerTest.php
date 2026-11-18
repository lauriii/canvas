<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Controller;

use Drupal\canvas\Controller\ApiLinkAutocompleteController;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Entity\Page;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the link prop autocomplete endpoint.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[CoversClass(ApiLinkAutocompleteController::class)]
class ApiLinkAutocompleteControllerTest extends CanvasKernelTestBase {

  use UserCreationTrait;
  use RequestTrait;

  private const string URL = '/canvas/api/v0/ui/link-autocomplete';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'field',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $this->installConfig(['system', 'field', 'filter', 'user', 'node']);
    // Take uid 1, which bypasses access checks, so test users don't.
    $this->createUser();

    NodeType::create(['type' => 'page', 'name' => 'Basic page'])->save();
    Node::create([
      'type' => 'page',
      'title' => 'Our legacy team',
      'path' => ['alias' => '/legacy-team'],
    ])->save();
    Page::create([
      'title' => 'About our team',
      'path' => ['alias' => '/about-our-team'],
    ])->save();
    Page::create([
      'title' => 'Hello, team',
      'path' => ['alias' => '/hello-team'],
    ])->save();
    Page::create([
      'title' => 'Unpublished team',
      'status' => FALSE,
    ])->save();
    Node::create([
      'type' => 'page',
      'title' => 'Teamwork',
    ])->save();
    Page::create([
      'title' => 'Steamboat',
    ])->save();
  }

  public function testMatchesMultipleEntityTypes(): void {
    $this->setUpCurrentUser([], [JavaScriptComponent::ADMIN_PERMISSION, 'access content']);

    $data = self::decodeResponse($this->request(Request::create(self::URL, parameters: ['q' => 'team'])));

    // Sorted by relevance, then label, across entity types; not grouped.
    self::assertSame([
      // The label starts with the typed string.
      'Teamwork (node/2)',
      // A word in the label starts with the typed string.
      'About our team (canvas_page/1)',
      // Core quotes labels containing a comma; the quotes are kept.
      '"Hello, team (canvas_page/2)"',
      'Our legacy team (node/1)',
      // The label merely contains the typed string.
      'Steamboat (canvas_page/4)',
      // 'Unpublished team' is not viewable.
    ], \array_column($data, 'value'));

    // Label, path and type pill.
    self::assertStringContainsString('<span class="canvas-link-suggestion__label">Our legacy team</span>', $data[3]['label']);
    self::assertStringContainsString('<span class="canvas-link-suggestion__path">/legacy-team</span>', $data[3]['label']);
    self::assertStringContainsString('<span class="canvas-link-suggestion__type">Basic page</span>', $data[3]['label']);
    // Bundle-less entity types show the entity type label.
    self::assertStringContainsString('<span class="canvas-link-suggestion__path">/about-our-team</span>', $data[1]['label']);
    self::assertStringContainsString('<span class="canvas-link-suggestion__type">Page</span>', $data[1]['label']);
  }

  public function testLimit(): void {
    $this->setUpCurrentUser([], [JavaScriptComponent::ADMIN_PERMISSION, 'access content']);
    foreach (\range(1, 10) as $i) {
      Node::create(['type' => 'page', 'title' => "Steam engine $i"])->save();
    }
    Node::create(['type' => 'page', 'title' => 'Team captain'])->save();

    $values = \array_column(self::decodeResponse($this->request(Request::create(self::URL, parameters: ['q' => 'team']))), 'value');

    self::assertCount(10, $values);
    // Not crowded out by the older nodes that only contain the typed string.
    self::assertSame('Team captain (node/13)', $values[0]);

    // An exact match is not crowded out by the older nodes whose label starts
    // with the typed string.
    foreach (\range(1, 10) as $i) {
      Node::create(['type' => 'page', 'title' => "Teammate $i"])->save();
    }
    Node::create(['type' => 'page', 'title' => 'Team'])->save();

    $values = \array_column(self::decodeResponse($this->request(Request::create(self::URL, parameters: ['q' => 'team']))), 'value');

    self::assertCount(10, $values);
    self::assertSame('Team (node/24)', $values[0]);
  }

  public function testEmptyInput(): void {
    $this->setUpCurrentUser([], [JavaScriptComponent::ADMIN_PERMISSION, 'access content']);

    self::assertSame([], self::decodeResponse($this->request(Request::create(self::URL))));
    self::assertSame([], self::decodeResponse($this->request(Request::create(self::URL, parameters: ['q' => '']))));
  }

  public function testOnlyAccessibleEntitiesMatch(): void {
    // No 'access content': neither nodes nor Canvas pages are viewable.
    $this->setUpCurrentUser([], [JavaScriptComponent::ADMIN_PERMISSION]);
    self::assertSame([], self::decodeResponse($this->request(Request::create(self::URL, parameters: ['q' => 'team']))));

    // Unpublished Canvas pages need a Canvas page permission.
    $this->setUpCurrentUser([], [JavaScriptComponent::ADMIN_PERMISSION, 'access content', Page::EDIT_PERMISSION]);
    $values = \array_column(self::decodeResponse($this->request(Request::create(self::URL, parameters: ['q' => 'unpublished']))), 'value');
    self::assertSame(['Unpublished team (canvas_page/3)'], $values);
  }

}
