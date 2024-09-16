<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Functional;

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

    // One entity fields is required: `Title`. Fill it, press `Save`.
    $page->fillField('title[0][value]', 'The first entity using XB!');
    $page->pressButton('Save');

    // Success!
    $this->assertStringEndsWith('node/1', $this->getSession()->getCurrentUrl());

    $node = Node::load(1);
    // @phpstan-ignore-next-line
    $this->assertInstanceOf(Node::class, $node);
    return $node;
  }

}
