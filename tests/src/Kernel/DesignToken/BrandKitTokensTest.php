<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\DesignToken;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Controller\ApiStagedConfigUpdateAutoSaveController;
use Drupal\canvas\Controller\ErrorCodesEnum;
use Drupal\canvas\DesignToken\BrandKitTokens;
use Drupal\canvas\DesignToken\ColorValue;
use Drupal\canvas\Entity\AssetLibrary;
use Drupal\canvas\Entity\BrandKit;
use Drupal\canvas\Entity\StagedConfigUpdate;
use Drupal\canvas\Exception\ConstraintViolationException;
use Drupal\canvas\Plugin\ConfigAction\DesignTokenValue;
use Drupal\canvas\Utility\ColorResolver;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\Config\Action\ConfigActionException;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Extension\ThemeInstallerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Theme\Entity\DesignToken;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\canvas\Traits\AutoSaveRequestTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests theme design tokens in the brand kit, on core's design tokens API.
 *
 * Uses a test theme whose tokens.yml declares every type Canvas lists.
 *
 * @see tests/modules/canvas_test_design_tokens_theme/canvas_test_design_tokens_theme.tokens.yml
 */
#[Group('canvas')]
#[CoversClass(BrandKitTokens::class)]
#[CoversClass(ColorValue::class)]
#[CoversClass(DesignTokenValue::class)]
#[RunTestsInSeparateProcesses]
final class BrandKitTokensTest extends CanvasKernelTestBase {

  use AutoSaveRequestTestTrait;
  use RequestTrait;
  use UserCreationTrait;

  private const string THEME = 'canvas_test_design_tokens_theme';

  private BrandKitTokens $tokens;

  protected function setUp(): void {
    if (!\class_exists(DesignToken::class)) {
      $this->markTestSkipped('Requires the design tokens API of Drupal core 11.5 or later.');
    }
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->container->get(ThemeInstallerInterface::class)->install([self::THEME]);
    $this->config('system.theme')->set('default', self::THEME)->save();
    $directory = AssetLibrary::ASSETS_DIRECTORY;
    $this->container->get(FileSystemInterface::class)->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    $this->setUpCurrentUser([], [BrandKit::ADMIN_PERMISSION, 'publish auto-saves']);
    $tokens = $this->container->get(BrandKitTokens::class);
    \assert($tokens instanceof BrandKitTokens);
    $this->tokens = $tokens;
  }

  public function testListingFollowsTheTheme(): void {
    self::assertTrue($this->tokens->isAvailable());
    self::assertFalse($this->tokens->isEnabled());

    $tokens = \array_column($this->tokens->themeTokens(), NULL, 'path');
    // The alias is dropped: core has no alias support yet. Every other token
    // is listed, in declaration order.
    self::assertSame([
      'color.palette.violet',
      'color.semantic.primary',
      'font.heading',
      'font.body',
      'spacing.sm',
      'spacing.md',
      'type.heading',
    ], \array_keys($tokens));

    $violet = $tokens['color.palette.violet'];
    self::assertSame('{color.palette.violet}', $violet['reference']);
    self::assertSame('--color-palette-violet', $violet['cssVariable']);
    self::assertSame('Violet', $violet['name']);
    self::assertSame(['color', 'palette'], $violet['groupPath']);
    // Hex input is normalized: lowercase, and alpha always present.
    self::assertSame('#7c3aed', $violet['declaredValue']['hex']);
    self::assertArrayHasKey('alpha', $violet['declaredValue']);
    self::assertSame(self::THEME, $violet['provider']['name']);
    self::assertFalse($violet['overridden']);

    self::assertSame('md', $tokens['spacing.md']['name']);
    self::assertSame(['Font 3', 'serif'], $tokens['font.body']['declaredValue']);

    $groups = $this->tokens->themeTokenGroups();
    self::assertSame(['Color', 'Palette', 'Semantic', 'Font', 'Spacing', 'Type'], \array_column($groups, 'name'));
    self::assertSame('Spacing scale', $groups[4]['description']);

    // The brand kit response always reports the feature.
    $values = BrandKit::load(BrandKit::GLOBAL_ID)?->normalizeForClientSide()->values;
    self::assertIsArray($values);
    self::assertSame(['available' => TRUE, 'enabled' => FALSE, 'enableImpact' => ['otherModuleValues' => 0, 'collidingSiteColors' => 0]], $values['designTokens']);
    self::assertCount(7, $values['themeTokens']);
  }

