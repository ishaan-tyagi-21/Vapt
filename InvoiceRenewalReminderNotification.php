<?php

/**
 * Code: InvoiceRenewalReminder
 *
 * Portal-configurable recurring invoice renewal reminder.
 * Schedule (daily/weekly/monthly) and excluded clients are set via
 * addonmodules.php?module=lknhooknotification&page=renewal-notifications
 */

namespace Lkn\HookNotification\Notifications;

use DateTime;
use Lkn\HookNotification\Core\NotificationReport\Domain\NotificationReportCategory;
use Lkn\HookNotification\Core\Notification\Domain\AbstractCronNotification;
use Lkn\HookNotification\Core\Notification\Domain\NotificationParameter;
use Lkn\HookNotification\Core\Notification\Domain\NotificationParameterCollection;
use Lkn\HookNotification\Core\Shared\Infrastructure\Config\Settings;
use Lkn\HookNotification\Core\Shared\Infrastructure\Hooks;

final class InvoiceRenewalReminderNotification extends AbstractCronNotification
{
    public function __construct()
    {
        $parameters = [
            new NotificationParameter(
                'invoice_id',
                lkn_hn_lang('invoice_id'),
                fn(): int => (int) $this->whmcsHookParams['invoice_id'],
            ),
            new NotificationParameter(
                'invoice_balance',
                lkn_hn_lang('invoice_balance'),
                fn(): string => getInvoiceBalance($this->whmcsHookParams['invoice_id'])
            ),
            new NotificationParameter(
                'invoice_total',
                lkn_hn_lang('invoice_total'),
                fn(): string => getInvoiceTotal($this->whmcsHookParams['invoice_id'])
            ),
            new NotificationParameter(
                'invoice_subtotal',
                lkn_hn_lang('invoice_subtotal'),
                fn(): string => getInvoiceSubtotal($this->whmcsHookParams['invoice_id'])
            ),
            new NotificationParameter(
                'invoice_due_date',
                lkn_hn_lang('invoice_due_date'),
                fn(): string => getInvoiceDueDateByInvoiceId($this->whmcsHookParams['invoice_id'])
            ),
            new NotificationParameter(
                'invoice_pdf_url',
                lkn_hn_lang('invoice_pdf_url'),
                fn(): string => getInvoicePdfUrlByInvocieId($this->whmcsHookParams['invoice_id'])
            ),
            new NotificationParameter(
                'client_id',
                lkn_hn_lang('Client ID'),
                fn(): int => $this->client->id
            ),
            new NotificationParameter(
                'client_email',
                lkn_hn_lang('Client email'),
                fn(): string => getClientEmailByClientId($this->client->id)
            ),
            new NotificationParameter(
                'client_first_name',
                lkn_hn_lang('Client first name'),
                fn(): string => getClientFirstNameByClientId($this->client->id)
            ),
            new NotificationParameter(
                'client_last_name',
                lkn_hn_lang('Client last name'),
                fn(): string => getClientLastNameByClientId($this->client->id)
            ),
            new NotificationParameter(
                'client_full_name',
                lkn_hn_lang('Client full name'),
                fn(): string => getClientFullNameByClientId($this->client->id)
            ),
        ];

        parent::__construct(
            'InvoiceRenewalReminder',
            NotificationReportCategory::INVOICE,
            Hooks::DAILY_CRON_JOB,
            new NotificationParameterCollection($parameters),
            fn() => (int) $this->whmcsHookParams['client_id'],
            fn() => (int) $this->whmcsHookParams['report_category_id'],
        );
    }

    public function getPayload(): array
    {
        $config = lkn_hn_config(Settings::INVOICE_RENEWAL_CONFIG);

        if (!is_array($config) || empty($config['enabled'])) {
            return [];
        }

        if (!$this->shouldRunToday($config)) {
            return [];
        }

        $excludedClientIds = array_map('intval', $config['excluded_client_ids'] ?? []);

        $invoicesResponse = localAPI('GetInvoices', [
            'limitnum' => 1000,
            'status' => 'Unpaid',
        ]);

        $payloads = [];

        if (!isset($invoicesResponse['invoices']['invoice']) || !is_array($invoicesResponse['invoices']['invoice'])) {
            return [];
        }

        foreach ($invoicesResponse['invoices']['invoice'] as $invoice) {
            $invoiceId = (int) $invoice['id'];
            $clientId = (int) $invoice['userid'];

            // Skip excluded clients
            if (in_array($clientId, $excludedClientIds, true)) {
                continue;
            }

            // Skip free invoices
            if (($invoice['paymentmethod'] ?? '') === 'freeproducts' || ($invoice['total'] ?? '0.00') === '0.00') {
                continue;
            }

            $payloads[] = [
                'client_id' => $clientId,
                'report_category_id' => $invoiceId,
                'invoice_id' => $invoiceId,
            ];
        }

        return $payloads;
    }

    /**
     * Determine if the renewal reminder should run today based on frequency.
     */
    private function shouldRunToday(array $config): bool
    {
        $frequency = $config['frequency'] ?? 'daily';
        $today = new DateTime();

        switch ($frequency) {
            case 'daily':
                return true;

            case 'weekly':
                $weekdays = $config['weekdays'] ?? [];
                if (empty($weekdays)) {
                    return false;
                }
                $todayName = strtolower($today->format('l')); // e.g. "monday"
                return in_array($todayName, $weekdays, true);

            case 'monthly':
                $configuredDay = (int) ($config['day_of_month'] ?? 1);
                return (int) $today->format('j') === $configuredDay;

            default:
                return false;
        }
    }
}
