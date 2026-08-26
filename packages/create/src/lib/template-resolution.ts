import type { Template } from '../types/template.js';

export function resolveTemplate(
  templates: Template[],
  identifier: string,
): Template | undefined {
  return templates.find(
    (template) =>
      template.id === identifier || template.aliases?.includes(identifier),
  );
}

export function assertUniqueTemplateIdentifiers(templates: Template[]): void {
  const identifiers = new Map<string, string>();

  for (const template of templates) {
    for (const identifier of [template.id, ...(template.aliases ?? [])]) {
      const existingTemplateId = identifiers.get(identifier);
      if (existingTemplateId) {
        throw new Error(
          `Template identifier "${identifier}" is used by both "${existingTemplateId}" and "${template.id}"`,
        );
      }
      identifiers.set(identifier, template.id);
    }
  }
}
