<?php

namespace GlpiPlugin\Mailtech;

/**
 * Pequenas diferenças de banco entre GLPI 10 e 11.
 */
final class Db
{
    /**
     * Expressão SQL crua para usar em $DB->request() (a classe mudou de lugar no GLPI 11).
     *
     * @return object
     */
    public static function expr(string $sql)
    {
        $class = class_exists('\\Glpi\\DBAL\\QueryExpression') ? '\\Glpi\\DBAL\\QueryExpression' : '\\QueryExpression';

        return new $class($sql);
    }

    /**
     * Escapa um texto para usar dentro de LIKE ou de uma expressão crua.
     */
    public static function escape(string $value): string
    {
        /** @var \DBmysql $DB */
        global $DB;

        return $DB->escape($value);
    }

    /**
     * Executa uma consulta de estrutura ou de cópia (CREATE/ALTER/INSERT ... SELECT)
     * em qualquer GLPI 10/11.
     */
    public static function run(string $query): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        return (bool) (method_exists($DB, 'doQuery') ? $DB->doQuery($query) : $DB->query($query));
    }

    /**
     * Data/hora do banco ("AAAA-MM-DD HH:MM:SS") para timestamp; 0 se vazia.
     *
     * @param mixed $value
     */
    public static function time($value): int
    {
        if (empty($value)) {
            return 0;
        }
        $time = strtotime((string) $value);

        return $time === false ? 0 : $time;
    }
}
