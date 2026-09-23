import {
  afterNextRender,
  computed,
  DestroyRef,
  DOCUMENT,
  effect,
  inject,
  Injectable,
  makeEnvironmentProviders,
  makeStateKey,
  provideAppInitializer,
  REQUEST_CONTEXT,
  signal,
  TransferState,
  untracked,
} from '@angular/core';
import {
  NavigationCancel,
  NavigationCancellationCode,
  NavigationEnd,
  NavigationError,
  NavigationSkipped,
  NavigationStart,
  RedirectCommand,
  Router,
} from '@angular/router';
import { isPageRedirect, serializeJsonForHtml } from '@drupal-canvas/headless';
import { assertCanvasPath } from '@drupal-canvas/headless-angular/contracts';
import {
  createAsyncRefreshQueue,
  createCanvasGeometryBridge,
  createDraftSession,
  createHeightReporter,
} from '@drupal-canvas/headless/client';

import type { ResolveFn } from '@angular/router';
import type { EntityResult, PageHead } from '@drupal-canvas/headless';
import type {
  CanvasEntityOptions,
  CanvasPageData,
  CanvasRequestContext,
  CanvasSession,
} from '@drupal-canvas/headless-angular/contracts';
import type {
  DraftSession,
  DraftSessionRenewState,
} from '@drupal-canvas/headless/client';

interface PendingCanvasNavigation {
  id: number;
  done: Promise<void>;
  release: () => void;
  awaitingSuccessor?: boolean;
  staged?: { data: CanvasPageData; sessionRevision: number };
}

