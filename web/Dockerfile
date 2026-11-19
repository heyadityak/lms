# syntax=docker/dockerfile:1
# Next.js frontend + BFF (D-43, D-45). Build context: web/. Runtime config is env only.
ARG NODE_IMAGE=node:24.22.3-alpine

FROM ${NODE_IMAGE} AS deps
WORKDIR /app
COPY package.json package-lock.json ./
RUN --mount=type=cache,target=/root/.npm npm ci --no-audit --no-fund

FROM ${NODE_IMAGE} AS build
WORKDIR /app
ENV NEXT_TELEMETRY_DISABLED=1
COPY --from=deps /app/node_modules ./node_modules
COPY . .
RUN npm run build \
 # The one-off auth migrate job must resolve its imports from the standalone tree (D-45 (h)).
 && cd .next/standalone \
 && node --input-type=module -e "await import('better-auth/db/migration'); await import('./src/auth/options.mjs')"

FROM ${NODE_IMAGE} AS runtime
WORKDIR /app
ENV NODE_ENV=production \
    NEXT_TELEMETRY_DISABLED=1 \
    PORT=3000 \
    HOSTNAME=0.0.0.0
COPY --from=build /app/.next/standalone ./
COPY --from=build /app/.next/static ./.next/static
# Next's runtime fetch/ISR cache is the only writable path.
RUN mkdir -p .next/cache && chown node:node .next/cache
USER node
EXPOSE 3000
HEALTHCHECK --interval=10s --timeout=3s --start-period=10s --retries=3 \
  CMD wget -qO- http://127.0.0.1:3000/bff/healthz >/dev/null || exit 1
CMD ["node", "server.js"]
