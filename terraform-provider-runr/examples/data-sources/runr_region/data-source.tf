data "runr_region" "eu" {
  name = "eu-1"
}

# With neither name nor id: the only active region.
data "runr_region" "default" {}

output "queues_available" {
  value = data.runr_region.eu.has_redis
}
