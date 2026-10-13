import type { ContentStub } from '@/types/Content';

export interface ContentOperation {
  rel: string;
  href: string;
  label: string;
}

/**
 * Gets the operations other modules provide for a content entity.
 *
 * @see hook_canvas_content_operation()
 */
export const getModuleOperations = (item: ContentStub): ContentOperation[] =>
  Object.entries(item.linkLabels ?? {}).flatMap(([rel, label]) => {
    const href = item.links[rel];
    return href ? [{ rel, href, label }] : [];
  });
