<?php

namespace GlpiPlugin\Mailbridge;

use CommonGLPI;
use Html;
use Session;

/**
 * Aba "MailBridge" na tela de perfis do GLPI (permissões finas do plugin).
 */
class Profile extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return 'MailBridge';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof \Profile && $item->getID() > 0 && ($item->fields['interface'] ?? '') === 'central') {
            return self::createTabEntry('MailBridge');
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof \Profile) {
            return false;
        }

        $canedit = Session::haveRightsOr(\Profile::$rightname, [CREATE, UPDATE, PURGE]);

        echo "<div class='spaced'>";
        if ($canedit) {
            echo "<form method='post' action='" . Text::esc(\Profile::getFormURL()) . "'>";
        }

        $item->displayRightsChoiceMatrix(
            [
                [
                    'rights' => Rights::labels(),
                    'label'  => __('MailBridge (e-mails de notificação)', 'mailbridge'),
                    'field'  => Rights::NAME,
                ],
            ],
            [
                'canedit'       => $canedit,
                'default_class' => 'tab_bg_2',
                'title'         => 'MailBridge',
            ]
        );

        echo "<p class='form-help'>" . __(
            'Quem já tem o direito de alterar a configuração geral do GLPI tem acesso total ao MailBridge, mesmo sem marcar nada aqui.',
            'mailbridge'
        ) . "</p>";

        if ($canedit) {
            echo "<div class='center'>";
            echo Html::hidden('id', ['value' => $item->getID()]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']);
            echo "</div>";
            Html::closeForm();
        }
        echo "</div>";

        return true;
    }
}
