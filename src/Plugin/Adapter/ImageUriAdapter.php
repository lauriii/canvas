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
  id: 'image_extract_url',
  label: new TranslatableMarkup('Extract image URL'),
  inputs: [
    'imageUri' => ['type' => 'object', '$ref' => 'json-schema-definitions://experience_builder.module/image-uri'],
  ],
  requiredInputs: ['image'],
  output: ['type' => 'object', '$ref' => 'json-schema-definitions://experience_builder.module/image-uri'],
)]
class ImageUriAdapter extends AdapterBase implements ContainerFactoryPluginInterface {

  protected string $imageUri;
  protected EntityStorageInterface $fileStorage;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entityTypeManager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->fileStorage = $entityTypeManager->getStorage('file');
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager')
    );
  }

  public function adapt(): mixed {
    $files = $this->fileStorage
      ->loadByProperties(['filename' => urldecode(basename($this->imageUri))]);
    $image = reset($files);
    if (!$image instanceof FileInterface) {
      throw new \Exception('No image file found');
    }

    return $image->createFileUrl(FALSE);
  }

}
