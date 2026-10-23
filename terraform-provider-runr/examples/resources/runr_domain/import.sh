# <project name or id>/<domain>
terraform import runr_domain.api orders-api/api.example.com

# A domain of an environment: <project name or id>/<environment>/<domain>
terraform import runr_domain.staging orders-api/staging/staging.example.com
