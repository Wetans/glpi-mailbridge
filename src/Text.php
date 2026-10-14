<?php

namespace GlpiPlugin\Mailbridge;

/**
 * Utilitários de texto.
 *
 * O GLPI 10 guarda textos "sanitizados" (caracteres especiais codificados como
 * entidades HTML) no banco e espera dados sanitizados em CommonDBTM::add().
 * O GLPI 11 abandonou isso. Estes helpers funcionam nos dois casos.
 */
final class Text
{
    private const SANITIZER = '\\Glpi\\Toolbox\\Sanitizer';

    /**
     * Desfaz a sanitização de um texto vindo do banco / de $_POST.
     */
    public static function decode(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $class = self::SANITIZER;
        if (class_exists($class)) {
            return $class::decodeHtmlSpecialChars($value);
        }
        return $value;
    }

    /**
     * Valor exatamente como o usuário digitou (desfaz a sanitização e o
     * escape que o GLPI 10 aplica em $_POST / $_GET). Use antes de validar.
     *
     * @param mixed $value
     */
    public static function raw($value): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $value = (string) $value;
        $class = self::SANITIZER;
        if ($value !== '' && class_exists($class)) {
            return (string) $class::unsanitize($value);
        }
        return $value;
    }

    /**
     * Sanitiza texto ou array de textos para uso em ->add() / ->update().
     *
     * @param string|array $data
     * @return string|array
     */
    public static function sanitize($data)
    {
        $class = self::SANITIZER;
        if (class_exists($class)) {
            return $class::sanitize($data);
        }
        return $data;
    }

    /**
     * Escapa para exibição em HTML.
     */
    public static function esc(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Texto de uma linha só: remove quebras de linha e caracteres de controle
     * (impede injeção de cabeçalhos em assunto, nomes e endereços).
     */
    public static function oneLine(?string $value, int $max = 255): string
    {
        $value = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value);
        $value = trim((string) preg_replace('/\s{2,}/u', ' ', $value));

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max, 'UTF-8');
        }
        return substr($value, 0, $max);
    }

    /**
     * Converte HTML em texto simples preservando quebras de linha básicas.
     */
    public static function toPlain(string $html): string
    {
        // Link vira "texto (endereço)": no texto simples o endereço precisa aparecer.
        $text = preg_replace_callback(
            '#<a\s[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>#is',
            static function (array $m): string {
                $label = trim(strip_tags($m[3]));
                $url   = trim(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($url === '' || $url === $label || stripos($url, 'javascript:') === 0) {
                    return $m[3];
                }

                return $label === '' ? $url : $m[3] . ' (' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . ')';
            },
            $html
        );
        // "<br>" seguido de quebra de linha no modelo conta como uma quebra só.
        $text = preg_replace('#<\s*br\s*/?\s*>[ \t]*\r?\n?#i', "\n", (string) $text);
        $text = preg_replace('#</\s*(p|div|li|tr|h[1-6]|table|ul|ol)\s*>#i', "\n", $text);
        $text = preg_replace('#<\s*li[^>]*>#i', '- ', $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+\n/", "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }
}
