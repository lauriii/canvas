<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\TestSite;

use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\file\Entity\File;
use Drupal\image\Plugin\Field\FieldType\ImageItem;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\RandomGeneratorTrait;
use Drupal\Tests\TestFileCreationTrait;
use Drupal\TestSite\TestSetupInterface;
use Drupal\user\Entity\User;
use Drupal\user\Entity\Role;

class XBTestSetup implements TestSetupInterface {

  use MediaTypeCreationTrait;
  use RandomGeneratorTrait;
  use TestFileCreationTrait;

  public function setup(): void {
    $config_factory = \Drupal::configFactory();
    $config = $config_factory->getEditable('system.logging');
    $config->set('error_level', ERROR_REPORTING_DISPLAY_VERBOSE);
    $config->save(TRUE);

    $config = \Drupal::service('config.factory')->getEditable('system.performance');
    $config->set('js.preprocess', FALSE);
    $config->save();

    $module_installer = \Drupal::service('module_installer');
    assert($module_installer instanceof ModuleInstallerInterface);
    $module_installer->install(['node', 'media']);

    $type = NodeType::create([
      'type' => 'article',
      'name' => 'Article',
    ]);
    $type->save();

    // The `image` media type must be installed before
    // media_library_storage_prop_shape_alter() is invoked, which it is after
    // installing new modules.
    // @see media_library_storage_prop_shape_alter()
    $this->createMediaType('image', ['id' => 'image', 'label' => 'Image']);
    $test_image_files = $this->getTestFiles('image');
    $first_image_file = $test_image_files[0];
    File::create([
      // @phpstan-ignore-next-line
      'uri' => $first_image_file->uri,
    ])->save();
    Media::create([
      'bundle' => 'image',
      'name' => 'The bones are their money',
      'field_media_image' => [
        [
          'target_id' => 1,
          'alt' => 'The bones equal dollars',
          'title' => 'Bones are the skeletons money',
        ],
      ],
    ])->save();
    $module_installer->install([
      'experience_builder',
    ]);

    $field_definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', 'article');
    $image_field_sample_value = ImageItem::generateSampleValue($field_definitions['field_hero']);
    $node = Node::create([
      'type' => 'article',
      'title' => 'XB Needs This For The Time Being',
      'field_hero' => $image_field_sample_value,
    ]);
    $node->save();

    $xb_role = Role::create([
      'id' => 'xb',
      'label' => 'xb',
    ]);
    $xb_role->grantPermission('access administration pages');
    $xb_role->grantPermission('access content');
    $xb_role->grantPermission('administer media');
    $xb_role->grantPermission('access media overview');
    $xb_role->grantPermission('view media');
    $xb_role->grantPermission('create media');
    $xb_role->grantPermission('create article content');
    $xb_role->save();

    $xb_user = User::create();
    $xb_user->setUsername('xbUser');
    $xb_user->setPassword('xbUser');
    $xb_user->setEmail('xb@test.com');
    $xb_user->addRole((string) $xb_role->id());
    $xb_user->enforceIsNew();
    $xb_user->activate();
    $xb_user->save();
  }

  /**
   * TRICKY: to allow reusing MediaTypeCreationTrait, simulate `::assertSame()`.
   *
   * @see \Drupal\Tests\media\Traits\MediaTypeCreationTrait
   */
  public static function assertSame(mixed $expected, mixed $actual, string $message = ''): void {
    // Intentionally empty;
  }

}
