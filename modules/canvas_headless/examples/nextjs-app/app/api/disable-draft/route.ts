import { disableDraftMode } from "@/lib/drupal-draft";

// POST, not GET: exiting draft mode changes state (it clears the session
// cookies), and a GET endpoint reached by links would be eligible for
// prefetching — a framework or browser prefetch could silently end the
// session. The banners submit a plain form here instead.
export async function POST(): Promise<Response> {
  return disableDraftMode();
}
