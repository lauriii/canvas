import { useMemo, useState } from 'react';
import { Provider } from 'react-redux';
import { configureStore } from '@reduxjs/toolkit';
import { fn } from '@storybook/test';

import { uiSliceReducer } from '@/features/ui/uiSlice';

import {
  ConflictResolutionView,
  PageConflictComparisonView,
} from './ConflictResolutionView';

import type React from 'react';
import type { Meta, StoryObj } from '@storybook/react';
import type { ConflictVersionSelection } from './ConflictResolutionView';

const publishedPageHtml = `
  <!doctype html>
  <html>
    <head>
      <style>
        body { margin: 0; font-family: Inter, system-ui, sans-serif; color: #24262f; }
        main { padding: 48px 64px; }
        .hero { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 0.8fr); gap: 32px; align-items: center; }
        .image { height: 280px; border-radius: 8px; background: linear-gradient(135deg, #d5e8ff, #7ba5b8 46%, #334155); }
        h1 { margin: 0 0 16px; font-size: 40px; line-height: 1.05; }
        p { margin: 0; color: #6b7280; font-size: 18px; line-height: 1.6; }
      </style>
    </head>
    <body>
      <main>
        <section class="region-content hero">
          <div class="image"></div>
          <div>
            <h1>Welcome to our site</h1>
            <p>This is the currently published page content.</p>
          </div>
        </section>
      </main>
    </body>
  </html>
`;

const newPageHtml = `
  <!doctype html>
  <html>
    <head>
      <style>
        body { margin: 0; font-family: Inter, system-ui, sans-serif; color: #24262f; }
        main { padding: 48px 64px; }
        .hero { display: grid; grid-template-columns: minmax(0, 0.8fr) minmax(0, 1fr); gap: 32px; align-items: center; }
        .image { height: 280px; border-radius: 8px; background: linear-gradient(135deg, #ffe8b5, #d48b40 48%, #1f2937); }
        h1 { margin: 0 0 16px; font-size: 40px; line-height: 1.05; }
        p { margin: 0; color: #6b7280; font-size: 18px; line-height: 1.6; }
      </style>
    </head>
    <body>
      <main>
        <section class="region-content hero">
          <div class="image"></div>
          <div>
            <h1>Welcome to our new site</h1>
            <p>This is the conflicting auto-saved version.</p>
          </div>
        </section>
      </main>
    </body>
  </html>
`;

const publishedVersion = {
  html: publishedPageHtml,
  updated: 'Updated 8/19/26 at 12:01 PM',
  props: {
    entity_form_fields: {
      title: 'Homepage',
      status: true,
    },
    layout: [{ uuid: 'published-hero', type: 'example:hero' }],
    model: {
      'published-hero': {
        title: 'Welcome to our site',
      },
    },
  },
};

const newVersion = {
  html: newPageHtml,
  updated: 'Updated 8/20/26 at 8:10 PM',
  props: {
    entity_form_fields: {
      title: 'Homepage',
      status: true,
    },
    layout: [{ uuid: 'new-hero', type: 'example:hero' }],
    model: {
      'new-hero': {
        title: 'Welcome to our new site',
      },
    },
  },
  changed: true,
};

const createStoryStore = () =>
  configureStore({
    reducer: {
      ui: uiSliceReducer,
    },
  });

const StoryFrame = ({ children }: { children: React.ReactNode }) => {
  const store = useMemo(() => createStoryStore(), []);

  return (
    <Provider store={store}>
      <div style={{ width: '100%', height: '760px', background: '#fff' }}>
        {children}
      </div>
    </Provider>
  );
};

const STORY_ENTITY_TYPE = 'canvas_page';
const STORY_ENTITY_ID = '1';

const onPublishedPreviewClick = fn().mockName('onPublishedPreviewClick');
const onNewPreviewClick = fn().mockName('onNewPreviewClick');

const PageComparison = ({
  loading = false,
  selectedVersion,
  onSelectVersion,
}: {
  loading?: boolean;
  selectedVersion?: ConflictVersionSelection;
  onSelectVersion?: (
    version: Exclude<ConflictVersionSelection, undefined>,
  ) => void;
}) => (
  <PageConflictComparisonView
    entityType={STORY_ENTITY_TYPE}
    entityId={STORY_ENTITY_ID}
    publishedVersion={{ ...publishedVersion, loading }}
    newVersion={{ ...newVersion, loading }}
    selectedVersion={selectedVersion}
    onSelectVersion={onSelectVersion}
    onPublishedPreviewClick={onPublishedPreviewClick}
    onNewPreviewClick={onNewPreviewClick}
  />
);

