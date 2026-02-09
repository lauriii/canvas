<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Hook\FieldUiHooks;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the FieldUiHooks for canvas CTA display logic.
 */
#[Group('canvas')]
#[CoversClass(FieldUiHooks::class)]
final class FieldUiHooksTest extends CanvasKernelTestBase {

  use ContentTypeCreationTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'field',
  ];

  /**
   * The entity type manager service.
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('node');
    $this->installConfig(['node']);
    // Create user 1, which has special admin privileges that could interfere
    // with access tests.
    // @todo Remove in https://www.drupal.org/node/540008.
    $this->createUser();
    $this->entityTypeManager = $this->container->get('entity_type.manager');
  }

  /**
   * Tests the canvas CTA display on entity view display edit form.
   *
   * @param array<int, string> $permissions
   *   The permissions to assign to the test user.
   * @param bool $cta_should_exist
   *   Whether the CTA message should be displayed.
   * @param string $entity_type
   *   The content entity type ID (e.g., 'node').
   * @param string $bundle
   *   The content entity bundle (e.g., 'article').
   * @param bool $create_template
   *   Whether to create a content template.
   * @param string|null $route_name
   *   Optional route name override for testing.
   */
  #[DataProvider('viewDisplayEditFormAlterDataProvider')]
  public function testEntityViewDisplayEditFormAlter(
    array $permissions,
    bool $cta_should_exist,
    string $entity_type,
    string $bundle,
    bool $create_template,
    ?string $route_name = NULL,
  ): void {
    $this->createContentType(['type' => $bundle]);
    if ($create_template) {
      $this->createContentTemplate($entity_type, $bundle, 'full');
    }

    $user = $this->createUser($permissions);
    self::assertInstanceOf(AccountInterface::class, $user);
    $this->setCurrentUser($user);

    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);
    $form_object = $this->createMock(EntityFormInterface::class);
    $messenger = $this->createMock(MessengerInterface::class);
    $route_match = $this->createMock(RouteMatchInterface::class);
    $view_display = $this->createMock(EntityViewDisplayInterface::class);
    $view_display->method('getTargetEntityTypeId')->willReturn($entity_type);
    $view_display->method('getTargetBundle')->willReturn($bundle);

    $route_name = $route_name ?? "entity.entity_view_display.$entity_type.default";
    $route_match->method('getRouteName')->willReturn($route_name);

    if ($cta_should_exist) {
      $messenger->expects($this->once())
        ->method('addMessage')
        ->with(
          $this->callback(function ($message): bool {
            self::assertIsArray($message);
            $required_keys = ['#theme', '#icon', '#title', '#description', '#url', '#link_title'];
            foreach ($required_keys as $key) {
              self::assertArrayHasKey($key, $message);
            }
            $expected_text = [
              '#title' => 'New: Design with Drupal Canvas',
              '#link_title' => 'Create with Canvas',
            ];
            foreach ($expected_text as $key => $title) {
              self::assertInstanceOf(MarkupInterface::class, $message[$key]);
              self::assertSame($title, (string) $message[$key]);
            }
            return TRUE;
          }),
          $this->callback(function ($type): bool {
            self::assertSame('canvas_cta', $type);
            return TRUE;
          })
        );
    }
    else {
      $messenger->expects($this->never())
        ->method('addMessage');
    }

    $form_object->method('getEntity')
      ->willReturn($view_display);
    $form_state->method('getFormObject')
      ->willReturn($form_object);

    $field_ui_hooks = new FieldUiHooks(
      $route_match,
      $messenger,
      $this->container->get('entity_type.manager'),
      $this->container->get('string_translation')
    );
    $field_ui_hooks->formEntityViewDisplayEditFormAlter($form, $form_state);
  }

  /**
   * Data provider for testEntityViewDisplayEditFormAlter.
   *
   * @return array<string, array<string, mixed>>
   *   Test cases with permissions, expected CTA display, and entity parameters.
   */
  public static function viewDisplayEditFormAlterDataProvider(): array {
    return [
      'permission with template not present' => [
        'permissions' => [ContentTemplate::ADMIN_PERMISSION],
        'cta_should_exist' => TRUE,
        'create_template' => FALSE,
        'entity_type' => 'node',
        'bundle' => 'article',
      ],
      'permission with at-least one template present' => [
        'permissions' => [ContentTemplate::ADMIN_PERMISSION],
        'cta_should_exist' => FALSE,
        'create_template' => TRUE,
        'entity_type' => 'node',
        'bundle' => 'article',
      ],
      'permission with at-least one template present but different route' => [
        'permissions' => [ContentTemplate::ADMIN_PERMISSION],
        'cta_should_exist' => FALSE,
        'create_template' => TRUE,
        'entity_type' => 'node',
        'bundle' => 'article',
        'route_name' => 'entity.entity_view_display.node.full',
      ],
      'no permission with template not present' => [
        'permissions' => [],
        'cta_should_exist' => FALSE,
        'create_template' => TRUE,
        'entity_type' => 'node',
        'bundle' => 'article',
      ],
      'no permission with at-least one template present' => [
        'permissions' => [],
        'cta_should_exist' => FALSE,
        'create_template' => TRUE,
        'entity_type' => 'node',
        'bundle' => 'article',
      ],
    ];
  }

  /**
   * Creates a content template entity.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $bundle
   *   The bundle.
   * @param string $view_mode_id
   *   The view mode ID.
   * @param bool $status
   *   The content template status.
   *
   * @return \Drupal\Core\Config\Entity\ConfigEntityInterface
   *   The created content template.
   */
  private function createContentTemplate(string $entity_type_id, string $bundle, string $view_mode_id, bool $status = FALSE): ConfigEntityInterface {
    $content_template_storage = $this->entityTypeManager->getStorage(ContentTemplate::ENTITY_TYPE_ID);
    $template = $content_template_storage->create([
      'id' => "$entity_type_id.$bundle.$view_mode_id",
      'content_entity_type_id' => $entity_type_id,
      'content_entity_type_bundle' => $bundle,
      'content_entity_type_view_mode' => $view_mode_id,
      'component_tree' => [],
      'status' => $status,
    ]);
    self::assertInstanceOf(ConfigEntityInterface::class, $template);
    $template->save();

    return $template;
  }

}
