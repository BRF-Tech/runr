# A runtime plugin from its manifest and the files of its dockerfile version
# (examples/runtimes/q in the RunR repository): RunR builds the image in each region.
resource "runr_runtime" "q" {
  manifest = file("${path.module}/runtimes/q/runtime.yaml")
  files = {
    for f in fileset("${path.module}/runtimes/q/interpreter", "*") :
    f => file("${path.module}/runtimes/q/interpreter/${f}")
  }
}

# An exec runtime on an existing image, written inline.
resource "runr_runtime" "bash" {
  manifest = yamlencode({
    apiVersion = "runr.sh/v1"
    kind       = "Runtime"
    id         = "bash"
    name       = "Bash"
    extensions = ["sh"]
    versions   = [{ version = "5", image = "bash:5", default = true }]
    run = {
      mode    = "exec"
      input   = "raw"
      command = ["bash", "/app/code/{file}"]
    }
  })
  regions = [data.runr_region.eu.id]
}

# Projects use the runtime by its id.
resource "runr_project" "script" {
  name      = "hello-sh"
  type      = "xaas"
  runtime   = runr_runtime.bash.id
  region_id = data.runr_region.eu.id
}
