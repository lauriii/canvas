export const isPreviewPath = (pathname: string): boolean =>
  pathname.includes('/preview') || pathname.includes('/version-preview');
