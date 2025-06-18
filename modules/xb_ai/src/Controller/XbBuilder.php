<?php

namespace Drupal\xb_ai\Controller;

use Drupal\ai\AiProviderPluginManager;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\ai_agents\PluginInterfaces\AiAgentInterface;
use Drupal\ai_agents\Task\Task;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\xb_ai\Plugin\AiFunctionCall\CreateComponent;
use Drupal\xb_ai\Plugin\AiFunctionCall\EditComponentJs;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Yaml\Yaml;

/**
 * Renders the Experience Builder AI calls.
 */
final class XbBuilder extends ControllerBase {

  /**
   * The AI provider service.
   */
  protected AiProviderPluginManager $providerService;

  /**
   * The AI agent plugin manager.
   */
  protected PluginManagerInterface $agentManager;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The CSRF token generator.
   *
   * @var \Drupal\Core\Access\CsrfTokenGenerator
   */
  protected $csrfTokenGenerator;

  /**
   * Constructs a new XbBuilder object.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, AccountInterface $currentUser, AiProviderPluginManager $providerService, PluginManagerInterface $agentManager, CsrfTokenGenerator $csrfTokenGenerator) {
    $this->entityTypeManager = $entityTypeManager;
    $this->currentUser = $currentUser;
    $this->providerService = $providerService;
    $this->agentManager = $agentManager;
    $this->csrfTokenGenerator = $csrfTokenGenerator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('ai.provider'),
      $container->get('plugin.manager.ai_agents'),
      $container->get('csrf_token'),
    );
  }

  /**
   * Renders the Experience Builder AI calls.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   */
  public function render(Request $request): JsonResponse {
    $token = $request->headers->get('X-CSRF-Token') ?? '';
    if (!$this->csrfTokenGenerator->validate($token, 'xb_ai.xb_builder')) {
      throw new AccessDeniedHttpException('Invalid CSRF token');
    }

    /** @var \Drupal\ai_agents\PluginBase\AiAgentEntityWrapper $agent */
    $agent = $this->agentManager->createInstance('xb_ai_orchestrator');
    $prompt = json_decode($request->getContent(), TRUE);
    if (empty($prompt['messages'])) {
      return new JsonResponse([
        'status' => FALSE,
        'message' => 'No prompt provided',
      ]);
    }
    // Add dynamic comments.
    $comments = [];
    $comments[] = [
      'role' => 'user',
      'message' => 'component_name is ' . (!empty($prompt['selected_component']) ? $prompt['selected_component'] : 'not required.'),
    ];
    $task_message = array_pop($prompt['messages']);
    $task = $prompt['messages'];
    foreach ($task as $message) {
      $comments[] = [
        'role' => $message['role'],
        'message' => $message['text'],
      ];
    }
    $task = new Task($task_message['text']);
    $agent->setTask($task);
    $task->setComments($comments);
    $default = $this->providerService->getDefaultProviderForOperationType('chat');
    if (!is_array($default) || empty($default['provider_id']) || empty($default['model_id'])) {
      return new JsonResponse([
        'status' => FALSE,
        'message' => 'No default provider found.',
      ]);
    }
    $agent->setAiProvider($this->providerService->createInstance($default['provider_id']));
    $agent->setModelName($default['model_id']);
    $agent->setAiConfiguration([]);
    $agent->setCreateDirectly(TRUE);
    $solvability = $agent->determineSolvability();
    $status = FALSE;
    $message = '';
    if ($solvability == AiAgentInterface::JOB_NOT_SOLVABLE) {
      $message = 'Something went wrong';
    }
    elseif ($solvability == AiAgentInterface::JOB_SHOULD_ANSWER_QUESTION) {
      $message = $agent->answerQuestion();
    }
    elseif ($solvability == AiAgentInterface::JOB_INFORMS) {
      $message = $agent->inform();
      $status = TRUE;
    }
    elseif ($solvability == AiAgentInterface::JOB_SOLVABLE) {
      $response['status'] = TRUE;
      $tools = $agent->getToolResults();
      $map = [
        EditComponentJs::class => ['js_structure', 'props_metadata'],
        CreateComponent::class => ['component_structure'],
      ];
      if (!empty($tools)) {
        foreach ($tools as $tool) {
          // @todo Refactor this after https://www.drupal.org/i/3529310 is fixed.
          if (
            $tool->getPluginId() === 'ai_agents::ai_agent::experience_builder_component_agent'
          ) {
            $response['message'] = $tool->getReadableOutput();
            foreach ($tool->getAgent()->getToolResults() as $sub_agent_tool) {
              foreach ($map as $class => $keys) {
                if ($sub_agent_tool instanceof $class) {
                  // @todo Refactor this after https://www.drupal.org/i/3529313 is fixed.
                  $output = $sub_agent_tool->getReadableOutput();
                  $data = Yaml::parse($output);
                  foreach ($keys as $key) {
                    if (!empty($data[$key])) {
                      $response[$key] = $data[$key];
                    }
                  }
                }
              }
            }
          }
        }
      }
      else {
        $response['message'] = $agent->solve();
      }
      return new JsonResponse(
        $response,
      );
    }
    return new JsonResponse([
      'status' => $status,
      'message' => $message,
    ]);
  }

  /**
   * Function to get the x-csrf-token.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response object.
   */
  public function getCsrfToken(Request $request): Response {
    return new Response($this->csrfTokenGenerator->get('xb_ai.xb_builder'));
  }

}
