import Link from "next/link";
import { notFound } from "next/navigation";
import { fetchCeApiPage } from "@/lib/drupal-draft";

export const dynamic = "force-dynamic";

/**
 * Catch-all page: resolves the current path through Drupal's routing via
 * the Lupus Decoupled CE API (custom_elements + lupus_ce_renderer at
 * /ce-api/{path}) and renders proof of what came back — the raw payload.
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
  const page = await fetchCeApiPage(path);

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
        Rendered from{" "}
        <code className="rounded bg-gray-100 px-1">/ce-api{path}</code> —
        content format <code>{page.content_format}</code>.
      </p>

      <h2 className="mb-2 mt-8 text-lg font-semibold">Raw CE API payload</h2>
      <pre className="overflow-x-auto rounded bg-gray-100 p-3 text-xs">
        {JSON.stringify(page, null, 2)}
      </pre>
    </main>
  );
}
