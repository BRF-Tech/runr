# The starter as your own image, for a CaaS project (bring your own image): the same server.ts,
# dependencies installed at build time. Build and push it to a registry the region can pull
# from, then create a CaaS project with port 8080 and serve it as an MCP server (docs/mcp.md).
FROM ghcr.io/runrsh/reqs/node:22-slim

WORKDIR /app
COPY package.json .
RUN npm install --omit=dev --no-audit --no-fund
COPY server.ts .

ENV PORT=8080
EXPOSE 8080
CMD ["node", "--experimental-strip-types", "--disable-warning=ExperimentalWarning", "server.ts"]
