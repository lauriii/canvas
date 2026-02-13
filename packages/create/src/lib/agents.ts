/**
 * @file
 * Ported from https://github.com/vercel-labs/skills/blob/v1.3.7/src/agents.ts
 */
export type AgentType =
  | 'amp'
  | 'antigravity'
  | 'augment'
  | 'claude-code'
  | 'openclaw'
  | 'cline'
  | 'codebuddy'
  | 'codex'
  | 'command-code'
  | 'continue'
  | 'crush'
  | 'cursor'
  | 'droid'
  | 'gemini-cli'
  | 'github-copilot'
  | 'goose'
  | 'iflow-cli'
  | 'junie'
  | 'kilo'
  | 'kimi-cli'
  | 'kiro-cli'
  | 'kode'
  | 'mcpjam'
  | 'mistral-vibe'
  | 'mux'
  | 'neovate'
  | 'opencode'
  | 'openhands'
  | 'pi'
  | 'qoder'
  | 'qwen-code'
  | 'replit'
  | 'roo'
  | 'trae'
  | 'trae-cn'
  | 'windsurf'
  | 'zencoder'
  | 'pochi'
  | 'adal';

export type AgentConfig = {
  displayName: string;
  skillsDir: string;
  showInUniversalList?: boolean;
};

export const DEFAULT_AGENT_SELECTION: AgentType[] = [
  'claude-code',
  'opencode',
  'codex',
];

export const agents: Record<AgentType, AgentConfig> = {
  amp: {
    displayName: 'Amp',
    skillsDir: '.agents/skills',
  },
  antigravity: {
    displayName: 'Antigravity',
    skillsDir: '.agent/skills',
  },
  augment: {
    displayName: 'Augment',
    skillsDir: '.augment/skills',
  },
  'claude-code': {
    displayName: 'Claude Code',
    skillsDir: '.claude/skills',
  },
  openclaw: {
    displayName: 'OpenClaw',
    skillsDir: 'skills',
  },
  cline: {
    displayName: 'Cline',
    skillsDir: '.cline/skills',
  },
  codebuddy: {
    displayName: 'CodeBuddy',
    skillsDir: '.codebuddy/skills',
  },
  codex: {
    displayName: 'Codex',
    skillsDir: '.agents/skills',
  },
  'command-code': {
    displayName: 'Command Code',
    skillsDir: '.commandcode/skills',
  },
  continue: {
    displayName: 'Continue',
    skillsDir: '.continue/skills',
  },
  crush: {
    displayName: 'Crush',
    skillsDir: '.crush/skills',
  },
  cursor: {
    displayName: 'Cursor',
    skillsDir: '.cursor/skills',
  },
  droid: {
    displayName: 'Droid',
    skillsDir: '.factory/skills',
  },
  'gemini-cli': {
    displayName: 'Gemini CLI',
    skillsDir: '.agents/skills',
  },
  'github-copilot': {
    displayName: 'GitHub Copilot',
    skillsDir: '.agents/skills',
  },
  goose: {
    displayName: 'Goose',
    skillsDir: '.goose/skills',
  },
  junie: {
    displayName: 'Junie',
    skillsDir: '.junie/skills',
  },
  'iflow-cli': {
    displayName: 'iFlow CLI',
    skillsDir: '.iflow/skills',
  },
  kilo: {
    displayName: 'Kilo Code',
    skillsDir: '.kilocode/skills',
  },
  'kimi-cli': {
    displayName: 'Kimi Code CLI',
    skillsDir: '.agents/skills',
  },
  'kiro-cli': {
    displayName: 'Kiro CLI',
    skillsDir: '.kiro/skills',
  },
  kode: {
    displayName: 'Kode',
    skillsDir: '.kode/skills',
  },
  mcpjam: {
    displayName: 'MCPJam',
    skillsDir: '.mcpjam/skills',
  },
  'mistral-vibe': {
    displayName: 'Mistral Vibe',
    skillsDir: '.vibe/skills',
  },
  mux: {
    displayName: 'Mux',
    skillsDir: '.mux/skills',
  },
  opencode: {
    displayName: 'OpenCode',
    skillsDir: '.agents/skills',
  },
  openhands: {
    displayName: 'OpenHands',
    skillsDir: '.openhands/skills',
  },
  pi: {
    displayName: 'Pi',
    skillsDir: '.pi/skills',
  },
  qoder: {
    displayName: 'Qoder',
    skillsDir: '.qoder/skills',
  },
  'qwen-code': {
    displayName: 'Qwen Code',
    skillsDir: '.qwen/skills',
  },
  replit: {
    displayName: 'Replit',
    skillsDir: '.agents/skills',
    showInUniversalList: false,
  },
  roo: {
    displayName: 'Roo Code',
    skillsDir: '.roo/skills',
  },
  trae: {
    displayName: 'Trae',
    skillsDir: '.trae/skills',
  },
  'trae-cn': {
    displayName: 'Trae CN',
    skillsDir: '.trae/skills',
  },
  windsurf: {
    displayName: 'Windsurf',
    skillsDir: '.windsurf/skills',
  },
  zencoder: {
    displayName: 'Zencoder',
    skillsDir: '.zencoder/skills',
  },
  neovate: {
    displayName: 'Neovate',
    skillsDir: '.neovate/skills',
  },
  pochi: {
    displayName: 'Pochi',
    skillsDir: '.pochi/skills',
  },
  adal: {
    displayName: 'AdaL',
    skillsDir: '.adal/skills',
  },
};

export const agentChoices: Array<{
  value: AgentType;
  label: string;
  hint: string;
}> = (Object.entries(agents) as [AgentType, AgentConfig][]).map(
  ([value, config]) => ({
    value,
    label: config.displayName,
    hint: config.skillsDir,
  }),
);

export function getUniversalAgents(): AgentType[] {
  return (Object.entries(agents) as [AgentType, AgentConfig][])
    .filter(
      ([_, config]) =>
        config.skillsDir === '.agents/skills' &&
        config.showInUniversalList !== false,
    )
    .map(([type]) => type);
}

export function getNonUniversalAgents(): AgentType[] {
  return (Object.entries(agents) as [AgentType, AgentConfig][])
    .filter(([_, config]) => config.skillsDir !== '.agents/skills')
    .map(([type]) => type);
}

export function isUniversalAgent(type: AgentType): boolean {
  return agents[type].skillsDir === '.agents/skills';
}
