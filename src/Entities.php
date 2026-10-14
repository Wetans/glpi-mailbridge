<?php

namespace GlpiPlugin\Mailbridge;

/**
 * Consultas sobre as entidades do GLPI usadas pelo liga/desliga por entidade.
 */
final class Entities
{
    /** @var array<int,int[]> */
    private static $chains = [];

    /**
     * A entidade e suas "mães", da mais próxima para a raiz.
     *
     * @return int[]
     */
    public static function chain(int $entities_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (isset(self::$chains[$entities_id])) {
            return self::$chains[$entities_id];
        }

        $chain     = [$entities_id];
        $ancestors = array_values(array_filter(
            array_map('intval', array_values((array) getAncestorsOf('glpi_entities', $entities_id))),
            static function (int $id) use ($entities_id): bool {
                return $id !== $entities_id;
            }
        ));
        // A raiz tem id 0: array_filter acima não pode descartá-la por ser "vazia".
        if ($entities_id > 0 && !in_array(0, $ancestors, true)) {
            $ancestors[] = 0;
        }
        if ($ancestors) {
            $rows = $DB->request([
                'SELECT' => ['id', 'level'],
                'FROM'   => 'glpi_entities',
                'WHERE'  => ['id' => $ancestors],
                'ORDER'  => ['level DESC'],
            ]);
            foreach ($rows as $row) {
                $chain[] = (int) $row['id'];
            }
        }

        return self::$chains[$entities_id] = $chain;
    }

    public static function total(): int
    {
        return (int) countElementsInTable('glpi_entities');
    }

    /**
     * Página da lista de entidades com a situação dos avisos em cada uma.
     * Três consultas para a página inteira, qualquer que seja o tamanho dela.
     *
     * @param array<int,int> $modes escolhas gravadas (entidade => 0/1)
     * @return array<int,array{id:int,name:string,level:int,choice:string,enabled:bool,source:int|null,source_name:string}>
     */
    public static function page(array $modes, int $start, int $limit): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $rows = [];
        $all  = [];
        $iterator = $DB->request([
            'SELECT' => ['id', 'completename', 'level', 'ancestors_cache'],
            'FROM'   => 'glpi_entities',
            'ORDER'  => ['completename'],
            'START'  => max(0, $start),
            'LIMIT'  => max(1, $limit),
        ]);
        foreach ($iterator as $row) {
            $id        = (int) $row['id'];
            $ancestors = json_decode((string) ($row['ancestors_cache'] ?? ''), true);
            if (!is_array($ancestors)) {
                // Cache ainda não montado pelo GLPI: pergunta a ele.
                $ancestors = (array) getAncestorsOf('glpi_entities', $id);
            }
            $ancestors = array_values(array_unique(array_map('intval', array_merge(array_keys($ancestors), array_values($ancestors)))));
            if ($id > 0 && !in_array(0, $ancestors, true)) {
                $ancestors[] = 0;
            }
            $ancestors = array_values(array_diff($ancestors, [$id]));
            $row['_ancestors'] = $ancestors;
            $rows[]            = $row;
            foreach ($ancestors as $ancestor) {
                $all[$ancestor] = true;
            }
        }

        // Nível e nome de todas as "mães" da página, de uma vez.
        $levels = [];
        $names  = [];
        if ($all) {
            $iterator = $DB->request([
                'SELECT' => ['id', 'completename', 'level'],
                'FROM'   => 'glpi_entities',
                'WHERE'  => ['id' => array_keys($all)],
            ]);
            foreach ($iterator as $row) {
                $levels[(int) $row['id']] = (int) $row['level'];
                $names[(int) $row['id']]  = Text::decode((string) $row['completename']);
            }
        }

        $out = [];
        foreach ($rows as $row) {
            $id        = (int) $row['id'];
            $ancestors = $row['_ancestors'];
            usort($ancestors, static function (int $a, int $b) use ($levels): int {
                return ($levels[$b] ?? 0) <=> ($levels[$a] ?? 0);
            });
            $scope = EntityScope::resolve($modes, array_merge([$id], $ancestors));
            $name  = Text::decode((string) $row['completename']);

            $out[] = [
                'id'          => $id,
                'name'        => $name,
                'level'       => (int) $row['level'],
                'choice'      => array_key_exists($id, $modes) ? ((int) $modes[$id] === 1 ? 'on' : 'off') : 'inherit',
                'enabled'     => $scope['enabled'],
                'source'      => $scope['source'],
                'source_name' => $scope['source'] === null ? '' : ($scope['source'] === $id ? $name : ($names[$scope['source']] ?? ('#' . $scope['source']))),
            ];
        }

        return $out;
    }

    /**
     * Nomes completos de várias entidades, numa consulta só.
     *
     * @param int[] $ids
     * @return array<int,string>
     */
    public static function names(array $ids): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $out  = [];
        $rows = $DB->request([
            'SELECT' => ['id', 'completename'],
            'FROM'   => 'glpi_entities',
            'WHERE'  => ['id' => $ids],
        ]);
        foreach ($rows as $row) {
            $out[(int) $row['id']] = Text::decode((string) $row['completename']);
        }

        return $out;
    }
}
