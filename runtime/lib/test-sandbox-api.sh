# Sandbox API e2e (docs/sandbox-api.md → Testing).
#
# cmd_test_sandbox_api  — `ci.sh test-sandbox-api [cmd_test flags]`: Kind cluster + docker compose
#                         API (cmd_test) running only the Sandbox API suite
#                         (`php artisan test:e2e --suite=sandbox-api`): gVisor installed through
#                         the API — runsc runs inside the Kind nodes; a runc-handler RuntimeClass
#                         stands in only when the install fails, and the run says why — runc
#                         opted in on the region, sandbox lifecycle, exec + SSE,
#                         code runs, files, processes, pause / resume, network none, TTL expiry
#                         through the reaper, warm pool claim, quota refusal. It prints the start
#                         time of cold and pool-claimed sandboxes.
#
#                         Kata runs only where the host has hardware virtualization (vmx / svm
#                         and a /dev/kvm that opens): then the suite gets --sandbox-kata (installs
#                         Kata with Cloud Hypervisor, QEMU and Dragonball through the API, runs a Kata
#                         sandbox and checks each guest's vCPUs — nproc = 1 + the sandbox's CPU
#                         limit, for 1 and 2 CPUs); without it the Kata checks are reported as
#                         skipped. SANDBOX_KATA=0 / 1 overrides the detection.
#
# KIND_LOCK=/path       — one Kind cluster at a time on a shared host (see test-runtimes.sh).

# Hardware virtualization as RunR's node probe sees it (KataKvmProbeTrait): the CPU's vmx / svm
# flag and a /dev/kvm that opens. A VM without nested virtualization (WSL 2 on some hosts, cloud
# VMs) can have the device node without either: opening it fails with "No such device".
host_has_kvm() {
  grep -qwE 'vmx|svm' /proc/cpuinfo 2>/dev/null || return 1
  ( exec 3<>/dev/kvm ) 2>/dev/null
}

sandbox_api_kata_flag() {
  case "${SANDBOX_KATA:-auto}" in
    1|true|yes) echo "--sandbox-kata" ;;
    0|false|no) echo "" ;;
    *)
      if host_has_kvm; then
        echo "--sandbox-kata"
      else
        echo ""
      fi
      ;;
  esac
}

cmd_test_sandbox_api() {
  local kata
  kata="$(sandbox_api_kata_flag)"

  echo -e "${BOLD}Sandbox API e2e${NC}"
  if [ -n "$kata" ]; then
    echo "  hardware virtualization (vmx / svm, /dev/kvm opens): Kata sandboxes included"
  else
    echo "  no hardware virtualization (no vmx / svm, or /dev/kvm does not open): Kata sandboxes skipped (gVisor and runc only)"
  fi

  TEST_E2E_EXTRA_ARGS="${TEST_E2E_EXTRA_ARGS:-} ${kata}" cmd_test --suite=sandbox-api "$@"
}
