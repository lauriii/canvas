import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';

import * as context from './context';
import * as root from './index';
import * as clientContext from './jsonapi-client-context';
import * as react from './react';

import type {
  CanvasContextProviderProps,
  JsonApiClientProviderProps,
} from './react';

const names = [
  'CanvasContextProvider',
  'JsonApiClientProvider',
  'useHasCanvasContext',
  'useHasJsonApiClient',
  'useJsonApiClient',
  'usePageContext',
  'useSiteContext',
];

describe('React entry point', () => {
  it('exports only the new React values, sharing their existing implementations', () => {
    expect(Object.keys(react).sort()).toEqual(names);
    for (const [name, value] of Object.entries(react)) {
      expect(value).toBe(
        { ...context, ...clientContext }[name as keyof typeof react],
      );
      expect(root).not.toHaveProperty(name);
    }
  });

  it('leaves existing components on the root', () => {
    for (const name of [
      'FormattedText',
      'Image',
      'Region',
      'RegionsProvider',
    ]) {
      expect(root).toHaveProperty(name);
      expect(react).not.toHaveProperty(name);
    }
  });

  it('shares provider state across the entry point and internal hooks', () => {
    const props: CanvasContextProviderProps = {
      context: {
        page: { pageTitle: 'React entry', breadcrumbs: [], mainEntity: null },
        site: null,
      },
    };
    const clientProps: JsonApiClientProviderProps = {
      client: root.createJsonApiClient({ baseUrl: 'https://example.test' }),
    };
    function Probe() {
      expect(react.usePageContext()).toBe(context.usePageContext());
      expect(react.useJsonApiClient()).toBe(clientProps.client);
      expect(clientContext.useJsonApiClient()).toBe(clientProps.client);
      expect(react.useHasCanvasContext()).toBe(true);
      expect(react.useHasJsonApiClient()).toBe(true);
      expect(react.useSiteContext()).toBeNull();
      return <h1>{react.usePageContext()?.pageTitle}</h1>;
    }
    expect(
      renderToStaticMarkup(
        <react.CanvasContextProvider {...props}>
          <react.JsonApiClientProvider {...clientProps}>
            <Probe />
          </react.JsonApiClientProvider>
        </react.CanvasContextProvider>,
      ),
    ).toBe('<h1>React entry</h1>');
  });
});
