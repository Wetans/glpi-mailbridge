<?php

namespace GlpiPlugin\Mailbridge;

use Config as CoreConfig;
use Plugin;

/**
 * Traz os dados de uma instalação com o nome antigo do plugin ("Techmail",
 * pasta `techmail`, versões 1.0.0 e 1.1.0) para o MailBridge.
 *
 * Regras desta classe:
 * - só COPIA: tabelas, ajustes, permissões e histórico antigos ficam como
 *   estavam, para dar para voltar atrás;
 * - roda uma vez (fica anotado no ajuste `legacy_import`) e pode ser chamada
 *   de novo sem efeito;
 * - nunca sobrescreve o que já existe no MailBridge.
 *
 * Este arquivo, a leitura de exportações antigas (Porter) e o histórico de
 * alterações (Audit) são os únicos lugares onde o nome antigo ainda aparece.
 */
final class LegacyImport
{
    /** Pasta (identificador no GLPI) do plugin antigo. */
    public const KEY = 'techmail';

    public const CONTEXT = 'plugin:techmail';

    public const RIGHT = 'plugin_techmail';

    public const RULE_CLASS = 'GlpiPlugin\\Techmail\\Rule';

    private const RULES        = 'glpi_plugin_techmail_rules';
    private const TRANSLATIONS = 'glpi_plugin_techmail_ruletranslations';
    private const FAILURES     = 'glpi_plugin_techmail_failures';
    private const RULES_FK     = 'plugin_techmail_rules_id';

