import {
  HeadContent,
  Outlet,
  Scripts,
  createRootRoute,
} from '@tanstack/react-router'
import { TanStackRouterDevtoolsPanel } from '@tanstack/react-router-devtools'
import { TanStackDevtools } from '@tanstack/react-devtools'

import { DraftBanner } from '#/components/DraftBanner'
import { getDraftSessionState } from '#/server/canvas.functions'

import appCss from '../styles.css?url'

export const Route = createRootRoute({
  head: () => ({
    meta: [
      {
        charSet: 'utf-8',
      },
      {
        name: 'viewport',
        content: 'width=device-width, initial-scale=1',
      },
      {
        title: 'Canvas Headless example app (TanStack Start)',
      },
      {
        name: 'description',
        content:
          'Example frontend app embedded in the Drupal Canvas editor, rendering draft content via user-bound preview tokens.',
      },
    ],
    links: [
      {
        rel: 'stylesheet',
        href: appCss,
      },
    ],
  }),
  // The banner's session state, gathered server-side (the session lives in
  // httpOnly cookies).
  loader: () => getDraftSessionState(),
  component: RootComponent,
  shellComponent: RootDocument,
})

function RootComponent() {
  const session = Route.useLoaderData()
  return (
    <>
      <DraftBanner session={session} />
      <Outlet />
    </>
  )
}

function RootDocument({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en" className="h-full antialiased">
      <head>
        <HeadContent />
      </head>
      <body className="flex min-h-full flex-col">
        {children}
        <TanStackDevtools
          config={{
            position: 'bottom-right',
          }}
          plugins={[
            {
              name: 'Tanstack Router',
              render: <TanStackRouterDevtoolsPanel />,
            },
          ]}
        />
        <Scripts />
      </body>
    </html>
  )
}
