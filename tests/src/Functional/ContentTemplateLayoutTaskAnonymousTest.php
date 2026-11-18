<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\canvas\Access\AuthenticationAccessChecker;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that the "Edit in Canvas" task does not break anonymous views.
 *
 * The task links to `canvas.boot.entity`, which requires authentication. The
 * local tasks block checks that route's access on every node page, which must
 * hide the tab rather than challenge the visitor for credentials.
 */
#[Group('canvas')]
#[CoversClass(AuthenticationAccessChecker::class)]
#[RunTestsInSeparateProcesses]
final class ContentTemplateLayoutTaskAnonymousTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = [
    'block',
    'node',
    'canvas',
  ];

  /**
   * {@inheritdoc}
   *
   * @var string
   */
  protected $defaultTheme = 'stark';

  public function testAnonymousNodePage(): void {
    $this->drupalPlaceBlock('local_tasks_block');
    $this->drupalCreateContentType(['type' => 'article']);
    $node = $this->drupalCreateNode(['type' => 'article']);

    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderDoesNotExist('WWW-Authenticate');
    $this->assertSession()->linkByHrefNotExists('/canvas/editor/node/' . $node->id());

    // Requesting a route that requires authentication directly still
    // challenges for credentials.
    $this->drupalGet('/canvas/api/v0/layout/node/' . $node->id());
    $this->assertSession()->statusCodeEquals(401);
    $this->assertSession()->responseHeaderExists('WWW-Authenticate');
  }

}