  public function testColorValue(): void {
    // The CSS equals what a site color with the same value emits.
    self::assertSame('#7c3aed', (new ColorValue(['value' => '#7C3AED'], 'color', []))->toCss());
    self::assertSame('#aabbcc', (new ColorValue(['value' => '#abc'], 'color', []))->toCss());
    // An integer alpha from YAML is opaque, like 1.0.
    self::assertSame('#112233', (new ColorValue(['colorSpace' => 'srgb', 'components' => [0.1, 0.2, 0.3], 'alpha' => 1, 'hex' => '#112233'], 'color', []))->toCss());
    self::assertSame('rgba(255, 0, 0, 0.50)', (new ColorValue(['value' => '#ff000080'], 'color', []))->toCss());
    self::assertSame('hsl(120, 50%, 40%)', (new ColorValue(['colorSpace' => 'hsl', 'components' => [120, 50, 40]], 'color', []))->toCss());
    self::assertNull(ColorValue::parse('{color.palette.violet}'));
    self::assertNull(ColorValue::parse('red'));
  }

  public function testValueValidation(): void {
    $errors = static fn (string $type, mixed $value): array => BrandKitTokens::validateValue($type, $value)[1];
    self::assertSame([], $errors('fontFamily', ['-apple-system', 'Noto Sans JP', 'ui-sans-serif']));
    self::assertArrayHasKey('0', $errors('fontFamily', ['Font 3']));
    self::assertArrayHasKey('0', $errors('fontFamily', ['x;}body{display:none']));
    self::assertArrayHasKey('unit', $errors('dimension', ['value' => 1, 'unit' => 'em']));
    self::assertArrayHasKey('', $errors('number', \NAN));
    self::assertArrayHasKey('', $errors('fontWeight', 0));
    self::assertArrayHasKey('lineHeight', $errors('typography', [
      'fontFamily' => ['Inter'],
      'fontSize' => ['value' => 32, 'unit' => 'px'],
      'fontWeight' => 700,
      'letterSpacing' => ['value' => 0, 'unit' => 'px'],
    ]));
    self::assertSame(0.3, BrandKitTokens::validateValue('number', 0.1 + 0.2)[0]);
    self::assertTrue(BrandKitTokens::sameValue('number', 1, 1.0));

    // IDs depend on the path alone, and never collide when lossy.
    self::assertSame('color__semantic__primary', BrandKitTokens::entityId('color.semantic.primary'));
    self::assertNotSame(BrandKitTokens::entityId('a.b'), BrandKitTokens::entityId('a__b'));
    self::assertNotSame(BrandKitTokens::entityId('x-y'), BrandKitTokens::entityId('x_y'));
    self::assertMatchesRegularExpression('/^font_family__primary_font_[0-9a-f]{8}$/', BrandKitTokens::entityId('font_family.Primary font'));
    self::assertLessThanOrEqual(148, \strlen(BrandKitTokens::entityId(\str_repeat('a.', 100))));
    self::assertSame(37, \strlen(BrandKitTokens::stagedUpdateId(\str_repeat('long.path', 50))));
  }

