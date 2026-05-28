import { promises as fs } from 'node:fs';
import path from 'node:path';
import axios from 'axios';
import {
  defaultTokenEndpoint,
  fetchClientCredentialsToken,
  getTokenEntry,
  OauthError,
  refreshAccessToken,
  setTokenEntry,
} from '@drupal-canvas/auth';

import { BRAND_KIT_GLOBAL_ID, ensureConfig, getConfig } from '../config.js';

import type { AxiosError, AxiosInstance } from 'axios';
import type { CanvasComponentTree } from 'drupal-canvas/json-render-utils';
import type {
  AssetLibrary,
  BrandKit,
  BrandKitFontEntry,
  Component,
  UploadedArtifact,
  UploadedArtifactResult,
} from '../types/Component';
import type {
  ContentTemplate,
  ContentTemplateListItem,
} from '../types/ContentTemplate';
import type { Page, PageListItem } from '../types/Page';
import type { Region, RegionListItem } from '../types/Region';
import type { ConfigComponentTreePayload } from '../utils/component-tree-payload';

export interface ApiOptions {
  siteUrl: string;
  clientId: string;
  clientSecret: string;
  scope: string;
  userAgent?: string;
  accessToken?: string;
  refreshToken?: string;
  tokenEndpoint?: string;
}

export interface UploadedMedia<TInputsResolved = unknown> {
  id: number;
  uuid: string;
  inputs_resolved: TInputsResolved;
}

export class ApiService {
  private client: AxiosInstance;
  private readonly siteUrl: string;
  private readonly clientId: string;
  private readonly clientSecret: string;
  private readonly scope: string;
  private readonly userAgent: string;
  private accessToken: string | null = null;
  private refreshToken: string | null = null;
  private readonly tokenEndpoint: string | null;
  private refreshPromise: Promise<string> | null = null;

  private constructor(options: ApiOptions) {
    this.clientId = options.clientId;
    this.clientSecret = options.clientSecret;
    this.siteUrl = options.siteUrl;
    this.scope = options.scope;
    this.userAgent = options.userAgent || '';
    this.refreshToken = options.refreshToken ?? null;
    this.tokenEndpoint = options.tokenEndpoint ?? null;

    // Create the client without authorization headers by default
    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
      // CLI pushes make many short-lived requests; avoid reusing sockets that
      // may still have request-scoped listeners attached.
      Connection: 'close',
      // Add the CLI marker header to identify CLI requests
      'X-Canvas-CLI': '1',
    };

    // Add User-Agent header if provided
    if (this.userAgent) {
      headers['User-Agent'] = this.userAgent;
    }

    this.client = axios.create({
      baseURL: options.siteUrl,
      headers,
      // Allow longer timeout for uploads
      timeout: 30000,
      transformResponse: [
        (data) => {
          const forbidden = ['Fatal error'];

          // data comes as string, check it directly
          if (data.includes && forbidden.some((str) => data.includes(str))) {
            throw new Error(data);
          }

          // Parse JSON if it's a string (default axios behavior)
          try {
            return JSON.parse(data);
          } catch {
            return data;
          }
        },
      ],
    });

    // When a pre-issued access token is provided, use it directly without OAuth
    if (options.accessToken) {
      this.accessToken = options.accessToken;
      this.client.defaults.headers.common['Authorization'] =
        `Bearer ${options.accessToken}`;
    }

    // Add response interceptor for automatic token refresh
    this.client.interceptors.response.use(
      (response) => response,
      async (error) => {
        const originalRequest = error.config;

        // Check if this is a 401 error and we haven't already retried this request.
        if (
          error.response?.status === 401 &&
          !originalRequest._retry &&
          !originalRequest.url?.includes('/oauth/token')
        ) {
          originalRequest._retry = true;

          try {
            // Refresh the access token
            const newToken = await this.refreshAccessToken();

            // Update the authorization header for the retry
            originalRequest.headers.Authorization = `Bearer ${newToken}`;

            // Retry the original request
            return this.client(originalRequest);
          } catch (refreshError) {
            // Token refresh failed, reject with original error
            return Promise.reject(error);
          }
        }

        return Promise.reject(error);
      },
    );

