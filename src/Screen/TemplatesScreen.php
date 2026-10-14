<?php

namespace GlpiPlugin\Mailbridge\Screen;

use Glpi\Toolbox\FrontEnd;
use GlpiPlugin\Mailbridge\Actions;
use GlpiPlugin\Mailbridge\Page;
use GlpiPlugin\Mailbridge\Rule;
use GlpiPlugin\Mailbridge\Template;
use GlpiPlugin\Mailbridge\Text;
use GlpiPlugin\Mailbridge\Translation;
use Html;

/**
 * Modelos: a mensagem (assunto e corpo) de cada regra, por idioma.
 */
final class TemplatesScreen
{
    /**
     * @return array{0:string,1:array}
     */
    public static function build(array $get): array
    {
        $rule = new Rule();
        if (isset($get['rule']) && (int) $get['rule'] > 0 && $rule->getFromDB((int) $get['rule'])) {
            return self::editor($rule, $get);
        }

        $rows      = $rule->find([], ['id']);
        $events    = Rule::getEvents();
        $languages = Translation::languagesByRule(array_map('intval', array_keys($rows)));

        $list = [];
        foreach ($rows as $row) {
            $id     = (int) $row['id'];
            $list[] = [
                'id'        => $id,
                'name'      => Text::decode((string) $row['name']),
                'event'     => $events[(string) $row['event']] ?? (string) $row['event'],
                'subject'   => Text::decode((string) $row['subject']),
                'active'    => (int) $row['is_active'] === 1,
                'languages' => $languages[$id] ?? [],
                'url'       => Page::url('templates', ['rule' => $id]),
            ];
        }

        return ['templates.html.twig', ['list' => $list, 'events_url' => Page::url('events')]];
    }

    /**
     * Editor do modelo de uma regra.
     */
    private static function editor(Rule $rule, array $get): array
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $id           = (int) $rule->getID();
        $flash        = Page::pullFlash('template');
        $all          = Actions::languages();
        $translations = Translation::forRule($id);
        $event        = (string) $rule->fields['event'];

        $language = (string) ($get['lang'] ?? '');
        if ($language !== '' && !isset($all[$language])) {
            $language = '';
        }

        $default = [
            'subject'   => Text::decode((string) $rule->fields['subject']),
            'body'      => Text::decode((string) $rule->fields['body']),
            'body_text' => Text::decode((string) ($rule->fields['body_text'] ?? '')),
        ];
        // Tradução nova começa com o texto padrão, para a pessoa traduzir por cima.
        $values = $language !== '' ? ($translations[$language] ?? $default) : $default;
        foreach (['subject', 'body', 'body_text'] as $key) {
            if (isset($flash['old'][$key])) {
                $values[$key] = (string) $flash['old'][$key];
            }
        }

        // Abas de idioma.
        $tabs = [[
            'code'    => '',
            'label'   => __('Padrão (todos os idiomas)', 'mailbridge'),
            'url'     => Page::url('templates', ['rule' => $id]),
            'current' => $language === '',
        ]];
        foreach ($translations as $code => $ignored) {
            $tabs[] = [
                'code'    => $code,
                'label'   => $all[$code] ?? $code,
                'url'     => Page::url('templates', ['rule' => $id, 'lang' => $code]),
                'current' => $language === $code,
            ];
        }
        if ($language !== '' && !isset($translations[$language])) {
            $tabs[] = [
                'code'    => $language,
                'label'   => sprintf(__('%s (nova tradução)', 'mailbridge'), $all[$language]),
                'url'     => Page::url('templates', ['rule' => $id, 'lang' => $language]),
                'current' => true,
            ];
        }

        $addable = array_diff_key($all, $translations, $language !== '' ? [$language => 1] : []);

        // Prévia inicial montada no servidor (funciona mesmo sem JavaScript).
        $sample  = Template::sample($event, Actions::currentUserName(), (string) ($CFG_GLPI['url_base'] ?? ''));
        $preview = Template::compose(['body' => Template::clean($values['body'])] + $values, $sample);

        [$default_subject, $default_body] = Rule::defaultTemplate($event);

        // Editor de texto formatado do próprio GLPI (TinyMCE), carregado só nesta tela.
        Html::requireJs('tinymce');
        $editor_language = (string) ($_SESSION['glpilanguage'] ?? 'en_GB');
        if (!file_exists(GLPI_ROOT . "/public/lib/tinymce-i18n/langs6/$editor_language.js")) {
            $editor_language = (string) ($CFG_GLPI['languages'][$_SESSION['glpilanguage'] ?? '']['2'] ?? 'en_GB');
            if (!file_exists(GLPI_ROOT . "/public/lib/tinymce-i18n/langs6/$editor_language.js")) {
                $editor_language = 'en_GB';
            }
        }

        $result = $_SESSION['plugin_mailbridge_result'] ?? null;
        unset($_SESSION['plugin_mailbridge_result']);

        $placeholders = [];
        foreach (Template::placeholders() as $name => $description) {
            $placeholders[] = ['name' => $name, 'description' => $description];
        }

        return ['template_form.html.twig', [
            'id'              => $id,
            'rule_name'       => Text::decode((string) $rule->fields['name']),
            'event_label'     => Rule::getEvents()[$event] ?? $event,
            'lang'            => $language,
            'lang_name'       => $language !== '' ? $all[$language] : '',
            'is_translation'  => $language !== '' && isset($translations[$language]),
            'tabs'            => $tabs,
            'addable'         => $addable,
            'suggested'       => isset($addable['en_GB']) ? 'en_GB' : (string) array_key_first($addable),
            'v'               => $values,
            'errors'          => $flash['errors'],
            'placeholders'    => $placeholders,
            'defaults'        => ['subject' => $default_subject, 'body' => $default_body],
            'preview'         => $preview,
            'unknown'         => Template::unknownVariables(array_keys(Template::placeholders()), $values['subject'], $values['body'], $values['body_text']),
            'test_email'      => Actions::currentUserEmail(),
            'result'          => is_array($result) && isset($result['steps']) ? $result : null,
            'back_url'        => Page::url('templates'),
            'rule_url'        => Page::url('events', ['rule' => $id]),
            'base_url'        => Page::url(''),
            'rich'            => [
                'lang'     => $editor_language !== 'en_GB' ? $editor_language : '',
                'lang_url' => $CFG_GLPI['root_doc'] . '/public/lib/tinymce-i18n/langs6/' . $editor_language . '.js',
                'skin'     => $CFG_GLPI['root_doc'] . '/public/lib/tinymce/skins/ui',
                'cache'    => class_exists(FrontEnd::class) ? '?v=' . FrontEnd::getVersionCacheKey(GLPI_VERSION) : '',
            ],
        ]];
    }
}