  public function testOverrideResetAndCss(): void {
    // Off: nothing is written or emitted.
    self::assertSame('', $this->tokens->css());

    $this->tokens->setEnabled(TRUE);
    $this->tokens->setRootValue('color.semantic.primary', '#ff0000');
    $this->tokens->setRootValue('font.heading', ['Inter', '-apple-system', 'ui-sans-serif']);
    $entity = $this->tokens->findByPath('color.semantic.primary');
    self::assertNotNull($entity);
    self::assertTrue($entity->getThirdPartySetting('canvas', 'managed'));

    $css = BrandKit::load(BrandKit::GLOBAL_ID)?->getCss();
    self::assertStringContainsString(':root{--color-semantic-primary:#ff0000;}', (string) $css);
    self::assertStringContainsString('--font-heading:Inter, -apple-system, ui-sans-serif;', (string) $css);

    // Turning it off emits nothing, and deletes nothing.
    $this->tokens->setEnabled(FALSE);
    self::assertStringNotContainsString('--color-semantic-primary', (string) BrandKit::load(BrandKit::GLOBAL_ID)?->getCss());
    $this->tokens->setEnabled(TRUE);
    self::assertStringContainsString('#ff0000', (string) BrandKit::load(BrandKit::GLOBAL_ID)?->getCss());

    // Another tool's scope survives a reset, which persists.
    $scopes = $entity->get('scopes');
    $scopes['[data-theme=dark]'] = ColorValue::parse('#000000');
    $entity->set('scopes', $scopes)->save();
    $this->tokens->resetRootValue('color.semantic.primary');
    $reloaded = DesignToken::load((string) $entity->id());
    self::assertNotNull($reloaded);
    self::assertSame(['[data-theme=dark]'], \array_keys($reloaded->get('scopes')));
    self::assertStringContainsString('[data-theme=dark]{--color-semantic-primary:#000000;}', $this->tokens->css());

    // A managed entity with only `:root` is deleted on reset.
    $this->tokens->resetRootValue('font.heading');
    self::assertNull($this->tokens->findByPath('font.heading'));

    // An entity another tool saved with an invalid value is skipped, not
    // fatal, and appears under "Other values".
    // Core's entity refuses to save an `em` unit, so write the config itself.
    $this->config('core.design_token.spacing__gutter')->setData(['uuid' => '5d2ab8b6-2f8c-4f5e-9a51-3f6a0f1b8a10', 'langcode' => 'en', 'status' => TRUE, 'dependencies' => [], 'id' => 'spacing__gutter', 'path' => 'spacing.gutter', 'type' => 'dimension', 'scopes' => [':root' => ['value' => 1.0, 'unit' => 'em']]])->save();
    $this->tokens->resetIndex();
    DesignToken::create(['id' => 'spacing__ok', 'path' => 'spacing.ok', 'type' => 'dimension', 'scopes' => [':root' => ['value' => 4, 'unit' => 'px']]])->save();
    $css = $this->tokens->css();
    self::assertStringContainsString('--spacing-ok:4px;', $css);
    self::assertStringNotContainsString('--spacing-gutter', $css);
    $other = \array_column($this->tokens->themeTokens(), NULL, 'path')['spacing.ok'];
    self::assertNull($other['provider']);
    self::assertSame('Set by another module', $other['reason']);
    self::assertFalse($other['resettable']);
  }

  public function testReferences(): void {
    $resolver = $this->container->get(ColorResolver::class);
    $checksum = $this->container->get(CacheTagsChecksumInterface::class);

    // Off: the declared value.
    [$resolved, $cacheability] = $resolver->resolve('{color.semantic.primary}');
    self::assertIsArray($resolved);
    self::assertSame('rgb(0, 85, 170)', $resolved['cssColorValue']);
    self::assertSame('--color-semantic-primary', $resolved['cssVariable']);
    self::assertSame('Primary', $resolved['colorName']);
    self::assertContains(BrandKitTokens::MODE_CACHE_TAG, $cacheability->getCacheTags());
    self::assertContains('config:design_token_list', $cacheability->getCacheTags());

    $before = $checksum->getCurrentChecksum([BrandKitTokens::MODE_CACHE_TAG]);
    $this->tokens->setEnabled(TRUE);
    self::assertNotSame($before, $checksum->getCurrentChecksum([BrandKitTokens::MODE_CACHE_TAG]));

    $this->tokens->setRootValue('color.semantic.primary', '#ff0000');
    [$resolved, $cacheability] = $resolver->resolve('{color.semantic.primary}');
    self::assertIsArray($resolved);
    self::assertSame('rgb(255, 0, 0)', $resolved['cssColorValue']);
    // With an entity, saving unrelated tokens does not invalidate it.
    self::assertNotContains('config:design_token_list', $cacheability->getCacheTags());

    // A dangling reference resolves to NULL.
    self::assertNull($resolver->resolve('{color.gone}')[0]);
    self::assertSame('color.gone', ColorResolver::parseTokenPath('{color.gone}'));
    self::assertNull(ColorResolver::parseTokenPath('{}'));
  }

