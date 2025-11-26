import axios from 'axios';

import { getConfig } from '../config.js';

import type { AxiosInstance } from 'axios';
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

  private handleApiError(error: unknown): void {
    const config = getConfig();
    const verbose = config.verbose;

    if (axios.isAxiosError(error)) {
      if (error.response) {
        const status = error.response.status;
        const data = error.response.data;

        // Do not output verbose logs for 404 responses. They are expected when
        // uploading newly created components.
        if (verbose && status !== 404) {
          console.error('API Error Details:');
          console.error(`- Status: ${status}`);
          console.error(`- URL: ${error.config?.url || 'unknown'}`);
          console.error(
            `- Method: ${error.config?.method?.toUpperCase() || 'unknown'}`,
          );
          console.error('- Response data:', JSON.stringify(data, null, 2));

          // Hide auth token in logs
          const safeHeaders = { ...error.config?.headers };
          if (safeHeaders && safeHeaders.Authorization) {
            safeHeaders.Authorization = 'Bearer ********';
          }
          console.error(
            '- Request headers:',
            JSON.stringify(safeHeaders, null, 2),
          );
        }

        if (status === 401) {
          throw new Error(
            'Authentication failed. Please check your client ID and secret.',
          );
        } else if (status === 403) {
          throw new Error(
            'You do not have permission to perform this action. Check your configured scope.',
          );
        } else if (
          data &&
          (data.error || data.error_description || data.hint)
        ) {
          throw new Error(
            `API Error (${status}): ${[
              data.error,
              data.error_description,
              data.hint,
            ]
              .filter(Boolean)
              .join(' | ')}`,
          );
        } else {
          throw new Error(`API Error (${status}): ${error.message}`);
        }
      } else if (error.request) {
        // Request was made but no response received
        if (verbose) {
          console.error('Network Error Details:');
          console.error(`- No response received from server`);
          console.error(`- URL: ${error.config?.url || 'unknown'}`);
          console.error(
            `- Method: ${error.config?.method?.toUpperCase() || 'unknown'}`,
          );

          // Hide auth token in logs
          const safeHeaders = { ...error.config?.headers };
          if (safeHeaders && safeHeaders.Authorization) {
            safeHeaders.Authorization = 'Bearer ********';
          }
          console.error(
            '- Request headers:',
            JSON.stringify(safeHeaders, null, 2),
          );

          // Check if this is a local development site
          if (this.siteUrl.includes('ddev.site')) {
            console.error('\nDDEV Local Development Troubleshooting Tips:');
            console.error('1. Make sure DDEV is running: try "ddev status"');
            console.error(
              '2. Try using HTTP instead of HTTPS: use "http://drupal-dev.ddev.site" as URL',
            );
            console.error('3. Check if the site is accessible in your browser');
            console.error(
              '4. For HTTPS issues: Try "ddev auth ssl" to set up local SSL certificates',
            );
          }
        }

        if (this.siteUrl.includes('ddev.site')) {
          throw new Error(
            `Network error: No response from DDEV site. Is DDEV running? Try using HTTP instead of HTTPS.`,
          );
        } else {
          throw new Error(
            `Network error: No response from server. Check your site URL and internet connection.`,
          );
        }
      } else {
        if (verbose) {
          console.error('Request Setup Error:');
          console.error(`- Error: ${error.message}`);
          console.error('- Stack:', error.stack);
        }
        throw new Error(`Request setup error: ${error.message}`);
      }
    } else if (error instanceof Error) {
      if (verbose) {
        console.error('General Error:');
        console.error(`- Message: ${error.message}`);
        console.error('- Stack:', error.stack);
      }
      throw new Error(`Network error: ${error.message}`);
    } else {
      if (verbose) {
        console.error('Unknown Error:', error);
      }
      throw new Error('Unknown API error occurred');
    }
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
