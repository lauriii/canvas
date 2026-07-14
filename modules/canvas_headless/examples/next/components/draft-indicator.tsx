import { draftMode } from "next/headers";
import {
  getDraftConfig,
  getDraftData,
  isDraftSessionExpired,
} from "@drupal-canvas/headless-next";
import { DraftBanner } from "./draft-banner";

/**
 * Server half of the draft session indicator: gathers the session state and
 * configuration and hands them to the client banner, which owns the
 * presentation and drives the SDK's session lifecycle.
 */
export async function DraftIndicator() {
  const draft = await draftMode();
  if (!draft.isEnabled) {
    return null;
  }

  const draftData = await getDraftData();
  const config = getDraftConfig();

  return (
    <DraftBanner
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
