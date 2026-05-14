<?php

namespace App;

enum MerchantInvoiceStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case Failed = 'failed';
    case Canceled = 'canceled';
}
