<?php

namespace Lkn\HookNotification\Core\Notification\Domain;

/**
 * Notification instance created from a row in mod_lkn_hook_notification_custom.
 * Behaves like a built-in notification but its code/description/hook are
 * defined by an admin at runtime rather than in PHP source.
 */
final class CustomNotification extends AbstractNotification
{
}
