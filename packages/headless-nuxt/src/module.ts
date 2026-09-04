import { CANVAS_COMPONENT_PREVIEW_PATH } from '@drupal-canvas/headless';
import {
  readComponentManifest,
  writeComponentManifest,
} from '@drupal-canvas/headless/components-endpoint';
import { resolveDraftConfig } from '@drupal-canvas/headless/server';
import { canvasComponentRegistry } from '@drupal-canvas/headless/vite';
import {
  addComponent,
  addPlugin,
  addServerHandler,
  addServerPlugin,
  addVitePlugin,
  createResolver,
  defineNuxtModule,
  extendPages,
} from '@nuxt/kit';

// Nuxt 4 declares the nitro:* hooks through this builder package's module
// augmentation of @nuxt/schema; the type-only import pulls it in.
import type {} from '@nuxt/nitro-server';

/**
 * The SDK packages the app's builds must compile rather than
 * externalize; the adapter package ships TypeScript source. The Vue
 * build gets them through build.transpile, the Nitro build through
 * externals.inline.
 */
const SDK_PACKAGES = [
  '@drupal-canvas/headless',
  '@drupal-canvas/headless-nuxt',
];

/** The tag of the framework-free session element from the SDK core. */
const DRAFT_SESSION_ELEMENT_TAG = 'canvas-draft-session';

export interface CanvasModuleOptions {
  /**
   * Mount the draft and component metadata routes automatically. Default
   * true; disable to mount the runtime handlers at paths of your own with
   * addServerHandler(), pointing at this package's `routes/*` subpath
   * exports (for example `@drupal-canvas/headless-nuxt/routes/draft`).
   */
  injectRoutes?: boolean;
  /**
   * The route the component metadata endpoint is mounted at.
   */
  componentsRoutePath?: string;
}

/**
 * The Drupal Canvas headless module for Nuxt:
 *
 * - Mounts the draft session routes (/api/draft, /api/draft/renew,
 *   /api/disable-draft, /api/draft/session) and the component metadata
 *   endpoint (/api/canvas/components).
 * - Merges the CSP `frame-ancestors` directive into every response,
 *   keeping responses 'self'-only by default and admitting the exact
 *   editor origin from a draft session's signed renewal URL while
 *   preserving the app's own policy.
 * - Registers the <DraftSession> component and teaches the Vue compiler
 *   about the SDK's <canvas-draft-session> custom element.
 * - Refreshes the consuming application's async data after Canvas auto-saves.
 * - Adds the SDK packages to the Vue and Nitro builds, including linked
 *   workspace packages outside the app root during development.
 * - Generates the component manifest (`.canvas/components.manifest.json`)
 *   at build time — in production the metadata endpoint serves this
 *   manifest, so the registry always describes the deployed build. A
 *   malformed component.yml fails the build; a broken registry never
 *   ships silently.
 * - Registers the shared Vite component implementation registry, which updates
 *   when local components are added, removed, or renamed during development.
 *
 * ```ts
 * // nuxt.config.ts
 * export default defineNuxtConfig({
 *   modules: ['@drupal-canvas/headless-nuxt'],
 * });
 * ```
 */
