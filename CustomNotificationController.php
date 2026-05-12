<?php

namespace Lkn\HookNotification\Core\Notification\Http\Controllers;

use Lkn\HookNotification\Core\Notification\Application\NotificationFactory;
use Lkn\HookNotification\Core\Notification\Infrastructure\Repositories\CustomNotificationRepository;
use Lkn\HookNotification\Core\NotificationReport\Domain\NotificationReportCategory;
use Lkn\HookNotification\Core\Shared\Infrastructure\Hooks;
use Lkn\HookNotification\Core\Shared\Infrastructure\Interfaces\BaseController;
use Lkn\HookNotification\Core\Shared\Infrastructure\View\View;

final class CustomNotificationController extends BaseController
{
    private CustomNotificationRepository $repository;

    public function __construct(View $view)
    {
        parent::__construct($view);

        $this->repository = new CustomNotificationRepository();
    }

    public function viewList(array $request): void
    {
        if (!empty($request['delete-code'])) {
            $this->repository->deleteByCode((string) $request['delete-code']);
            $this->view->alert('success', lkn_hn_lang('Custom notification deleted.'));
        }

        $this->view->view('custom_notification_list', [
            'custom_notifications' => $this->repository->all(),
            'supported_hooks_by_recipe' => $this->getSupportedHooks(),
        ]);
    }

    public function viewForm(string $code, array $request): void
    {
        $isNew = ($code === 'new');
        $editing = $isNew ? null : $this->repository->findByCode($code);

        if (!$isNew && $editing === null) {
            lkn_hn_redirect_to_404();

            return;
        }

        if (!empty($request['save'])) {
            $result = $this->handleSave($isNew, $editing, $request);

            if ($result['ok']) {
                $this->view->alert('success', lkn_hn_lang('Custom notification saved.'));

                header('Location: addonmodules.php?module=lknhooknotification&page=custom-notifications/' . $result['code']);

                return;
            }

            $this->view->alert('danger', $result['error']);
        }

        $this->view->view('custom_notification_form', [
            'is_new' => $isNew,
            'editing' => $editing,
            'hooks_by_recipe' => $this->getSupportedHooks(),
            'categories' => array_map(static fn ($c) => $c->value, NotificationReportCategory::cases()),
        ]);
    }

    /**
     * @return array{ok:bool, code?:string, error?:string}
     */
    private function handleSave(bool $isNew, ?array $editing, array $request): array
    {
        $code = trim((string) ($request['code'] ?? ''));
        $description = trim((string) ($request['description'] ?? ''));
        $hook = trim((string) ($request['hook'] ?? ''));
        $enabled = !empty($request['enabled']);

        if ($code === '') {
            return ['ok' => false, 'error' => lkn_hn_lang('Code is required.')];
        }

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $code)) {
            return ['ok' => false, 'error' => lkn_hn_lang('Code must contain only letters, digits, and underscores.')];
        }

        if (str_starts_with($code, 'Default')) {
            return ['ok' => false, 'error' => lkn_hn_lang('Code cannot start with "Default" (reserved for built-in notifications).')];
        }

        $hookCase = Hooks::tryFrom($hook);

        if ($hookCase === null) {
            return ['ok' => false, 'error' => lkn_hn_lang('Selected hook is not valid.')];
        }

        $factory = NotificationFactory::getInstance();
        $recipe = $this->findRecipeForHook($factory, $hookCase);

        if ($recipe === null) {
            return ['ok' => false, 'error' => lkn_hn_lang('Selected hook does not support custom notifications yet.')];
        }

        $category = $recipe['category']->value ?? null;

        if ($isNew) {
            if ($this->repository->existsByCode($code)) {
                return ['ok' => false, 'error' => lkn_hn_lang('A custom notification with this code already exists.')];
            }

            $existingBuiltIn = $factory->makeByCode($code);

            if ($existingBuiltIn !== null) {
                return ['ok' => false, 'error' => lkn_hn_lang('This code is already used by a built-in or file-based notification.')];
            }

            $this->repository->create($code, $description ?: null, $hookCase->value, $category, $enabled);

            return ['ok' => true, 'code' => $code];
        }

        $this->repository->update($editing['id'], $description ?: null, $hookCase->value, $category, $enabled);

        return ['ok' => true, 'code' => $editing['code']];
    }

    private function findRecipeForHook(NotificationFactory $factory, Hooks $hook): ?array
    {
        $recipes = require dirname(__DIR__, 3) . '/Notification/Application/built_in_notifications_recipes.php';

        foreach ($recipes as $recipe) {
            foreach ($recipe['hooks'] as $recipeHook) {
                if ($recipeHook === $hook) {
                    return $recipe;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, array{label:string, hooks:array<int, array{value:string, name:string}>}>
     */
    private function getSupportedHooks(): array
    {
        $recipes = require dirname(__DIR__, 3) . '/Notification/Application/built_in_notifications_recipes.php';
        $result = [];

        foreach ($recipes as $recipeKey => $recipe) {
            $hooks = [];

            foreach ($recipe['hooks'] as $hook) {
                $hooks[] = ['value' => $hook->value, 'name' => $hook->name];
            }

            $result[$recipeKey] = [
                'label' => ucfirst($recipeKey),
                'hooks' => $hooks,
            ];
        }

        return $result;
    }
}
