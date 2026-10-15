# <project name or id>/<variable name>
terraform import runr_env_var.log_level orders-api/LOG_LEVEL

# A secret variable is imported with secret = true and without its value (RunR never returns
# it); the next apply writes the value of the configuration.
terraform import runr_env_var.database_url orders-api/DATABASE_URL
