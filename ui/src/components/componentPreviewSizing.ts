export const COMPONENT_PREVIEW_IFRAME_WIDTH = 1200;
export const COMPONENT_PREVIEW_IFRAME_HEIGHT = 800;
export const COMPONENT_PREVIEW_WIDTH = 300;
export const COMPONENT_PREVIEW_HEIGHT = 200;

interface ComponentPreviewSizing {
  scale: number;
  width: number;
  height: number;
}

export function calculateComponentPreviewSizing(
  width: number,
  height: number,
  maxWidth = COMPONENT_PREVIEW_WIDTH,
  maxHeight = COMPONENT_PREVIEW_HEIGHT,
): ComponentPreviewSizing {
  const scale = Math.min(maxWidth / width, maxHeight / height, 1);

  return {
    scale,
    width: width * scale,
    height: height * scale,
  };
}
