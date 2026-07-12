import { draftMode } from "next/headers";
import {
  getDraftData,
  getDrupalDraftConfig,
  isDraftSessionExpired,
} from "@/lib/drupal-draft";
import { DraftSessionClient } from "./draft-session-client";

/**
 * Server half of the draft session indicator: gathers the session state and
 * configuration and hands them to the client component, which owns the
 * banner, the renewal protocol, and the host messaging.
 */
export async function DraftIndicator() {
  const draft = await draftMode();
  if (!draft.isEnabled) {
    return null;
  }

  const draftData = await getDraftData();
  const config = getDrupalDraftConfig();

  return (
    <DraftSessionClient
      tokenExpiresAt={draftData?.tokenExpiresAt ?? null}
      initialExpired={!draftData || isDraftSessionExpired(draftData)}
      // From a signed assertion claim — Drupal states its own browser-facing
      // URL, so the app never has to be configured with one. Null when the
      // session cookie is gone, in which case there is nothing to renew.
      renewUrl={draftData?.renewUrl ?? null}
      embedderOrigins={config.embedderOrigins}
    />
  );
}
