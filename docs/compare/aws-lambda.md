# RunR vs AWS Lambda

<!-- description: RunR vs AWS Lambda: functions, containers and durable workflows on your own Kubernetes clusters next to AWS's managed service, with sources. -->

RunR is an open-source (MIT) serverless platform: functions, multi-file projects and containers on
Knative, on Kubernetes clusters you operate. [AWS Lambda](https://docs.aws.amazon.com/lambda/latest/dg/welcome.html)
is the serverless compute service of Amazon Web Services: functions deployed as .zip archives or
container images run in an AWS Region and are invoked by requests and events.

Both run functions that scale to zero and react to events. Lambda is a managed service in AWS
Regions; RunR is software you install on your own clusters.

## Differences at a glance

| | RunR | AWS Lambda |
| --- | --- | --- |
| Where it runs | Your Kubernetes clusters (1.30 or newer), each connected as a region ([Regions](../regions.md)) | One AWS Region per function, across several Availability Zones there ([resilience](https://docs.aws.amazon.com/lambda/latest/dg/security-resilience.html)) |
| Who operates it | You: the control plane installs with Helm or Docker Compose ([Production install](../install.md)) | AWS; Lambda Managed Instances run functions on EC2 instances in your account that Lambda manages ([Managed Instances](https://docs.aws.amazon.com/lambda/latest/dg/lambda-managed-instances.html)) |
| What you deploy | A single-file function, a multi-file project built in the cluster, any OCI image, or WebAssembly ([Architecture](../architecture.md), [WebAssembly](../wasm.md)) | .zip archives for managed runtimes, or container images up to 10 GB uncompressed ([deployment packages](https://docs.aws.amazon.com/lambda/latest/dg/images-create.html)) |
| Isolation | runc, gVisor or Kata Containers per project type, set up per region ([Kata Containers hypervisors](../kata-hypervisors.md)) | Firecracker microVMs; Managed Instances use containers on EC2 Nitro instances ([how Lambda works](https://docs.aws.amazon.com/lambda/latest/dg/welcome.html), [Managed Instances](https://docs.aws.amazon.com/lambda/latest/dg/lambda-managed-instances.html)) |
| Events | HTTP, schedules, queues, webhooks, topics, Kafka, NATS, RabbitMQ, MQTT, Postgres CDC, S3 and IMAP, with retries and dead letters ([Triggers](../triggers.md)) | Event source mappings for DocumentDB, DynamoDB, Kinesis, Amazon MQ, Amazon MSK, self-managed Kafka and SQS ([event source mappings](https://docs.aws.amazon.com/lambda/latest/dg/invocation-eventsourcemapping.html)) |
| Rollouts | Blue-green with a test URL; canary in steps that rolls back by itself on error rate or p95 latency ([Deploy strategies](../deploy-strategies.md)) | An alias splits traffic between two versions; AWS SAM with CodeDeploy runs canary or linear deployments that roll back on CloudWatch alarms ([alias routing](https://docs.aws.amazon.com/lambda/latest/dg/configuring-alias-routing.html), [gradual deployments](https://docs.aws.amazon.com/serverless-application-model/latest/developerguide/automating-updates-to-serverless-apps.html)) |
| Workflows | Durable workflows built in: steps, retries, sleeps, events, child workflows; Python and TypeScript SDKs ([Durable workflows](../workflows.md)) | Lambda durable functions run multi-step workflows for up to one year; Step Functions is a separate orchestration service ([durable functions](https://docs.aws.amazon.com/lambda/latest/dg/durable-functions.html), [Step Functions](https://docs.aws.amazon.com/step-functions/latest/dg/welcome.html)) |
| Several regions | Global projects: one host name, HA or proximity mode, failover between regions ([Global projects](../global-projects.md)) | Deployed per Region; AWS describes cross-Region failover with Route 53 ([cross-Region failover](https://docs.aws.amazon.com/solutions/cross-region-failover-and-graceful-failback-on-aws/)); Web Functions have multi-Region endpoints with latency routing and no health-based failover ([Web Functions endpoints](https://docs.aws.amazon.com/lambda/latest/dg/web-functions-endpoints.html)) |
| Untrusted code | The Sandbox API: sandboxes under gVisor or Kata on your cluster, with exec, files, processes and warm pools ([Sandbox API](../sandbox-api.md)) | Lambda MicroVMs: Firecracker sandboxes that can be suspended and resumed ([MicroVMs](https://docs.aws.amazon.com/lambda/latest/dg/lambda-microvms-guide.html)) |
| Trusted code | SBOMs, scans, cosign signatures, a policy gate and signed-image admission ([Supply chain](../supply-chain.md)) | Code signing with AWS Signer ([code signing](https://docs.aws.amazon.com/lambda/latest/dg/configuration-codesigning.html)) |
| Infrastructure as code | Terraform / OpenTofu provider `brf-tech/runr`, the `runr` CLI, a GitHub Action ([Terraform provider](../terraform.md)) | AWS SAM, AWS CDK, CloudFormation `AWS::Lambda::Function`, Terraform `aws_lambda_function` ([SAM](https://docs.aws.amazon.com/serverless-application-model/latest/developerguide/what-is-sam.html), [Terraform](https://registry.terraform.io/providers/hashicorp/aws/latest/docs/resources/lambda_function)) |

Lambda limits an invocation to 900 seconds and a function to 128 MB – 10,240 MB of memory
([quotas](https://docs.aws.amazon.com/lambda/latest/dg/gettingstarted-limits.html)). On RunR the
longest request of a project is its region's `request_timeout`, 300 seconds by default and up to
3,600 ([Regions](../regions.md#request-timeout)), and the GPU nodes of your clusters serve GPU
functions and containers ([GPU workers](../ai.md)).

## When to choose which

**AWS Lambda** fits when your data and events are already in AWS services, you want AWS to run the
compute, and the event source mappings, IAM and the rest of AWS are what your functions work with.

**RunR** fits when code, data and logs must stay on infrastructure you operate (on premises, a
private cloud, air-gapped regions), when you want the same platform on clusters at several
providers, or when functions, long-running containers, GPU workloads and agent sandboxes should
share one console, one API and one set of roles ([Roles and permissions](../permissions.md)).

## RunR documentation to read next

- [Production install](../install.md) and [single-host install](../../compose/README.md)
- [Triggers](../triggers.md) and [Scaling](../scaling.md)
- [Durable workflows](../workflows.md) and [Sandbox API](../sandbox-api.md)
- [Global projects](../global-projects.md)
- [All comparisons](README.md)

---

The statements about AWS Lambda come from the AWS documentation, read on 2026-11-03 and linked next
to each one. AWS and AWS Lambda are trademarks of Amazon.com, Inc. or its affiliates. RunR is not
affiliated with or endorsed by Amazon Web Services.
