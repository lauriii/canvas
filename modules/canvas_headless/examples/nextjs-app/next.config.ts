import type { NextConfig } from "next";

// Origins allowed to embed this app in an iframe, in addition to 'self'
// (which is always allowed). Configurable so a new embedding surface is a
// config change, not a code change. Space-separated list of origins; empty
// when unset, leaving a valid 'self'-only policy. ('none' cannot be combined
// with other sources, so it is not used as the fallback here.)
const extraFrameAncestors =
  process.env.DRAFT_ALLOWED_FRAME_ANCESTORS?.trim() ?? "";

const frameAncestors = ["'self'", extraFrameAncestors].filter(Boolean).join(" ");

const nextConfig: NextConfig = {
  async headers() {
    return [
      {
        source: "/:path*",
        headers: [
          {
            key: "Content-Security-Policy",
            value: `frame-ancestors ${frameAncestors}`,
          },
        ],
      },
    ];
  },
};

export default nextConfig;