    // Add request interceptor for lazy token loading
    this.client.interceptors.request.use(
      async (config) => {
        // If we don't have a token and this isn't the token endpoint, get one
        if (!this.accessToken && !config.url?.includes('/oauth/token')) {
          try {
            const token = await this.refreshAccessToken();
            config.headers.Authorization = `Bearer ${token}`;
          } catch (error) {
            return Promise.reject(error);
          }
        }
        return config;
      },
      (error) => {
        return Promise.reject(error);
      },
    );
  }

  /**
   * Refresh the access token.
   * Supports both the refresh_token grant (user tokens from auth:login) and
   * the client_credentials grant (service accounts).
   * Handles concurrent refresh attempts by reusing the same promise.
   */
  private async refreshAccessToken(): Promise<string> {
    // If a refresh is already in progress, wait for it
    if (this.refreshPromise) {
      return this.refreshPromise;
    }

    // Start a new refresh - create the promise immediately so concurrent calls share it
    this.refreshPromise = (async (): Promise<string> => {
      try {
        // User token: use refresh_token grant.
        if (this.refreshToken && this.tokenEndpoint) {
          let result;
          try {
            result = await refreshAccessToken({
              tokenEndpoint: this.tokenEndpoint,
              refreshToken: this.refreshToken,
              clientId: this.clientId,
            });
          } catch (refreshError) {
            // Refresh-token grant failures are almost always recoverable only
            // by re-authenticating, so prefer a CLI-specific hint over the
            // generic auth-error path. Surface the OAuth server's
            // `error_description` (if any) for diagnostics.
            if (refreshError instanceof OauthError) {
              throw new Error(
                refreshError.errorDescription ??
                  refreshError.errorCode ??
                  'Session expired. Run `canvas login` to re-authenticate.',
              );
            }
            throw refreshError;
          }

          this.accessToken = result.accessToken;
          // simple_oauth rotates refresh tokens by default; persist whichever
          // refresh token we should use next so future refreshes don't trip
          // on a stale value.
          this.refreshToken = result.refreshToken ?? this.refreshToken;
          this.client.defaults.headers.common['Authorization'] =
            `Bearer ${this.accessToken}`;

          // Persist updated tokens back to the store so subsequent CLI runs
          // (and the workbench dev server) see the latest credentials.
          const entry = getTokenEntry(this.siteUrl);
          if (entry) {
            setTokenEntry(this.siteUrl, {
              ...entry,
              accessToken: this.accessToken,
              refreshToken: this.refreshToken ?? entry.refreshToken,
              expiresAt: result.expiresAt,
            });
          }

          return this.accessToken;
        }

        // Service account: use client_credentials grant.
        if (!this.clientId || !this.clientSecret) {
          throw new Error(
            'No client credentials configured; cannot refresh access token.',
          );
        }

        const result = await fetchClientCredentialsToken({
          tokenEndpoint: defaultTokenEndpoint(this.siteUrl),
          clientId: this.clientId,
          clientSecret: this.clientSecret,
          scope: this.scope,
        });

        this.accessToken = result.accessToken;

        // Update the default authorization header
        this.client.defaults.headers.common['Authorization'] =
          `Bearer ${this.accessToken}`;

        return this.accessToken;
      } catch (error) {
        this.handleApiError(error);
      }
    })();

    try {
      return await this.refreshPromise;
    } finally {
      this.refreshPromise = null;
    }
  }

  public static async create(options: ApiOptions): Promise<ApiService> {
    return new ApiService(options);
  }

  getAccessToken(): string | null {
    return this.accessToken;
  }

  /**
   * List all components.
   */
  async listComponents(): Promise<Record<string, Component>> {
    try {
      const response = await this.client.get(
        '/canvas/api/v0/config/js_component',
      );
      return response.data;
    } catch (error) {
      this.handleApiError(error);
    }
  }

  /**
   * Fetch active version hashes for all Component config entities.
   */
  async listComponentVersions(): Promise<Map<string, string>> {
    try {
      const response = await this.client.get('/canvas/api/v0/config/component');
      const versions = new Map<string, string>();
      for (const [id, comp] of Object.entries(
        response.data as Record<string, { version?: string }>,
      )) {
        if (comp.version) {
          versions.set(id, comp.version);
        }
      }
      return versions;
    } catch (error) {
      this.handleApiError(error);
    }
  }

  /**
   * Create a new component in Canvas.
   */
  async createComponent(
    component: Component,
    raw: boolean = false,
  ): Promise<Component> {
    try {
      const response = await this.client.post(
        '/canvas/api/v0/config/js_component',
        component,
      );
      return response.data;
    } catch (error) {
      // If raw is true (not the default), rethrow so the caller can handle it.
      if (raw) {
        throw error;
      }
      this.handleApiError(error);
    }
  }

  /**
   * Get a specific component
   */
  async getComponent(machineName: string): Promise<Component> {
    try {
      const response = await this.client.get(
        `/canvas/api/v0/config/js_component/${machineName}`,
      );
      return response.data;
    } catch (error) {
      this.handleApiError(error);
    }
  }

  /**
   * Update an existing component
   */
  async updateComponent(
    machineName: string,
    component: Partial<Component>,
  ): Promise<Component> {
    try {
      const response = await this.client.patch(
        `/canvas/api/v0/config/js_component/${machineName}`,
        component,
      );
      return response.data;
    } catch (error) {
      this.handleApiError(error);
    }
  }

  /**
   * Delete a component
   */
  async deleteComponent(machineName: string): Promise<void> {
    try {
      await this.client.delete(
        `/canvas/api/v0/config/js_component/${machineName}`,
      );
    } catch (error) {
      this.handleApiError(error);
    }
  }

  /**
   * Get global asset library.
   */
  async getGlobalAssetLibrary(): Promise<AssetLibrary> {
    try {
      const response = await this.client.get(
        '/canvas/api/v0/config/asset_library/global',
      );
      return response.data;
    } catch (error) {
      this.handleApiError(error);
    }
  }

  /**
   * List all pages, paginating through all results.
   */
  async listPages(): Promise<Record<string, PageListItem>> {
    try {
      const pages: Record<string, PageListItem> = {};
      let nextUrl: string | null = '/canvas/api/v0/content/canvas_page';

      while (nextUrl !== null) {
        const response = await this.client.get(nextUrl);
        const body = response.data as {
          data: PageListItem[];
          links?: Record<string, { href: string }>;
        };

        for (const page of body.data) {
          pages[page.id] = page;
        }

        nextUrl = body.links?.next?.href ?? null;
      }

      return pages;
    } catch (error) {
      this.handleApiError(error);
    }
  }

  /**
   * Get a single page with its component tree.
   */
  async getPage(id: string | number): Promise<Page> {
    try {
      const response = await this.client.get(
        `/canvas/api/v0/content/canvas_page/${id}`,
      );
      return response.data;
    } catch (error) {
      this.handleApiError(error);
    }
  }

  /**
   * Create a new page.
   */
  async createPage(page: {
    title: string;
    description: string;
    status: boolean;
    path: string;
    components: CanvasComponentTree;
  }): Promise<Page> {
    try {
      const response = await this.client.post(
        '/canvas/api/v0/content/canvas_page',
        page,
      );
      return response.data;
    } catch (error) {
      this.handleApiError(error);
    }
  }

  /**
   * Update an existing page.
   */
  async updatePage(
    id: string | number,
    page: {
      title: string;
      description: string;
      status: boolean;
      path: string;
      components: CanvasComponentTree;
    },
  ): Promise<Page> {
    try {
      const response = await this.client.patch(
        `/canvas/api/v0/content/canvas_page/${id}`,
        page,
      );
      return response.data;
    } catch (error) {
      this.handleApiError(error);
    }
  }

  /**
   * List all content templates.
   *
   * The `/canvas/api/v0/config/content_template` endpoint returns a hierarchical
   * representation. We also try the flat listing at
   * `/canvas/api/v0/config/{entity_type_id}` (which is the generic config list
   * endpoint) to keep the CLI flow symmetrical with components.
   */
  async listContentTemplates(): Promise<
    Record<string, ContentTemplateListItem>
  > {
    try {
      const response = await this.client.get(
        '/canvas/api/v0/config/content_template',
      );
      const data = response.data as unknown;

      if (!data || typeof data !== 'object') {
        return {};
      }

      const result: Record<string, ContentTemplateListItem> = {};
      // The endpoint returns either a flat map keyed by id, or a hierarchical
      // representation grouped by entity type / bundle. Flatten both shapes.
      const visit = (value: unknown): void => {
        if (!value || typeof value !== 'object') {
          return;
        }
        const record = value as Record<string, unknown>;
        if (typeof record.id === 'string' && record.id.length > 0) {
          result[record.id] = record as unknown as ContentTemplateListItem;
          return;
        }
        for (const nested of Object.values(record)) {
          visit(nested);
        }
      };
      visit(data);
      return result;
    } catch (error) {
      this.handleApiError(error);
    }
  }

  /**
   * Get a single content template with its component tree.
   */
  async getContentTemplate(id: string): Promise<ContentTemplate> {
    try {
      const response = await this.client.get(
        `/canvas/api/v0/config/content_template/${encodeURIComponent(id)}`,
      );
      return response.data;
    } catch (error) {
      this.handleApiError(error);
    }
  }

  /**
   * Create a new content template.
   */
  async createContentTemplate(template: {
    label: string;
    entityType: string;
    bundle: string;
    viewMode: string;
    status: boolean;
    component_tree: ConfigComponentTreePayload;
  }): Promise<ContentTemplate> {
    try {
      const response = await this.client.post(
        '/canvas/api/v0/config/content_template',
        template,
      );
      return response.data;
    } catch (error) {
      this.handleApiError(error);
    }
  }

  /**
   * Update an existing content template.
   */
  async updateContentTemplate(
    id: string,
    template: {
      label?: string;
      status?: boolean;
      component_tree?: ConfigComponentTreePayload;
    },
  ): Promise<ContentTemplate> {
    try {
      const response = await this.client.patch(
        `/canvas/api/v0/config/content_template/${encodeURIComponent(id)}`,
        template,
      );
      return response.data;
    } catch (error) {
      this.handleApiError(error);
    }
  }

  /**
   * Delete a content template.
   */
  async deleteContentTemplate(id: string): Promise<void> {
    try {
      await this.client.delete(
        `/canvas/api/v0/config/content_template/${encodeURIComponent(id)}`,
      );
    } catch (error) {
      this.handleApiError(error);
    }
  }

  /**
   * Fetch preview entity suggestions for a content template's entity
   * type and bundle. Returns up to 10 candidate entities.
   */
  async fetchPreviewEntitySuggestions(
    entityTypeId: string,
    bundle: string,
  ): Promise<Array<{ id: string; label: string }>> {
    try {
      const response = await this.client.get(
        `/canvas/api/v0/ui/content_template/suggestions/preview/${encodeURIComponent(entityTypeId)}/${encodeURIComponent(bundle)}`,
      );
      const data = response.data as unknown;
      const items: unknown[] = Array.isArray(data)
        ? data
        : data && typeof data === 'object'
          ? Object.values(data as Record<string, unknown>)
          : [];
      return items
        .map((item) => {
          if (!item || typeof item !== 'object') return null;
          const record = item as Record<string, unknown>;
          const id =
            typeof record.id === 'string' || typeof record.id === 'number'
              ? String(record.id)
              : null;
          if (!id) return null;
          const label =
            typeof record.label === 'string' ? record.label : '(untitled)';
          return { id, label };
        })
        .filter((item): item is { id: string; label: string } => item !== null);
    } catch {
      return [];
    }
  }

  /**
   * POST a draft content template to the server for validation/resolution.
   * Returns the resolved model, or throws on 4xx/5xx errors.
   */
  async validateContentTemplateDraft(
    entityTypeId: string,
    previewEntityId: string,
    bundle: string,
    viewMode: string,
    componentTree: CanvasComponentTree,
  ): Promise<{ model: Record<string, unknown> }> {
    try {
      const response = await this.client.post(
        `/canvas/api/v0/layout-content-template-draft/${encodeURIComponent(entityTypeId)}/${encodeURIComponent(previewEntityId)}?_format=json`,
        {
          bundle,
          viewMode,
          component_tree: componentTree,
        },
      );
      return response.data;
    } catch (error) {
      this.handleApiError(error);
    }
  }

  async listViewModes(): Promise<
    Record<
      string,
      Record<string, Record<string, { label: string; hasTemplate: boolean }>>
    >
  > {
    try {
      const response = await this.client.get(
        '/canvas/api/v0/ui/content_template/view_modes/node',
      );
      return response.data;
    } catch (error) {
      this.handleApiError(error);
    }
  }

  async fetchPropSourceSuggestions(
    entityTypeId: string,
    bundle: string,
    componentId: string,
  ): Promise<Record<string, unknown[]>> {
    try {
      const response = await this.client.get(
        `/canvas/api/v0/ui/content_template/suggestions/prop-sources/${encodeURIComponent(entityTypeId)}/${encodeURIComponent(bundle)}/${encodeURIComponent(componentId)}`,
      );
      return response.data;
    } catch (error) {
      this.handleApiError(error);
    }
  }

  /**
   * List all regions.
   */
  async listRegions(): Promise<Record<string, RegionListItem>> {
    try {
      const response = await this.client.get(
        '/canvas/api/v0/config/page_region',
      );
      return response.data;
    } catch (error) {
      this.handleApiError(error);
    }
  }

  /**
   * Get a single region.
   */
  async getRegion(id: string): Promise<Region> {
    try {
      const response = await this.client.get(
        `/canvas/api/v0/config/page_region/${id}`,
      );
      return response.data;
    } catch (error) {
      this.handleApiError(error);
    }
  }

  /**
   * Create a new region.
   */
  async createRegion(region: {
    theme?: string;
    region: string;
    status: boolean;
    component_tree: ConfigComponentTreePayload;
  }): Promise<Region> {
    try {
      const response = await this.client.post(
        '/canvas/api/v0/config/page_region',
        region,
      );
      return response.data;
    } catch (error) {
      this.handleApiError(error);
    }
  }

  /**
   * Update an existing region.
   */
  async updateRegion(
    id: string,
    region: {
      status?: boolean;
      component_tree?: ConfigComponentTreePayload;
    },
  ): Promise<Region> {
    try {
      const response = await this.client.patch(
        `/canvas/api/v0/config/page_region/${id}`,
        region,
      );
      return response.data;
    } catch (error) {
      this.handleApiError(error);
    }
  }

  /**
   * Delete a region.
   */
  async deleteRegion(id: string): Promise<void> {
    try {
      await this.client.delete(`/canvas/api/v0/config/page_region/${id}`);
    } catch (error) {
      this.handleApiError(error);
    }
  }

  /**
   * Update global asset library.
   */
  async updateGlobalAssetLibrary(
    assetLibrary: Partial<AssetLibrary>,
  ): Promise<AssetLibrary> {
    try {
      const response = await this.client.patch(
        '/canvas/api/v0/config/asset_library/global',
        assetLibrary,
      );
      return response.data;
    } catch (error) {
      this.handleApiError(error);
    }
  }

  /**
   * Upload a single build artifact file.
   */
  async uploadArtifact(
    filename: string,
    fileBuffer: Buffer,
  ): Promise<UploadedArtifactResult> {
    try {
      const response = await this.client.post(
        '/canvas/api/v0/artifacts/upload',
        fileBuffer,
        {
          headers: {
            'Content-Type': 'application/octet-stream',
            'Content-Disposition': `file; filename="${filename}"`,
          },
          maxBodyLength: Infinity,
          maxContentLength: Infinity,
        },
      );
      return response.data;
    } catch (error) {
      this.handleApiError(error);
    }
  }

  /**
   * Upload a file and create a Drupal media entity.
   */
  async uploadMedia<TInputsResolved = unknown>(options: {
    mediaType: string;
    filename: string;
    fileBuffer: Buffer;
    data?: Record<string, string | Blob>;
  }): Promise<UploadedMedia<TInputsResolved>> {
    try {
      const formData = new FormData();
      formData.append(
        'file',
        new Blob([options.fileBuffer as unknown as BlobPart]),
        options.filename,
      );

      for (const [key, value] of Object.entries(options.data ?? {})) {
        formData.append(key, value);
      }

      const response = await this.client.post(
        `/canvas/api/v0/media/${encodeURIComponent(options.mediaType)}/upload`,
        formData,
        {
          headers: {
            'Content-Type': 'multipart/form-data',
          },
          maxBodyLength: Infinity,
          maxContentLength: Infinity,
        },
      );
      return response.data;
    } catch (error) {
      this.handleApiError(error);
    }
  }

  /**
   * Sync the build manifest after all files are uploaded.
   *
   * Updates the global asset library's manifest properties (imports, assets, shared)
   * via the generic config PATCH endpoint.
   */
  async syncManifest(manifest: {
    vendor: UploadedArtifact[];
    local: UploadedArtifact[];
    shared: UploadedArtifact[];
  }): Promise<{
    manifest: {
      vendor: UploadedArtifact[];
      local: UploadedArtifact[];
      shared: UploadedArtifact[];
    };
  }> {
    try {
      // Map CLI manifest structure to AssetLibrary entity structure:
      // - vendor -> imports
      // - local -> assets
      // - shared -> shared
      const response = await this.client.patch(
        '/canvas/api/v0/config/asset_library/global',
        {
          imports: manifest.vendor,
          assets: manifest.local,
          shared: manifest.shared,
        },
      );

      // Map response back to CLI format for backward compatibility
      return {
        manifest: {
          vendor: response.data.imports || [],
          local: response.data.assets || [],
          shared: response.data.shared || [],
        },
      };
    } catch (error) {
      this.handleApiError(error);
    }
  }

  /**
   * Signals that a CLI push has started (best-effort).
   */
  async signalPushStart(): Promise<void> {
    try {
      await this.client.post('/canvas/api/v0/push/start');
    } catch {
      // Best-effort: signal errors are safe to ignore since they don't affect the push data.
    }
  }

  /**
   * Signals that a CLI push completed successfully (best-effort).
   */
  async signalPushComplete(): Promise<void> {
    try {
      await this.client.post('/canvas/api/v0/push/complete');
    } catch {
      // Best-effort: signal errors are safe to ignore since they don't affect the push data.
    }
  }

  /**
   * Signals that a CLI push failed (best-effort).
   */
  async signalPushFail(message?: string): Promise<void> {
    try {
      await this.client.post(
        '/canvas/api/v0/push/fail',
        message ? { message } : undefined,
      );
    } catch {
      // Best-effort: signal errors are safe to ignore since they don't affect the push data.
    }
  }

  /**
   * Upload a font file for Brand Kit (same endpoint as UI: artifacts/upload).
   * Returns uri and fid for building a Brand Kit font entry.
   * When filename is provided (e.g. slugified), it is used for the upload; otherwise the path basename is used.
   */
  async uploadFont(
    filePath: string,
    filename?: string,
  ): Promise<UploadedArtifactResult> {
    const buffer = await fs.readFile(filePath);
    const name = filename ?? path.basename(filePath);
    return this.uploadArtifact(name, buffer);
  }

  /**
   * Download a file by URL using the authenticated client.
   * URL may be relative (resolved against siteUrl) or absolute.
   */
  async downloadFile(url: string): Promise<Buffer> {
    try {
      const response = await this.client.get(url, {
        responseType: 'arraybuffer',
        transformResponse: [(data: unknown) => data],
      });
      return Buffer.from(response.data as ArrayBuffer);
    } catch (error) {
      this.handleApiError(error);
    }
  }

  /**
   * Get a Brand Kit config entity by id.
   */
  async getBrandKit(id: string = BRAND_KIT_GLOBAL_ID): Promise<BrandKit> {
    try {
      const response = await this.client.get(
        `/canvas/api/v0/config/brand_kit/${id}`,
      );
      return response.data;
    } catch (error) {
      this.handleApiError(error);
    }
  }

  /**
   * Update the global Brand Kit (replace the fonts array).
   */
  async updateBrandKit(data: {
    fonts: BrandKitFontEntry[];
  }): Promise<BrandKit> {
    try {
      const response = await this.client.patch(
        `/canvas/api/v0/config/brand_kit/${BRAND_KIT_GLOBAL_ID}`,
        data,
      );
      return response.data;
    } catch (error) {
      this.handleApiError(error);
    }
  }

  /**
   * Parse Canvas API error responses into user-friendly messages.
   * Handles both structured validation errors and simple string errors.
   */
  private parseCanvasErrors(data: unknown): string[] {
    if (
      data &&
      typeof data === 'object' &&
      'errors' in data &&
      Array.isArray(data.errors)
    ) {
      return data.errors
        .map((err: unknown) => {
          // Handle simple string errors (e.g., 409 conflicts)
          if (typeof err === 'string') {
            return err.trim();
          }

          // Handle structured errors with detail field
          if (err && typeof err === 'object' && 'detail' in err) {
            let message =
              typeof err.detail === 'string' ? err.detail : String(err.detail);

            // Strip HTML tags and decode HTML entities
            message = message
              .replace(/<[^>]*>/g, '')
              .replace(/&quot;/g, '"')
              .replace(/&#039;/g, "'")
              .replace(/&lt;/g, '<')
              .replace(/&gt;/g, '>')
              .replace(/&amp;/g, '&')
              .trim();

            // Skip empty messages
            if (!message) {
              return '';
            }

            // Add source pointer context if available and meaningful
            if (
              'source' in err &&
              err.source &&
              typeof err.source === 'object' &&
              'pointer' in err.source &&
              typeof err.source.pointer === 'string' &&
              err.source.pointer !== ''
            ) {
              message = `[${err.source.pointer}] ${message}`;
            }

            return message;
          }

          return '';
        })
        .filter((msg: string) => msg !== '');
    }
    return [];
  }

  /**
   * Throws an appropriate error based on the API response.
   */
  private throwApiError(
    status: number,
    data: unknown,
    error: AxiosError,
    canvasErrors: string[],
  ): never {
    // Canvas API structured errors (validation, conflicts, etc.)
    if (canvasErrors.length > 0) {
      const errorList = canvasErrors.join('\n\n').trim();
      if (errorList) {
        throw new Error(errorList);
      }
    }

    // 401 Authentication errors
    if (status === 401) {
      let message = !this.clientId
        ? 'Authentication failed. Please check your access token (CANVAS_ACCESS_TOKEN).'
        : 'Authentication failed. Please check your client ID and secret.';

      // Include error_description if available
      if (
        data &&
        typeof data === 'object' &&
        'error_description' in data &&
        typeof data.error_description === 'string'
      ) {
        message = `Authentication Error: ${data.error_description}\n\n${message}`;
      }

      throw new Error(message);
    }

    // 403 Forbidden errors
    if (status === 403) {
      throw new Error(
        'You do not have permission to perform this action. Check your configured scope.',
      );
    }

    // 404 Not Found errors with troubleshooting tips
    if (status === 404) {
      const url = error.config?.url || 'unknown';
      let message = `API endpoint not found: ${url}\n\n`;

      if (this.siteUrl.includes('ddev.site')) {
        message += 'Possible causes:\n';
        message += '  • DDEV is not running (run: ddev start)\n';
        message +=
          '  • Canvas module is not enabled (run: ddev drush en canvas -y)\n';
        message += '  • Site URL is incorrect';
      } else {
        message += 'Possible causes:\n';
        message += '  • Canvas module is not enabled\n';
        message += '  • Site URL is incorrect\n';
        message += '  • Server is not responding correctly';
      }

      throw new Error(message);
    }

    // Simple message format (e.g., 500 errors)
    if (
      data &&
      typeof data === 'object' &&
      'message' in data &&
      typeof data.message === 'string'
    ) {
      throw new Error(data.message);
    }

    // OAuth-style errors
    if (data && typeof data === 'object') {
      const errorParts: string[] = [];
      if ('error' in data && typeof data.error === 'string') {
        errorParts.push(data.error);
      }
      if (
        'error_description' in data &&
        typeof data.error_description === 'string'
      ) {
        errorParts.push(data.error_description);
      }
      if ('hint' in data && typeof data.hint === 'string') {
        errorParts.push(data.hint);
      }
      if (errorParts.length > 0) {
        throw new Error(`API Error (${status}): ${errorParts.join(' | ')}`);
      }
    }

    // Fallback generic error with details
    const url = error.config?.url || 'unknown';
    const method = error.config?.method?.toUpperCase() || 'unknown';
    throw new Error(
      `API Error (${status}): ${error.message}\n\nURL: ${url}\nMethod: ${method}`,
    );
  }

  /**
   * Handles network errors (no response from server).
   */
  private handleNetworkError(): never {
    let message = `No response from: ${this.siteUrl}\n\n`;

    if (this.siteUrl.includes('ddev.site')) {
      message += 'Troubleshooting tips:\n';
      message += '  • Check if DDEV is running: ddev status\n';
      message += '  • Try HTTP instead of HTTPS\n';
      message += '  • Verify site is accessible in browser\n';
      message += '  • For HTTPS issues, try: ddev auth ssl';
    } else {
      message += 'Check your site URL and internet connection.';
    }

    throw new Error(message);
  }

  /**
   * Main error handler for API requests.
   */
  private handleApiError(error: unknown): never {
    if (error instanceof OauthError) {
      if (error.status === undefined) {
        this.handleNetworkError();
      }
      const canvasErrors = this.parseCanvasErrors(error.body);
      this.throwApiError(
        error.status,
        error.body,
        {
          config: { url: '/oauth/token', method: 'post' },
          message: error.message,
        } as AxiosError,
        canvasErrors,
      );
    }

    if (!axios.isAxiosError(error)) {
      if (error instanceof Error) {
        throw error;
      }
      throw new Error('Unknown API error occurred');
    }

    // Handle response errors
    if (error.response) {
      const { status, data } = error.response;
      const canvasErrors = this.parseCanvasErrors(data);

      this.throwApiError(status, data, error, canvasErrors);
    }

    // Handle network errors (no response)
    if (error.request) {
      this.handleNetworkError();
    }

    // Handle request setup errors
    throw new Error(`Request setup error: ${error.message}`);
  }
}