    /**
     * Existem dados do plugin antigo neste GLPI?
     */
    public static function found(): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        return $DB->tableExists(self::RULES);
    }

    /**
     * O plugin antigo está ATIVO? (Os dois ativos mandariam avisos repetidos.)
     */
    public static function legacyActive(): bool
    {
        return (new Plugin())->isActivated(self::KEY);
    }

    /**
     * Registro do plugin antigo em glpi_plugins, ou null.
     *
     * @return array{id:int,state:int,version:string}|null
     */
    public static function legacyPlugin(): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;

        foreach ($DB->request(['FROM' => 'glpi_plugins', 'WHERE' => ['directory' => self::KEY], 'LIMIT' => 1]) as $row) {
            return ['id' => (int) $row['id'], 'state' => (int) $row['state'], 'version' => (string) $row['version']];
        }

        return null;
    }

    /**
     * O que foi trazido (ou [] se a importação ainda não rodou).
     *
     * @return array<string,mixed>
     */
    public static function report(): array
    {
        $report = json_decode(Settings::text('legacy_import'), true);

        return is_array($report) ? $report : [];
    }

    /**
     * Importa o que existir do plugin antigo. Chamada pela instalação, depois
     * que as tabelas do MailBridge já existem.
     *
     * @return array<string,mixed> relatório; `ran` diz se algo foi feito agora
     */
    public static function run(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (self::report()) {
            return ['ran' => false, 'reason' => 'done'];
        }
        if (!$DB->tableExists(self::RULES)) {
            return ['ran' => false, 'reason' => 'nothing'];
        }

        $legacy = self::legacyPlugin();
        $report = [
            'from'         => self::KEY,
            'version'      => $legacy['version'] ?? '',
            'date'         => date('Y-m-d H:i:s'),
            'rules'        => 0,
            'translations' => 0,
            'failures'     => 0,
            'history'      => 0,
            'settings'     => 0,
            'rights'       => 0,
            'deactivated'  => 0,
            'notes'        => [],
        ];

        // 1. Regras, traduções e motivos de falha (com os mesmos números).
        if (countElementsInTable(Rule::getTable()) > 0) {
            // Já há regras no MailBridge: não mistura nem sobrescreve.
            $report['notes'][] = 'rules-kept';
        } else {
            $report['rules'] = self::copyTable(self::RULES, Rule::getTable());
            if ($DB->tableExists(self::TRANSLATIONS)) {
                $report['translations'] = self::copyTable(
                    self::TRANSLATIONS,
                    Translation::getTable(),
                    [self::RULES_FK => 'plugin_mailbridge_rules_id']
                );
            }
            if ($DB->tableExists(self::FAILURES) && countElementsInTable(FailureLog::getTable()) === 0) {
                $report['failures'] = self::copyTable(self::FAILURES, FailureLog::getTable());
            }
            $report['history'] = self::copyHistory();
        }

        // 2. Ajustes: só os que o MailBridge conhece e ainda não tem.
        $old     = CoreConfig::getConfigurationValues(self::CONTEXT);
        $current = CoreConfig::getConfigurationValues(Settings::CONTEXT);
        $known   = Settings::defaults();
        // Não são herdados: o relatório desta importação e a opção de APAGAR os dados ao
        // desinstalar (uma escolha destrutiva feita para o plugin antigo não vale sozinha
        // para o novo: no MailBridge ela começa desligada).
        unset($known['legacy_import'], $known['purge_on_uninstall']);
        $copy = array_diff_key(
            array_intersect_key(is_array($old) ? $old : [], $known),
            is_array($current) ? $current : []
        );
        if ($copy) {
            // Os valores já estão no formato em que o GLPI os guarda: vão como estão.
            CoreConfig::setConfigurationValues(Settings::CONTEXT, $copy);
            $report['settings'] = count($copy);
        }

        // 3. Permissões por perfil: copia para os perfis que ainda não têm nada no nome novo.
        Rights::register();
        $rights = $DB->request([
            'FROM'  => 'glpi_profilerights',
            'WHERE' => ['name' => self::RIGHT, 'rights' => ['>', 0]],
        ]);
        foreach ($rights as $row) {
            $DB->update(
                'glpi_profilerights',
                ['rights' => (int) $row['rights']],
                ['profiles_id' => (int) $row['profiles_id'], 'name' => Rights::NAME, 'rights' => 0]
            );
            $report['rights'] += (int) $DB->affectedRows();
        }

        // 4. Os dois plugins ativos mandariam o mesmo aviso duas vezes: o antigo é
        //    DESATIVADO (não desinstalado; os dados dele continuam intactos).
        if ($legacy !== null && $legacy['state'] === Plugin::ACTIVATED) {
            $plugin = new Plugin();
            if ($plugin->unactivate($legacy['id'])) {
                $report['deactivated'] = 1;
            }
        }

        Settings::set(['legacy_import' => (string) json_encode($report)]);
        $report['ran'] = true;

        return $report;
    }

    /**
     * Copia as linhas de uma tabela antiga para a nova, só nas colunas que as
     * duas têm (o Techmail 1.0.0 tinha menos colunas). Os dados não passam pelo PHP.
     *
     * @param array<string,string> $rename coluna antiga => coluna nova
     */
    private static function copyTable(string $from, string $to, array $rename = []): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $source = array_keys((array) $DB->listFields($from, false));
        $target = array_keys((array) $DB->listFields($to, false));

        $select = [];
        $insert = [];
        foreach ($source as $column) {
            $destination = $rename[$column] ?? $column;
            if (in_array($destination, $target, true)) {
                $select[] = '`' . $column . '`';
                $insert[] = '`' . $destination . '`';
            }
        }
        if (!$insert) {
            return 0;
        }

        $ok = Db::run(sprintf(
            'INSERT INTO `%s` (%s) SELECT %s FROM `%s` ORDER BY `id`',
            $to,
            implode(', ', $insert),
            implode(', ', $select),
            $from
        ));

        return $ok ? (int) $DB->affectedRows() : 0;
    }

    /**
     * Copia o histórico ("quem alterou o quê") das regras para o nome novo da classe.
     */
    private static function copyHistory(): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        // Só uma vez: se já existe histórico no nome novo, não duplica.
        // (No GLPI 10 o valor de um critério precisa ir escapado; no 11, não.)
        $mine = class_exists('\\Glpi\\Toolbox\\Sanitizer') ? Db::escape(Rule::class) : Rule::class;
        if (countElementsInTable('glpi_logs', ['itemtype' => $mine]) > 0) {
            return 0;
        }

        $columns = 'items_id, itemtype_link, linked_action, user_name, date_mod, id_search_option, old_value, new_value';
        $ok      = Db::run(sprintf(
            "INSERT INTO `glpi_logs` (itemtype, %s) SELECT '%s', %s FROM `glpi_logs` WHERE itemtype = '%s' ORDER BY `id`",
            $columns,
            Db::escape(Rule::class),
            $columns,
            Db::escape(self::RULE_CLASS)
        ));

        return $ok ? (int) $DB->affectedRows() : 0;
    }
}
