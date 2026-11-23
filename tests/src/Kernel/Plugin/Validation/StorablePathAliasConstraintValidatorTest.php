<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Plugin\Validation;

use Drupal\canvas\Entity\Page;
use Drupal\canvas\Plugin\Validation\Constraint\StorablePathAliasConstraintValidator;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\PageTrait;
use Drupal\Tests\content_translation\Traits\ContentTranslationTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests validating page path aliases as they would be stored.
 *
 * The path alias validation itself is core's, and tested there: this tests
 * how pages' aliases are adapted to it.
 *
 * @see \Drupal\Tests\path\Functional\PathAliasTest
 */
#[CoversClass(StorablePathAliasConstraintValidator::class)]
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class StorablePathAliasConstraintValidatorTest extends CanvasKernelTestBase {

  use ContentTranslationTestTrait;
  use PageTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...self::PAGE_TEST_MODULES,
    'language',
    'content_translation',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installPageEntitySchema();
    $this->installConfig(['language']);
    ConfigurableLanguage::createFromLangcode('es')->save();
    $this->enableContentTranslation(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID);
    // Validate as a user who may edit pages, but has no other permissions.
    $this->setUpCurrentUser(permissions: [Page::EDIT_PERMISSION]);
  }

  public static function providerValidAlias(): array {
    return [
      'omitted' => [[], NULL],
      'NULL' => [['path' => NULL], NULL],
      'empty' => [['path' => ['alias' => '']], NULL],
      'whitespace' => [['path' => ['alias' => '  ']], NULL],
      'alias' => [['path' => ['alias' => '/foo']], '/foo'],
      'alias with trailing slashes and whitespace' => [['path' => ['alias' => ' /foo/\\ ']], '/foo'],
    ];
  }

  #[DataProvider('providerValidAlias')]
  public function testValidAlias(array $values, ?string $expected_stored_alias): void {
    $page = Page::create(['title' => 'Page'] + $values);
    self::assertEntityIsValid($page);
    $page->save();
    self::assertSame($expected_stored_alias, $this->loadStoredAlias($page));
    self::assertSame($expected_stored_alias === NULL ? 0 : 1, $this->countPathAliases());
  }

  public static function providerInvalidAlias(): array {
    return [
      'slash' => ['/', self::slashOnlyMessage('/')],
      'slashes' => ['//', self::slashOnlyMessage('//')],
      'backslash' => ['\\', self::slashOnlyMessage('\\')],
      'slash and backslash' => ['/\\', self::slashOnlyMessage('/\\')],
      'slash surrounded by whitespace' => [' / ', self::slashOnlyMessage(' / ')],
      'slashes and backslashes surrounded by mixed whitespace' => [" \t/\\/\n", self::slashOnlyMessage(" \t/\\/\n")],
      'no leading slash' => ['foo', 'The alias path has to start with a slash.'],
    ];
  }

  #[DataProvider('providerInvalidAlias')]
  public function testInvalidAlias(string $alias, string $expected_message): void {
    $page = Page::create(['title' => 'Page', 'path' => ['alias' => $alias]]);
    self::assertSame(['path.0.alias' => $expected_message], self::violationsToArray($page->validate()));

    // An existing alias cannot be replaced by an invalid one either.
    $page = Page::create(['title' => 'Page', 'path' => ['alias' => '/existing']]);
    $page->save();
    $page->set('path', ['alias' => $alias, 'pid' => $page->get('path')->first()?->get('pid')->getValue()]);
    self::assertSame(['path.0.alias' => $expected_message], self::violationsToArray($page->validate()));
  }

  public function testDuplicateAlias(): void {
    $page = Page::create(['title' => 'Page', 'path' => ['alias' => '/taken']]);
    $page->save();

    // Duplicates are detected after normalization.
    $new_page = Page::create(['title' => 'New page', 'path' => ['alias' => '/taken/']]);
    self::assertSame([
      'path.0.alias' => 'The alias <em class="placeholder">/taken</em> is already in use in this language.',
    ], self::violationsToArray($new_page->validate()));
  }

  public function testOwnAlias(): void {
    $page = Page::create(['title' => 'Page', 'path' => ['alias' => '/mine']]);
    $page->save();
    $pid = $page->get('path')->first()?->get('pid')->getValue();

    // Setting the stored alias again, with or without its ID.
    $page->set('path', ['alias' => '/mine', 'pid' => $pid]);
    self::assertEntityIsValid($page);
    $page->set('path', ['alias' => '/mine']);
    self::assertEntityIsValid($page);
    // Changing the capitalization of the page's own alias.
    $page->set('path', ['alias' => '/Mine', 'pid' => $pid]);
    self::assertEntityIsValid($page);
    $page->save();
    self::assertSame('/Mine', $this->loadStoredAlias($page));
    self::assertSame(1, $this->countPathAliases());
  }

  /**
   * Tests that an existing path alias is validated without changing it.
   */
  public function testExistingPathAlias(): void {
    $page = Page::create(['title' => 'Page', 'path' => ['alias' => '/mine']]);
    $page->save();
    $pid = $page->get('path')->first()?->get('pid')->getValue();
    $path_alias_storage = $this->container->get(EntityTypeManagerInterface::class)->getStorage('path_alias');
    $path_alias = $path_alias_storage->load($pid);
    \assert($path_alias instanceof PathAlias);

    $page->set('path', ['alias' => '/changed/', 'pid' => $pid]);
    self::assertEntityIsValid($page);
    $page->set('path', ['alias' => '/', 'pid' => $pid]);
    self::assertSame(['path.0.alias' => self::slashOnlyMessage('/')], self::violationsToArray($page->validate()));
    self::assertSame($path_alias, $path_alias_storage->load($pid));
    self::assertSame(
      [$pid, '/page/' . $page->id(), '/mine', 'en', FALSE],
      [$path_alias->id(), $path_alias->getPath(), $path_alias->getAlias(), $path_alias->language()->getId(), $path_alias->isNew()],
    );

    // An existing alias is validated in its own language, which may differ
    // from the page's.
    $other_page = Page::create(['title' => 'Other page']);
    $other_page->save();
    $neutral_path_alias = PathAlias::create(['path' => '/page/' . $other_page->id(), 'alias' => '/neutral', 'langcode' => 'und']);
    $neutral_path_alias->save();
    $other_page = $this->reloadPage($other_page);
    $other_page->set('path', ['alias' => '/mine', 'pid' => $neutral_path_alias->id()]);
    self::assertEntityIsValid($other_page);
    $other_page->set('path', ['alias' => '/mine']);
    self::assertSame([
      'path.0.alias' => 'The alias <em class="placeholder">/mine</em> is already in use in this language.',
    ], self::violationsToArray($other_page->validate()));
  }

  /**
   * Tests aliases stored before this validation existed.
   */
  public function testLegacyAlias(): void {
    $page = Page::create(['title' => 'Page', 'path' => ['alias' => '/duplicate']]);
    $page->save();
    $other_page = Page::create(['title' => 'Other page']);
    $other_page->save();
    $empty_alias_page = Page::create(['title' => 'Empty alias page']);
    $empty_alias_page->save();
    // Store a duplicate alias and a path alias with a NULL alias, as could be
    // stored before this validation existed.
    PathAlias::create(['path' => '/page/' . $other_page->id(), 'alias' => '/duplicate', 'langcode' => 'en'])->save();
    PathAlias::create(['path' => '/page/' . $empty_alias_page->id(), 'alias' => '/', 'langcode' => 'en'])->save();
    $other_page = $this->reloadPage($other_page);
    $empty_alias_page = $this->reloadPage($empty_alias_page);

    // An invalid alias is rejected even if unchanged, until it is corrected.
    $other_page->set('title', 'Other page, edited');
    self::assertSame([
      'path.0.alias' => 'The alias <em class="placeholder">/duplicate</em> is already in use in this language.',
    ], self::violationsToArray($other_page->validate()));
    $other_page->set('path', ['alias' => '/unique', 'pid' => $other_page->get('path')->first()?->get('pid')->getValue()]);
    self::assertEntityIsValid($other_page);
    $other_page->save();
    self::assertSame('/unique', $this->loadStoredAlias($other_page));

    // A NULL alias is no alias to validate, but a slash-only alias cannot be set
    // anew.
    $empty_alias_page->set('title', 'Empty alias page, edited');
    self::assertEntityIsValid($empty_alias_page);
    $empty_alias_page->set('path', ['alias' => '/', 'pid' => $empty_alias_page->get('path')->first()?->get('pid')->getValue()]);
    self::assertSame([
      'path.0.alias' => self::slashOnlyMessage('/'),
    ], self::violationsToArray($empty_alias_page->validate()));
  }

  /**
   * Tests that an empty alias removes an existing one.
   */
  #[DataProvider('providerClearedAlias')]
  public function testClearedAlias(string $alias): void {
    $page = Page::create(['title' => 'Page', 'path' => ['alias' => '/existing']]);
    $page->save();
    $page->set('path', ['alias' => $alias, 'pid' => $page->get('path')->first()?->get('pid')->getValue()]);
    self::assertEntityIsValid($page);
    $page->save();
    self::assertNull($this->loadStoredAlias($page));
    self::assertSame(0, $this->countPathAliases());
  }

  public static function providerClearedAlias(): array {
    return [
      'empty' => [''],
      'whitespace' => [" \t\n"],
    ];
  }

  /**
   * Tests that the source path is validated for existing pages only.
   */
  public function testSourcePath(): void {
    $page = Page::create(['title' => 'Page', 'status' => FALSE, 'path' => ['alias' => '/unpublished']]);
    self::assertEntityIsValid($page);
    $page->save();

    // Users who may edit pages may view unpublished ones too.
    $page->set('path', ['alias' => '/unpublished-changed', 'pid' => $page->get('path')->first()?->get('pid')->getValue()]);
    self::assertEntityIsValid($page);

    // Users who may not view the page cannot alias it.
    $this->setUpCurrentUser(permissions: ['access content']);
    self::assertSame([
      'path.0.alias' => "Either the path '<em class=\"placeholder\">/page/{$page->id()}</em>' is invalid or you do not have access to it.",
    ], self::violationsToArray($page->validate()));
  }

  public function testTranslations(): void {
    $page = Page::create(['title' => 'Page', 'path' => ['alias' => '/shared']]);
    $page->save();
    // Aliases are unique per language.
    $translation = $page->addTranslation('es', ['title' => 'Page (es)', 'path' => ['alias' => '/shared']]);
    self::assertEntityIsValid($translation);
    $translation->save();
    $page = $this->reloadPage($page);
    self::assertSame('/shared', $this->loadStoredAlias($page));
    self::assertSame('/shared', $this->loadStoredAlias($page->getTranslation('es')));
    self::assertSame(2, $this->countPathAliases());

    $other_page = Page::create(['title' => 'Other page', 'path' => ['alias' => '/other']]);
    $other_page->save();
    $other_translation = $other_page->addTranslation('es', ['title' => 'Other page (es)', 'path' => ['alias' => '/shared']]);
    self::assertSame([
      'path.0.alias' => 'The alias <em class="placeholder">/shared</em> is already in use in this language.',
    ], self::violationsToArray($other_translation->validate()));
    $other_translation->set('path', ['alias' => '/other-es']);
    self::assertEntityIsValid($other_translation);
    $other_translation->save();

    // A translation's existing alias is validated in its own language.
    $other_translation = $this->reloadPage($other_page)->getTranslation('es');
    $other_translation->set('path', ['alias' => '/shared', 'pid' => $other_translation->get('path')->first()?->get('pid')->getValue()]);
    self::assertSame([
      'path.0.alias' => 'The alias <em class="placeholder">/shared</em> is already in use in this language.',
    ], self::violationsToArray($other_translation->validate()));
  }

  private function reloadPage(Page $page): Page {
    $page_id = $page->id();
    \assert($page_id !== NULL);
    $page = $this->container->get(EntityTypeManagerInterface::class)->getStorage(Page::ENTITY_TYPE_ID)->loadUnchanged($page_id);
    \assert($page instanceof Page);
    return $page;
  }

  private function loadStoredAlias(Page $page): ?string {
    $path_aliases = $this->container->get(EntityTypeManagerInterface::class)->getStorage('path_alias')->loadByProperties([
      'path' => '/page/' . $page->id(),
      'langcode' => $page->language()->getId(),
    ]);
    self::assertLessThanOrEqual(1, \count($path_aliases));
    $path_alias = \reset($path_aliases);
    return $path_alias instanceof PathAlias ? (string) $path_alias->getAlias() : NULL;
  }

  private static function slashOnlyMessage(string $alias): string {
    return "The alias <em class=\"placeholder\">$alias</em> cannot consist only of slashes.";
  }

  private function countPathAliases(): int {
    return (int) $this->container->get(EntityTypeManagerInterface::class)->getStorage('path_alias')->getQuery()->accessCheck(FALSE)->count()->execute();
  }

}
