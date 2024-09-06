<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\Adapter;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\file\FileInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\image\ImageStyleInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

#[Adapter(
  id: 'image_apply_style',
  label: new TranslatableMarkup('Apply image style'),
  inputs: [
    'image' => [
      'type' => 'object',
      // @todo Make `width` and `height` required?
      'required' => ['src'],
      'properties' => [
        'src' => [
          'title' => 'Original image stream wrapper URI',
          '$ref' => 'json-schema-definitions://experience_builder.module/stream-wrapper-image-uri',
        ],
        'width' => [
          'title' => 'Original image width',
          'type' => 'integer',
        ],
        'height' => [
          'title' => 'Original image height',
          'type' => 'integer',
        ],
        'alt' => [
          'title' => 'Original image alternative text',
          'type' => 'string',
        ],
      ],
    ],
    'imageStyle' => ['type' => 'string', '$ref' => 'json-schema-definitions://experience_builder.module/config-entity-id'],
  ],
  requiredInputs: ['image'],
  output: ['type' => 'object', '$ref' => 'json-schema-definitions://experience_builder.module/image'],
)]
final class ImageAndStyleAdapter extends AdapterBase implements ContainerFactoryPluginInterface {

  /**
   * @var array{src:string, alt: string, width:integer, height:integer}
   */
  protected array $image;
  protected string $imageStyle;
  protected EntityStorageInterface $fileStorage;
  protected ImageFactory $imageFactory;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entityTypeManager,
    ImageFactory $imageFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->fileStorage = $entityTypeManager->getStorage('file');
    $this->imageFactory = $imageFactory;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('image.factory')
    );
  }

  public function adapt(): mixed {
    $files = $this->fileStorage
      ->loadByProperties(['filename' => urldecode(basename($this->image['src']))]);
    $image = reset($files);
    if (!$image instanceof FileInterface) {
      throw new \Exception('No image file found');
    }

    $image_style = ImageStyle::load($this->imageStyle);
    if ($image_style instanceof ImageStyleInterface) {
      $src = $image_style->buildUrl((string) $image->getFileUri());
      $dimensions = ['width' => $this->image['width'], 'height' => $this->image['height']];
      $image_style->transformDimensions($dimensions, $this->image['src']);
      ['width' => $width, 'height' => $height] = $dimensions;
    }
    else {
      $src = $image->createFileUrl(FALSE);
      $height = $this->image['height'];
      $width = $this->image['width'];
    }

    return [
      'src' => $src,
      'alt' => $this->image['alt'],
      'width' => $width,
      'height' => $height,
    ];
  }

}
