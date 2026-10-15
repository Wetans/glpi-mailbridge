<?php

namespace GlpiPlugin\Mailtech;

use Config as CoreConfig;
use Plugin;

/**
 * Traz para o MailTech os dados de uma instalação com um nome anterior do
 * plugin: "MailBridge" (pasta `mailbridge`, 1.0) ou "Techmail" (pasta
 * `techmail`, versões 1.0.0 e 1.1.0).
 *
 * Regras desta classe:
 * - só COPIA: tabelas, ajustes, permissões e histórico antigos ficam como
 *   estavam, para dar para voltar atrás;
 * - usa uma fonte só, a mais nova que existir (uma instalação do MailBridge
 *   já guarda o que veio do Techmail);
 * - roda uma vez (fica anotado no ajuste `legacy_import`) e pode ser chamada
 *   de novo sem efeito;
 * - nunca sobrescreve o que já existe no MailTech.
 *
 * Este arquivo, a leitura de exportações antigas (Porter), a fila (Queue) e o
 * histórico de alterações (Audit) são os únicos lugares onde os nomes antigos
 * ainda aparecem.
 */
final class LegacyImport
{
    /**
     * Nomes anteriores do plugin, do mais novo para o mais antigo. A chave é a
     * pasta (identificador no GLPI); dela saem as tabelas
     * ("glpi_plugin_<chave>_..."), o contexto dos ajustes ("plugin:<chave>") e
     * a permissão ("plugin_<chave>").
     */
    private const SOURCES = [
        'mailbridge' => ['name' => 'MailBridge', 'namespace' => 'Mailbridge'],
        'techmail'   => ['name' => 'Techmail', 'namespace' => 'Techmail'],
    ];

    /**
     * Pastas (identificadores no GLPI) dos nomes anteriores, do mais novo para o mais antigo.
     *
     * @return string[]
     */
    public static function keys(): array
    {
        return array_keys(self::SOURCES);
    }

    /**
     * Nome que as pessoas viam para um identificador anterior.
     */
    public static function name(string $key): string
    {
        return self::SOURCES[$key]['name'] ?? $key;
    }

    /**
     * Identificador anterior mais novo que tem dados neste GLPI, ou null.
     */
    public static function source(): ?string
    {
        /** @var \DBmysql $DB */
        global $DB;

        foreach (self::keys() as $key) {
            if ($DB->tableExists(self::table($key, 'rules'))) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Existem dados de um plugin anterior neste GLPI?
     */
    public static function found(): bool
    {
        return self::source() !== null;
    }

    /**
     * Nome do plugin anterior que está ATIVO junto com este, ou null.
     * (Os dois ativos mandariam avisos repetidos.)
     */
    public static function activeName(): ?string
    {
        $plugin = new Plugin();
        foreach (self::keys() as $key) {
            if ($plugin->isActivated($key)) {
                return self::name($key);
            }
        }

        return null;
    }

    /**
     * Algum plugin anterior está ATIVO?
     */
    public static function legacyActive(): bool
    {
        return self::activeName() !== null;
    }

    /**
     * Registro de um plugin anterior em glpi_plugins, ou null.
     *
     * @return array{id:int,state:int,version:string}|null
     */
    public static function legacyPlugin(string $key): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;

        foreach ($DB->request(['FROM' => 'glpi_plugins', 'WHERE' => ['directory' => $key], 'LIMIT' => 1]) as $row) {
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
     * Importa o que existir do plugin anterior mais novo. Chamada pela
     * instalação, depois que as tabelas do MailTech já existem.
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
        $key = self::source();
        if ($key === null) {
            return ['ran' => false, 'reason' => 'nothing'];
        }

        $legacy = self::legacyPlugin($key);
        $report = [
            'from'         => $key,
            'name'         => self::name($key),
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
            // Já há regras no MailTech: não mistura nem sobrescreve.
            $report['notes'][] = 'rules-kept';
        } else {
            $report['rules'] = self::copyTable(self::table($key, 'rules'), Rule::getTable());
            if ($DB->tableExists(self::table($key, 'ruletranslations'))) {
                $report['translations'] = self::copyTable(
                    self::table($key, 'ruletranslations'),
                    Translation::getTable(),
                    ['plugin_' . $key . '_rules_id' => 'plugin_mailtech_rules_id']
                );
            }
            if ($DB->tableExists(self::table($key, 'failures')) && countElementsInTable(FailureLog::getTable()) === 0) {
                $report['failures'] = self::copyTable(self::table($key, 'failures'), FailureLog::getTable());
            }
            $report['history'] = self::copyHistory('GlpiPlugin\\' . self::SOURCES[$key]['namespace'] . '\\Rule');
        }

        // 2. Ajustes: só os que o MailTech conhece e ainda não tem.
        $old     = CoreConfig::getConfigurationValues('plugin:' . $key);
        $current = CoreConfig::getConfigurationValues(Settings::CONTEXT);
        $known   = Settings::defaults();
        // Não são herdados: o relatório de uma importação anterior e a opção de APAGAR os
        // dados ao desinstalar (uma escolha destrutiva feita para o plugin anterior não vale
        // sozinha para o novo: no MailTech ela começa desligada).
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
            'WHERE' => ['name' => 'plugin_' . $key, 'rights' => ['>', 0]],
        ]);
        foreach ($rights as $row) {
            $DB->update(
                'glpi_profilerights',
                ['rights' => (int) $row['rights']],
                ['profiles_id' => (int) $row['profiles_id'], 'name' => Rights::NAME, 'rights' => 0]
            );
            $report['rights'] += (int) $DB->affectedRows();
        }

        // 4. Dois plugins ativos mandariam o mesmo aviso duas vezes: todo plugin anterior
        //    ativo é DESATIVADO (não desinstalado; os dados dele continuam intactos).
        foreach (self::keys() as $old_key) {
            $row = self::legacyPlugin($old_key);
            if ($row !== null && $row['state'] === Plugin::ACTIVATED) {
                $plugin = new Plugin();
                if ($plugin->unactivate($row['id'])) {
                    $report['deactivated']++;
                }
            }
        }

        Settings::set(['legacy_import' => (string) json_encode($report)]);
        $report['ran'] = true;

        return $report;
    }

    /**
     * Nome de uma tabela de um plugin anterior.
     */
    private static function table(string $key, string $what): string
    {
        return 'glpi_plugin_' . $key . '_' . $what;
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
     *
     * @param string $old_class classe das regras no plugin anterior
     */
    private static function copyHistory(string $old_class): int
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
            Db::escape($old_class)
        ));

        return $ok ? (int) $DB->affectedRows() : 0;
    }
}
