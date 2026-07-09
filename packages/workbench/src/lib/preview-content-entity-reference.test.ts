import { afterEach, describe, expect, it, vi } from 'vitest';

import {
  fetchResolvedContentEntityReferenceFields,
  fetchResolvedContentEntityReferenceFieldsForSpec,
  getContentEntityReferencePropPreviews,
  getContentEntityReferenceSpecResolutionJobs,
  groupEntityFieldsByProp,
} from './preview-content-entity-reference';

describe('preview-content-entity-reference', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('posts entity field expressions to the preview endpoint', async () => {
    const fetchMock = vi
      .spyOn(globalThis, 'fetch')
      .mockResolvedValueOnce(new Response('csrf-token'))
      .mockResolvedValueOnce(
        Response.json({
          data: {
            article: {
              __type: 'article',
              title: 'Article title',
            },
          },
        }),
      );

    const result = await fetchResolvedContentEntityReferenceFields(
      'node',
      '2',
      {
        article: ['entity:node:article.title.value'],
      },
    );

    expect(result).toEqual({
      article: {
        __type: 'article',
        title: 'Article title',
      },
    });
    expect(fetchMock).toHaveBeenNthCalledWith(1, '/session/token', {
      credentials: 'include',
    });
    expect(fetchMock).toHaveBeenNthCalledWith(
      2,
      '/canvas/api/v0/ui/content-entity-reference/preview/node/2',
      {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-Token': 'csrf-token',
        },
        body: JSON.stringify({
          entityFields: {
            article: ['entity:node:article.title.value'],
          },
        }),
      },
    );
  });

  it('uses the server error message when the preview request fails', async () => {
    vi.spyOn(globalThis, 'fetch')
      .mockResolvedValueOnce(new Response('csrf-token'))
      .mockResolvedValueOnce(
        Response.json(
          { message: 'Unable to resolve preview.' },
          { status: 400 },
        ),
      );

    await expect(
      fetchResolvedContentEntityReferenceFields('node', '2', {
        article: ['entity:node:article.title.value'],
      }),
    ).rejects.toThrow('Unable to resolve preview.');
  });

  it('returns CER prop previews in component prop definition order', () => {
    const previews = getContentEntityReferencePropPreviews(
      {
        articleTwo: ['ℹ︎␜entity:node:article␝title␞␟value'],
        articleOne: ['ℹ︎␜entity:node:article␝title␞␟value'],
        articleThree: ['ℹ︎␜entity:node:article␝title␞␟value'],
      },
      {
        articleOne: {
          'x-allowed-entity-type-id': 'node',
          'x-allowed-bundle': 'article',
        },
        articleTwo: {
          'x-allowed-entity-type-id': 'node',
          'x-allowed-bundle': 'article',
        },
        articleThree: {
          'x-allowed-entity-type-id': 'node',
          'x-allowed-bundle': 'article',
        },
      },
    );

    expect(previews.map((preview) => preview.propName)).toEqual([
      'articleOne',
      'articleTwo',
      'articleThree',
    ]);
  });

  it('skips props without projected entity targets', () => {
    expect(
      getContentEntityReferencePropPreviews(
        {
          article: ['ℹ︎␜entity:node:article␝title␞␟value'],
        },
        {
          article: {
            'x-allowed-entity-type-id': 'node',
          },
        },
      ),
    ).toEqual([]);
  });

  it('groups selected CER entity fields by prop', () => {
    expect(
      groupEntityFieldsByProp(
        {
          articleOne: ['ℹ︎␜entity:node:article␝title␞␟value'],
          articleTwo: ['ℹ︎␜entity:node:article␝title␞␟value'],
          articleThree: ['ℹ︎␜entity:node:article␝title␞␟value'],
        },
        {
          articleOne: {
            'x-allowed-entity-type-id': 'node',
            'x-allowed-bundle': 'article',
          },
          articleTwo: {
            'x-allowed-entity-type-id': 'node',
            'x-allowed-bundle': 'article',
          },
          articleThree: {
            'x-allowed-entity-type-id': 'node',
            'x-allowed-bundle': 'article',
          },
        },
        {
          articleOne: '1',
          articleTwo: null,
          articleThree: '3',
        },
      ),
    ).toEqual([
      {
        propName: 'articleOne',
        target: {
          key: 'node:article',
          entityTypeId: 'node',
          bundle: 'article',
        },
        entityId: '1',
        entityFields: {
          articleOne: ['ℹ︎␜entity:node:article␝title␞␟value'],
        },
      },
      {
        propName: 'articleThree',
        target: {
          key: 'node:article',
          entityTypeId: 'node',
          bundle: 'article',
        },
        entityId: '3',
        entityFields: {
          articleThree: ['ℹ︎␜entity:node:article␝title␞␟value'],
        },
      },
    ]);
  });

  it('finds authored CER target IDs in specs', () => {
    expect(
      getContentEntityReferenceSpecResolutionJobs(
        {
          root: 'root',
          elements: {
            root: {
              type: 'canvas:component-tree',
              props: {},
              children: ['card'],
            },
            card: {
              type: 'js.article-card',
              props: {
                article: { target_id: 42 },
              },
            },
          },
        },
        [
          {
            id: 'article-card',
            name: 'js.article-card',
            label: 'Article card',
            relativeDirectory: 'article-card',
            projectRelativeDirectory: 'components/article-card',
            metadataPath: '/components/article-card/component.yml',
            js: { entryPath: '/components/article-card/index.tsx', url: '/x' },
            css: { entryPath: null, url: null },
            previewable: true,
            ineligibilityReason: null,
            exampleProps: {},
            props: {
              article: {
                'x-allowed-entity-type-id': 'node',
                'x-allowed-bundle': 'article',
              },
            },
            dataDependencies: {
              entityFields: {
                article: ['ℹ︎␜entity:node:article␝title␞␟value'],
              },
            },
            mocks: [],
          },
        ],
      ),
    ).toEqual([
      {
        uuid: 'card',
        propName: 'article',
        target: {
          key: 'node:article',
          entityTypeId: 'node',
          bundle: 'article',
        },
        entityId: '42',
        entityFields: {
          article: ['ℹ︎␜entity:node:article␝title␞␟value'],
        },
      },
    ]);
  });

  it('resolves authored CER target IDs into a preview model', async () => {
    vi.spyOn(globalThis, 'fetch')
      .mockResolvedValueOnce(new Response('csrf-token'))
      .mockResolvedValueOnce(
        Response.json({
          data: {
            article: {
              __type: 'article',
              title: 'Article title',
            },
          },
        }),
      );

    await expect(
      fetchResolvedContentEntityReferenceFieldsForSpec(
        {
          root: 'root',
          elements: {
            card: {
              type: 'js.article-card',
              props: {
                article: { target_id: 42 },
              },
            },
          },
        },
        [
          {
            id: 'article-card',
            name: 'js.article-card',
            label: 'Article card',
            relativeDirectory: 'article-card',
            projectRelativeDirectory: 'components/article-card',
            metadataPath: '/components/article-card/component.yml',
            js: { entryPath: '/components/article-card/index.tsx', url: '/x' },
            css: { entryPath: null, url: null },
            previewable: true,
            ineligibilityReason: null,
            exampleProps: {},
            props: {
              article: {
                'x-allowed-entity-type-id': 'node',
                'x-allowed-bundle': 'article',
              },
            },
            dataDependencies: {
              entityFields: {
                article: ['ℹ︎␜entity:node:article␝title␞␟value'],
              },
            },
            mocks: [],
          },
        ],
      ),
    ).resolves.toEqual({
      card: {
        resolved: {
          article: {
            __type: 'article',
            title: 'Article title',
          },
        },
      },
    });
  });
});
