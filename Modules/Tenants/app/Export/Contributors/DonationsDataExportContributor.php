<?php

namespace Modules\Tenants\Export\Contributors;

use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Models\ContributionPlan;
use Modules\Donations\Models\ContributionPlanAssignment;
use Modules\Donations\Models\Donation;
use Modules\Donations\Models\DonationCategory;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\DonationProject;
use Modules\Donations\Models\DonationReceipt;
use Modules\Donations\Models\DonationSetting;
use Modules\Donations\Models\Donor;
use Modules\Donations\Models\Fund;
use Modules\Donations\Models\ParishExpense;
use Modules\Donations\Models\ProjectFamilyAssignment;
use Modules\Donations\Models\ProjectInstallmentDue;
use Modules\Tenants\Contracts\ExportWriterFactory;
use Modules\Tenants\Contracts\TenantDataExportContributor;

class DonationsDataExportContributor implements TenantDataExportContributor
{
    use WritesChunkedExportCsv;

    public function key(): string
    {
        return 'donations';
    }

    public function label(): string
    {
        return 'Donations & stewardship';
    }

    public function defaultSelected(): bool
    {
        return true;
    }

    public function estimateCount(int $tenantId): int
    {
        return DonationCategory::forTenant($tenantId)->count()
            + Fund::forTenant($tenantId)->count()
            + DonationProject::forTenant($tenantId)->count()
            + Donor::forTenant($tenantId)->count()
            + Donation::forTenant($tenantId)->count()
            + DonationPayment::forTenant($tenantId)->count()
            + ContributionPlan::forTenant($tenantId)->count()
            + ContributionPlanAssignment::forTenant($tenantId)->count()
            + ContributionDue::forTenant($tenantId)->count()
            + ProjectFamilyAssignment::forTenant($tenantId)->count()
            + ProjectInstallmentDue::forTenant($tenantId)->count()
            + DonationReceipt::forTenant($tenantId)->count()
            + ParishExpense::forTenant($tenantId)->count()
            + DonationSetting::forTenant($tenantId)->count();
    }

