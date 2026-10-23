# <project name or id>/<environment name>
terraform import runr_environment.staging orders-api/staging

# production (the project's own workload) is imported like any other environment.
terraform import runr_environment.production orders-api/production
