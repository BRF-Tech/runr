terraform {
  required_providers {
    runr = {
      source  = "runr.sh/brf-tech/runr"
      version = "~> 0.5"
    }
  }
}

# Credentials: this block, else RUNR_API_URL / RUNR_TOKEN, else the current
# context of the runr CLI (runr login).
provider "runr" {
  api_url = "https://runr.example.com"
  token   = var.runr_token # runr tokens create terraform --preset terraform
}

variable "runr_token" {
  type      = string
  sensitive = true
}
