import Link from "next/link";
import { notFound } from "next/navigation";
import { fetchPage } from "@drupal-canvas/headless-next";

export const dynamic = "force-dynamic";

/**
 * Catch-all page: resolves the current path through Drupal's routing via
 * the SDK's fetchPage() and renders proof of what came back — the raw
 * payload.
 * The Canvas custom elements renderer is still in development, so this
 * deliberately shows the API's output rather than attempting a full page
 * render.
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
    <main className="mx-auto w-full max-w-2xl px-6 py-10">
      <p className="mb-6">
        <Link href="/" className="text-sm underline">
          ← All content
        </Link>
      </p>
      <h1 className="mb-2 text-3xl font-bold">{page.title}</h1>
      <p className="mb-6 text-sm text-gray-500">
        Resolved through Drupal&apos;s routing for{" "}
        <code className="rounded bg-gray-100 px-1">{path}</code> — content
        format <code>{page.content_format}</code>.
      </p>

      <h2 className="mb-2 mt-8 text-lg font-semibold">Raw page payload</h2>
      <pre className="overflow-x-auto rounded bg-gray-100 p-3 text-xs">
        {JSON.stringify(page, null, 2)}
      </pre>
    </main>
  );
}
