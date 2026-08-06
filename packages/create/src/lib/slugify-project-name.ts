import slugify from '@sindresorhus/slugify';

import validateName from './validate-name.js';

export const DEFAULT_PROJECT_NAME = 'my-canvas-project';
const MAX_PACKAGE_NAME_LENGTH = 214;

export function slugifyProjectName(siteName: string): string {
  const slug = slugify(siteName)
    .slice(0, MAX_PACKAGE_NAME_LENGTH)
    .replace(/-+$/g, '');

  if (!slug || !validateName(slug).valid) {
    return DEFAULT_PROJECT_NAME;
  }

  return slug;
}
