<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\canvas\Entity\Page;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Tests a user being able to submit a block form in a component.
 *
 * @group canvas
 */
final class BlockComponentFormTest extends FunctionalTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas',
    // @see \Drupal\block_test\Plugin\Block\TestFormBlock
    'block_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  public function test(): void {
    $user = $this->drupalCreateUser(['access content']);
    \assert($user instanceof AccountInterface);
    $this->drupalLogin($user);

    $page = Page::create([
      'title' => 'Test page using a block component',
      'components' => [
        'uuid' => '16176e0b-8197-40e3-ad49-48f1b6e9a7f9',
        'component_id' => 'block.test_form_in_block',
        'component_version' => 'fd9a41c092d9de57',
      ],
    ]);

    self::assertCount(0, $page->validate());
    $page->save();
    \assert($page instanceof Page);

    $html = $this->drupalGet($page->toUrl()->toString());
    $crawler = new Crawler($html);
    $form = $crawler->filter('#block-test-form-test');
    self::assertCount(1, $form);

    $this->submitForm([
      'edit-email' => 'example@example.com',
    ], 'Submit');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('Oops, something went wrong! Site admins have been notified.');
    $this->assertSession()->pageTextNotContains('This is not a .com email address.');
    $this->assertSession()->pageTextContains('Your email address is example@example.com');
    // Check for the form label to ensure the form is present.
    $this->assertSession()->pageTextContains('Your .com email address.');
  }

}
