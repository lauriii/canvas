import { getCanvasSettings } from '@/utils/drupal-globals';

import type { viewportSize } from '@/types/Preview';

/**
 * Each viewport defines its ID, display name, default width, and aspect ratio.
 *
 * Aspect ratios (height/width) are used to calculate viewport heights:
 * - mobile: 800/468 ≈ 1.71 (portrait orientation)
 * - tablet: 768/1024 = 0.75 (landscape orientation, 4:3)
 * - desktop: 1080/1920 = 0.5625 (landscape orientation, 16:9)
 * - large_desktop: 1440/2560 = 0.5625 (landscape orientation, 16:9)
 */
const VIEWPORTS = {
  mobile: {
    id: 'mobile',
    name: 'Mobile',
    defaultWidth: 468,
    aspectRatio: 800 / 468, // ~1.71 (portrait)
  },
  tablet: {
    id: 'tablet',
    name: 'Tablet',
    defaultWidth: 1024,
    aspectRatio: 768 / 1024, // 0.75 (4:3 landscape)
  },
  desktop: {
    id: 'desktop',
    name: 'Desktop',
    defaultWidth: 1920,
    aspectRatio: 1080 / 1920, // 0.5625 (16:9 landscape)
  },
  large_desktop: {
    id: 'large_desktop',
    name: 'Large Desktop',
    defaultWidth: 2560,
    aspectRatio: 1440 / 2560, // 0.5625 (16:9 landscape)
  },
} as const;

/**
 * Creates a viewport size object from a width and viewport ID.
 *
 * Height is calculated dynamically based on the viewport's aspect ratio.
 *
 * @param {string} id
 *   The viewport ID (mobile, tablet, desktop, large_desktop).
 * @param {number} width
 *   The viewport width in pixels.
 *
 * @return {viewportSize}
 *   A viewport size object with calculated height.
 */
function createViewportSize(
  id: keyof typeof VIEWPORTS,
  width: number,
): viewportSize {
  const viewport = VIEWPORTS[id];
  const height = Math.round(width * viewport.aspectRatio);
  return {
    name: viewport.name,
    id: viewport.id,
    width,
    height,
  };
}

/**
 * Gets viewport sizes, using theme configuration if available.
 *
 * Themes can define custom viewport widths in {theme}.canvas.yml:
 * @code
 * viewports:
 *   mobile: 468
 *   tablet: 1024
 *   desktop: 1920
 *   large_desktop: 2560
 * @endcode
 *
 * Custom widths override the default widths for existing viewport types.
 * Additional viewport keys in the configuration are ignored - only the four
 * standard viewports (mobile, tablet, desktop, large_desktop) are used.
 *
 * @return {viewportSize[]}
 *   Array of viewport size objects.
 */
export function getViewportSizes(): viewportSize[] {
  const customViewports = getCanvasSettings()?.viewports;

  return Object.keys(VIEWPORTS).map((id) => {
    const viewportId = id as keyof typeof VIEWPORTS;
    const width = getValidWidth(customViewports?.[viewportId], viewportId);
    return createViewportSize(viewportId, width);
  });
}

/**
 * Validates and returns a viewport width, falling back to default if invalid.
 */
function getValidWidth(
  customWidth: unknown,
  id: keyof typeof VIEWPORTS,
): number {
  if (customWidth === undefined) {
    return VIEWPORTS[id].defaultWidth;
  }

  const numericWidth =
    typeof customWidth === 'string' ? parseInt(customWidth, 10) : customWidth;

  const isValid =
    typeof numericWidth === 'number' &&
    Number.isInteger(numericWidth) &&
    numericWidth > 0;

  return isValid ? numericWidth : VIEWPORTS[id].defaultWidth;
}
