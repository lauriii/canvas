// The translations here are Finnish, so that a test asserting on translated
// output cannot pass on untranslated English by accident.
// cspell:ignore Julkaisu Julkaistu muutoksesta muutoksia muutos muutosta
// cspell:ignore ratkaistavana ristiriita ristiriitaa Tarkista valittuna valmis

import { afterEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import { PageStatusBadge } from '@/components/pageStatus/PageStatus';
import ConflictBanner from '@/components/review/ConflictBanner';
import PublishReview from '@/components/review/PublishReview';

import type React from 'react';
import type { UnpublishedChange } from '@/types/Review';

vi.mock('@/features/conflict/conflictUtils', () => ({
  isConflictUxEnabled: () => true,
}));

vi.mock('@/components/PermissionCheck', () => ({
  default: ({ children }: any) => <>{children}</>,
}));

/**
 * Installs translations the way Drupal's generated JavaScript file does.
 *
 * @see _locale_rebuild_js()
 */
function setTranslations(strings: Record<string, Record<string, string>>) {
  window.drupalTranslations = { strings };
}

const change = (index: number): UnpublishedChange => ({
  pointer: `canvas_page:${index}:en`,
  label: `Page ${index}`,
  updated: 1_777_000_000,
  entity_type: 'canvas_page',
  data_hash: `hash-${index}`,
  entity_id: index,
  langcode: 'en',
  owner: { name: 'Editor', avatar: null, id: 2, uri: '/user/2' },
});

const renderReview = (
  changes: UnpublishedChange[],
  overrides: Partial<React.ComponentProps<typeof PublishReview>> = {},
) =>
  render(
    <AppWrapper store={makeStore()} location="/" path="*">
      <PublishReview
        changes={changes}
        errors={undefined}
        onOpenChangeCallback={vi.fn()}
        onPublishClick={vi.fn()}
        onDiscardClick={vi.fn()}
        isPublishing={false}
        isDiscarding={false}
        isUpdating={false}
        {...overrides}
      />
    </AppWrapper>,
  );

afterEach(() => {
  delete window.drupalTranslations;
});

describe('Editor UI translation', () => {
  it('renders source English when nothing is translated', () => {
    renderReview([change(1), change(2)]);

    expect(screen.getByText('Review 2 changes')).toBeInTheDocument();
  });

  it('renders a plain string from the translation store', () => {
    setTranslations({ '': { 'No changes': 'Ei muutoksia' } });

    renderReview([]);

    expect(screen.getByText('Ei muutoksia')).toBeInTheDocument();
    expect(screen.queryByText('No changes')).not.toBeInTheDocument();
  });

  it('interpolates placeholders into the translated string', () => {
    setTranslations({
      '': {
        '@selected of @total changes selected':
          '@total muutoksesta @selected valittuna',
      },
    });

    renderReview([change(1), change(2), change(3)], { open: true });

    expect(screen.getByText('3 muutoksesta 0 valittuna')).toBeInTheDocument();
  });

  it('picks the plural form the translation defines', () => {
    // A single source string holding both forms, joined the way Drupal joins
    // them, is what a translated plural looks like on the wire.
    setTranslations({
      '': {
        [`Review 1 change${String.fromCharCode(3)}Review @count changes`]: `Tarkista 1 muutos${String.fromCharCode(3)}Tarkista @count muutosta`,
      },
    });

    const { unmount } = renderReview([change(1)]);
    expect(screen.getByText('Tarkista 1 muutos')).toBeInTheDocument();
    unmount();

    renderReview([change(1), change(2), change(3)]);
    expect(screen.getByText('Tarkista 3 muutosta')).toBeInTheDocument();
  });

  it('translates a plural through ConflictBanner', () => {
    setTranslations({
      '': {
        [`1 conflict to resolve${String.fromCharCode(3)}@count conflicts to resolve`]: `1 ristiriita ratkaistavana${String.fromCharCode(3)}@count ristiriitaa ratkaistavana`,
      },
    });

    render(<ConflictBanner conflictCount={2} onResolveClick={vi.fn()} />);

    expect(screen.getByText('2 ristiriitaa ratkaistavana')).toBeInTheDocument();
  });

  it('keeps translations with different contexts apart', () => {
    setTranslations({
      'Canvas page status': { Published: 'Julkaistu' },
      'Canvas publishing result': { Published: 'Julkaisu valmis' },
    });

    render(
      <PageStatusBadge isNew={false} hasAutoSave={false} isPublished={true} />,
    );

    expect(screen.getByText('Julkaistu')).toBeInTheDocument();
    expect(screen.queryByText('Julkaisu valmis')).not.toBeInTheDocument();
  });

  it('falls back to English when only another context is translated', () => {
    setTranslations({
      'Canvas publishing result': { Published: 'Julkaisu valmis' },
    });

    render(
      <PageStatusBadge isNew={false} hasAutoSave={false} isPublished={true} />,
    );

    expect(screen.getByText('Published')).toBeInTheDocument();
  });
});
