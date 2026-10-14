<?php

use GlpiPlugin\Mailbridge\Db;
use GlpiPlugin\Mailbridge\FailureLog;
use GlpiPlugin\Mailbridge\LegacyImport;
use GlpiPlugin\Mailbridge\Rights;
use GlpiPlugin\Mailbridge\Rule;
use GlpiPlugin\Mailbridge\Settings;
use GlpiPlugin\Mailbridge\Text;
use GlpiPlugin\Mailbridge\Translation;

/**
 * Instalação e atualização (a mesma função serve para os dois casos e pode
 * rodar várias vezes): cria o que faltar, nunca apaga nem sobrescreve dados.
 *
 * - Techmail 1.0.0: tabela de regras, configurações padrão e regras de exemplo.
 * - Techmail 1.1.0: novos destinatários (solicitantes, observadores), versão
 *                   em texto do modelo, traduções de modelos, motivo das
 *                   falhas de envio e permissões finas.
 * - MailBridge 1.0: o plugin passa a se chamar MailBridge (pasta `mailbridge`).
 *                   Se existir uma instalação com o nome antigo (Techmail), os
 *                   dados dela são COPIADOS para cá (veja LegacyImport); nada
 *                   do antigo é apagado.
 */
function plugin_mailbridge_install(): bool
{
    /** @var DBmysql $DB */
    global $DB;

    $migration = new Migration(PLUGIN_MAILBRIDGE_VERSION);

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $key_sign  = DBConnection::getDefaultPrimaryKeySignOption();
    $suffix    = "ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC";

    // ------------------------------------------------------------------
    // Regras
    // ------------------------------------------------------------------
    $table       = Rule::getTable();
    $first_time  = !$DB->tableExists($table);
    $with_legacy = LegacyImport::found();

    if ($first_time) {
        $migration->displayMessage('Criando a tabela ' . $table);

        $query = "CREATE TABLE `$table` (
            `id` int {$key_sign} NOT NULL AUTO_INCREMENT,
            `name` varchar(255) DEFAULT NULL,
            `comment` text,
            `is_active` tinyint NOT NULL DEFAULT '1',
            `event` varchar(40) NOT NULL DEFAULT 'assigned',
            `filter_entities_id` int {$key_sign} NOT NULL DEFAULT '0',
            `filter_itilcategories_id` int {$key_sign} NOT NULL DEFAULT '0',
            `filter_type` int NOT NULL DEFAULT '0',
            `filter_min_priority` int NOT NULL DEFAULT '0',
            `notify_assigned_users` tinyint NOT NULL DEFAULT '1',
            `notify_assigned_groups` tinyint NOT NULL DEFAULT '0',
            `extra_emails` text,
            `exclude_author` tinyint NOT NULL DEFAULT '1',
            `subject` varchar(255) DEFAULT NULL,
            `body` mediumtext,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `is_active` (`is_active`),
            KEY `event` (`event`),
            KEY `date_creation` (`date_creation`),
            KEY `date_mod` (`date_mod`)
        ) {$suffix};";
        if (!Db::run($query)) {
            $migration->displayWarning('Falha ao criar a tabela ' . $table . ': ' . $DB->error(), true);

            return false;
        }
    }

    // Regras de exemplo: só numa instalação realmente nova (sem dados do plugin antigo para trazer).
    $seed_examples = static function () use ($DB, $table): void {
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

        $examples = [
            [
                'name'                   => 'Chamado atribuído: avisar técnico ou grupo',
                'event'                  => Rule::EVENT_ASSIGNED,
                'is_active'              => 1,
                'notify_assigned_users'  => 1,
                'notify_assigned_groups' => 1,
            ],
            [
                'name'                   => 'Novo chamado: avisar técnicos atribuídos',
                'event'                  => Rule::EVENT_NEW_TICKET,
                'is_active'              => 0,
                'notify_assigned_users'  => 1,
                'notify_assigned_groups' => 1,
            ],
            [
                'name'                   => 'Novo acompanhamento: avisar técnicos atribuídos',
                'event'                  => Rule::EVENT_FOLLOWUP,
                'is_active'              => 0,
                'notify_assigned_users'  => 1,
                'notify_assigned_groups' => 0,
            ],
        ];

        foreach ($examples as $example) {
            [$subject, $body] = Rule::defaultTemplate($example['event']);
            $DB->insert($table, Text::sanitize($example + [
                'subject'       => $subject,
                'body'          => $body,
                'exclude_author' => 1,
                'date_creation' => $now,
                'date_mod'      => $now,
            ]));
        }
    };

    // Techmail 1.1.0: colunas novas (só se ainda não existirem; as regras antigas ficam como estavam).
    $columns = [
        'notify_requesters' => "tinyint NOT NULL DEFAULT '0'",
        'notify_observers'  => "tinyint NOT NULL DEFAULT '0'",
        'body_text'         => 'mediumtext',
    ];
    foreach ($columns as $column => $definition) {
        if (!$DB->fieldExists($table, $column, false)) {
            if (!Db::run("ALTER TABLE `$table` ADD `$column` $definition")) {
                $migration->displayWarning('Falha ao alterar a tabela ' . $table . ': ' . $DB->error(), true);

                return false;
            }
        }
    }

    // ------------------------------------------------------------------
    // Techmail 1.1.0: traduções dos modelos (um registro por regra e idioma)
    // ------------------------------------------------------------------
    $table = Translation::getTable();
    if (!$DB->tableExists($table)) {
        $query = "CREATE TABLE `$table` (
            `id` int {$key_sign} NOT NULL AUTO_INCREMENT,
            `plugin_mailbridge_rules_id` int {$key_sign} NOT NULL DEFAULT '0',
            `language` varchar(10) NOT NULL DEFAULT '',
            `subject` varchar(255) DEFAULT NULL,
            `body` mediumtext,
            `body_text` mediumtext,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`plugin_mailbridge_rules_id`,`language`),
            KEY `date_mod` (`date_mod`)
        ) {$suffix};";
        if (!Db::run($query)) {
            $migration->displayWarning('Falha ao criar a tabela ' . $table . ': ' . $DB->error(), true);

            return false;
        }
    }

    // ------------------------------------------------------------------
    // Techmail 1.1.0: motivo das falhas de envio (o GLPI só guarda isso num log geral)
    // ------------------------------------------------------------------
    $table = FailureLog::getTable();
    if (!$DB->tableExists($table)) {
        $query = "CREATE TABLE `$table` (
            `id` int {$key_sign} NOT NULL AUTO_INCREMENT,
            `queuednotifications_id` int {$key_sign} NOT NULL DEFAULT '0',
            `date_creation` timestamp NULL DEFAULT NULL,
            `error` text,
            `retries_left` int NOT NULL DEFAULT '0',
            `gave_up` tinyint NOT NULL DEFAULT '0',
            PRIMARY KEY (`id`),
            KEY `queuednotifications_id` (`queuednotifications_id`),
            KEY `date_creation` (`date_creation`)
        ) {$suffix};";
        if (!Db::run($query)) {
            $migration->displayWarning('Falha ao criar a tabela ' . $table . ': ' . $DB->error(), true);

            return false;
        }
    }

    // MailBridge 1.0: traz os dados de uma instalação com o nome antigo (Techmail), sem apagar nada dela.
    $imported = LegacyImport::run();
    if (!empty($imported['ran'])) {
        $migration->displayMessage(sprintf(
            'Dados do Techmail %s trazidos para o MailBridge: %d regras, %d traduções, %d ajustes, %d perfis com permissão.',
            $imported['version'],
            $imported['rules'],
            $imported['translations'],
            $imported['settings'],
            $imported['rights']
        ));
        if (!empty($imported['deactivated'])) {
            $migration->displayMessage('O plugin Techmail foi desativado (os dados dele continuam guardados).');
        }
    } elseif ($first_time && !$with_legacy) {
        $seed_examples();
    }

    // Configurações padrão (não sobrescreve o que já existir).
    $current = Config::getConfigurationValues(Settings::CONTEXT);
    $missing = array_diff_key(Settings::defaults(), is_array($current) ? $current : []);
    if ($missing) {
        Config::setConfigurationValues(Settings::CONTEXT, $missing);
    }

    // Techmail 1.1.0: permissão própria (começa sem ninguém; quem tem o direito de
    // configuração do GLPI continua com acesso total, como antes).
    Rights::register();

    $migration->executeMigration();

    return true;
}

