<?php

/*
| Billing module (docs/billing.md)
*/

return [
    'types' => [
        'payg' => 'pay as you go',
        'fixed' => 'fixed resources',
    ],

    'tenant_context_required' => 'This is available inside an organization (tenant) only.',
    'tenant_not_found' => 'Organization not found.',
    'region_not_found' => 'Region not found in this organization.',
    'region_prices_owned_by_platform' => 'A shared platform region carries the platform\'s prices. Set your markup instead.',
    'region_type_owned_by_platform' => 'A shared platform region carries the platform\'s billing type.',
    'region_type_locked' => 'The billing type cannot change while :count fixed allocation(s) are active in this region. End or move those projects first.',
    'region_now_payg' => 'The region was switched to pay as you go.',
    'price_in_effect' => 'This price version is already in effect and part of the bills. Add a new version with a later date instead.',
    'markup_in_effect' => 'This markup version is already in effect. Add a new version with a later date instead.',
    'markup_shared_regions_only' => 'A markup applies to shared platform regions only.',
    'offer_not_pending' => 'This region offer is not waiting for acceptance.',

    'region_not_fixed' => 'The project\'s region is pay as you go: it needs no fixed resource request.',
    'gpu_not_available' => 'The project\'s region does not offer GPUs.',
    'replaced_by_newer_request' => 'Replaced by a newer request.',
    'request_not_pending' => 'This request has already been decided.',
    'project_moved' => 'The project is no longer in the region of this request.',
    'project_left_region' => 'The project left the region.',
    'project_forbidden' => 'Only the project\'s owner, members with access to its bills and billing staff see this.',

    'fixed_allocation_required' => 'This project runs in a region with fixed resources: request resources in the project\'s Billing tab and wait for the approval before deploying.',
    'fixed_allocation_exceeded' => 'At its maximum scale (:pods pods, previews included) the project needs :cpu m CPU and :memory MB memory; its approved resources are :allowed_cpu m CPU and :allowed_memory MB. Lower max_scale or the size, or request more resources in the Billing tab.',
    'fixed_gpus_exceeded' => 'At its maximum scale the project needs :gpus GPU(s); its approved resources include :allowed. Lower max_scale, or request more resources in the Billing tab.',
    'fixed_max_scale_required' => 'In a region with fixed resources the project needs a maximum scale (max_scale): its largest size has to fit the approved resources.',
    'fixed_scale_down_first' => 'The project runs with up to :cpu m CPU, :memory MB memory and :gpus GPU(s) now (at its maximum scale, previews included); the request is smaller. Scale the project down first (lower max_scale or the size and deploy), then request the smaller resources.',
    'fixed_running_unbounded' => 'The project runs without a maximum scale (max_scale): Knative may start any number of pods, so no resources can be checked against what runs. Set max_scale and redeploy the project first, then request (or approve) the resources.',
    'region_no_longer_fixed' => 'The region is not a region with fixed resources any more: this request cannot be approved.',

    'bill_open' => 'The bill\'s month is still running. Payment links and the paid status are set on closed bills.',
    'webhook_not_configured' => 'No billing webhook is configured (billing settings or BILLING_WEBHOOK_URL).',
    'webhook_unreachable' => 'The webhook did not answer: the connection failed.',
    'webhook_timeout' => 'The webhook did not answer in time.',
    'project_moved_to_tenant' => 'The project is billed by the organization it was converted into.',
    'legacy_currency_mismatch' => 'The prices imported from RunR 0.5.7 were in :legacy, but BILLING_CURRENCY is :currency: they are read as :currency amounts. Set BILLING_CURRENCY to :legacy, or review every price in Regions & prices.',

    'notify' => [
        'open' => 'Open in RunR',
        'offer_title' => 'Region :region was shared with your organization',
        'offer_body' => 'The platform shared the region :region (:type) with your organization. Review its prices, set your markup and accept it before your users can create projects there.',
        'request_title' => 'Fixed resources requested for :project',
        'request_body' => ':project requests :resources for :price per month. Approve or reject the request.',
        'approved_title' => 'Fixed resources approved for :project',
        'approved_body' => ':resources for :price per month were approved for :project and are billed from today.',
        'rejected_title' => 'Fixed resources rejected for :project',
        'rejected_body' => 'The request for :resources (:price per month) for :project was rejected: :reason',
    ],
];
