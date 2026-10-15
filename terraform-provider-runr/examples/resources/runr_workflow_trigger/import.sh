# <project name or id>/<workflow>/<event:NAME | queue:STREAM | cron:EXPRESSION[@TIME_ZONE]>
terraform import runr_workflow_trigger.large_orders orders-api/process-order/event:order.created
terraform import runr_workflow_trigger.nightly 'orders-api/nightly-report/cron:0 3 * * *@Europe/Istanbul'
