#!/bin/bash
set -euo pipefail

# ── RunR Kind Cluster Setup ─────────────────────────────────────────────────
# Creates a Kind cluster + installs cloud-provider-kind (LoadBalancer).
# Usage: bash kind-setup.sh [cluster-name] [k8s-version]

CLUSTER_NAME="${1:-runr}"
K8S_VERSION="${2:-v1.32.2}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CPK_VERSION="v0.10.0"

echo "══════════════════════════════════════════════════════════"
echo "  RunR Kind Cluster Setup"
echo "══════════════════════════════════════════════════════════"
echo ""
echo "  Cluster:  ${CLUSTER_NAME}"
echo "  K8s:      ${K8S_VERSION}"
echo ""

# ── 1. Check for an existing cluster ──────────────────────────────────────
if kind get clusters 2>/dev/null | grep -qx "$CLUSTER_NAME"; then
    echo "⚠ Cluster '${CLUSTER_NAME}' already exists."
    read -rp "  Delete and recreate it? [y/N] " ans
    if [[ "$ans" =~ ^[Yy]$ ]]; then
        echo "▶ Deleting the old cluster..."
        kind delete cluster --name "$CLUSTER_NAME"
    else
        echo "Exiting."
        exit 0
    fi
fi

# ── 2. Create the Kind cluster ────────────────────────────────────────────
echo "▶ Creating the Kind cluster (1 control-plane + 2 workers)..."
kind create cluster \
    --name "$CLUSTER_NAME" \
    --image "kindest/node:${K8S_VERSION}" \
    --config "${SCRIPT_DIR}/kind-config.yml"

kubectl cluster-info --context "kind-${CLUSTER_NAME}"

# ── 3. Cloud Provider KIND (LoadBalancer support) ─────────────────────────
echo ""
echo "▶ Installing Cloud Provider KIND..."

if ! command -v cloud-provider-kind &>/dev/null; then
    cpk_os="$(uname -s | tr '[:upper:]' '[:lower:]')"
    cpk_arch="$(uname -m)"
    [ "$cpk_arch" = "x86_64" ] && cpk_arch="amd64"
    [ "$cpk_arch" = "aarch64" ] && cpk_arch="arm64"

    cpk_url="https://github.com/kubernetes-sigs/cloud-provider-kind/releases/download/${CPK_VERSION}/cloud-provider-kind_${CPK_VERSION#v}_${cpk_os}_${cpk_arch}.tar.gz"
    echo "  Downloading: ${cpk_url}"
    curl -fsSL "$cpk_url" | sudo tar xz -C /usr/local/bin cloud-provider-kind
fi

echo "  Starting Cloud Provider KIND (in the background)..."
cloud-provider-kind &>/dev/null &
CPK_PID=$!
echo "  PID: ${CPK_PID}"

# ── 4. Kubeconfig export ──────────────────────────────────────────────────
KUBECONFIG_FILE="${SCRIPT_DIR}/runr-kubeconfig.yaml"
kind get kubeconfig --name "$CLUSTER_NAME" > "$KUBECONFIG_FILE"

echo ""
echo "══════════════════════════════════════════════════════════"
echo "  Cluster ready!"
echo ""
echo "  Kubeconfig: ${KUBECONFIG_FILE}"
echo "  kubectl:    kubectl --context kind-${CLUSTER_NAME} get nodes"
echo ""
echo "  Upload this kubeconfig when adding a region to RunR."
echo "  (adaptKubeconfig adjusts the SSL + host settings automatically)"
echo ""
echo "  To delete the cluster:"
echo "    kind delete cluster --name ${CLUSTER_NAME}"
echo "    kill ${CPK_PID}  # stop cloud-provider-kind"
echo "══════════════════════════════════════════════════════════"
