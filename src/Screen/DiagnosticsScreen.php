<?php

namespace GlpiPlugin\Mailtech\Screen;

use GlpiPlugin\Mailtech\Audit;
use GlpiPlugin\Mailtech\Facts;
use GlpiPlugin\Mailtech\LegacyImport;
use GlpiPlugin\Mailtech\Page;
use GlpiPlugin\Mailtech\Rights;
use GlpiPlugin\Mailtech\Settings;

/**
 * Diagnóstico: verificações com a ação sugerida, alterações recentes,
 * cópia da configuração e restauração dos padrões.
 */
final class DiagnosticsScreen
{
    /**
     * @return array{0:string,1:array}
     */
    public static function build(array $get): array
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $checks = [];
        foreach (Facts::health(true)['checks'] as $check) {
            $check['url'] = $check['section'] !== '' ? Page::url($check['section'], $check['params']) : '';
            $checks[]     = $check;
        }

        return ['diagnostics.html.twig', [
            'checks'      => $checks,
            'changes'     => Audit::recent(15),
            'export_url'  => Page::webDir() . '/front/export.php',
            'purge'       => (int) Settings::get('purge_on_uninstall') === 1,
            'profile_url' => $CFG_GLPI['root_doc'] . '/front/profile.php',
            'rights'      => Rights::labels(),
            'imported'    => LegacyImport::report(),
            'about'       => [
                'plugin' => PLUGIN_MAILTECH_VERSION,
                'glpi'   => GLPI_VERSION,
                'php'    => PHP_VERSION,
            ],
        ]];
    }
}
