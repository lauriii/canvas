import { describe, expect, it } from 'vitest';

import {
  assertUniqueTemplateIdentifiers,
  resolveTemplate,
} from './template-resolution.js';

import type { Template } from '../types/template.js';

const templates: Template[] = [
  {
    id: 'default',
    aliases: ['acquia-nebula'],
    label: 'Default frontend',
    repository: { url: 'https://example.com/default.git', ref: 'main' },
  },
  {
    id: 'nextjs',
    label: 'Next.js',
    repository: { url: 'https://example.com/nextjs.git', ref: 'main' },
  },
];

describe('resolveTemplate', () => {
  it('resolves a canonical template ID', () => {
    expect(resolveTemplate(templates, 'default')?.id).toBe('default');
  });

  it('resolves a template alias', () => {
    expect(resolveTemplate(templates, 'acquia-nebula')?.id).toBe('default');
  });

  it('returns undefined for an unknown identifier', () => {
    expect(resolveTemplate(templates, 'unknown')).toBeUndefined();
  });
});

describe('assertUniqueTemplateIdentifiers', () => {
  it('accepts unique IDs and aliases', () => {
    expect(() => assertUniqueTemplateIdentifiers(templates)).not.toThrow();
  });

  it('rejects an alias that conflicts with a canonical ID', () => {
    const conflictingTemplates: Template[] = [
      ...templates,
      {
        id: 'legacy',
        aliases: ['nextjs'],
        label: 'Legacy',
        repository: { url: 'https://example.com/legacy.git', ref: 'main' },
      },
    ];

    expect(() => assertUniqueTemplateIdentifiers(conflictingTemplates)).toThrow(
      'Template identifier "nextjs" is used by both',
    );
  });

  it('rejects an alias used by multiple templates', () => {
    const conflictingTemplates: Template[] = [
      ...templates,
      {
        id: 'legacy',
        aliases: ['acquia-nebula'],
        label: 'Legacy',
        repository: { url: 'https://example.com/legacy.git', ref: 'main' },
      },
    ];

    expect(() => assertUniqueTemplateIdentifiers(conflictingTemplates)).toThrow(
      'Template identifier "acquia-nebula" is used by both',
    );
  });
});