  public function testDraftsArePublishedFromReviewChanges(): void {
    $this->tokens->setEnabled(TRUE);
    $brand_kit = BrandKit::load(BrandKit::GLOBAL_ID);
    \assert($brand_kit instanceof BrandKit);

    // Staging a value: the server derives ID, target, label, and `base`.
    self::assertSame(['value' => ColorValue::parse('#123456')], $this->stage('color.semantic.primary', 'color', '#123456', ['base' => 'client-supplied']));
    $entry = $this->container->get(AutoSaveManager::class)->getAllAutoSaveList(FALSE, FALSE)['staged_config_update:' . BrandKitTokens::stagedUpdateId('color.semantic.primary')];
    self::assertSame('Style guide: Canvas test: design tokens theme / Color / Semantic / Primary', $entry['label']);
    self::assertSame(DesignTokenValue::TARGET, $entry['data']['target']);
    self::assertNull($entry['data']['actions'][0]['input']['base']);

    // Drafts preview, but are not live.
    self::assertStringContainsString('--color-semantic-primary:#123456;', $brand_kit->getDraftCss());
    self::assertStringNotContainsString('#123456', $brand_kit->getCss());
    $tokens = \array_column($this->tokens->themeTokens(), NULL, 'path');
    self::assertSame('#123456', $tokens['color.semantic.primary']['draft']['value']['hex']);

    // Invalid values, extra keys (no other scopes), and unlisted paths are
    // refused, and nothing is staged.
    foreach ([
      ['spacing.md', 'dimension', ['value' => 1, 'unit' => 'em'], [], 'actions.0.input.value.unit'],
      ['spacing.md', 'dimension', ['value' => 1, 'unit' => 'px'], ['scopes' => ['.card' => 1]], 'actions.0.input'],
      ['color.not.listed', 'color', '#000000', [], 'actions.0.input.path'],
      ['font.heading', 'fontFamily', ['x;}body{display:none'], [], 'actions.0.input.value.0'],
    ] as [$path, $type, $value, $extra, $pointer]) {
      try {
        $this->stage($path, $type, $value, $extra);
        self::fail("Expected $pointer to be rejected.");
      }
      catch (ConstraintViolationException $e) {
        self::assertSame($pointer, $e->getConstraintViolationList()->get(0)->getPropertyPath());
      }
    }
    self::assertCount(1, $this->getAutoSaveStatesFromServer());

    // Changed elsewhere (a CLI push) before publishing: the whole publish is
    // refused, and the other value stays.
    $this->tokens->setRootValue('color.semantic.primary', '#abcdef');
    $response = $this->makePublishAllRequest();
    self::assertSame(422, $response->getStatusCode());
    $error = \json_decode((string) $response->getContent(), TRUE)['errors'][0];
    self::assertSame(ErrorCodesEnum::DesignTokenChangedElsewhere->value, $error['code']);
    self::assertSame('Changed elsewhere since you edited it.', $error['detail']);
    self::assertSame('#abcdef', $this->tokens->publishedValue('color.semantic.primary')['hex']);

    // "Use my value": discard, then restage against the current value.
    $this->container->get(AutoSaveManager::class)->delete(StagedConfigUpdate::create(['id' => BrandKitTokens::stagedUpdateId('color.semantic.primary')]));
    $this->stage('color.semantic.primary', 'color', '#123456');
    // A stage of a reset of a value without override is a no-op, deleted.
    self::assertNull($this->stage('spacing.md', 'dimension', NULL));
    $this->stage('spacing.md', 'dimension', ['value' => 24, 'unit' => 'px']);

    $response = $this->makePublishAllRequest();
    self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    self::assertSame('#123456', $this->tokens->publishedValue('color.semantic.primary')['hex']);
    self::assertSame(['value' => 24.0, 'unit' => 'px'], $this->tokens->publishedValue('spacing.md'));
    self::assertStringContainsString('--spacing-md:24px;', (string) BrandKit::load(BrandKit::GLOBAL_ID)?->getCss());
    self::assertSame([], $this->tokens->stagedValues());
    // Staging the published value again is no change: no draft.
    self::assertNull($this->stage('spacing.md', 'dimension', ['value' => 24.0, 'unit' => 'px']));

    // A staged reset, published, removes the override.
    $this->stage('spacing.md', 'dimension', NULL);
    self::assertSame(200, $this->makePublishAllRequest()->getStatusCode());
    self::assertNull($this->tokens->findByPath('spacing.md'));

    // An override the theme no longer declares can be reset, and nothing else.
    $this->tokens->setRootValue('spacing.md', ['value' => 4, 'unit' => 'px']);
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->tokens->resetIndex();
    $other = \array_column($this->tokens->themeTokens(), NULL, 'path')['spacing.md'];
    self::assertTrue($other['resettable']);
    self::assertTrue($other['overridden']);
    try {
      $this->stage('spacing.md', 'dimension', ['value' => 8, 'unit' => 'px']);
      self::fail('Expected a value for an unlisted path to be rejected.');
    }
    catch (ConstraintViolationException $e) {
      self::assertSame('actions.0.input.path', $e->getConstraintViolationList()->get(0)->getPropertyPath());
    }
    self::assertSame(['value' => NULL], $this->stage('spacing.md', 'dimension', NULL));
    self::assertSame(['value' => NULL], \array_column($this->tokens->themeTokens(), NULL, 'path')['spacing.md']['draft']);
    self::assertSame(200, $this->makePublishAllRequest()->getStatusCode());
    self::assertNull($this->tokens->findByPath('spacing.md'));

    // While off, "Other values" overrides are not in effect.
    $this->tokens->setRootValue('color.semantic.primary', '#ff0000');
    $this->tokens->setEnabled(FALSE);
    self::assertFalse(\array_column($this->tokens->themeTokens(), NULL, 'path')['color.semantic.primary']['overridden']);
    $this->tokens->setEnabled(TRUE);

    // A token action must be the only action of its draft.
    $staged = StagedConfigUpdate::create([
      'id' => BrandKitTokens::stagedUpdateId('color.semantic.primary'),
      'label' => 'x',
      'target' => DesignTokenValue::TARGET,
      'actions' => [
        ['name' => BrandKitTokens::CONFIG_ACTION, 'input' => ['path' => 'color.semantic.primary', 'type' => 'color', 'value' => NULL, 'base' => NULL]],
        ['name' => 'simpleConfigUpdate', 'input' => ['label' => 'x']],
      ],
    ]);
    self::assertSame('actions.0', $staged->getTypedData()->validate()->get(0)->getPropertyPath());

    // The action is pinned to the brand kit.
    $this->expectException(ConfigActionException::class);
    $this->container->get('plugin.manager.config_action')->applyAction(BrandKitTokens::CONFIG_ACTION, 'system.site', ['path' => 'spacing.md', 'type' => 'dimension', 'value' => NULL]);
  }

  /**
   * Stages a style guide draft through the staged update controller.
   *
   * @return array{value: mixed}|null
   *   The `draft` the controller responds with.
   */
  private function stage(string $path, string $type, mixed $value, array $extra = []): ?array {
    $request = Request::create('/canvas/api/v0/staged-update/auto-save', 'POST', content: (string) \json_encode([
      'data' => [
        'id' => 'ignored',
        'label' => 'ignored',
        'target' => 'ignored',
        'actions' => [['name' => BrandKitTokens::CONFIG_ACTION, 'input' => ['path' => $path, 'type' => $type, 'value' => $value, ...$extra]]],
      ],
    ]));
    $request->headers->set('Content-Type', 'application/json');
    $controller = $this->container->get(ClassResolverInterface::class)->getInstanceFromDefinition(ApiStagedConfigUpdateAutoSaveController::class);
    $response = $controller->post($request);
    self::assertInstanceOf(JsonResponse::class, $response);
    self::assertSame(200, $response->getStatusCode());
    return \json_decode((string) $response->getContent(), TRUE)['draft'];
  }

}
