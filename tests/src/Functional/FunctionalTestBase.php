<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Functional;

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\node\Entity\Node;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\TestFileCreationTrait;

abstract class FunctionalTestBase extends BrowserTestBase {

  use TestFileCreationTrait;

  protected function createTestNode1(): Node {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();
    // The `thumbnail` image style already exists.
    $this->assertInstanceOf(ImageStyle::class, ImageStyle::load('thumbnail'));

    // Node 1 does not exist.
    $this->assertNull(Node::load(1));

    // Navigate to `/node/add/article` and press `Save`, do nothing else.
    $this->drupalLogin($this->rootUser);
    $this->drupalGet('node/add/article');
    $assert_session->statusCodeEquals(200);
    $page->pressButton('Save');
    $this->assertStringEndsWith('node/add/article', $this->getSession()->getCurrentUrl());
    // @todo For some reason, specifying `type: 'error'` fails: the expected HTML structure is different?! 🤯
    $this->assertSession()->statusMessageContains('Title field is required.');
    $this->assertSession()->statusMessageContains('Hero field is required.');

    // Two entity fields are required: `Title` + `Hero`. Fill 'em, press `Save`.
    $page->fillField('title[0][value]', 'The first entity using XB!');
    $image_file = current($this->getTestFiles('image'));
    // @phpstan-ignore-next-line
    $image_file_uri = 'public://' . $image_file->name . ' with spaces.png';
    $file_system = $this->container->get(FileSystemInterface::class);
    assert($file_system instanceof FileSystemInterface);
    // @phpstan-ignore-next-line
    $file_system->move($image_file->uri, $image_file_uri, FileExists::Rename);
    $image_path = $this->container->get('file_system')->realpath($image_file_uri);
    $this->assertNotFalse($image_path);
    $page->attachFileToField('files[field_hero_0]', $image_path);
    $page->pressButton('Save');
    $this->assertStringEndsWith('node/add/article', $this->getSession()->getCurrentUrl());

    // Now that a file has been uploaded, we also need to specify `alt`.
    $this->assertSession()->statusMessageContains('Alternative text field is required.');
    $page->fillField('field_hero[0][alt]', 'A random image for testing purposes.');
    $page->pressButton('Save');

    // Success!
    $this->assertStringEndsWith('node/1', $this->getSession()->getCurrentUrl());

    $node = Node::load(1);
    // @phpstan-ignore-next-line
    $this->assertInstanceOf(Node::class, $node);
    return $node;
  }

}
