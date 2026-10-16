<?php

namespace Modules\Donations\Support\Reports;

enum ReportDateSemantic: string
{
    case AsOf = 'as_of';
    case PaymentPeriod = 'payment_period';
    case ReceiptIssued = 'receipt_issued';
    case ExpenseDate = 'expense_date';
    case FiscalYear = 'fiscal_year';
    case ParticipationWindow = 'participation_window';
    case AdjustmentDate = 'adjustment_date';
    case ReceivedAt = 'received_at';
}
