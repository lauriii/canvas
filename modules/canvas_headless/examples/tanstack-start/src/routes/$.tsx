import { Link, createFileRoute, notFound } from '@tanstack/react-router'
import CanvasComponentTree from '@drupal-canvas/headless-tanstack-start/CanvasComponentTree'

import { getPageForPath } from '#/server/canvas.functions'

/**
 * Catch-all page: resolves the current path through Drupal's routing via
 * the SDK's fetchPage() (behind a server function) and renders its component
 * tree with implementations from this app's registry.
 */
export const Route = createFileRoute('/$')({
  loader: async ({ params }) => {
    const path = `/${(params._splat ?? '')
      .split('/')
      .map(encodeURIComponent)
      .join('/')}`
    const page = await getPageForPath({ data: path })
    if (!page) {
      throw notFound()
    }
    return { page, path }
  },
  component: CatchAllPage,
  notFoundComponent: NotFoundPage,
})

function CatchAllPage() {
  const { page } = Route.useLoaderData()

  return (
    <CanvasComponentTree tree={page.content} />
  )
}

function NotFoundPage() {
  return (
    <main className="mx-auto w-full max-w-2xl px-6 py-10">
      <p className="mb-6">
        <Link to="/" className="text-sm underline">
          ← All content
        </Link>
      </p>
      <h1 className="mb-2 text-3xl font-bold">Not found</h1>
      <p className="text-sm text-gray-500">
        Drupal answered nothing for this path.
      </p>
    </main>
  )
}
