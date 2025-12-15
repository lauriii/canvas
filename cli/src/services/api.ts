import axios from 'axios';

import { getConfig } from '../config.js';

import type { AxiosError, AxiosInstance } from 'axios';
import type { AssetLibrary, Component } from '../types/Component';

export interface ApiOptions {
  siteUrl: string;
  clientId: string;
  clientSecret: string;
  scope: string;
  userAgent?: string;
}

export class ApiService {
  private client: AxiosInstance;
  private readonly siteUrl: string;
  private readonly clientId: string;
  private readonly clientSecret: string;
  private readonly scope: string;
  private readonly userAgent: string;
  private accessToken: string | null = null;
  private refreshPromise: Promise<string> | null = null;

  private constructor(options: ApiOptions) {
    this.clientId = options.clientId;
    this.clientSecret = options.clientSecret;
    this.siteUrl = options.siteUrl;
    this.scope = options.scope;
    this.userAgent = options.userAgent || '';

    // Create the client without authorization headers by default
    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
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

    // Add response interceptor for automatic token refresh
    this.client.interceptors.response.use(
      (response) => response,
      async (error) => {
        const originalRequest = error.config;

        // Check if this is a 401 error and we haven't already retried this request
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
            // eslint-disable-next-line @typescript-eslint/no-unused-vars
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
   * Refresh the access token using client credentials.
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
        const response = await this.client.post(
          '/oauth/token',
          new URLSearchParams({
            grant_type: 'client_credentials',
            client_id: this.clientId,
            client_secret: this.clientSecret,
            scope: this.scope,
          }).toString(),
          {
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded',
            },
          },
        );

        this.accessToken = response.data.access_token;

        // Update the default authorization header
        this.client.defaults.headers.common['Authorization'] =
          `Bearer ${this.accessToken}`;

        return this.accessToken!;
      } catch (error) {
        // Use the existing error handling to maintain consistency with original behavior
        this.handleApiError(error);
        // This line should never be reached because handleApiError always throws
        throw new Error('Failed to refresh access token');
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
      throw new Error('Failed to list components');
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
      throw new Error(`Failed to create component: '${component.machineName}'`);
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
      throw new Error(`Component '${machineName}' not found`);
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
      throw new Error(`Failed to update component '${machineName}'`);
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
      throw new Error('Failed to get global asset library');
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
      throw new Error('Failed to update global asset library');
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
      let message =
        'Authentication failed. Please check your client ID and secret.';

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
  private handleApiError(error: unknown): void {
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

export function createApiService(): Promise<ApiService> {
  const config = getConfig();

  if (!config.siteUrl) {
    throw new Error(
      'Site URL is required. Set it in the CANVAS_SITE_URL environment variable or pass it with --site-url.',
    );
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

  return ApiService.create({
    siteUrl: config.siteUrl,
    clientId: config.clientId,
    clientSecret: config.clientSecret,
    scope: config.scope,
    userAgent: config.userAgent,
  });
}
