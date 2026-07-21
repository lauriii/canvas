<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaObjectRef;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
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
 * the rest of the component's props — the host entity's language, resolved with
 * the same view-access-aware fallback core applies elsewhere — not in the
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
   * The component id of the code component under test.
   */
  private string $componentId;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', 'node_access');
    $this->installConfig(['node', 'language']);
    ConfigurableLanguage::createFromLangcode('es')->save();

    NodeType::create(['type' => 'news_item', 'name' => 'News item'])->save();
    \Drupal::service('content_translation.manager')->setEnabled('node', 'news_item', TRUE);

    // A code component whose content-entity-reference prop exposes the
    // referenced node's title.
    $machine_name = 'cer_translation_test_component';
    $this->componentId = JsComponent::componentIdFromJavascriptComponentId($machine_name);
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
  }

  /**
   * Resolves the developer-facing payload for a picked reference, in $langcode.
   */
  private function resolvePicked(NodeInterface $referenced, string $host_langcode): mixed {
    // A picked content-entity-reference: the author selected $referenced.
    $inputs = [
      'news_item_reference' => [
        'sourceType' => 'static:field_item:entity_reference',
        'expression' => 'ℹ︎entity_reference␟entity',
        'value' => [['target_id' => $referenced->id()]],
        'sourceTypeSettings' => [
          'storage' => ['target_type' => 'node'],
          'instance' => [
            'handler' => 'default:node',
            'handler_settings' => ['target_bundles' => ['news_item' => 'news_item']],
          ],
        ],
      ],
    ];
    // A host entity in $host_langcode carries the language the payload resolves
    // in (matching how getExplicitInput derives it from the tree-root host).
    $host = $this->createNode(['type' => 'news_item', 'title' => 'Host item', 'langcode' => 'en']);
    if ($host_langcode !== 'en') {
      $host->addTranslation($host_langcode, ['title' => 'Host item translation'])->save();
    }
    $host = $host->getTranslation($host_langcode);
    $component = Component::load($this->componentId);
    self::assertInstanceOf(Component::class, $component);
    $source = $component->getComponentSource();
    \assert($source instanceof JsComponent);
    $item = $this->buildComponentTreeItem($this->componentId, $inputs, $host);
    $resolved = $source->getExplicitInput($this->container->get('uuid')->generate(), $item);
    return $resolved['resolved']['news_item_reference']->value;
  }

  /**
   * The resolved payload must use the host entity's language.
   */
  public function testContentEntityReferenceResolvesInHostLanguage(): void {
    $this->setUpCurrentUser([], ['access content']);
    $referenced = $this->createNode(['type' => 'news_item', 'title' => 'Referenced item in English', 'langcode' => 'en']);
    $referenced->addTranslation('es', ['title' => 'Referenced item in Spanish'])->save();

    self::assertSame('Referenced item in English', $this->resolvePicked($referenced, 'en')['label'] ?? NULL);
    self::assertSame('Referenced item in Spanish', $this->resolvePicked($referenced, 'es')['label'] ?? NULL);
  }

  /**
   * A draft (unpublished) translation is gated on view-unpublished permission.
   *
   * The referenced node has a published English translation and an unpublished
   * Spanish one. In the Spanish host language, a user who cannot view the draft
   * receives the published English fallback (getTranslationFromContext applies
   * core's access-aware fallback), while a user who can view unpublished content
   * receives the Spanish draft.
   */
  public function testDraftTranslationRespectsViewPermission(): void {
    $referenced = $this->createNode(['type' => 'news_item', 'title' => 'Published item in English', 'langcode' => 'en', 'status' => 1]);
    $referenced->addTranslation('es', ['title' => 'Unpublished draft in Spanish', 'status' => 0])->save();

    // A user who cannot view unpublished content gets the published fallback.
    $this->setUpCurrentUser([], ['access content']);
    self::assertSame('Published item in English', $this->resolvePicked($referenced, 'es')['label'] ?? NULL);

    // A user who can view unpublished content gets the Spanish draft.
    $this->setUpCurrentUser([], ['access content', 'bypass node access']);
    self::assertSame('Unpublished draft in Spanish', $this->resolvePicked($referenced, 'es')['label'] ?? NULL);
  }

}
