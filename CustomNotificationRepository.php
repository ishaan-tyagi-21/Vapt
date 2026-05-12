<?php

namespace Lkn\HookNotification\Core\Notification\Infrastructure\Repositories;

use WHMCS\Database\Capsule;

final class CustomNotificationRepository
{
    private const TABLE = 'mod_lkn_hook_notification_custom';

    private static bool $tableEnsured = false;

    /**
     * Creates the table if it doesn't exist. Handles cases where the module
     * was deployed without running the v440 migration (e.g. file drop-in
     * update). Runs at most once per request.
     */
    private function ensureTable(): void
    {
        if (self::$tableEnsured) {
            return;
        }

        Capsule::connection()->statement(
            'CREATE TABLE IF NOT EXISTS mod_lkn_hook_notification_custom (
                id INT AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(255) NOT NULL UNIQUE,
                description TEXT NULL,
                hook VARCHAR(255) NOT NULL,
                category VARCHAR(20) NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;'
        );

        self::$tableEnsured = true;
    }

    /**
     * @return array<int, array{id:int, code:string, description:?string, hook:string, category:?string, enabled:int}>
     */
    public function all(): array
    {
        $this->ensureTable();

        return array_map(
            static fn ($row) => (array) $row,
            Capsule::table(self::TABLE)->orderBy('code')->get()->all()
        );
    }

    /**
     * @return array<int, array{id:int, code:string, description:?string, hook:string, category:?string, enabled:int}>
     */
    public function allEnabled(): array
    {
        $this->ensureTable();

        return array_map(
            static fn ($row) => (array) $row,
            Capsule::table(self::TABLE)->where('enabled', 1)->get()->all()
        );
    }

    public function findByCode(string $code): ?array
    {
        $this->ensureTable();

        $row = Capsule::table(self::TABLE)->where('code', $code)->first();

        return $row ? (array) $row : null;
    }

    public function existsByCode(string $code): bool
    {
        $this->ensureTable();

        return Capsule::table(self::TABLE)->where('code', $code)->exists();
    }

    public function create(string $code, ?string $description, string $hook, ?string $category, bool $enabled): int
    {
        $this->ensureTable();

        return (int) Capsule::table(self::TABLE)->insertGetId([
            'code' => $code,
            'description' => $description,
            'hook' => $hook,
            'category' => $category,
            'enabled' => $enabled ? 1 : 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function update(int $id, ?string $description, string $hook, ?string $category, bool $enabled): void
    {
        $this->ensureTable();

        Capsule::table(self::TABLE)
            ->where('id', $id)
            ->update([
                'description' => $description,
                'hook' => $hook,
                'category' => $category,
                'enabled' => $enabled ? 1 : 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    public function deleteByCode(string $code): void
    {
        $this->ensureTable();

        Capsule::table(self::TABLE)->where('code', $code)->delete();
    }
}