const ConflictResolutionStory = ({
  defaultSelectedVersion,
  loading = false,
  ...args
}: React.ComponentProps<typeof ConflictResolutionView> & {
  defaultSelectedVersion?: ConflictVersionSelection;
  loading?: boolean;
}) => {
  const [selectedVersion, setSelectedVersion] =
    useState<ConflictVersionSelection>(defaultSelectedVersion);

  return (
    <ConflictResolutionView
      {...args}
      canResolveConflict={!!selectedVersion}
      comparison={
        <PageComparison
          loading={loading}
          selectedVersion={selectedVersion}
          onSelectVersion={(version) =>
            setSelectedVersion((currentVersion) =>
              currentVersion === version ? undefined : version,
            )
          }
        />
      }
    />
  );
};

const meta = {
  title: 'Features/Conflict Resolution/Page Comparison',
  component: ConflictResolutionView,
  parameters: {
    layout: 'fullscreen',
    docs: {
      description: {
        component: `
The Page conflict comparison screen is available in development mode for conflicting \`canvas_page\` changes.

It compares the published response from \`GET /canvas/api/v0/layout/canvas_page/{id}?status=true\` with the auto-saved response from \`GET /canvas/api/v0/layout/canvas_page/{id}\`.

No version is selected by default. The user selects either **Published version** or **New version**, then uses **Resolve conflict**.

Resolving with **Published version** discards the conflicting auto-save. Resolving with **New version** sends \`resolved_conflict_id\` and keeps the auto-saved changes.
        `,
      },
    },
  },
  args: {
    label: 'Homepage',
    comparison: <PageComparison />,
    currentIndex: 0,
    reviewIndex: 0,
    reviewTotal: 1,
    unresolvedTotal: 1,
    isResolving: false,
    canResolveConflict: false,
    onPrevious: fn(),
    onNext: fn(),
    onResolveConflict: fn(),
    onClose: fn(),
  },
  argTypes: {
    comparison: {
      control: false,
      description: 'PageConflictComparisonView content for both Page versions.',
    },
    label: {
      control: 'text',
      description: 'Label of the conflicted Page.',
    },
    currentIndex: {
      control: 'number',
      description: 'Zero-based position in the unresolved Page conflict queue.',
    },
    reviewIndex: {
      control: 'number',
      description: 'Zero-based position displayed in the footer.',
    },
    reviewTotal: {
      control: 'number',
      description: 'Number of conflicts in the initial review queue.',
    },
    unresolvedTotal: {
      control: 'number',
      description: 'Number of unresolved Page conflicts remaining.',
    },
    isResolving: {
      control: 'boolean',
      description: 'Whether the Resolve conflict request is in progress.',
    },
    canResolveConflict: {
      control: false,
      description: 'Whether a version is selected and can be resolved.',
    },
  },
} satisfies Meta<typeof ConflictResolutionView>;

export default meta;

type Story = StoryObj<typeof meta>;

export const PageConflict: Story = {
  render: (args) => (
    <StoryFrame>
      <ConflictResolutionStory {...args} />
    </StoryFrame>
  ),
};

export const LoadingComparison: Story = {
  render: (args) => (
    <StoryFrame>
      <ConflictResolutionStory {...args} loading />
    </StoryFrame>
  ),
};

export const PublishedVersionSelected: Story = {
  render: (args) => (
    <StoryFrame>
      <ConflictResolutionStory {...args} defaultSelectedVersion="published" />
    </StoryFrame>
  ),
};

export const NewVersionSelected: Story = {
  render: (args) => (
    <StoryFrame>
      <ConflictResolutionStory {...args} defaultSelectedVersion="new" />
    </StoryFrame>
  ),
};

export const ResolvingConflict: Story = {
  args: {
    isResolving: true,
  },
  render: (args) => (
    <StoryFrame>
      <ConflictResolutionStory {...args} defaultSelectedVersion="new" />
    </StoryFrame>
  ),
};
