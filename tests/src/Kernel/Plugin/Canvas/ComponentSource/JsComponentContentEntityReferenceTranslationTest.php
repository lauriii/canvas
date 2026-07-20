<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaObjectRef;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent;
use Drupal\canvas\PropSource\PropSource;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\ComponentTreeItemInstantiatorTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that code-component entity references resolve in the host's language.
 *
 * A code component's content-entity-reference prop exposes fields of a
 * referenced content entity. Those fields must be read in the same language as
 * the rest of the component's props — the host entity's language — not in the
 * referenced entity's own default language.
 *
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent::buildReferencePayload()
 */
#[Group('canvas')]
#[CoversMethod(JsComponent::class, 'buildReferencePayload')]
#[CoversMethod(JsComponent::class, 'getExplicitInput')]
final class JsComponentContentEntityReferenceTranslationTest extends CanvasKernelTestBase {

  use ComponentTreeItemInstantiatorTrait;
  use NodeCreationTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'field',
    'node',
    'language',
    'content_translation',
  ];

  /**
   * The resolved payload must use the host entity's language.
   */
  public function testContentEntityReferenceResolvesInHostLanguage(): void {
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', 'node_access');
    $this->installConfig(['node', 'language']);
    ConfigurableLanguage::createFromLangcode('es')->save();

    // Field access checks during expression evaluation require `access content`.
    $this->setUpCurrentUser([], ['access content']);

    NodeType::create(['type' => 'news_item', 'name' => 'News item'])->save();
    $content_translation_manager = \Drupal::service('content_translation.manager');
    $content_translation_manager->setEnabled('node', 'news_item', TRUE);

    FieldStorageConfig::create([
      'field_name' => 'field_related_news',
      'type' => 'entity_reference',
      'entity_type' => 'node',
      'settings' => ['target_type' => 'node'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_related_news',
      'entity_type' => 'node',
      'bundle' => 'news_item',
      'label' => 'Related news',
      'settings' => [
        'handler' => 'default:node',
        'handler_settings' => ['target_bundles' => ['news_item' => 'news_item']],
      ],
    ])->save();

    // Referenced node: differing English and Spanish titles.
    $referenced = $this->createNode([
      'type' => 'news_item',
      'title' => 'The referenced news item',
      'langcode' => 'en',
    ]);
    $referenced->addTranslation('es', ['title' => 'La noticia referenciada'])->save();

    // Host node, referencing the node above, translated to Spanish too.
    $host = $this->createNode([
      'type' => 'news_item',
      'title' => 'The host news item',
      'langcode' => 'en',
      'field_related_news' => $referenced->id(),
    ]);
    $host->addTranslation('es', [
      'title' => 'La noticia anfitriona',
      'field_related_news' => $referenced->id(),
    ])->save();

    // A code component whose content-entity-reference prop exposes the
    // referenced node's title.
    $machine_name = 'cer_translation_test_component';
    $component_id = JsComponent::componentIdFromJavascriptComponentId($machine_name);
    JavaScriptComponent::create([
      'machineName' => $machine_name,
      'name' => 'Entity reference translation test component',
      'status' => TRUE,
      'props' => [
        'news_item_reference' => [
          'title' => 'Featured news item',
          ...JsonSchemaObjectRef::ContentEntityReference->asPropShapeArray(),
        ],
      ],
      'required' => [],
      'js' => ['original' => '', 'compiled' => ''],
      'css' => ['original' => '', 'compiled' => ''],
      'dataDependencies' => [
        'entityFields' => [
          'news_item_reference' => ['ℹ︎␜entity:node:news_item␝title␞␟value'],
        ],
      ],
    ])->save();

    $component = Component::load($component_id);
    self::assertInstanceOf(Component::class, $component);
    $source = $component->getComponentSource();
    self::assertInstanceOf(JsComponent::class, $source);

    // The prop is bound to the host's reference field: the referenced entity is
    // resolved by following that field.
    $inputs = [
      'news_item_reference' => [
        'sourceType' => PropSource::EntityField->value,
        'expression' => 'ℹ︎␜entity:node:news_item␝field_related_news␞␟entity',
      ],
    ];

    // Render each host translation the way ComponentTreeItemList::getHydratedValue()
    // does: build the tree rooted in the host translation and evaluate without an
    // explicit host argument, so the tree-root host is used.
    $expected_by_langcode = [
      'en' => 'The referenced news item',
      'es' => 'La noticia referenciada',
    ];
    foreach ($expected_by_langcode as $langcode => $expected_title) {
      $host_translation = $host->getTranslation($langcode);
      $item = $this->buildComponentTreeItem($component_id, $inputs, $host_translation);
      $resolved = $source->getExplicitInput($this->container->get('uuid')->generate(), $item);

      self::assertArrayHasKey('news_item_reference', $resolved['resolved']);
      $payload = $resolved['resolved']['news_item_reference']->value;
      self::assertIsArray($payload, "Resolved value must be the developer-facing payload for host language $langcode.");
      self::assertSame(
        $expected_title,
        $payload['label'] ?? NULL,
        "Referenced entity title must be resolved in the host language ($langcode).",
      );
    }
  }

}
