# <project name or id>/<binding name or id>
terraform import runr_binding_attachment.orders_db orders-api/orders-db

# An attachment of an environment: <project name or id>/<environment>/<binding name or id>
terraform import runr_binding_attachment.staging_db orders-api/staging/orders-db-staging
