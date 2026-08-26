import { describe, expect, it } from 'vitest';

import { selectPageTemplate } from './page-template-selection';

import type { DiscoveredPageTemplate } from './discovery-client';

const templates: DiscoveredPageTemplate[] = [
  {
    id: 'default',
    label: 'Default',
    status: true,
    isDefault: true,
    path: '/page-templates/default.json',
    relativePath: 'page-templates/default.json',
  },
  {
    id: 'marketing',
    label: 'Marketing',
    status: true,
    isDefault: false,
    path: '/page-templates/marketing.json',
    relativePath: 'page-templates/marketing.json',
  },
  {
    id: 'disabled',
    label: 'Disabled',
    status: false,
    isDefault: false,
    path: '/page-templates/disabled.json',
    relativePath: 'page-templates/disabled.json',
  },
];

describe('selectPageTemplate', () => {
  it('uses an explicit enabled page template', () => {
    expect(selectPageTemplate(templates, 'marketing')?.id).toBe('marketing');
  });

  it('uses the default when no page template is selected', () => {
    expect(selectPageTemplate(templates, null)?.id).toBe('default');
  });

  it('does not use a missing or disabled page template', () => {
    expect(selectPageTemplate(templates, 'missing')).toBeNull();
    expect(selectPageTemplate(templates, 'disabled')).toBeNull();
  });
});
