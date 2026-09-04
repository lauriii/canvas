<?php

declare(strict_types=1);

namespace Drupal\Tests\multi_frontend\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\KernelTests\KernelTestBase;
use Drupal\multi_frontend\Form\FormDescriber;
use Drupal\multi_frontend\Form\FormRegistry;
use Drupal\multi_frontend\Form\FormSubmitter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests describing a form as data and submitting one from raw values.
 */
#[Group('multi_frontend')]
#[RunTestsInSeparateProcesses]
final class FormContractTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'file', 'multi_frontend', 'multi_frontend_test'];

  private const FORM = '\Drupal\multi_frontend_test\Form\DescribableTestForm';

  private FormDescriber $describer;
  private FormSubmitter $submitter;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->describer = $this->container->get(FormDescriber::class);
    $this->submitter = $this->container->get(FormSubmitter::class);
  }

  /**
   * The description carries the form's own ID, not the class it was asked for.
   */
  public function testReportsFormId(): void {
    $this->assertSame('multi_frontend_test_describable', $this->describer->describe(self::FORM)['id']);
  }

  /**
   * Scalar elements become typed properties, with their constraints.
   */
  public function testDescribesScalars(): void {
    $properties = $this->describer->describe(self::FORM)['schema']['properties'];

    // Core's Email element carries #maxlength 254, so the contract does too.
    $this->assertSame(
      ['type' => 'string', 'format' => 'email', 'maxLength' => 254],
      $properties['email'],
    );
    $this->assertSame(['type' => 'string', 'maxLength' => 32], $properties['nickname']);
    $this->assertSame(['type' => 'string'], $properties['note']);
  }

  /**
   * Options become an enum, and checkboxes an array of them.
   */
  public function testDescribesChoices(): void {
    $properties = $this->describer->describe(self::FORM)['schema']['properties'];

    $this->assertSame(['free', 'paid'], $properties['plan']['enum']);
    $this->assertSame(['free' => 'Free', 'paid' => 'Paid'], $properties['plan']['meta:enum']);
    $this->assertSame('free', $properties['plan']['default'] ?? NULL);

    $this->assertSame('array', $properties['topics']['type']);
    $this->assertSame(['news', 'events'], $properties['topics']['items']['enum']);
  }

  /**
   * Required elements are required in the schema, and only those.
   */
  public function testDescribesRequired(): void {
    $this->assertSame(['email'], $this->describer->describe(self::FORM)['schema']['required']);
  }

  /**
   * A form is a closed contract: a client may not invent properties.
   */
  public function testSchemaIsClosed(): void {
    $schema = $this->describer->describe(self::FORM)['schema'];
    $this->assertFalse($schema['additionalProperties']);
    $this->assertSame('http://json-schema.org/draft-07/schema#', $schema['$schema']);
  }

  /**
   * Layout elements are descended through, not published and not reported.
   */
  public function testDescendsThroughLayout(): void {
    $description = $this->describer->describe(self::FORM);

    $this->assertArrayHasKey('note', $description['schema']['properties']);
    $this->assertArrayNotHasKey('group', $description['schema']['properties']);
    $this->assertArrayNotHasKey('actions', $description['schema']['properties']);
    $this->assertNotContains('group (details)', $description['unsupported']);
  }

  /**
   * What cannot be described is named, rather than silently dropped.
   *
   * This is the whole honesty claim: a client can see that this form has a
   * part the contract does not express, and decide accordingly.
   */
  public function testReportsUndescribableElements(): void {
    $description = $this->describer->describe(self::FORM);

    $this->assertContains('attachment (managed_file)', $description['unsupported']);
    $this->assertArrayNotHasKey('attachment', $description['schema']['properties']);
  }

  /**
   * A duplicate element name is reported rather than silently overwriting.
   *
   * Values are flat, so two elements sharing a name at different depths would
   * publish a schema describing only one of them, and a client would send a
   * payload the form does not read.
   */
  public function testDuplicateNameIsReported(): void {
    $description = $this->describer->describe(self::FORM);

    $this->assertContains('note (duplicate element name)', $description['unsupported']);
    // The first one still published, so the rest of the form is usable.
    $this->assertArrayHasKey('note', $description['schema']['properties']);
  }

  /**
   * A #tree subtree is reported, not flattened into a wrong shape.
   */
  public function testTreeSubtreeIsReported(): void {
    $description = $this->describer->describe(self::FORM);

    $this->assertContains('nested (nested #tree values)', $description['unsupported']);
    $this->assertArrayNotHasKey('inner', $description['schema']['properties']);
  }

  /**
   * A composite element's internal #tree does not exclude it.
   *
   * Checkboxes and managed_file both set #tree on themselves to collect their
   * own parts. Reading that as author-declared value nesting would drop every
   * checkbox group and file field out of the contract, which is a regression
   * a real front end caught and these assertions pin down.
   *
   * @see \Drupal\Core\Render\Element\Checkboxes
   * @see \Drupal\file\Element\ManagedFile
   */
  public function testCompositeElementTreeIsNotAuthorNesting(): void {
    $description = $this->describer->describe(self::FORM);

    // A checkbox group is published, not reported as nested.
    $this->assertArrayHasKey('topics', $description['schema']['properties']);
    $this->assertNotContains('topics (nested #tree values)', $description['unsupported']);
    // A file field is reported by its own type, not as nesting.
    $this->assertContains('attachment (managed_file)', $description['unsupported']);
    $this->assertNotContains('attachment (nested #tree values)', $description['unsupported']);
  }

  /**
   * An element the describer has never heard of describes itself.
   *
   * This is the inversion core asked for and never built: the describer holds
   * no knowledge of this type, and the form is still fully published. Without
   * it, every contrib element type would need a patch to the describer, which
   * is why the one shipped attempt at this contract stalled.
   *
   * @see \Drupal\multi_frontend\Form\JsonSchemaFormElementInterface
   * @see https://www.drupal.org/project/drupal/issues/2913372
   */
  public function testElementDescribesItself(): void {
    $description = $this->describer->describe(self::FORM);

    $this->assertSame(
      'An ISO 8601 duration.',
      $description['schema']['properties']['duration']['description'] ?? NULL,
    );
    $this->assertNotEmpty($description['schema']['properties']['duration']['pattern']);
    $this->assertSame('multi_frontend_test_duration', $description['ui']['duration']['widget']);
    $this->assertNotContains('duration (multi_frontend_test_duration)', $description['unsupported']);
  }

  /**
   * Drupal's own submission machinery never reaches the contract.
   *
   * FormBuilder adds form_build_id, form_id and form_token to every built
   * form. A client sends values, so these are not fields, and publishing them
   * would put a Drupalism into the one artifact that exists to hide it.
   */
  public function testExcludesSubmissionMachinery(): void {
    $description = $this->describer->describe(self::FORM);

    foreach (['form_build_id', 'form_id', 'form_token'] as $name) {
      $this->assertArrayNotHasKey($name, $description['schema']['properties'], "$name is not a field");
      $this->assertArrayNotHasKey($name, $description['ui'], "$name has no widget");
    }
    $this->assertSame([], \array_filter(
      $description['unsupported'],
      static fn (string $u): bool => \str_contains($u, 'form_'),
    ));
  }

  /**
   * Presentation hints are separate from the value schema.
   */
  public function testDescribesPresentationSeparately(): void {
    $ui = $this->describer->describe(self::FORM)['ui'];

    $this->assertSame('email', $ui['email']['widget']);
    $this->assertSame('Email', $ui['email']['label']);
    $this->assertSame('Where to reach you.', $ui['email']['description']);
    $this->assertSame('you@example.com', $ui['email']['placeholder']);
    $this->assertTrue($ui['topics']['multiple']);
    // A single-value select carries no multiple hint at all.
    $this->assertArrayNotHasKey('multiple', $ui['plan']);
  }

  /**
   * A password field is described but marked not to be read back.
   */
  public function testPasswordIsWriteOnly(): void {
    $properties = $this->describer->describe('\Drupal\user\Form\UserLoginForm')['schema']['properties'];
    $this->assertTrue($properties['pass']['writeOnly']);
  }

  /**
   * The built form's cacheability reaches the caller.
   *
   * A description is not derived from code alone: a form can build its
   * elements from configuration, so a response that carried no cache tags
   * would be served stale until a full cache rebuild.
   */
  public function testCollectsCacheability(): void {
    $cacheability = new CacheableMetadata();
    $this->describer->describe(self::FORM, $cacheability);

    // Every form carries at least the form-level cacheability that
    // FormBuilder puts on the built array.
    $this->assertNotSame(0, $cacheability->getCacheMaxAge());
    $this->assertIsArray($cacheability->getCacheTags());
    $this->assertIsArray($cacheability->getCacheContexts());
  }

  /**
   * Describing works without a collector, since it is optional.
   */
  public function testCacheabilityCollectorIsOptional(): void {
    $this->assertArrayHasKey('email', $this->describer->describe(self::FORM)['schema']['properties']);
  }

  /**
   * A valid submission runs the form's own submit handler.
   */
  public function testSubmitRunsHandlers(): void {
    $result = $this->submitter->submit(self::FORM, ['email' => 'a@example.com', 'nickname' => 'ada']);

    $this->assertSame('ok', $result['status']);
    $this->assertSame([], $result['violations']);
    $this->assertContains('Saved ada.', $result['messages']);
  }

  /**
   * A client cannot set a value for an element hidden behind #access.
   *
   * Core defaults programmatic submission to bypassing element access, on the
   * assumption that the caller is trusted PHP. Over HTTP the caller is not,
   * and FormBuilder's own comment on that branch warns that such submissions
   * "may bypass access restriction and be treated as high-privilege users".
   *
   * @see \Drupal\Core\Form\FormBuilder::doBuildForm()
   */
  public function testAccessDeniedElementCannotBeSet(): void {
    $result = $this->submitter->submit(self::FORM, [
      'email' => 'a@example.com',
      'nickname' => 'ada',
      'secret' => 'injected',
    ]);

    $this->assertSame('ok', $result['status']);
    $this->assertContains('Secret is untouched.', $result['messages']);
    $this->assertNotContains('Secret is injected.', $result['messages']);
  }

  /**
   * An element hidden behind #access is not published either.
   *
   * Publishing it would advertise a field the submit endpoint is required to
   * ignore, which is a contract that lies about itself.
   */
  public function testAccessDeniedElementIsNotPublished(): void {
    $description = $this->describer->describe(self::FORM);

    $this->assertArrayNotHasKey('secret', $description['schema']['properties']);
    $this->assertArrayNotHasKey('secret', $description['ui']);
    // The object form of #access, which is what core's own code produces.
    $this->assertArrayNotHasKey('object_denied', $description['schema']['properties']);
    $this->assertArrayNotHasKey('object_denied', $description['ui']);
    $this->assertSame([], \array_filter(
      $description['unsupported'],
      static fn (string $u): bool => \str_starts_with($u, 'secret'),
    ));
  }

  /**
   * The form's own validation runs, so its rules hold for any client.
   */
  public function testServerValidationRuns(): void {
    $result = $this->submitter->submit(self::FORM, ['email' => 'a@example.com', 'nickname' => 'taken']);

    $this->assertSame('invalid', $result['status']);
    $this->assertSame(
      [['path' => '/nickname', 'message' => 'That nickname is taken.']],
      $result['violations'],
    );
  }

  /**
   * A nested error becomes a JSON pointer a client can map onto its payload.
   */
  public function testNestedViolationBecomesPointer(): void {
    $result = $this->submitter->submit(self::FORM, ['email' => 'a@example.com', 'note' => 'bad']);

    $this->assertSame('/group/note', $result['violations'][0]['path']);
  }

  /**
   * A failed submission reports violations and no success messages.
   */
  public function testFailedSubmitReportsNoMessages(): void {
    $result = $this->submitter->submit(self::FORM, ['nickname' => 'taken']);

    $this->assertSame('invalid', $result['status']);
    $this->assertSame([], $result['messages']);
  }

  /**
   * Only forms a module opted in are in the registry, with their gates.
   */
  public function testRegistryIsOptIn(): void {
    $registry = $this->container->get(FormRegistry::class);

    $this->assertSame(['test.describable', 'test.restricted'], \array_keys($registry->all()));
    $this->assertNull($registry->get('test.describable')['permission']);
    $this->assertSame('administer site configuration', $registry->get('test.restricted')['permission']);
    $this->assertNull($registry->get('user.login'));
  }

}