/** Per-Angular-application store: SSR must bootstrap one application per request. */
@Injectable({ providedIn: 'root' })
export class CanvasPageStore {
  private readonly context = inject(REQUEST_CONTEXT, {
    optional: true,
  }) as CanvasRequestContext | null;
  private readonly transfer = inject(TransferState);
  private readonly document = inject(DOCUMENT);
  private readonly head = inject(CanvasDocumentHead);
  private readonly destroy = inject(DestroyRef);
  private readonly router = inject(Router);
  private navigationController?: AbortController;
  private refreshController?: AbortController;
  private navigation?: PendingCanvasNavigation;
  private sessionRevision = 0;
  private readonly entityControllers = new Set<AbortController>();
  private generation = 0;
  private disposed = false;
  /** Last committed router path, never the destination of an unfinished resolver. */
  readonly path = signal('/');
  readonly pendingPath = signal<string | null>(null);
  readonly data = signal<CanvasPageData | null>(null);
  readonly error = signal<unknown>(null);
  readonly session = computed(() => this.data()?.session ?? null);
  readonly page = computed(() => {
    const page = this.data()?.page;
    return page && !isPageRedirect(page) ? page : null;
  });
  private readonly queue = createAsyncRefreshQueue(
    () => this.refreshCommittedPage(),
    (error) => {
      if (
        !this.disposed &&
        !(error instanceof Error && error.name === 'AbortError')
      )
        this.error.set(error);
    },
  );
  constructor() {
    const events = this.router.events.subscribe((event) => {
      if (event instanceof NavigationStart)
        this.beginNavigation(event.id, event.url);
      else if (
        event instanceof NavigationEnd &&
        event.id === this.navigation?.id
      ) {
        const staged = this.navigation.staged;
        this.path.set(event.urlAfterRedirects.split('#')[0]);
        if (staged) this.commit(staged.data, staged.sessionRevision);
        this.endNavigation();
      } else if (
        (event instanceof NavigationCancel ||
          event instanceof NavigationError) &&
        event.id === this.navigation?.id
      ) {
        this.navigationController?.abort();
        this.navigation.staged = undefined;
        if (
          event instanceof NavigationCancel &&
          (event.code === NavigationCancellationCode.Redirect ||
            event.code === NavigationCancellationCode.SupersededByNewNavigation)
        ) {
          // Keep refreshes behind the whole redirect/supersession chain.
          this.navigation.awaitingSuccessor = true;
        } else this.endNavigation();
      } else if (
        event instanceof NavigationSkipped &&
        (event.id === this.navigation?.id || this.navigation?.awaitingSuccessor)
      )
        this.endNavigation();
    });
    this.destroy.onDestroy(() => {
      this.disposed = true;
      events.unsubscribe();
      this.navigationController?.abort();
      this.refreshController?.abort();
      this.endNavigation();
      for (const controller of this.entityControllers) controller.abort();
    });
  }
  private beginNavigation(id: number, path: string): void {
    if (this.navigation?.id === id) return;
    const previous = this.navigation;
    let release!: () => void;
    const done = new Promise<void>((resolve) => {
      release = resolve;
    });
    this.navigation = { id, done, release };
    this.pendingPath.set(path.split('#')[0]);
    ++this.generation;
    this.refreshController?.abort();
    this.navigationController?.abort();
    previous?.release();
  }
  private endNavigation(): void {
    const previous = this.navigation;
    this.navigation = undefined;
    this.pendingPath.set(null);
    previous?.release();
  }
  refresh(): Promise<void> {
    return this.disposed ? Promise.resolve() : this.queue.request();
  }
  setSession(session: CanvasSession): void {
    ++this.sessionRevision;
    this.data.update((data) => (data ? { ...data, session } : data));
  }
  async load(path: string): Promise<CanvasPageData> {
    assertCanvasPath(path);
    if (this.disposed) throw new Error('Canvas store destroyed');
    // Also support construction inside a resolver, after NavigationStart fired.
    const current = this.router.getCurrentNavigation();
    if (current) this.beginNavigation(current.id, path);
    const navigation = this.navigation;
    const generation = ++this.generation;
    const sessionRevision = this.sessionRevision;
    this.refreshController?.abort();
    this.navigationController?.abort();
    this.navigationController = new AbortController();
    const result = await this.readPage(path, this.navigationController.signal);
    if (!this.disposed && generation === this.generation) {
      if (navigation && navigation === this.navigation)
        navigation.staged = { data: result, sessionRevision };
      else if (!navigation && !(result.page && isPageRedirect(result.page))) {
        this.path.set(path);
        this.commit(result, sessionRevision);
      }
    }
    return result;
  }
  private async readPage(
    path: string,
    signal: AbortSignal,
  ): Promise<CanvasPageData> {
    const key = makeStateKey<CanvasPageData>(`canvas:${path}`);
    let result: CanvasPageData;
    if (this.context?.canvas) {
      if (this.context.canvas.path !== path)
        throw new Error('SSR Canvas context does not match the router path');
      result = this.context.canvas.data;
      // Only the allowlisted data contract crosses hydration, never REQUEST_CONTEXT.
      this.transfer.set(key, result);
    } else if (this.transfer.hasKey(key)) {
      result = this.transfer.get(key, null as unknown as CanvasPageData);
      this.transfer.remove(key);
    } else {
      result = await this.getJson(
        `/api/canvas/page?path=${encodeURIComponent(path)}`,
        signal,
      );
    }
    return result;
  }
  private commit(result: CanvasPageData, sessionRevision: number): void {
    if (result.page && isPageRedirect(result.page)) return;
    // A renewal can complete while an older navigation response is in flight.
    // Do not roll its epoch back before the queued post-navigation refresh.
    const session = this.session();
    this.data.set(
      sessionRevision !== this.sessionRevision && session
        ? { ...result, session }
        : result,
    );
    this.error.set(null);
    this.head.apply(result.page ? result.page.head : { title: 'Not found' });
  }
  private async refreshCommittedPage(): Promise<void> {
    while (!this.disposed && this.navigation) await this.navigation.done;
    if (this.disposed || !this.data()) return;
    const generation = this.generation;
    const sessionRevision = this.sessionRevision;
    const path = this.path();
    this.refreshController = new AbortController();
    const result = await this.readPage(path, this.refreshController.signal);
    if (this.disposed || this.navigation || generation !== this.generation)
      return;
    if (result.page && isPageRedirect(result.page)) {
      const redirect = pageRedirect(result, this.router, this.document);
      if (redirect)
        await this.router.navigateByUrl(
          redirect.redirectTo,
          redirect.navigationBehaviorOptions,
        );
      return;
    }
    this.commit(result, sessionRevision);
  }
  async fetchEntity(
    options: CanvasEntityOptions,
  ): Promise<EntityResult | null> {
    if (this.disposed) throw new Error('Canvas store destroyed');
    if (this.context?.canvas) return this.context.canvas.fetchEntity(options);
    const query = new URLSearchParams({ type: options.type, id: options.id });
    if (options.viewMode) query.set('viewMode', options.viewMode);
    const controller = new AbortController();
    this.entityControllers.add(controller);
    try {
      return await this.getJson(
        `/api/canvas/entity?${query}`,
        controller.signal,
      );
    } finally {
      this.entityControllers.delete(controller);
    }
  }
  private async getJson<T>(path: string, signal?: AbortSignal): Promise<T> {
    const view = this.document.defaultView;
    if (!view)
      throw new Error(
        'Canvas SSR requires createCanvasHandler and REQUEST_CONTEXT',
      );
    const response = await view.fetch(new URL(path, view.location.origin), {
      credentials: 'same-origin',
      cache: 'no-store',
      signal,
    });
    if (!response.ok)
      throw new Error(`Canvas data request failed (${response.status})`);
    return response.json();
  }
}

/** Catch-all resolver. SSR redirects/status are handled before Angular rendering. */
export const canvasPageResolver: ResolveFn<
  CanvasPageData | RedirectCommand
