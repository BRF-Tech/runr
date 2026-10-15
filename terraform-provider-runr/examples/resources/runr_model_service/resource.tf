data "runr_region" "eu" {
  name = "eu-1"
}

# Embeddings on CPU: RunR picks the engine (TEI), size and scaling.
resource "runr_model_service" "embed" {
  hub_id    = "sentence-transformers/all-MiniLM-L6-v2"
  region_id = data.runr_region.eu.id
  min_scale = 1 # keep one instance warm
}

# Chat on a GPU, served from the region's offline copy (no egress).
resource "runr_model_service" "chat" {
  name      = "chat"
  hub_id    = "Qwen/Qwen2.5-7B-Instruct"
  revision  = "main"
  region_id = data.runr_region.eu.id
  mode      = "offline"
  device    = "gpu"
  engine    = "vllm"
  gpu = {
    count      = 1
    memory_min = 24000
  }
  args    = ["--max-model-len", "8192"]
  timeout = "45m"
}

output "chat_openai_base" {
  value = runr_model_service.chat.openai_base
}
