import {
  getNonUniversalAgents,
  isAgentType,
  isUniversalAgent,
} from './agents.js';

import type { AgentType } from './agents.js';

const NONE_VALUE = 'none';

export function parseAgentSelection(
  value: string | undefined,
  label = '--agents',
): AgentType[] | undefined {
  if (value === undefined) {
    return undefined;
  }

  const selectedAgents = Array.from(
    new Set(
      value
        .split(',')
        .map((agent) => agent.trim())
        .filter(Boolean),
    ),
  );

  if (selectedAgents.length === 0) {
    throw new Error(
      `Invalid ${label} value. Provide a comma-separated list or "none".`,
    );
  }

  if (selectedAgents.includes(NONE_VALUE)) {
    if (selectedAgents.length > 1) {
      throw new Error(
        `Invalid ${label} value. "none" cannot be combined with other agents.`,
      );
    }

    return [];
  }

  const nonUniversalAgents = new Set(getNonUniversalAgents());

  return selectedAgents.map((agent) => {
    if (!isAgentType(agent)) {
      throw new Error(`Invalid ${label} value. Unknown agent "${agent}".`);
    }

    if (isUniversalAgent(agent) || !nonUniversalAgents.has(agent)) {
      throw new Error(
        `Invalid ${label} value. Agent "${agent}" already uses .agents/skills.`,
      );
    }

    return agent;
  });
}
