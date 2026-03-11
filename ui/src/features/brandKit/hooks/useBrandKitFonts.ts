import { useEffect, useMemo, useState } from 'react';

import { useAppDispatch } from '@/app/hooks';
import { BRAND_KIT_ID } from '@/features/brandKit/constants';
import { stripFontListClientFields } from '@/features/brandKit/fontCss';
import {
  hydrateFontForUi,
  readStoredAxisSelections,
} from '@/features/brandKit/variableFontState';
import { setBrandKitFonts } from '@/features/code-editor/codeEditorSlice';
import { unsetActivePanel } from '@/features/ui/primaryPanelSlice';
import {
  brandKitApi,
  useGetAutoSaveQuery,
  useGetBrandKitQuery,
  useUpdateAutoSaveMutation,
} from '@/services/brandKit';
import { getOptionalQueryErrorMessage } from '@/utils/error-handling';

import type { FetchBaseQueryError } from '@reduxjs/toolkit/query';
import type { AssetLibraryFont, BrandKit } from '@/types/CodeComponent';

const isConflictError = (error: unknown): boolean =>
  typeof error === 'object' &&
  error !== null &&
  'status' in error &&
  (error as { status?: unknown }).status === 409;

const areFontListsEqual = (
  left: AssetLibraryFont[],
  right: AssetLibraryFont[],
): boolean =>
  JSON.stringify(stripFontListClientFields(left)) ===
  JSON.stringify(stripFontListClientFields(right));

export const useBrandKitFonts = () => {
  const dispatch = useAppDispatch();
  const [fonts, setFonts] = useState<AssetLibraryFont[]>([]);

  const {
    currentData: canonicalBrandKit,
    isFetching: isFetchingBrandKit,
    error: brandKitError,
  } = useGetBrandKitQuery(BRAND_KIT_ID);
  const {
    currentData: autoSaveBrandKit,
    isFetching: isFetchingAutoSave,
    error: autoSaveError,
  } = useGetAutoSaveQuery(BRAND_KIT_ID);
  const [updateAutoSave, updateAutoSaveState] = useUpdateAutoSaveMutation();

  const sourceFonts = useMemo(() => {
    const draft = autoSaveBrandKit?.data;
    if (draft == null) {
      return canonicalBrandKit?.fonts ?? [];
    }
    return draft.fonts ?? [];
  }, [autoSaveBrandKit?.data, canonicalBrandKit?.fonts]);

  useEffect(() => {
    const storedAxisSelections = readStoredAxisSelections();
    const nextFonts = (sourceFonts ?? []).map((font) =>
      hydrateFontForUi(font, storedAxisSelections),
    );
    setFonts((currentFonts) =>
      areFontListsEqual(currentFonts, nextFonts) ? currentFonts : nextFonts,
    );
    dispatch(setBrandKitFonts([sourceFonts ?? null, { needsAutoSave: false }]));
  }, [dispatch, sourceFonts]);

  const isLoading =
    !canonicalBrandKit &&
    !autoSaveBrandKit &&
    (isFetchingBrandKit || isFetchingAutoSave);

  const errorMessage =
    getOptionalQueryErrorMessage(updateAutoSaveState.error) ??
    getOptionalQueryErrorMessage(
      brandKitError as FetchBaseQueryError | undefined,
    ) ??
    getOptionalQueryErrorMessage(
      autoSaveError as FetchBaseQueryError | undefined,
    );

  const saveFonts = async (nextFonts: AssetLibraryFont[]) => {
    const persistedFonts =
      nextFonts.length > 0 ? stripFontListClientFields(nextFonts) : null;

    const applyFontsLocally = (fontsToApply: AssetLibraryFont[]) => {
      setFonts(fontsToApply);
      dispatch(
        setBrandKitFonts([
          fontsToApply.length > 0 ? fontsToApply : null,
          { needsAutoSave: false },
        ]),
      );
      dispatch(
        brandKitApi.util.updateQueryData(
          'getAutoSave',
          BRAND_KIT_ID,
          (draft) => {
            const fallbackData: BrandKit = {
              id: BRAND_KIT_ID,
              label: canonicalBrandKit?.label ?? 'Global brand kit',
              fonts: persistedFonts,
            };

            draft.data = draft.data
              ? {
                  ...draft.data,
                  fonts: persistedFonts,
                }
              : fallbackData;
          },
        ),
      );
    };

    const persistFonts = async () =>
      updateAutoSave({
        id: BRAND_KIT_ID,
        data: {
          fonts: persistedFonts,
        },
      }).unwrap();

    applyFontsLocally(nextFonts);

    try {
      await persistFonts();
      return;
    } catch (error) {
      if (!isConflictError(error)) {
        throw error;
      }
      dispatch(unsetActivePanel());
      return;
    }
  };

  return {
    errorMessage,
    fonts,
    isLoading,
    isSaving: updateAutoSaveState.isLoading,
    saveFonts,
    setFonts,
  };
};