export default defineNuxtModule<CanvasModuleOptions>({
  meta: {
    name: '@drupal-canvas/headless-nuxt',
    configKey: 'drupalCanvas',
    compatibility: {
      nuxt: '>=4.0.0',
    },
  },
  defaults: {
    injectRoutes: true,
    componentsRoutePath: '/api/canvas/components',
  },
  setup(options, nuxt) {
    const resolver = createResolver(import.meta.url);
    if (nuxt.options.dev) {
      resolveDraftConfig();
      // Workspace consumers resolve the linked core package outside the app
      // root. Its preview stylesheet must remain available to Vite there.
      nuxt.options.vite.server ??= {};
      nuxt.options.vite.server.fs ??= {};
      nuxt.options.vite.server.fs.allow ??= [nuxt.options.workspaceDir];
      nuxt.options.vite.server.fs.allow.push(
        resolver.resolve('../../headless'),
      );
    }

    // The Vue build compiles the SDK's raw TypeScript.
    nuxt.options.build.transpile.push(...SDK_PACKAGES);

    // A static generate bakes every page at build time, so no server
    // exists afterwards to hold a draft session. The flag lets the
    // <DraftSession> component degrade gracefully instead of fetching a
    // session route that is not there. `nuxt generate` (and
    // `nuxt build --prerender`) marks the build with the legacy _generate
    // flag and Nitro's static mode; neither is part of the public typings.
    const nitroOptions = (nuxt.options.nitro ?? {}) as {
      preset?: string;
      static?: boolean;
    };
    const isStaticBuild =
      (nuxt.options as { _generate?: boolean })._generate === true ||
      nitroOptions.preset === 'static' ||
      nitroOptions.static === true;
    nuxt.options.runtimeConfig.public.canvasStaticBuild ??= isStaticBuild;

    // The Nitro build externalizes node_modules by default, which would
    // ask Node to load the packages' raw TypeScript at runtime — inline
    // them into the server bundle instead, where they are compiled.
    nuxt.hook('nitro:config', (nitroConfig) => {
      nitroConfig.externals ??= {};
      nitroConfig.externals.inline ??= [];
      nitroConfig.externals.inline.push(...SDK_PACKAGES);

      // The component preview page 404s without its query parameter, and
      // the generate crawler visits every page route bare — that 404
      // aborts the whole generate. Unconditional: the route must never be
      // prerendered, because previews exist only inside a live draft
      // session.
      nitroConfig.prerender ??= {};
      nitroConfig.prerender.ignore ??= [];
      nitroConfig.prerender.ignore.push(CANVAS_COMPONENT_PREVIEW_PATH);

      // The production manifest, inlined into the server bundle as a
      // virtual module (the build:before hook below wrote the file before
      // Nitro bundles). Inlining removes any filesystem assumption from
      // the deployed shape: shipping only `.output/` works, serverless
      // presets work. Null in dev, where the endpoint scans live.
      nitroConfig.virtual ??= {};
      nitroConfig.virtual['#canvas-components-manifest'] = async () => {
        if (nuxt.options.dev) {
          return 'export default null;';
        }
        const manifest = await readComponentManifest({
          projectRoot: nuxt.options.rootDir,
        });
        return `export default ${JSON.stringify(manifest)};`;
      };
    });

    // <canvas-draft-session> is a custom element, not a Vue component;
    // without this the Vue compiler warns about the unresolved component.
    const isCustomElement = nuxt.options.vue.compilerOptions.isCustomElement;
    nuxt.options.vue.compilerOptions.isCustomElement = (tag) =>
      tag === DRAFT_SESSION_ELEMENT_TAG || (isCustomElement?.(tag) ?? false);

    addComponent({
      name: 'DraftSession',
      filePath: resolver.resolve('./runtime/components/DraftSession.vue'),
    });
    addComponent({
      name: 'CanvasComponentTree',
      filePath: resolver.resolve('./runtime/components/CanvasComponentTree.ts'),
    });
    addPlugin(resolver.resolve('./runtime/plugins/draft-refresh.client'));
    addVitePlugin(
      canvasComponentRegistry({ projectRoot: nuxt.options.rootDir }),
    );

    addServerPlugin(resolver.resolve('./runtime/server/plugins/csp'));

    // Registered regardless of injectRoutes: the <DraftSession> component
    // reads it (its sessionEndpoint prop defaults to this path), and the
    // route only exposes non-secret session state.
    addServerHandler({
      route: '/api/draft/session',
      method: 'get',
      handler: resolver.resolve('./runtime/server/routes/session-state'),
    });

    extendPages((pages) => {
      pages.push({
        name: 'canvas-component-preview',
        path: CANVAS_COMPONENT_PREVIEW_PATH,
        file: resolver.resolve('./runtime/pages/component-preview.vue'),
        meta: { layout: false },
      });
    });

    if (options.injectRoutes) {
      addServerHandler({
        route: '/api/draft',
        method: 'get',
        handler: resolver.resolve('./runtime/server/routes/draft'),
      });
      addServerHandler({
        route: '/api/draft/renew',
        method: 'post',
        handler: resolver.resolve('./runtime/server/routes/draft-renew'),
      });
      addServerHandler({
        route: '/api/disable-draft',
        method: 'post',
        handler: resolver.resolve('./runtime/server/routes/disable-draft'),
      });
      addServerHandler({
        route: options.componentsRoutePath,
        handler: resolver.resolve('./runtime/server/routes/components'),
      });
    }

    // The build-time manifest, served by the metadata endpoint in
    // production. Not in dev: there the endpoint scans live per request.
    if (!nuxt.options.dev) {
      nuxt.hook('build:before', async () => {
        const manifest = await writeComponentManifest({
          projectRoot: nuxt.options.rootDir,
        });
        console.info(
          `[canvas] Wrote the component manifest: ${manifest.components.length} component(s), ${manifest.warnings.length} warning(s).`,
        );
        for (const warning of manifest.warnings) {
          console.warn(`[canvas] ${warning.message}`);
        }
      });
    }
  },
});

declare module '@nuxt/schema' {
  interface PublicRuntimeConfig {
    /**
     * True while the app is being statically generated. Set by the module
     * from the build flags; the <DraftSession> component reads it to
     * degrade gracefully where no server holds a draft session.
     */
    canvasStaticBuild?: boolean;
  }
}
