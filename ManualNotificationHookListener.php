<?php

namespace Lkn\HookNotification\Core\Notification\Infrastructure;

use Lkn\HookNotification\Core\NotificationReport\Application\NotificationReportService;
use Lkn\HookNotification\Core\NotificationReport\Domain\NotificationReportCategory;
use Lkn\HookNotification\Core\Notification\Application\NotificationFactory;
use Lkn\HookNotification\Core\Notification\Application\Services\NotificationSender;
use Lkn\HookNotification\Core\Platforms\Common\PlatformNotificationSendResult;
use Lkn\HookNotification\Core\Shared\Infrastructure\Hooks;
use Lkn\HookNotification\Core\Shared\Infrastructure\View\View;
use Throwable;

final class ManualNotificationHookListener
{
    private readonly View $view;
    private readonly NotificationFactory $notificationFactory;
    private readonly NotificationReportService $notificationReportService;
    private readonly NotificationSender $notificationSender;

    public function __construct()
    {
        $this->notificationSender = NotificationSender::getInstance();
        $this->view               =  new View();
        $this->view->setTemplateDir(__DIR__ . '/../Http/Views');
        $this->notificationFactory       = NotificationFactory::getInstance();
        $this->notificationReportService = new NotificationReportService();
    }

    public function listenFor(Hooks $hook): void
    {
        $notificationsForHook = $this->notificationFactory->makeAllForHook(
            $hook,
            false
        );

        if (count($notificationsForHook) === 0) {
            return;
        }

        if ($hook === Hooks::ADMIN_INVOICES_CONTROLS_OUTPUT) {
            add_hook(
                'AdminAreaFooterOutput',
                999,
                function (?array $vars = []) use ($hook, $notificationsForHook): ?string {
                    try {
                        $scriptName = basename($_SERVER['SCRIPT_NAME'] ?? '');
                        $invoiceId = $_GET['id'] ?? null;

                        if ($scriptName !== 'invoices.php' || !$invoiceId) {
                            return null;
                        }

                        $whmcsHookParams = ['invoiceid' => $invoiceId];
                        $widgetHtml = $this->renderWidget($hook, $whmcsHookParams, $notificationsForHook);

                        if (!$widgetHtml) {
                            return null;
                        }

                        $escapedHtml = json_encode($widgetHtml);
                        return <<<HTML
<script type="text/javascript">
(function() {
    // Don't inject if already present
    if (document.getElementById('lkn-hn-ADMIN_INVOICES_CONTROLS_OUTPUT')) return;

    var widgetHtml = {$escapedHtml};

    // Find the "Send Email" button to place widget right after it
    var sendEmailBtn = document.querySelector('input[value="Send Email"], button:contains("Send Email")');
    var targetRow = null;

    // Try to find the invoice controls area (the row with Send Email / Attempt Capture)
    var allButtons = document.querySelectorAll('input[type="submit"], input[type="button"], button');
    for (var i = 0; i < allButtons.length; i++) {
        if (allButtons[i].value === 'Send Email' || allButtons[i].textContent.trim() === 'Send Email') {
            targetRow = allButtons[i].closest('div, td, form');
            break;
        }
    }

    // Find the Attempt Capture button area as secondary target
    if (!targetRow) {
        for (var i = 0; i < allButtons.length; i++) {
            if (allButtons[i].value === 'Attempt Capture' || allButtons[i].textContent.trim() === 'Attempt Capture') {
                targetRow = allButtons[i].closest('div, td');
                break;
            }
        }
    }

    var wrapper = document.createElement('div');
    wrapper.style.marginTop = '10px';
    wrapper.innerHTML = widgetHtml;

    if (targetRow) {
        // Insert right after the Send Email / controls row
        targetRow.parentNode.insertBefore(wrapper, targetRow.nextSibling);
    } else {
        // Fallback: insert after the invoice summary table
        var summaryTable = document.querySelector('.invoice-header, #contentarea table:first-of-type');
        if (summaryTable) {
            summaryTable.parentNode.insertBefore(wrapper, summaryTable.nextSibling);
        }
    }
})();
</script>
HTML;
                    } catch (Throwable $th) {
                        lkn_hn_debug_trace('ERROR: footer fallback widget failed', [
                            'error' => $th->getMessage(),
                            'file' => $th->getFile() . ':' . $th->getLine(),
                        ]);
                        return null;
                    }
                }
            );
        } else {
            // For non-invoice hooks, use the original hook directly
            add_hook(
                $hook->value,
                999,
                function (?array $whmcsHookParams = []) use ($hook, $notificationsForHook): ?string {
                    return $this->renderWidget($hook, $whmcsHookParams ?? [], $notificationsForHook);
                }
            );
        }
    }

    private function renderWidget(Hooks $hook, array $whmcsHookParams, array $notificationsForHook): ?string
    {
        try {
            $reports = $this->buildReports();

            $viewParams = [
                'hook' => $hook,
                'whmcsHookParams' => $whmcsHookParams,
                'notification_reports' => $reports,
            ];

            $wasSent = $this->listenToTrigger();

            $viewParams['notification_send_result'] = $wasSent;
            $viewParams['notifications']            = $notificationsForHook;

            return $this->view->view(
                'components/manual_notification',
                $viewParams
            )->render();
        } catch (Throwable $th) {
            lkn_hn_debug_trace('ERROR: manual notification widget failed', [
                'hook' => $hook->name,
                'error' => $th->getMessage(),
                'file' => $th->getFile() . ':' . $th->getLine(),
            ]);

            lkn_hn_log(
                'manual listener error',
                [
                    'notificationsForHook' => $notificationsForHook,
                    'hook' => $hook->name,
                ],
                [
                    'exception' => $th->__toString(),
                ]
            );
        }

        return null;
    }

    /**
     * @return ?array
     */
    private function listenToTrigger(): ?array
    {
        /** @var string $notificationCode */
        $notificationCode = $_POST['lkn-hn-manual-notif-code'] ?? null;

        if (empty($notificationCode)) {
            return null;
        }

        unset($_POST['lkn-hn-manual-notif-code']);

        $notification = $this->notificationFactory->makeByCode($notificationCode);

        if (!$notification) {
            return null;
        }

        $whmcsHookParams = $_POST;

        $platformSendResult = $this->notificationSender->dispatchNotification($notification, $whmcsHookParams);

        if ($platformSendResult instanceof PlatformNotificationSendResult) {
            return ['code' => $platformSendResult->status->label(), 'msg' => $platformSendResult->msg];
        } else {
            return ['code' => $platformSendResult->code, 'msg' => $platformSendResult->msg];
        }

        return null;
    }

    private function buildReports()
    {
        $reports = $this->notificationReportService->getReportsForCategory(
            NotificationReportCategory::INVOICE,
            $_GET['id'] ?? 0
        );

        return $reports;
    }
}
