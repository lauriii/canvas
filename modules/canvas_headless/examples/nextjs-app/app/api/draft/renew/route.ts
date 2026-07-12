import { renewDraftSession } from "@/lib/drupal-draft";

export async function POST(request: Request): Promise<Response> {
  return renewDraftSession(request);
}
