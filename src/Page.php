<?php

namespace GlpiPlugin\Mailbridge;

use Glpi\Application\View\TemplateRenderer;
use Html;
use Plugin;

/**
 * Moldura da tela única do MailBridge: seções, endereços e dados comuns.
 * O conteúdo de cada seção fica nas classes de src/Screen.
 */
final class Page
{
    public const SECTIONS = ['overview', 'sending', 'events', 'templates', 'queue', 'diagnostics'];

    private const FLASH = 'plugin_mailbridge_flash';

    /**
     * Caminho da tela a partir da raiz do GLPI (usado no menu).
     */
    public static function path(): string
    {
        return '/' . Plugin::getWebDir('mailbridge', false) . '/front/index.php';
    }

    public static function webDir(): string
    {
        return (string) Plugin::getWebDir('mailbridge');
    }

    /**
     * Endereço de uma seção.
     */
    public static function url(string $section = 'overview', array $params = []): string
    {
        $query = [];
        if ($section !== '' && $section !== 'overview') {
            $query['section'] = $section;
        }
        $query += $params;

        return self::webDir() . '/front/index.php' . ($query ? '?' . http_build_query($query) : '');
    }

    /**
     * Seções: código => rótulo, ícone e descrição curta.
     *
     * @return array<string,array{label:string,icon:string}>
     */
    public static function sections(): array
    {
        return [
            'overview'    => ['label' => __('Visão geral', 'mailbridge'), 'icon' => 'ti ti-layout-dashboard'],
            'sending'     => ['label' => __('Envio', 'mailbridge'), 'icon' => 'ti ti-send'],
            'events'      => ['label' => __('Eventos e destinatários', 'mailbridge'), 'icon' => 'ti ti-users'],
            'templates'   => ['label' => __('Modelos', 'mailbridge'), 'icon' => 'ti ti-template'],
            'queue'       => ['label' => __('Fila e histórico', 'mailbridge'), 'icon' => 'ti ti-inbox'],
            'diagnostics' => ['label' => __('Diagnóstico', 'mailbridge'), 'icon' => 'ti ti-stethoscope'],
        ];
    }

    /**
     * Mostra a seção pedida.
     */
    public static function display(array $get): void
    {
        $section = (string) ($get['section'] ?? 'overview');
        if (!in_array($section, self::SECTIONS, true)) {
            $section = 'overview';
        }

        $screens = [
            'overview'    => Screen\OverviewScreen::class,
            'sending'     => Screen\SendingScreen::class,
            'events'      => Screen\EventsScreen::class,
            'templates'   => Screen\TemplatesScreen::class,
            'queue'       => Screen\QueueScreen::class,
            'diagnostics' => Screen\DiagnosticsScreen::class,
        ];
        if (!class_exists($screens[$section])) {
            // Seção ainda não disponível nesta instalação: volta para a Visão geral.
            $section = 'overview';
        }
        [$template, $data] = $screens[$section]::build($get);

        Html::header('MailBridge', $_SERVER['PHP_SELF'], 'config', Menu::class);
        TemplateRenderer::getInstance()->display('@mailbridge/' . $template, self::context($section) + $data);
        Html::footer();
    }

    /**
     * Dados comuns a todas as seções.
     */
    public static function context(string $section): array
    {
        $health = Facts::health();
        $labels = [
            Health::ACTIVE    => __('Ativo', 'mailbridge'),
            Health::INACTIVE  => __('Inativo', 'mailbridge'),
            Health::ATTENTION => __('Precisa de atenção', 'mailbridge'),
        ];
        $tones = [
            Health::ACTIVE    => 'success',
            Health::INACTIVE  => 'secondary',
            Health::ATTENTION => 'warning',
        ];

        $nav = [];
        foreach (self::sections() as $code => $info) {
            $nav[] = $info + ['code' => $code, 'url' => self::url($code), 'current' => $code === $section];
        }

        return [
            'tm' => [
                'section'      => $section,
                'nav'          => $nav,
                'base'         => self::webDir() . '/front/index.php',
                'web_dir'      => self::webDir(),
                'action_url'   => self::webDir() . '/front/action.php',
                'ajax_url'     => self::webDir() . '/ajax/action.php',
                'version'      => PLUGIN_MAILBRIDGE_VERSION,
                'asset_v'      => self::assetVersion(),
                'status'       => $health['status'],
                'status_label' => $labels[$health['status']],
                'status_tone'  => $tones[$health['status']],
                'reasons'      => $health['reasons'],
                'can'          => [
                    'manage' => Rights::can(Rights::MANAGE),
                    'test'   => Rights::can(Rights::TEST),
                    'queue'  => Rights::can(Rights::QUEUE),
                ],
                'js'           => [
                    'ajax'          => self::webDir() . '/ajax/action.php',
                    'working'       => __('Aguarde…', 'mailbridge'),
                    'network_error' => __('Não foi possível concluir a ação. Recarregue a página e tente de novo.', 'mailbridge'),
                    'required'      => __('Preencha este campo.', 'mailbridge'),
                    'ok'            => __('Feito', 'mailbridge'),
                    'failed'        => __('Falhou', 'mailbridge'),
                    'skipped'       => __('Não se aplica', 'mailbridge'),
                    'what_to_try'   => __('O que tentar:', 'mailbridge'),
                    'technical'     => __('Detalhe técnico', 'mailbridge'),
                    'select_some'   => __('Marque pelo menos um e-mail da lista.', 'mailbridge'),
                    'preview_wait'  => __('Atualizando a prévia…', 'mailbridge'),
                    'unsaved_title' => __('Você alterou a configuração e ainda não salvou.', 'mailbridge'),
                    'unsaved_tip'   => __('Os testes usam a configuração salva: clique em Salvar e teste de novo.', 'mailbridge'),
                ],
            ],
        ];
    }

    /**
     * Versão dos arquivos de estilo e de script: muda quando eles mudam, para
     * o navegador não continuar usando uma cópia antiga depois de atualizar.
     */
    private static function assetVersion(): string
    {
        $dir   = Plugin::getPhpDir('mailbridge');
        $stamp = max((int) @filemtime($dir . '/css/mailbridge.css'), (int) @filemtime($dir . '/js/mailbridge.js'));

        return PLUGIN_MAILBRIDGE_VERSION . '-' . $stamp;
    }

    // ------------------------------------------------------------------
    // Erros de formulário guardados de um envio para a próxima exibição
    // ------------------------------------------------------------------

    /**
     * Guarda os erros e o que foi digitado para reexibir o formulário.
     * Nunca guarda senhas.
     */
    public static function flash(string $form, array $errors, array $old): void
    {
        unset($old['smtp_passwd'], $old['_glpi_csrf_token']);
        $_SESSION[self::FLASH][$form] = ['errors' => $errors, 'old' => $old];
    }

    /**
     * @return array{errors:array<string,string>,old:array}
     */
    public static function pullFlash(string $form): array
    {
        $flash = $_SESSION[self::FLASH][$form] ?? null;
        unset($_SESSION[self::FLASH][$form]);

        return is_array($flash) ? $flash : ['errors' => [], 'old' => []];
    }
}