/**
 * Desinstalação: por padrão NÃO apaga nada (regras, modelos, traduções e
 * ajustes ficam guardados para uma reinstalação). Só apaga se a opção
 * "Apagar os dados ao desinstalar" estiver ligada em MailBridge > Diagnóstico.
 * Os e-mails já colocados na fila do GLPI nunca são tocados, e os dados de uma
 * instalação antiga (Techmail) também não: quem os apaga é o plugin antigo.
 */
function plugin_mailbridge_uninstall(): bool
{
    /** @var DBmysql $DB */
    global $DB;

    $settings = Config::getConfigurationValues(Settings::CONTEXT);
    if (empty($settings['purge_on_uninstall'])) {
        return true;
    }

    foreach ([Rule::getTable(), Translation::getTable(), FailureLog::getTable()] as $table) {
        if (!Db::run('DROP TABLE IF EXISTS `' . $table . '`')) {
            return false;
        }
    }

    // O método exige a lista de nomes: sem ela não apaga nada.
    Config::deleteConfigurationValues(Settings::CONTEXT, array_keys(Settings::defaults()));
    ProfileRight::deleteProfileRights([Rights::NAME]);
    // O histórico das regras apagadas não tem mais a que se referir.
    Db::run("DELETE FROM `glpi_logs` WHERE itemtype = '" . Db::escape(Rule::class) . "'");

    return true;
}
