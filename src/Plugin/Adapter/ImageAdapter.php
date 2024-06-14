<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\Adapter;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\file\FileInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

#[Adapter(
  id: 'image_url_rel_to_abs',
  label: new TranslatableMarkup('Make relative image URL absolute'),
  inputs: [
    'image' => ['type' => 'object', '$ref' => 'json-schema-definitions://experience_builder.module/image'],
  ],
  requiredInputs: ['image'],
  output: ['type' => 'object', '$ref' => 'json-schema-definitions://experience_builder.module/image'],
)]
final class ImageAdapter extends AdapterBase implements ContainerFactoryPluginInterface {

  /**
   * @var array{src: string, alt: string, width:integer, height:integer}
   */
  protected array $image;
  protected EntityStorageInterface $fileStorage;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entityTypeManager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->fileStorage = $entityTypeManager->getStorage('file');
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager')
    );
  }

  public function adapt(): mixed {
    $files = $this->fileStorage
      ->loadByProperties(['filename' => urldecode(basename($this->image['src']))]);
    $image = reset($files);
    if (!$image instanceof FileInterface) {
      throw new \Exception('No image file found');
    }

    return [
      'src' => $image->createFileUrl(FALSE),
      'alt' => $this->image['alt'],
      'width' => $this->image['width'],
      'height' => $this->image['height'],
    ];
  }

}
