import { notFound } from "next/navigation";
import { fetchPage } from "@drupal-canvas/headless-next";
import CanvasComponentTree from "@drupal-canvas/headless-next/CanvasComponentTree";

export const dynamic = "force-dynamic";

/**
 * Catch-all page: resolves the current path through Drupal's routing via
 * the SDK's fetchPage() and renders its component tree with implementations
 * from this app's registry.
 */
export default async function CatchAllPage({
  params,
}: {
  params: Promise<{ slug: string[] }>;
}) {
  const { slug } = await params;
  const path = `/${slug.map(encodeURIComponent).join("/")}`;
  const page = await fetchPage(path);

  if (!page) {
    notFound();
  }

  return (
    <CanvasComponentTree tree={page.content} />
  );
}
