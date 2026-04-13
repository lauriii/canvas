import { describe, expect, it } from 'vitest';

import { parseAgentSelection } from './agent-selection.js';

describe('parseAgentSelection', () => {
  it('parses a comma-separated list of additional agents', () => {
    expect(parseAgentSelection(' claude-code, roo,claude-code ')).toEqual([
      'claude-code',
      'roo',
    ]);
  });

  it('parses none as an explicit empty selection', () => {
    expect(parseAgentSelection('none')).toEqual([]);
  });

  it('returns undefined when the flag is omitted', () => {
    expect(parseAgentSelection(undefined)).toBeUndefined();
  });

  it('rejects unknown agents', () => {
    expect(() => parseAgentSelection('made-up-agent')).toThrow(
      'Invalid --agents value. Unknown agent "made-up-agent".',
    );
  });

  it('uses custom label in error messages', () => {
    expect(() => parseAgentSelection('made-up-agent', 'agents')).toThrow(
      'Invalid agents value. Unknown agent "made-up-agent".',
    );
  });

  it('rejects universal agents', () => {
    expect(() => parseAgentSelection('codex')).toThrow(
      'Invalid --agents value. Agent "codex" already uses .agents/skills.',
    );
  });

  it('rejects mixing none with other agents', () => {
    expect(() => parseAgentSelection('none,roo')).toThrow(
      'Invalid --agents value. "none" cannot be combined with other agents.',
    );
  });
});
