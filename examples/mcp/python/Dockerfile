# The starter as your own image, for a CaaS project (bring your own image): the same server.py,
# dependencies installed at build time. Build and push it to a registry the region can pull
# from, then create a CaaS project with port 8080 and serve it as an MCP server (docs/mcp.md).
FROM ghcr.io/brf-tech/reqs/python:3.13-slim

WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY server.py .

ENV PORT=8080
EXPOSE 8080
CMD ["python", "-u", "server.py"]