> = async (_route, state) => {
  const store = inject(CanvasPageStore);
  const router = inject(Router);
  const document = inject(DOCUMENT);
  const result = await store.load(state.url.split('#')[0]);
  return pageRedirect(result, router, document) ?? result;
};

/** The resolver and refresh lane share identical redirect semantics. */
function pageRedirect(
  result: CanvasPageData,
  router: Router,
  document: Document,
): RedirectCommand | null {
  if (!result.page || !isPageRedirect(result.page)) return null;
  const target = result.page.redirect.url;
  if (result.page.redirect.external) {
    document.defaultView?.location.assign(target);
    return null;
  }
  return new RedirectCommand(router.parseUrl(target), { replaceUrl: true });
}

/** Own only Canvas head nodes. Angular manages body hydration independently. */
@Injectable({ providedIn: 'root' })
export class CanvasDocumentHead {
  private readonly document = inject(DOCUMENT);
  constructor() {
    const originalTitle = this.document.title;
    inject(DestroyRef).onDestroy(() => {
      this.document.head
        .querySelectorAll('[data-canvas-head]')
        .forEach((node) => node.remove());
      this.document.title = originalTitle;
    });
  }
  apply(head: PageHead): void {
    this.document.title = head.title;
    this.document.head
      .querySelectorAll('[data-canvas-head]')
      .forEach((node) => node.remove());
    for (const [tag, values] of [
      ['meta', head.meta],
      ['link', head.link],
    ] as const) {
      for (const attributes of values ?? []) {
        const node = this.document.createElement(tag);
        for (const [name, value] of Object.entries(attributes)) {
          if (
            /^(name|content|property|charset|rel|href|hreflang|type|media|sizes|title|crossorigin|referrerpolicy)$/i.test(
              name,
            )
          )
            node.setAttribute(name, value);
        }
        node.setAttribute('data-canvas-head', '');
        this.document.head.appendChild(node);
      }
    }
    for (const entry of head.script ?? []) {
      const script = this.document.createElement('script');
      script.type = 'application/ld+json';
      script.textContent = serializeJsonForHtml(entry.textContent);
      script.setAttribute('data-canvas-head', '');
      this.document.head.appendChild(script);
    }
  }
}

/** Hydration-safe ownership of the existing host/session/geometry machines. */
@Injectable({ providedIn: 'root' })
export class CanvasDraftSession {
  private readonly store = inject(CanvasPageStore);
  private readonly document = inject(DOCUMENT);
  private readonly ready = signal(false);
  readonly embedded = signal<boolean | null>(null);
  private readonly runtimeExpired = signal(false);
  readonly expired = computed(() =>
    this.ready()
      ? this.runtimeExpired()
      : this.store.session()?.enabled
        ? this.store.session()!.initialExpired
        : false,
  );
  readonly renewState = signal<DraftSessionRenewState>('idle');
  private machine?: DraftSession;
  // Navigation changes path only; it must not reset the session's renewal timer.
  private readonly epoch = computed(() => JSON.stringify(this.store.session()));
  constructor() {
    afterNextRender(() => {
      this.embedded.set(
        this.document.defaultView!.self !== this.document.defaultView!.top,
      );
      this.ready.set(true);
    });
    effect((onCleanup) => {
      const ready = this.ready();
      const session = JSON.parse(this.epoch()) as CanvasSession | null;
      if (!ready || !session?.enabled) {
        this.runtimeExpired.set(false);
        this.renewState.set('idle');
        return;
      }
      const embedded = this.embedded() === true;
      const machine = createDraftSession({
        ...session,
        embedded,
        path: untracked(this.store.path),
        onEvent: (event) => {
          if (event.type === 'refresh-requested') void this.store.refresh();
          else if (event.type === 'renewed') {
            if (event.tokenExpiresAt !== null)
              this.store.setSession({
                ...session,
                tokenExpiresAt: event.tokenExpiresAt,
                initialExpired: false,
              });
            void this.store.refresh();
          } else {
            this.runtimeExpired.set(machine.getState().expired);
            this.renewState.set(machine.getState().renewState);
          }
        },
      });
      this.machine = machine;
      this.runtimeExpired.set(machine.getState().expired);
      this.renewState.set(machine.getState().renewState);
      const height = createHeightReporter({
        editorOrigin: session.editorOrigin,
        embedded,
      });
      const geometry =
        embedded && session.editorOrigin
          ? createCanvasGeometryBridge({ editorOrigin: session.editorOrigin })
          : null;
      onCleanup(() => {
        machine.destroy();
        height.destroy();
        geometry?.destroy();
        this.machine = undefined;
      });
    });
    effect(() => {
      // Subscribe even before hydration has created the session machine.
      const path = this.store.path();
      this.machine?.setPath(path);
    });
  }
}
export function provideCanvas() {
  return makeEnvironmentProviders([
    provideAppInitializer(() => {
      inject(CanvasDraftSession);
    }),
  ]);
}
