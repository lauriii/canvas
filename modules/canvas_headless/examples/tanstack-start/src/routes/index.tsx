import { Link, createFileRoute } from '@tanstack/react-router'

import { getContentLists } from '#/server/canvas.functions'
import { articlePath, canvasPagePath } from '#/lib/content'

export const Route = createFileRoute('/')({
  loader: () => getContentLists(),
  component: Home,
})

function Home() {
  const { canvasPages, articles } = Route.useLoaderData()

  return (
    <main className="mx-auto w-full max-w-2xl px-6 py-10">
      <h1 className="mb-6 text-3xl font-bold">Canvas pages</h1>
      {canvasPages.length === 0 ? (
        <p>No Canvas pages are visible.</p>
      ) : (
        <ul className="space-y-3">
          {canvasPages.map((page) => (
            <li key={page.id}>
              <Link to={canvasPagePath(page)} className="underline">
                {page.attributes.title}
              </Link>{' '}
              <span className="text-xs text-gray-500">
                {page.attributes.status ? 'published' : 'unpublished'}
              </span>
            </li>
          ))}
        </ul>
      )}

      <h1 className="mt-10 mb-6 text-3xl font-bold">Articles</h1>
      {articles.length === 0 ? (
        <p>No articles are visible.</p>
      ) : (
        <ul className="space-y-3">
          {articles.map((article) => (
            <li key={article.id}>
              <Link to={articlePath(article)} className="underline">
                {article.attributes.title}
              </Link>{' '}
              <span className="text-xs text-gray-500">
                {article.attributes.status ? 'published' : 'unpublished'}
              </span>
            </li>
          ))}
        </ul>
      )}
    </main>
  )
}
