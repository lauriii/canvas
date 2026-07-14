import type { ReactNode } from "react";

/**
 * A minimal Canvas code component. Its metadata lives in the sibling
 * component.yml, which the component metadata endpoint
 * (/api/canvas/components) exposes to the embedding Drupal Canvas site.
 */
export default function HelloCard({
  title,
  content,
}: {
  title: string;
  content?: ReactNode;
}) {
  return (
    <div className="rounded border border-gray-200 p-4">
      <h2 className="mb-2 text-lg font-semibold">{title}</h2>
      {content}
    </div>
  );
}
