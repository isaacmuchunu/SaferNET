<?php

namespace App\Enums;

enum InstitutionStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Onboarding = 'onboarding';
    case DeploymentInProgress = 'deployment_in_progress';
    case AttributionRequired = 'attribution_required';
    case Protected = 'protected';
    case AttentionRequired = 'attention_required';
    case Suspended = 'suspended';
    case Rejected = 'rejected';
}
