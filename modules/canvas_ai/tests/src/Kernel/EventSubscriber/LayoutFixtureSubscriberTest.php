<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel\EventSubscriber;

use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\canvas_ai\CanvasAiPermissions;
use Drupal\canvas_ai\CanvasAiTempStore;
use Drupal\canvas_ai_agents_test\EventSubscriber\LayoutFixtureSubscriber;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests LayoutFixtureSubscriber.
 */
#[CoversClass(LayoutFixtureSubscriber::class)]
#[Group('canvas_ai')]
final class LayoutFixtureSubscriberTest extends CanvasKernelTestBase {

  use UserCreationTrait;

  private const EMPTY_CANVAS_PAGE_LAYOUT_FIXTURE = '{"regions":{"content":{"nodePathPrefix":[0],"components":[]}}}';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'ai',
    'ai_agents',
    'ai_agents_test',
    'canvas_ai',
    'canvas_ai_agents_test',
  ];

  /**
   * {@inheritdoc}
   *
   * These configs are provided by the ai_agents_test module
   * and are excluded because they fail config schema validation.
   */
  protected static $configSchemaCheckerExclusions = [
    'views.view.ai_agents_test_group_result',
    'views.view.ai_agents_test_result',
  ];

  /**
   * The AI function call plugin manager.
   */
  private PluginManagerInterface $functionCallManager;

  /**
   * The Canvas AI tempstore.
   */
  private CanvasAiTempStore $tempStore;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('user');
    $this->installEntitySchema('ai_agents_test');
    $user = $this->createUser([CanvasAiPermissions::USE_CANVAS_AI]);
    $this->assertInstanceOf(AccountInterface::class, $user);
    $this->container->get('current_user')->setAccount($user);
    $this->functionCallManager = $this->container->get('plugin.manager.ai.function_calls');
    $this->tempStore = $this->container->get('canvas_ai.tempstore');
  }

  /**
   * The layout-dependent agents whose tests must supply a fixture.
   *
   * @return array<string, array{string, string}>
   *   Each case is [agent id, route].
   */
  public static function providerLayoutDependentAgents(): array {
    return [
      'page builder, run route' => ['canvas_page_builder_agent', 'ai_agents_test.run_test'],
      'template builder, ajax route' => ['canvas_template_builder_agent', 'ai_agents_test.group_ajax'],
    ];
  }

  /**
   * Tests the layout fixture lifecycle: loaded on request, cleared on response.
   *
   * @see \Drupal\canvas_ai\Plugin\AiFunctionCall\GetCurrentLayout::execute()
   */
  #[DataProvider('providerLayoutDependentAgents')]
  public function testLayoutFixtureLifecycleForExpectedAgents(string $agentId, string $route): void {
    $testId = $this->createAiAgentTestEntity($agentId, 'canvas_layout_fixture: any-name');
    $subscriber = $this->getLayoutFixtureSubscriberWithReadFixtureFileMethodReturning(self::EMPTY_CANVAS_PAGE_LAYOUT_FIXTURE);
    $subscriber->onRequest($this->createRequestEventObject($route, $testId));

    // The subscriber stored the fixture in the tempstore during onRequest above.
    // The get_current_layout tool reads it back from the tempstore when executed.
    $tool = $this->functionCallManager->createInstance('canvas_ai:get_current_layout');
    $this->assertInstanceOf(ExecutableFunctionCallInterface::class, $tool);
    $tool->execute();
    $this->assertSame(self::EMPTY_CANVAS_PAGE_LAYOUT_FIXTURE, $tool->getReadableOutput());

    // onResponse clears the stored layout once the test request is finished.
    $subscriber->onResponse($this->createResponseEventObject($route));
    $this->assertNull($this->tempStore->getData(CanvasAiTempStore::CURRENT_LAYOUT_KEY));
  }

  /**
   * The agents whose tests must not load a layout fixture.
   *
   * @return array<string, array{string, string}>
   *   Each case is [agent id, route].
   */
  public static function providerNonLayoutDependentAgents(): array {
    return [
      'orchestrator, run route' => ['canvas_ai_orchestrator', 'ai_agents_test.run_test'],
      'component agent, ajax route' => ['canvas_component_agent', 'ai_agents_test.group_ajax'],
    ];
  }

  /**
   * Tests that no layout is loaded for agents that do not depend on one.
   *
   * @see \Drupal\canvas_ai\Plugin\AiFunctionCall\GetCurrentLayout::execute()
   */
  #[DataProvider('providerNonLayoutDependentAgents')]
  public function testLayoutFixtureNotLoadedForOtherAgents(string $agentId, string $route): void {
    $testId = $this->createAiAgentTestEntity($agentId, 'canvas_layout_fixture: any-name');

    $tool = $this->functionCallManager->createInstance('canvas_ai:get_current_layout');
    $this->assertInstanceOf(ExecutableFunctionCallInterface::class, $tool);

    // Nothing is stored before the subscriber runs.
    $tool->execute();
    $before = $tool->getReadableOutput();

    $this->getLayoutFixtureSubscriberWithReadFixtureFileMethodReturning(self::EMPTY_CANVAS_PAGE_LAYOUT_FIXTURE)
      ->onRequest($this->createRequestEventObject($route, $testId));

    // The agent is not layout-dependent, so the subscriber stored nothing and
    // the tool still reports the same result.
    $tool->execute();
    $this->assertSame($before, $tool->getReadableOutput());
  }

  /**
   * Cases where the subscriber must store nothing — by ignoring or by throwing.
   *
   * @return array<string, array{string, string, string, ?string, ?class-string<\Throwable>}>
   *   Each case is [agent id, route, tokens YAML, stubbed fixture read result,
   *   expected exception class or NULL].
   */
  public static function providerInvalidFixturesAndNonTargetRoutes(): array {
    return [
      'non-test route is ignored' => [
        'canvas_page_builder_agent',
        'some.other.route',
        'canvas_layout_fixture: any-name',
        self::EMPTY_CANVAS_PAGE_LAYOUT_FIXTURE,
        NULL,
      ],
      'missing token throws' => [
        'canvas_page_builder_agent',
        'ai_agents_test.run_test',
        '',
        NULL,
        \InvalidArgumentException::class,
      ],
      'absent fixture file throws' => [
        'canvas_page_builder_agent',
        'ai_agents_test.run_test',
        'canvas_layout_fixture: does-not-exist',
        NULL,
        \InvalidArgumentException::class,
      ],
      'invalid json fixture throws' => [
        'canvas_page_builder_agent',
        'ai_agents_test.run_test',
        'canvas_layout_fixture: invalid',
        '{ "invalid": }',
        \InvalidArgumentException::class,
      ],
    ];
  }

  /**
   * Tests that the subscriber stores no layout when it should not.
   *
   * @param ?class-string<\Throwable> $expectedException
   *   The expected exception class, or NULL when none is expected.
   */
  #[DataProvider('providerInvalidFixturesAndNonTargetRoutes')]
  public function testSubscriberBehaviorWithInvalidFixturesAndNonTargetRoutes(string $agentId, string $route, string $tokens, ?string $fixtureRead, ?string $expectedException): void {
    $testId = $this->createAiAgentTestEntity($agentId, $tokens);
    $subscriber = $this->getLayoutFixtureSubscriberWithReadFixtureFileMethodReturning($fixtureRead);

    $this->assertNull($this->tempStore->getData(CanvasAiTempStore::CURRENT_LAYOUT_KEY));

    if ($expectedException !== NULL) {
      $this->expectException($expectedException);
    }

    $subscriber->onRequest($this->createRequestEventObject($route, $testId));
    $this->assertNull($this->tempStore->getData(CanvasAiTempStore::CURRENT_LAYOUT_KEY));
  }

  /**
   * Tests that the response handler leaves other routes untouched.
   */
  public function testOnResponseIgnoresOtherRoutes(): void {
    $this->tempStore->setData(CanvasAiTempStore::CURRENT_LAYOUT_KEY, self::EMPTY_CANVAS_PAGE_LAYOUT_FIXTURE);
    $this->getLayoutFixtureSubscriberWithReadFixtureFileMethodReturning(NULL)->onResponse($this->createResponseEventObject('some.other.route'));
    $this->assertSame(self::EMPTY_CANVAS_PAGE_LAYOUT_FIXTURE, $this->tempStore->getData(CanvasAiTempStore::CURRENT_LAYOUT_KEY));
  }

  /**
   * Creates a mock of the layout fixture event subscriber.
   *
   * Returns a mock object of the LayoutFixtureSubscriber class. Its
   * readFixtureFile() method — the one that loads the layout fixture — is
   * configured to return the given value, so tests can exercise how the
   * subscriber behaves for different layout fixtures.
   *
   * @param ?string $layout_fixture
   *   The value readFixtureFile() returns: the fixture JSON, or NULL to
   *   simulate a fixture file that does not exist.
   */
  private function getLayoutFixtureSubscriberWithReadFixtureFileMethodReturning(?string $layout_fixture): LayoutFixtureSubscriber {
    $subscriber = $this->getMockBuilder(LayoutFixtureSubscriber::class)
      ->setConstructorArgs([
        $this->tempStore,
        $this->container->get('entity_type.manager'),
        $this->container->get('module_handler'),
      ])
      ->onlyMethods(['readFixtureFile'])
      ->getMock();
    $subscriber->method('readFixtureFile')->willReturn($layout_fixture);
    return $subscriber;
  }

  /**
   * Creates a request event object to pass to the subscriber's onRequest().
   *
   * @param string $route
   *   The route machine name to set as the request's _route attribute.
   * @param int $testId
   *   The AI agent test entity id to set as the request's test_id attribute.
   */
  private function createRequestEventObject(string $route, int $testId): RequestEvent {
    // The path here doesn't matter; the subscriber checks only the test_id and
    // _route attributes set below, never the URL.
    $request = Request::create('/test');
    $request->attributes->set('_route', $route);
    $request->attributes->set('test_id', $testId);
    return new RequestEvent(
      $this->container->get('http_kernel'),
      $request,
      HttpKernelInterface::MAIN_REQUEST,
    );
  }

  /**
   * Creates a response event object to pass to the subscriber's onResponse().
   *
   * @param string $route
   *   The route machine name to set as the request's _route attribute.
   */
  private function createResponseEventObject(string $route): ResponseEvent {
    // The path here doesn't matter; the subscriber checks only the _route
    // attribute set below, never the URL.
    $request = Request::create('/test');
    $request->attributes->set('_route', $route);
    return new ResponseEvent(
      $this->container->get('http_kernel'),
      $request,
      HttpKernelInterface::MAIN_REQUEST,
      new Response(),
    );
  }

  /**
   * Creates a real AI agent test entity for the event subscriber to load.
   *
   * The event subscriber loads a real ai_agents_test entity by id to decide
   * what to do. This creates and saves one, and returns its id.
   *
   * @param string $agentId
   *   The agent the test is targeting.
   * @param string $token_replacements
   *   The test's token replacements, as YAML. The layout fixture is provided as
   *   a token here, under the canvas_layout_fixture key.
   *
   * @return int
   *   The id of the saved AI agent test entity.
   */
  private function createAiAgentTestEntity(string $agentId, string $token_replacements): int {
    $test = $this->container->get('entity_type.manager')
      ->getStorage('ai_agents_test')
      ->create([
        'label' => 'Test',
        'messages' => 'Build a page.',
        'triggering_instructions' => 'Build a page.',
        'ai_agent' => $agentId,
        'rules' => 'None.',
        'tokens' => $token_replacements,
      ]);
    $test->save();
    return (int) $test->id();
  }

}
