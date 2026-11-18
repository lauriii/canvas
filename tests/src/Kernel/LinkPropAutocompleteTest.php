<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\Entity\Page;
use Drupal\canvas\Hook\ReduxIntegratedFieldWidgetsHooks;
use Drupal\canvas\PropShape\PropShape;
use Drupal\canvas\PropShape\PropShapeRepositoryInterface;
use Drupal\Core\Form\FormState;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\system\Functional\Form\StubForm;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * Tests the link widget of link props.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[CoversMethod(ReduxIntegratedFieldWidgetsHooks::class, 'fieldWidgetSingleElementLinkDefaultWidgetFormAlter')]
final class LinkPropAutocompleteTest extends CanvasKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'field',
    'link',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $this->installConfig(['system', 'field', 'filter', 'user', 'node']);
    // Take uid 1, which bypasses access checks, so the test user doesn't.
    $this->createUser();
    $this->setUpCurrentUser([], ['access content']);

    NodeType::create(['type' => 'page', 'name' => 'Basic page'])->save();
    Node::create(['type' => 'page', 'title' => 'A node'])->save();
    Page::create(['title' => 'Hello, world'])->save();
  }

  #[TestWith(['entity:canvas_page/1', '"Hello, world (canvas_page/1)"'])]
  #[TestWith(['entity:node/1', 'A node (node/1)'])]
  #[TestWith(['https://example.com', 'https://example.com'])]
  public function testLinkProp(string $uri, string $expected_default_value): void {
    $element = $this->buildUriElement($uri, TRUE);

    self::assertSame($expected_default_value, $element['#default_value']);
    $process = \array_column($element['#process'], 1);
    $position = \array_search('processEntityAutocomplete', $process, TRUE);
    self::assertIsInt($position);
    self::assertSame('processLinkAutocompleteRoute', $process[$position + 1]);
  }

  public function testOtherLinkField(): void {
    $element = $this->buildUriElement('entity:canvas_page/1', FALSE);

    // Core's LinkWidget only displays node URIs as autocomplete values.
    self::assertSame('entity:canvas_page/1', $element['#default_value']);
    self::assertArrayNotHasKey('#process', $element);
  }

  /**
   * Builds the URI element of a link widget for a `uri-reference` prop.
   *
   * @param string $uri
   *   The stored URI.
   * @param bool $is_link_prop
   *   Whether the widget is built for a Canvas link prop.
   */
  private function buildUriElement(string $uri, bool $is_link_prop): array {
    $storable_prop_shape = $this->container->get(PropShapeRepositoryInterface::class)
      ->getStorablePropShape(new PropShape(['type' => 'string', 'format' => 'uri-reference']));
    self::assertNotNull($storable_prop_shape);
    self::assertSame('link_default', $storable_prop_shape->fieldWidget);
    $prop_source = $storable_prop_shape->toStaticPropSource()->withValue($uri);
    $widget = $prop_source->getWidget('irrelevant-for-this-test', 'irrelevant-for-this-test', 'link', 'Link', $storable_prop_shape->fieldWidget);

    $form = ['#parents' => []];
    $form_state = new FormState();
    $form_state->setFormObject(new StubForm('some_form_id', $form));
    if ($is_link_prop) {
      $form_state->set('is_canvas_static_prop_source', TRUE);
    }
    $element = $prop_source->formTemporaryRemoveThisExclamationExclamationExclamation($widget, 'link', FALSE, NULL, $form, $form_state);
    $uri_element = $element['widget'][0]['uri'];
    self::assertSame('entity_autocomplete', $uri_element['#type']);
    return $uri_element;
  }

}