    public function export(
        int $tenantId,
        ExportWriterFactory $writers,
        int $chunkSize,
        callable $onProgress
    ): array {
        return [
            'files' => [
                'data/donation_categories.csv' => $this->writeChunkedQuery(
                    $writers,
                    'data/donation_categories.csv',
                    ['category_id', 'name', 'code', 'description', 'is_tax_deductible', 'status', 'created_at'],
                    DonationCategory::forTenant($tenantId),
                    $chunkSize,
                    $onProgress,
                    fn ($row) => [
                        $row->id, $row->name, $row->code, $row->description,
                        $this->boolLabel($row->is_tax_deductible), $this->activeLabel($row->active), $row->created_at,
                    ]
                ),
                'data/donation_funds.csv' => $this->writeChunkedQuery(
                    $writers,
                    'data/donation_funds.csv',
                    ['fund_id', 'name', 'code', 'description', 'is_tax_deductible', 'status', 'created_at'],
                    Fund::forTenant($tenantId),
                    $chunkSize,
                    $onProgress,
                    fn ($row) => [
                        $row->id, $row->name, $row->code, $row->description,
                        $this->boolLabel($row->is_tax_deductible), $row->status, $row->created_at,
                    ]
                ),
                'data/donation_projects.csv' => $this->writeChunkedQuery(
                    $writers,
                    'data/donation_projects.csv',
                    [
                        'project_id', 'fund_id', 'name', 'code', 'entity_kind', 'campaign_type',
                        'target_amount', 'raised_amount', 'start_date', 'end_date', 'status', 'created_at',
                    ],
                    DonationProject::forTenant($tenantId),
                    $chunkSize,
                    $onProgress,
                    fn ($row) => [
                        $row->id, $row->fund_id, $row->name, $row->code, $row->entity_kind,
                        $row->campaign_type, $row->target_amount, $row->raised_amount,
                        $row->start_date, $row->end_date, $row->status, $row->created_at,
                    ]
                ),
                'data/donors.csv' => $this->writeChunkedQuery(
                    $writers,
                    'data/donors.csv',
                    [
                        'donor_id', 'family_id', 'family_member_id', 'name', 'email', 'phone',
                        'donor_type', 'is_anonymous', 'created_at',
                    ],
                    Donor::forTenant($tenantId),
                    $chunkSize,
                    $onProgress,
                    fn ($row) => [
                        $row->id, $row->family_id, $row->family_member_id, $row->name, $row->email,
                        $row->phone, $row->donor_type, $this->boolLabel($row->is_anonymous), $row->created_at,
                    ]
                ),
                'data/donations.csv' => $this->writeChunkedQuery(
                    $writers,
                    'data/donations.csv',
                    [
                        'donation_id', 'title', 'donor_id', 'donor_name', 'family_id', 'category_id',
                        'category_name', 'project_id', 'pledged_amount', 'collected_amount',
                        'received_at', 'financial_year', 'status', 'is_anonymous', 'created_at',
                    ],
                    Donation::forTenant($tenantId)->with([
                        'donor:id,name',
                        'category:id,name',
                    ]),
                    $chunkSize,
                    $onProgress,
                    fn ($row) => [
                        $row->id, $row->title, $row->donor_id, $row->is_anonymous ? 'Anonymous' : $row->donor?->name,
                        $row->family_id, $row->donation_category_id, $row->category?->name,
                        $row->project_id, $row->pledged_amount, $row->collected_amount,
                        $row->received_at, $row->financial_year, $row->status,
                        $this->boolLabel($row->is_anonymous), $row->created_at,
                    ]
                ),
                'data/donation_payments.csv' => $this->writeChunkedQuery(
                    $writers,
                    'data/donation_payments.csv',
                    [
                        'payment_id', 'payment_number', 'payment_date', 'family_id', 'donor_id',
                        'payer_name', 'method', 'status', 'amount', 'currency', 'source_type',
                        'is_anonymous', 'notes', 'created_at',
                    ],
                    DonationPayment::forTenant($tenantId),
                    $chunkSize,
                    $onProgress,
                    fn ($row) => [
                        $row->id, $row->payment_number, $row->payment_date, $row->family_id, $row->donor_id,
                        $row->is_anonymous ? 'Anonymous' : $row->payer_name, $row->method, $row->status,
                        $row->amount, $row->currency, $row->source_type, $this->boolLabel($row->is_anonymous),
                        $row->notes, $row->created_at,
                    ]
                ),
                'data/contribution_plans.csv' => $this->writeChunkedQuery(
                    $writers,
                    'data/contribution_plans.csv',
                    [
                        'plan_id', 'fund_id', 'name', 'code', 'plan_type', 'frequency',
                        'default_amount', 'start_date', 'end_date', 'status', 'created_at',
                    ],
                    ContributionPlan::forTenant($tenantId),
                    $chunkSize,
                    $onProgress,
                    fn ($row) => [
                        $row->id, $row->fund_id, $row->name, $row->code, $row->plan_type, $row->frequency,
                        $row->default_amount, $row->start_date, $row->end_date, $row->status, $row->created_at,
                    ]
                ),
                'data/contribution_plan_assignments.csv' => $this->writeChunkedQuery(
                    $writers,
                    'data/contribution_plan_assignments.csv',
                    [
                        'assignment_id', 'plan_id', 'family_id', 'amount', 'effective_from',
                        'effective_to', 'is_exempt', 'status', 'created_at',
                    ],
                    ContributionPlanAssignment::forTenant($tenantId),
                    $chunkSize,
                    $onProgress,
                    fn ($row) => [
                        $row->id, $row->plan_id, $row->family_id, $row->amount, $row->effective_from,
                        $row->effective_to, $this->boolLabel($row->is_exempt), $row->status, $row->created_at,
                    ]
                ),
                'data/contribution_dues.csv' => $this->writeChunkedQuery(
                    $writers,
                    'data/contribution_dues.csv',
                    [
                        'due_id', 'family_id', 'plan_id', 'period_label', 'due_date',
                        'amount_due', 'amount_paid', 'status', 'created_at',
                    ],
                    ContributionDue::forTenant($tenantId),
                    $chunkSize,
                    $onProgress,
                    fn ($row) => [
                        $row->id, $row->family_id, $row->plan_id, $row->period_label, $row->due_date,
                        $row->amount_due, $row->amount_paid, $row->status, $row->created_at,
                    ]
                ),
                'data/project_family_assignments.csv' => $this->writeChunkedQuery(
                    $writers,
                    'data/project_family_assignments.csv',
                    [
                        'assignment_id', 'project_id', 'family_id', 'target_amount', 'amount_collected',
                        'is_exempt', 'status', 'created_at',
                    ],
                    ProjectFamilyAssignment::forTenant($tenantId),
                    $chunkSize,
                    $onProgress,
                    fn ($row) => [
                        $row->id, $row->project_id, $row->family_id, $row->target_amount, $row->amount_collected,
                        $this->boolLabel($row->is_exempt), $row->status, $row->created_at,
                    ]
                ),
                'data/project_installment_dues.csv' => $this->writeChunkedQuery(
                    $writers,
                    'data/project_installment_dues.csv',
                    [
                        'installment_id', 'project_id', 'family_id', 'installment_number', 'installment_label',
                        'due_date', 'amount_due', 'amount_paid', 'status', 'created_at',
                    ],
                    ProjectInstallmentDue::forTenant($tenantId),
                    $chunkSize,
                    $onProgress,
                    fn ($row) => [
                        $row->id, $row->project_id, $row->family_id, $row->installment_number, $row->installment_label,
                        $row->due_date, $row->amount_due, $row->amount_paid, $row->status, $row->created_at,
                    ]
                ),
                'data/donation_receipts.csv' => $this->writeChunkedQuery(
                    $writers,
                    'data/donation_receipts.csv',
                    [
                        'receipt_id', 'payment_id', 'receipt_number', 'issued_on', 'is_void', 'void_reason', 'created_at',
                    ],
                    DonationReceipt::forTenant($tenantId),
                    $chunkSize,
                    $onProgress,
                    fn ($row) => [
                        $row->id, $row->payment_id, $row->receipt_number, $row->issued_on,
                        $this->boolLabel($row->is_void), $row->void_reason, $row->created_at,
                    ]
                ),
                'data/parish_expenses.csv' => $this->writeChunkedQuery(
                    $writers,
                    'data/parish_expenses.csv',
                    [
                        'expense_id', 'category', 'amount', 'currency', 'expense_date', 'payee',
                        'method', 'status', 'notes', 'created_at',
                    ],
                    ParishExpense::forTenant($tenantId),
                    $chunkSize,
                    $onProgress,
                    fn ($row) => [
                        $row->id, $row->category, $row->amount, $row->currency, $row->expense_date,
                        $row->payee, $row->method, $row->status, $row->notes, $row->created_at,
                    ]
                ),
                'data/donation_settings.csv' => $this->writeChunkedQuery(
                    $writers,
                    'data/donation_settings.csv',
                    [
                        'setting_id', 'default_currency', 'financial_year_start_month', 'financial_year_start_day',
                        'tax_registration_number', 'receipt_prefix_enabled', 'receipt_prefix', 'created_at',
                    ],
                    DonationSetting::forTenant($tenantId),
                    $chunkSize,
                    $onProgress,
                    fn ($row) => [
                        $row->id, $row->default_currency, $row->financial_year_start_month, $row->financial_year_start_day,
                        $row->tax_registration_number, $this->boolLabel($row->receipt_prefix_enabled),
                        $row->receipt_prefix, $row->created_at,
                    ]
                ),
            ],
        ];
    }
}
