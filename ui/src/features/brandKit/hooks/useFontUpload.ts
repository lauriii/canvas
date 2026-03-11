import { useRef, useState } from 'react';
import { v4 as uuidv4 } from 'uuid';

import { BRAND_KIT_ACCEPTED_FILE_TYPES } from '@/features/brandKit/constants';
import {
  fallbackFontFamilyFromFilename,
  readFontMetadataFromFile,
} from '@/features/brandKit/fontMetadata';
import { useUploadFontMutation } from '@/services/brandKit';
import { getOptionalQueryErrorMessage } from '@/utils/error-handling';

import type { ChangeEvent, RefObject } from 'react';
import type { AssetLibraryFont } from '@/types/CodeComponent';

const ACCEPTED_FONT_FILE_TYPES = BRAND_KIT_ACCEPTED_FILE_TYPES.map(
  (fileType) => `.${fileType}`,
).join(',');

const isSupportedFontFormat = (
  extension: string,
): extension is AssetLibraryFont['format'] =>
  BRAND_KIT_ACCEPTED_FILE_TYPES.includes(
    extension as AssetLibraryFont['format'],
  );

const getUploadedFontFormat = (file: File): AssetLibraryFont['format'] => {
  const extension = file.name.split('.').pop()?.toLowerCase();

  if (extension && isSupportedFontFormat(extension)) {
    return extension;
  }

  throw new Error('Unsupported font file extension.');
};

type UseFontUploadOptions = {
  fonts: AssetLibraryFont[];
  onFontUploaded: (font: AssetLibraryFont) => void;
  saveFonts: (nextFonts: AssetLibraryFont[]) => Promise<void>;
};

type UseFontUploadResult = {
  acceptedFileTypes: string;
  errorMessage: string | null;
  fileInputRef: RefObject<HTMLInputElement>;
  handleAddVariantClick: (family: string) => void;
  handleFilesSelected: (event: ChangeEvent<HTMLInputElement>) => Promise<void>;
  handleUploadClick: () => void;
  isUploading: boolean;
};

export const useFontUpload = ({
  fonts,
  onFontUploaded,
  saveFonts,
}: UseFontUploadOptions): UseFontUploadResult => {
  const fileInputRef = useRef<HTMLInputElement>(null);
  const [pendingUploadFamily, setPendingUploadFamily] = useState<string | null>(
    null,
  );
  const [uploadFont, uploadFontState] = useUploadFontMutation();

  const handleUploadClick = () => {
    setPendingUploadFamily(null);
    fileInputRef.current?.click();
  };

  const handleAddVariantClick = (family: string) => {
    setPendingUploadFamily(family);
    fileInputRef.current?.click();
  };

  const handleFilesSelected = async (event: ChangeEvent<HTMLInputElement>) => {
    const selectedFiles = Array.from(event.target.files ?? []);
    const targetFamily = pendingUploadFamily;

    if (selectedFiles.length === 0) {
      return;
    }

    try {
      const uploadedFonts: AssetLibraryFont[] = [];
      for (const file of selectedFiles) {
        const detectedMetadataPromise = readFontMetadataFromFile(file);
        const uploadedArtifact = await uploadFont(file).unwrap();
        const detectedMetadata = await detectedMetadataPromise;
        const format = getUploadedFontFormat(file);

        uploadedFonts.push({
          id: uuidv4(),
          uri: uploadedArtifact.uri,
          url: uploadedArtifact.url,
          format,
          variantType: detectedMetadata?.variantType ?? 'static',
          weight: detectedMetadata?.weight ?? '400',
          style: detectedMetadata?.style ?? 'normal',
          axes: detectedMetadata?.axes ?? null,
          axisSettings: detectedMetadata?.axisSettings ?? null,
          ...(detectedMetadata ?? {}),
          family:
            targetFamily ??
            detectedMetadata?.family ??
            fallbackFontFamilyFromFilename(file.name),
        });
      }

      await saveFonts([...fonts, ...uploadedFonts]);
      const nextSelectedFont = uploadedFonts[uploadedFonts.length - 1];
      if (nextSelectedFont) {
        onFontUploaded(nextSelectedFont);
      }
    } finally {
      setPendingUploadFamily(null);
      event.target.value = '';
    }
  };

  return {
    acceptedFileTypes: ACCEPTED_FONT_FILE_TYPES,
    errorMessage: getOptionalQueryErrorMessage(uploadFontState.error),
    fileInputRef: fileInputRef as RefObject<HTMLInputElement>,
    handleAddVariantClick,
    handleFilesSelected,
    handleUploadClick,
    isUploading: uploadFontState.isLoading,
  };
};
