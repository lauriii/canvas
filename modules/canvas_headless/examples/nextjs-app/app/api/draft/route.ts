import { enableDraftMode } from "@/lib/drupal-draft";
import type { NextRequest } from "next/server";

export async function GET(request: NextRequest): Promise<Response> {
  return enableDraftMode(request);
}
