import type { AgentType } from '../lib/agents.js';
import type { Template } from './template.js';

export type Context = {
  template: Template;
  appName: string;
  selectedAgents?: AgentType[];
};
