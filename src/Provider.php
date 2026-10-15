<?php

namespace GlpiPlugin\Mailtech;

/**
 * Reconhece os servidores públicos que sempre exigem login (Gmail, Microsoft)
 * e os endereços que não existem fora deste computador. Classe pura.
 *
 * Servidores de rede interna e caixas de teste ficam de fora de propósito:
 * para eles, enviar sem login e com remetente "@localhost" é normal.
 */
final class Provider
{
    /** Servidor => nome mostrado na tela. */
    private const HOSTS = [
        'smtp.gmail.com'        => 'Gmail',
        'smtp.googlemail.com'   => 'Gmail',
        'smtp.office365.com'    => 'Microsoft 365',
        'smtp-mail.outlook.com' => 'Outlook.com',
    ];

    /** Finais de domínio reservados que nunca recebem e-mail na internet. */
    private const LOCAL_SUFFIXES = ['localhost', 'local', 'localdomain', 'test', 'invalid', 'example', 'internal', 'lan'];

    /**
     * Nome do provedor que exige login, ou '' para qualquer outro servidor.
     */
    public static function name(string $host): string
    {
        return self::HOSTS[strtolower(rtrim(trim($host), '.'))] ?? '';
    }

    /**
     * O endereço pode existir na internet? ("admsys@localhost" e "x@empresa.test" não.)
     */
    public static function isPublicAddress(string $email): bool
    {
        $at = strrpos($email, '@');
        if ($at === false) {
            return false;
        }
        $domain = strtolower(trim(rtrim(substr($email, $at + 1), '.'), '[]'));
        if ($domain === '' || strpos($domain, '.') === false || filter_var($domain, FILTER_VALIDATE_IP) !== false) {
            return false;
        }
        $suffix = substr($domain, strrpos($domain, '.') + 1);

        return !in_array($suffix, self::LOCAL_SUFFIXES, true) && $domain !== 'example.com' && $domain !== 'example.org' && $domain !== 'example.net';
    }
}
