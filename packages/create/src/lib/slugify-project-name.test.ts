import { describe, expect, it } from 'vitest';

import {
  DEFAULT_PROJECT_NAME,
  slugifyProjectName,
} from './slugify-project-name.js';
import validateName from './validate-name.js';

describe('slugifyProjectName', () => {
  it.each([
    ['My Drupal Site', 'my-drupal-site'],
    ['Café Déjà Vu', 'cafe-deja-vu'],
    ['Company & Product', 'company-and-product'],
    ["Editor's Site", 'editors-site'],
    ['Drupal 2026!', 'drupal-2026'],
  ])('slugifies %j as %j', (siteName, expected) => {
    expect(slugifyProjectName(siteName)).toBe(expected);
  });

  it.each(['', '   ', '✨', '東京'])(
    'uses the default project name when %j has no usable slug',
    (siteName) => {
      expect(slugifyProjectName(siteName)).toBe(DEFAULT_PROJECT_NAME);
    },
  );

  it('limits the result to a valid npm package name', () => {
    const slug = slugifyProjectName(`A ${'very '.repeat(100)}long site name`);

    expect(slug.length).toBeLessThanOrEqual(214);
    expect(validateName(slug).valid).toBe(true);
  });
});
