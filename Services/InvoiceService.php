<?php


namespace App\Services;


class InvoiceService
{
    public static function GenerateInvoiceNumber(string $invoicePrefix)
    {
        return $invoicePrefix.rand(100,999).time();
    }
}