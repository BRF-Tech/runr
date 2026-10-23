# <project name or id>/<workflow> (the project's own workload)
terraform import runr_workflow.process_order orders-api/process-order

# <project name or id>/<environment>/<workflow>
terraform import runr_workflow.nightly_staging orders-api/staging/nightly-report
