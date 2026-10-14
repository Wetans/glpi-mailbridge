<?php

namespace GlpiPlugin\Mailbridge;

/**
 * Traduções dos modelos: um assunto/corpo por regra e por idioma.
 *
 * O modelo guardado na própria regra é o "padrão": vale para quem não tem
 * tradução no seu idioma.
 */
final class Translation
{
    public static function getTable(): string
    {
        return 'glpi_plugin_mailbridge_ruletranslations';
    }

    /**
     * Traduções de uma regra: idioma => modelo (já decodificado).
     *
     * @return array<string,array{subject:string,body:string,body_text:string}>
     */
    public static function forRule(int $rules_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        if ($rules_id <= 0) {
            return $out;
        }
        $rows = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['plugin_mailbridge_rules_id' => $rules_id],
            'ORDER' => ['language'],
        ]);
        foreach ($rows as $row) {
            $out[(string) $row['language']] = [
                'subject'   => Text::decode((string) $row['subject']),
                'body'      => Text::decode((string) $row['body']),
                'body_text' => Text::decode((string) $row['body_text']),
            ];
        }

        return $out;
    }

    /**
     * Idiomas traduzidos de várias regras, numa consulta só.
     *
     * @param int[] $rules_ids
     * @return array<int,string[]> regra => idiomas
     */
    public static function languagesByRule(array $rules_ids): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $rules_ids = array_values(array_unique(array_filter(array_map('intval', $rules_ids))));
        if (!$rules_ids) {
            return [];
        }

        $out  = [];
        $rows = $DB->request([
            'SELECT' => ['plugin_mailbridge_rules_id', 'language'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['plugin_mailbridge_rules_id' => $rules_ids],
            'ORDER'  => ['language'],
        ]);
        foreach ($rows as $row) {
            $out[(int) $row['plugin_mailbridge_rules_id']][] = (string) $row['language'];
        }

        return $out;
    }

    /**
     * Cria ou atualiza a tradução de uma regra.
     *
     * @param array{subject:string,body:string,body_text:string} $data valores como digitados
     */
    public static function save(int $rules_id, string $language, array $data): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($rules_id <= 0 || !preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $language)) {
            return false;
        }

        $values = Text::sanitize([
            'subject'   => (string) ($data['subject'] ?? ''),
            'body'      => (string) ($data['body'] ?? ''),
            'body_text' => (string) ($data['body_text'] ?? ''),
            'date_mod'  => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ]);
        $where = ['plugin_mailbridge_rules_id' => $rules_id, 'language' => $language];

        if (countElementsInTable(self::getTable(), $where) > 0) {
            return (bool) $DB->update(self::getTable(), $values, $where);
        }

        return (bool) $DB->insert(self::getTable(), $values + $where);
    }

    public static function delete(int $rules_id, string $language): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($rules_id <= 0 || !preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $language)) {
            return false;
        }

        return (bool) $DB->delete(
            self::getTable(),
            ['plugin_mailbridge_rules_id' => $rules_id, 'language' => $language]
        );
    }

    public static function deleteForRule(int $rules_id): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($rules_id > 0) {
            $DB->delete(self::getTable(), ['plugin_mailbridge_rules_id' => $rules_id]);
        }
    }
}