export async function createApiService(): Promise<ApiService> {
  const config = getConfig();

  if (!config.siteUrl) {
    throw new Error(
      'Site URL is required. Set it in the CANVAS_SITE_URL environment variable or pass it with --site-url.',
    );
  }

  const accessToken = process.env.CANVAS_ACCESS_TOKEN;

  if (accessToken) {
    return await ApiService.create({
      siteUrl: config.siteUrl,
      clientId: '',
      clientSecret: '',
      scope: '',
      userAgent: config.userAgent,
      accessToken,
    });
  }

  // Check for a stored user token from `canvas login`.
  const tokenEntry = getTokenEntry(config.siteUrl);
  if (tokenEntry) {
    return await ApiService.create({
      siteUrl: config.siteUrl,
      clientId: tokenEntry.clientId,
      clientSecret: '',
      scope: '',
      userAgent: config.userAgent,
      accessToken: tokenEntry.accessToken,
      refreshToken: tokenEntry.refreshToken,
      tokenEndpoint: tokenEntry.tokenEndpoint,
    });
  }

  if (!config.clientId) {
    throw new Error(
      'Client ID is required. Set it in the CANVAS_CLIENT_ID environment variable or pass it with --client-id.',
    );
  }

  if (!config.clientSecret) {
    throw new Error(
      'Client secret is required. Set it in the CANVAS_CLIENT_SECRET environment variable or pass it with --client-secret.',
    );
  }

  if (!config.scope) {
    throw new Error(
      'Scope is required. Set it in the CANVAS_SCOPE environment variable or pass it with --scope.',
    );
  }

  return await ApiService.create({
    siteUrl: config.siteUrl,
    clientId: config.clientId,
    clientSecret: config.clientSecret,
    scope: config.scope,
    userAgent: config.userAgent,
  });
}

/**
 * Returns true when the user has a stored OAuth token for the given site URL
 * or a pre-issued access token in the environment.
 * Used by commands to skip prompting for client credentials when not needed.
 */
export function isUserAuthenticated(siteUrl: string): boolean {
  if (process.env.CANVAS_ACCESS_TOKEN) return true;
  return getTokenEntry(siteUrl) !== null;
}

/**
 * Ensures siteUrl is configured, then prompts for client credentials only
 * when the user has no stored OAuth token and no CANVAS_ACCESS_TOKEN set.
 */
export async function ensureAuthConfig(): Promise<void> {
  await ensureConfig(['siteUrl']);
  if (!isUserAuthenticated(getConfig().siteUrl!)) {
    await ensureConfig(['clientId', 'clientSecret', 'scope']);
  }
}
