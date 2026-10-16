<?php

namespace Modules\Subscriptions\Billing;

enum BillingAction: string
{
    case QuotePurchase = 'quote_purchase';
    case CreateOnlineOrder = 'create_online_order';
    case VerifyOnlinePayment = 'verify_online_payment';
    case SubmitOfflineClaim = 'submit_offline_claim';
    case ViewBillingHistory = 'view_billing_history';
    case DownloadInvoice = 'download_invoice';
}
