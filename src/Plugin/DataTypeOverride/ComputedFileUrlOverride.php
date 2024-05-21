<?php

namespace Drupal\experience_builder\Plugin\DataTypeOverride;

use Drupal\Core\TypedData\Plugin\DataType\Uri;
use Drupal\file\FileInterface;

/**
 * Computed file URL property class.
 */
class ComputedFileUrlOverride extends Uri {

  /**
   * Computed root-relative file URL.
   *
   * @var string
   */
  protected $url = NULL;

  /**
   * {@inheritdoc}
   */
  public function getValue() {
    if ($this->url !== NULL) {
      return $this->url;
    }
    if ($this->getParent() === NULL || $this->getParent()->getParent() === NULL) {
      return NULL;
    }

    assert($this->getParent()->getEntity() instanceof FileInterface);

    $uri = $this->getParent()->getEntity()->getFileUri();
    /** @var \Drupal\Core\File\FileUrlGeneratorInterface $file_url_generator */
    $file_url_generator = \Drupal::service('file_url_generator');
    $this->url = $file_url_generator->generateString($uri);

    return $this->url;
  }

  /**
   * {@inheritdoc}
   */
  public function getCastedValue() {
    return $this->getValue();
  }

  /**
   * {@inheritdoc}
   */
  public function setValue($value, $notify = TRUE) {
    $this->url = $value;

    // Notify the parent of any changes.
    if ($notify && isset($this->parent)) {
      $this->parent->onChange($this->name);
    }
  }

}
