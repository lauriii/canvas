import { describe, expect, it } from 'vitest';

import { getPreviewedVariant } from '@/features/layout/personalizationUtils';

import {
  initialState,
  selectPreviewedVariants,
  setPreviewedVariant,
  uiSliceReducer,
} from './uiSlice';

describe('uiSlice previewed variants', () => {
  it('starts empty so every switch previews the default variant', () => {
    expect(initialState.previewedVariants).toEqual({});
    expect(
      getPreviewedVariant(initialState.previewedVariants, 'switch-1'),
    ).toBe('default');
  });

  it('stores the previewed variant per switch', () => {
    let state = uiSliceReducer(
      initialState,
      setPreviewedVariant({ switchUuid: 'switch-1', variantId: 'offer' }),
    );
    state = uiSliceReducer(
      state,
      setPreviewedVariant({ switchUuid: 'switch-2', variantId: 'welcome' }),
    );
    expect(state.previewedVariants).toEqual({
      'switch-1': 'offer',
      'switch-2': 'welcome',
    });
    expect(getPreviewedVariant(state.previewedVariants, 'switch-1')).toBe(
      'offer',
    );
    // Switches without an explicit choice still preview the default variant.
    expect(getPreviewedVariant(state.previewedVariants, 'switch-3')).toBe(
      'default',
    );
  });

  it('replaces the previewed variant of a switch', () => {
    let state = uiSliceReducer(
      initialState,
      setPreviewedVariant({ switchUuid: 'switch-1', variantId: 'offer' }),
    );
    state = uiSliceReducer(
      state,
      setPreviewedVariant({ switchUuid: 'switch-1', variantId: 'default' }),
    );
    expect(state.previewedVariants).toEqual({ 'switch-1': 'default' });
  });

  it('exposes the previewed variants through the slice selector', () => {
    const state = uiSliceReducer(
      initialState,
      setPreviewedVariant({ switchUuid: 'switch-1', variantId: 'offer' }),
    );
    expect(selectPreviewedVariants({ ui: state })).toEqual({
      'switch-1': 'offer',
    });
  });
});
