<?php

namespace GlpiPlugin\Mailtech;

use Notification;
use NotificationTarget;

/**
 * Avisos por e-mail NATIVOS do GLPI (os de Configurar > Notificações).
 * Com o modo e-mail ligado eles também disparam; o MailTech só os lista e
 * permite ligar/desligar cada um. Destinatários e modelos deles continuam
 * sendo editados na tela nativa.
 */
final class NativeNotifications
{
    private const JOIN = [
        'glpi_notifications_notificationtemplates' => [
            'ON' => [
                'glpi_notifications_notificationtemplates' => 'notifications_id',
                'glpi_notifications'                       => 'id',
                ['AND' => ['glpi_notifications_notificationtemplates.mode' => 'mailing']],
            ],
        ],
    ];

    /**
     * @return array{total:int,active:int}
     */
    public static function counts(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $rows = $DB->request([
            'SELECT'     => [
                Db::expr('COUNT(DISTINCT `glpi_notifications`.`id`) AS `total`'),
                Db::expr('COUNT(DISTINCT CASE WHEN `glpi_notifications`.`is_active` = 1 THEN `glpi_notifications`.`id` END) AS `active`'),
            ],
            'FROM'       => 'glpi_notifications',
            'INNER JOIN' => self::JOIN,
        ]);
        foreach ($rows as $row) {
            return ['total' => (int) $row['total'], 'active' => (int) $row['active']];
        }

        return ['total' => 0, 'active' => 0];
    }

    /**
     * Página da lista (ordenada por tipo e nome).
     *
     * @return array<int,array{id:int,name:string,type:string,event:string,active:bool,url:string}>
     */
    public static function page(string $search, int $start, int $limit): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $criteria = [
            'SELECT'     => ['glpi_notifications.id', 'glpi_notifications.name', 'glpi_notifications.itemtype', 'glpi_notifications.event', 'glpi_notifications.is_active'],
            'DISTINCT'   => true,
            'FROM'       => 'glpi_notifications',
            'INNER JOIN' => self::JOIN,
            'ORDER'      => ['glpi_notifications.itemtype', 'glpi_notifications.name'],
            'START'      => max(0, $start),
            'LIMIT'      => max(1, $limit),
        ];
        $where = self::searchCriteria($search);
        if ($where) {
            $criteria['WHERE'] = $where;
        }

        $targets = [];
        $out     = [];
        foreach ($DB->request($criteria) as $row) {
            $itemtype = (string) $row['itemtype'];
            $type     = $itemtype;
            $event    = (string) $row['event'];
            if ($itemtype !== '' && class_exists($itemtype) && is_subclass_of($itemtype, \CommonGLPI::class)) {
                $type = (string) $itemtype::getTypeName(1);
                if (!array_key_exists($itemtype, $targets)) {
                    try {
                        $target             = NotificationTarget::getInstanceByType($itemtype);
                        $targets[$itemtype] = $target ? (array) $target->getAllEvents() : [];
                    } catch (\Throwable $e) {
                        $targets[$itemtype] = [];
                    }
                }
                $event = (string) ($targets[$itemtype][$event] ?? $event);
            }

            $out[] = [
                'id'     => (int) $row['id'],
                'name'   => Text::decode((string) $row['name']),
                'type'   => $type,
                'event'  => $event,
                'active' => (int) $row['is_active'] === 1,
                'url'    => Notification::getFormURLWithID((int) $row['id']),
            ];
        }

        return $out;
    }

    public static function total(string $search): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $criteria = [
            'SELECT'     => [Db::expr('COUNT(DISTINCT `glpi_notifications`.`id`) AS `total`')],
            'FROM'       => 'glpi_notifications',
            'INNER JOIN' => self::JOIN,
        ];
        $where = self::searchCriteria($search);
        if ($where) {
            $criteria['WHERE'] = $where;
        }
        foreach ($DB->request($criteria) as $row) {
            return (int) $row['total'];
        }

        return 0;
    }

    private static function searchCriteria(string $search): array
    {
        $search = trim($search);
        if ($search === '') {
            return [];
        }

        return ['glpi_notifications.name' => ['LIKE', '%' . addcslashes((string) Text::sanitize($search), '%_') . '%']];
    }

    public static function toggle(int $id, bool $on): bool
    {
        $notification = new Notification();
        if ($id <= 0 || !$notification->getFromDB($id)) {
            return false;
        }

        return (bool) $notification->update(['id' => $id, 'is_active' => $on ? 1 : 0]);
    }
}
