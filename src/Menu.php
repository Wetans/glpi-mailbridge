<?php

namespace GlpiPlugin\Mailbridge;

use CommonGLPI;

/**
 * Entrada única do plugin no menu: Configurar > MailBridge.
 */
class Menu extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return 'MailBridge';
    }

    public static function getMenuName()
    {
        return 'MailBridge';
    }

    public static function getIcon()
    {
        return 'ti ti-mail-cog';
    }

    public static function canView()
    {
        return Rights::can(Rights::VIEW);
    }

    public static function getMenuContent()
    {
        if (!static::canView()) {
            return false;
        }

        return [
            'title' => static::getMenuName(),
            'page'  => Page::path(),
            'icon'  => static::getIcon(),
        ];
    }
}
