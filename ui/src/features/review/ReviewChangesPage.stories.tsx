import { useMemo, useState } from 'react';
import { Provider } from 'react-redux';
import { configureStore } from '@reduxjs/toolkit';
import { fn } from '@storybook/test';

import {
  ReviewChangesView,
  ReviewCompleteState,
} from '@/features/review/ReviewChangesView';
import { uiSliceReducer } from '@/features/ui/uiSlice';
import { PageVersionComparisonView } from '@/features/versionComparison/PageVersionComparisonView';

import type React from 'react';
import type { Meta, StoryObj } from '@storybook/react';
import type { PageVersionSelection } from '@/features/versionComparison/PageVersionComparisonView';

const oldPageHtml = `
  <!doctype html>
  <html>
    <head>
      <style>
        body { margin: 0; font-family: Inter, system-ui, sans-serif; color: #070b18; }
        main { padding: 56px 72px; }
        .eyebrow { color: #255ee8; font-weight: 700; margin-bottom: 12px; }
        h1 { margin: 0 0 32px; font-size: 32px; line-height: 1.1; }
        .card { width: 430px; padding: 28px; border-radius: 8px; background: #eef2f7; }
        .icon { width: 34px; height: 34px; border-radius: 10px; background: #2563eb; color: #fff; display: grid; place-items: center; margin-bottom: 18px; }
        h2 { margin: 0 0 12px; font-size: 16px; }
        p { margin: 0; color: #111827; font-size: 14px; line-height: 1.55; }
      </style>
    </head>
    <body>
      <main>
        <div class="eyebrow">Services</div>
        <h1>Our service offerings</h1>
        <section class="card">
          <div class="icon">✓</div>
          <h2>Feature or benefit</h2>
          <p>Help people become familiar with the organization and its offerings.</p>
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
        body { margin: 0; font-family: Inter, system-ui, sans-serif; color: #070b18; }
        main { padding: 56px 72px; }
        .eyebrow { color: #255ee8; font-weight: 700; margin-bottom: 12px; }
        h1 { margin: 0 0 32px; font-size: 32px; line-height: 1.1; }
        .card { width: 430px; padding: 28px; border-radius: 8px; background: #eef2f7; }
        .icon { width: 34px; height: 34px; border-radius: 10px; background: #2563eb; color: #fff; display: grid; place-items: center; margin-bottom: 18px; }
        h2 { margin: 0 0 12px; font-size: 16px; }
        p { margin: 0; color: #111827; font-size: 14px; line-height: 1.55; }
      </style>
    </head>
    <body>
      <main>
        <div class="eyebrow">Featured services</div>
        <h1>What we offer.</h1>
        <section class="card">
          <div class="icon">✓</div>
          <h2>Feature or benefit</h2>
          <p>Help people become familiar with the organization and its offerings.</p>
        </section>
      </main>
    </body>
  </html>
`;

const oldVersion = {
  html: oldPageHtml,
  updated: 'Updated 8/19/26 at 12:01 PM',
};

const newVersion = {
  html: newPageHtml,
  updated: 'Updated 8/20/26 at 8:10 PM',
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

const onPrevious = fn().mockName('onPrevious');
const onNext = fn().mockName('onNext');
const onApplyVersionSelection = fn().mockName('onApplyVersionSelection');
const onClose = fn().mockName('onClose');
const onNavigateToCanvas = fn().mockName('onNavigateToCanvas');
const onNavigateToReview = fn().mockName('onNavigateToReview');
const onNewEditClick = fn().mockName('onNewEditClick');
const onPublishedPreviewClick = fn().mockName('onPublishedPreviewClick');
const onNewPreviewClick = fn().mockName('onNewPreviewClick');

const ReviewComparison = ({
  selectedVersion,
  onSelectVersion,
}: {
  selectedVersion?: PageVersionSelection;
  onSelectVersion?: (version: Exclude<PageVersionSelection, undefined>) => void;
}) => (
  <PageVersionComparisonView
    entityType="canvas_page"
    entityId="1"
    publishedVersion={oldVersion}
    newVersion={newVersion}
    publishedVersionTitle="Old version"
    newVersionTitle="New version"
    selectedVersion={selectedVersion}
    onSelectVersion={onSelectVersion}
    onPublishedPreviewClick={onPublishedPreviewClick}
    onNewPreviewClick={onNewPreviewClick}
    onNewEditClick={onNewEditClick}
  />
);

const ReviewChangesStory = ({
  defaultSelectedVersion = 'new',
  ...args
}: React.ComponentProps<typeof ReviewChangesView> & {
  defaultSelectedVersion?: PageVersionSelection;
}) => {
  const [selectedVersion, setSelectedVersion] = useState<PageVersionSelection>(
    defaultSelectedVersion,
  );

  const selectVersion = (version: Exclude<PageVersionSelection, undefined>) => {
    setSelectedVersion((currentVersion) =>
      currentVersion === version ? undefined : version,
    );
  };

  return (
    <ReviewChangesView
      {...args}
      selectedVersion={selectedVersion}
      isSelectedForPublishing={selectedVersion === 'new'}
      canPublish={selectedVersion === 'new'}
      onSelectedForPublishingChange={(checked) =>
        setSelectedVersion(checked ? 'new' : 'published')
      }
      comparison={
        <ReviewComparison
          selectedVersion={selectedVersion}
          onSelectVersion={selectVersion}
        />
      }
    />
  );
};

const meta = {
  title: 'Features/Review Changes/Page Comparison',
  component: ReviewChangesView,
  parameters: {
    layout: 'fullscreen',
    docs: {
      description: {
        component:
          'Review Changes compares selected non-conflicted Page changes before publishing. Selecting Old version discards the auto-save; selecting New version keeps it selected for publishing.',
      },
    },
  },
  args: {
    label: 'Homepage',
    comparison: <ReviewComparison selectedVersion="new" />,
    onClose,
    reviewIndex: 0,
    reviewTotal: 3,
    selectedVersion: 'new',
    isSelectedForPublishing: true,
    isApplyingSelection: false,
    canPublish: true,
    canPrevious: false,
    onSelectedForPublishingChange: fn(),
    onPrevious,
    onNext,
    onApplyVersionSelection,
    onNavigateToCanvas,
    onNavigateToReview,
  },
  argTypes: {
    comparison: {
      control: false,
      description: 'Page version comparison content.',
    },
    selectedVersion: {
      control: false,
    },
    isSelectedForPublishing: {
      control: false,
    },
    canPublish: {
      control: false,
    },
  },
} satisfies Meta<typeof ReviewChangesView>;

export default meta;

type Story = StoryObj<typeof meta>;

export const SelectedForPublishing: Story = {
  render: (args) => (
    <StoryFrame>
      <ReviewChangesStory {...args} defaultSelectedVersion="new" />
    </StoryFrame>
  ),
};

export const OldVersionSelected: Story = {
  render: (args) => (
    <StoryFrame>
      <ReviewChangesStory {...args} defaultSelectedVersion="published" />
    </StoryFrame>
  ),
};

export const AllFilesReviewed: Story = {
  render: () => (
    <StoryFrame>
      <ReviewCompleteState
        selectedCount={2}
        isPublishing={false}
        onPublish={fn()}
        onClose={onClose}
        onPrevious={onPrevious}
        canPrevious
      />
    </StoryFrame>
  ),
};
