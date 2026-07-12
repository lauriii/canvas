import {
  JsonApiClient,
  type GetOptions,
  type JsonApiClientOptions,
} from "@drupal-api-client/json-api-client";
import { getDrupalDraftConfig } from "./config";
import type { DraftData } from "./draft-data";
import { getSessionToken } from "./token";

/**
 * A client for public content: unauthenticated, sees only published content.
 */
export function getPublicClient(): JsonApiClient {
  const config = getDrupalDraftConfig();
  return new JsonApiClient(config.baseUrl);
}

/**
 * A JsonApiClient that transparently reads draft content at the requested
 * resource version, so app code needs no draft-specific fetching logic.
 *
 * - getResource() asks for the resource version (working copy) unless the
 *   caller already pinned one.
 * - getCollection() hydrates each item with its working copy. Core JSON:API
 *   accepts `resourceVersion` on individual resources only — collections
 *   always return default revisions, which would hide forward revisions.
 *   The hydration is an N+1 per-item fan-out, run in parallel; acceptable
 *   for editor-facing preview traffic. A per-item fetch that fails falls
 *   back to the item as the collection returned it (default revision), so
 *   a transient error shows the published title rather than breaking the
 *   listing — resilience over alarms, chosen for preview traffic. Skipped
 *   for rawResponse requests, which promise the unmodified Response object.
 */
class DraftJsonApiClient extends JsonApiClient {
  constructor(
    baseUrl: string,
    options: JsonApiClientOptions,
    private readonly resourceVersion: string,
  ) {
    super(baseUrl, options);
  }

  private withResourceVersion(options?: GetOptions): GetOptions {
    if (options?.queryString?.includes("resourceVersion=")) {
      return options;
    }
    const version = `resourceVersion=${encodeURIComponent(this.resourceVersion)}`;
    return {
      ...options,
      queryString: options?.queryString
        ? `${options.queryString}&${version}`
        : version,
    };
  }

  override async getResource<T>(
    type: string,
    resourceId: string,
    options?: GetOptions,
  ) {
    return super.getResource<T>(
      type,
      resourceId,
      options?.rawResponse ? options : this.withResourceVersion(options),
    );
  }

  override async getCollection<T>(type: string, options?: GetOptions) {
    const document = await super.getCollection<T>(type, options);
    if (options?.rawResponse) {
      return document;
    }
    const data = (document as { data?: Array<{ id: string }> })?.data;
    if (!Array.isArray(data)) {
      return document;
    }
    const hydrated = await Promise.all(
      data.map(async (item) => {
        try {
          const workingCopy = (await this.getResource(type, item.id)) as {
            data?: unknown;
          };
          return workingCopy?.data ?? item;
        } catch {
          return item;
        }
      }),
    );
    return { ...document, data: hydrated } as typeof document;
  }
}

/**
 * A client for draft content, authenticated with the session's user-bound
 * access token (minted from the preview assertion, carrying the initiating
 * editor's own permissions). Returns working copies transparently; see
 * DraftJsonApiClient.
 *
 * Throws when the session has expired — callers are expected to check
 * isDraftSessionExpired() first and fall back to the public client with a
 * visible indicator instead of silently downgrading.
 */
export function getDraftClient(draftData: DraftData): JsonApiClient {
  const config = getDrupalDraftConfig();
  const token = getSessionToken(draftData);
  if (!token) {
    throw new Error("The draft preview session has expired.");
  }
  return new DraftJsonApiClient(
    config.baseUrl,
    {
      authentication: {
        type: "Custom",
        credentials: { value: `${token.tokenType} ${token.value}` },
      },
    },
    draftData.resourceVersion,
  );
}
