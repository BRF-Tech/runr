# <project name or id>/<connection name or id> (the project's own workload)
terraform import runr_private_connection.orders orders-api/orders

# <project name or id>/<environment>/<connection name or id>. The credentials are not imported:
# the next apply takes the configured ones into the state without replacing the connection.
terraform import runr_private_connection.orders_staging orders-api/staging/orders
