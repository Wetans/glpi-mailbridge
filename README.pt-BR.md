# MailBridge — Integração e diagnóstico de e-mail para o GLPI

<img src="pics/mailbridge.svg" alt="Logotipo do MailBridge" width="72">

*Versão em inglês: [README.md](README.md).*

## Documentação do MailBridge

### 1. Visão geral

O MailBridge é um plugin do GLPI que reúne numa tela só, em linguagem simples, tudo o que o GLPI faz com e-mail de saída:

- **Envio**: servidor de e-mail do GLPI, porta, segurança, usuário e senha, endereços de remetente e assinatura.
- **Testes**: "Testar conexão" e "Enviar e-mail de teste" mostram o resultado passo a passo (conexão, conexão segura, login, envio) e dizem o que tentar quando um passo falha.
- **Regras e modelos**: quem é avisado sobre o quê num chamado (técnicos e grupos atribuídos, solicitantes, observadores, endereços fixos), com mensagens editáveis por idioma.
- **Fila e histórico**: o que está esperando, o que saiu, o que falhou e por quê; reenviar, cancelar, apagar.
- **Diagnóstico**: verificações com a ação sugerida, quem alterou o quê, exportação e importação da configuração.

O MailBridge **não tem transporte de e-mail próprio**: as mensagens entram na fila de notificações nativa do GLPI e saem pelo servidor configurado no GLPI. A interface é em português do Brasil, com traduções para o inglês (en_GB e en_US).

O MailBridge 1.0 sucede o **Techmail** (versões 1.0.0 e 1.1.0). Se você o tem instalado, veja a [seção 5](#5-atualizar-a-partir-do-techmail).

### 2. Versão do GLPI suportada

| | |
|---|---|
| Testado | **GLPI 10.0.18** |
| Declarado no `setup.php` | Da 10.0.0 até antes da 12.0.0 |
| Não testado | GLPI 11 (o teste de conexão passo a passo depende da biblioteca de e-mail que acompanha o GLPI 10) |

### 3. Requisitos

- PHP mínimo declarado: 7.4. **Só foi executado em PHP 8.3** (8.3.35).
- Nenhuma extensão ou biblioteca PHP além das que o próprio GLPI exige. Nada para instalar com Composer ou npm.
- A rotina automática do GLPI que envia a fila (`queuednotification`) precisa estar rodando. Recomenda-se agendar o cron do GLPI no sistema (exemplo: `* * * * * php /caminho/glpi/front/cron.php`). A Visão geral avisa quando ela para.
- O registro em arquivos ligado no GLPI, para que o motivo de cada mensagem com falha possa ser guardado.

### 4. Instalação

1. Copie a pasta `mailbridge` para a pasta `plugins/` do GLPI. O nome da pasta precisa ser exatamente `mailbridge`.
2. Em **Configurar > Plugins**, clique em **Instalar** e depois em **Ativar** no MailBridge.
   Pela linha de comando: `php bin/console plugin:install mailbridge --username=glpi` e depois `php bin/console plugin:activate mailbridge`.
3. Saia e entre de novo no GLPI para o menu mostrar **Configurar > MailBridge**.

Uma instalação nova cria três regras de exemplo (uma ligada). Instalar de novo não muda nada.

### 5. Atualizar a partir do Techmail

O GLPI identifica um plugin pelo nome da pasta. Por isso, para o GLPI, o MailBridge (`mailbridge`) é um plugin diferente do Techmail (`techmail`), e a instalação **copia** os dados do Techmail: nada do Techmail é movido nem apagado.

1. Faça uma cópia de segurança do banco de dados do GLPI e da pasta `plugins/`.
2. Copie a pasta `mailbridge` para `plugins/`. **Deixe a pasta `techmail` onde está.**
3. Em **Configurar > Plugins**, clique em **Instalar** no MailBridge. A instalação:
   - copia regras, traduções dos modelos, motivos de falha, ajustes, permissões por perfil e o histórico de alterações das regras;
   - **desativa** o Techmail (não o desinstala), para nenhum aviso sair duas vezes.
4. Clique em **Ativar** no MailBridge, saia e entre de novo.
5. Abra **Configurar > MailBridge > Diagnóstico**. O último quadro diz o que foi trazido. Confira as regras e os modelos e envie um e-mail de teste.
6. Quando estiver tudo certo, desinstale o Techmail em **Configurar > Plugins** e apague a pasta `techmail`.

Vale saber:

- Funciona a partir do Techmail 1.0.0 e do 1.1.0.
- A cópia acontece uma vez. O que você mudar depois no MailBridge não volta para o Techmail.
- Um ajuste não é copiado de propósito: "apagar os dados ao desinstalar". No MailBridge ele sempre começa desligado.
- O servidor de envio e a fila de notificações são do GLPI e não são tocados.
- Os favoritos para `/plugins/techmail/...` deixam de funcionar quando a pasta antiga for apagada; o endereço novo é `/plugins/mailbridge/front/index.php`.
- Arquivos de configuração exportados pelo Techmail continuam podendo ser importados.
- Se o Techmail for ativado de novo com o MailBridge ativo, o MailBridge para os próprios avisos e a Visão geral avisa.

### 6. Configuração

Tudo fica em **Configurar > MailBridge**.

1. **Envio**: informe o servidor, a porta, a segurança da conexão, o usuário, a senha e o e-mail do remetente e clique em **Salvar**. Os testes usam sempre a configuração *salva*.
2. **Testar conexão** e depois **Enviar e-mail de teste**. O resultado aparece passo a passo; se algo falhar, a tela diz o que tentar.
3. **Eventos e destinatários**: ligue ou desligue cada regra; crie regras; escolha por entidade; veja as notificações nativas do GLPI. O resumo de cada linha diz quando a regra dispara e quem recebe.
4. **Modelos**: edite o assunto e a mensagem de cada regra, por idioma; clique numa variável para inseri-la; veja a prévia; use "Restaurar padrão" e "Enviar teste deste modelo".
5. **Fila e histórico** e **Diagnóstico** para o acompanhamento do dia a dia. A **Visão geral** mostra se o envio está *Ativo*, *Inativo* ou se *Precisa de atenção*.

Importante: **quem não tem e-mail no cadastro não recebe avisos**.

Permissões: quem pode alterar a configuração geral do GLPI tem acesso total. Os demais perfis ganham a aba **MailBridge** no cadastro do perfil (*Ver*, *Gerenciar*, *Testar envio*, *Operar a fila*).

### 7. Formas de envio suportadas

O MailBridge configura e testa o que o **próprio mecanismo de envio do GLPI** oferece:

| Forma | Onde se configura | Situação |
|---|---|---|
| SMTP sem criptografia | MailBridge > Envio | Testado (caixa de testes local) |
| SMTP + STARTTLS, com ou sem usuário e senha | MailBridge > Envio | Testado (veja a seção 8) |
| SMTP + SSL/TLS (TLS implícito) | MailBridge > Envio | Implementado; um envio com sucesso não foi testado |
| Função `mail()` do PHP | MailBridge > Envio | Implementado; não testado |
| SMTP + OAuth 2.0 (Google, Microsoft) | Tela de e-mail nativa do GLPI; o MailBridge mostra a situação e um atalho | Não testado |

### 8. Limitações do Microsoft 365 e do Gmail

Nada abaixo é garantia para a sua conta: provedores e organizações mudam as regras.

**Gmail**
- Servidor `smtp.gmail.com`, porta 587, STARTTLS, o endereço completo como usuário e remetente igual à conta.
- Exige uma **senha de app** do Google (com a verificação em duas etapas ligada). A senha normal da conta é recusada. Algumas contas de trabalho, de escola e com a Proteção Avançada não podem criar senhas de app.
- Verificado em 13/10/2026 com o mesmo código, ainda com o nome antigo: duas mensagens de teste aceitas pelo servidor do Gmail. Não foi testado de novo depois da troca de nome.
- O login pelo Google (OAuth) no GLPI exige que o GLPI tenha um endereço https público.

**Microsoft 365 (contas de organização)**
- Servidor `smtp.office365.com`, porta 587, STARTTLS. A porta 465 não é oferecida pela Microsoft.
- Verificado a partir do servidor de testes: resolução do nome, conexão, criptografia e certificado. **Login e entrega não foram testados.**
- O login com usuário e senha só funciona se a organização liberar o "SMTP autenticado" para a caixa de correio e não exigir os padrões de segurança; a Microsoft já anunciou o fim desse tipo de login. Caso contrário, use o login pela Microsoft (OAuth) na tela nativa do GLPI, o que exige um aplicativo registrado na organização.
- Enviar com um endereço diferente da conta exige a permissão "Enviar como".

**Outlook.com / Hotmail (contas pessoais)**: não testado. Há relatos de que o login com usuário e senha não é mais aceito.

### 9. Solução de problemas

| O que aparece | O que quer dizer | O que fazer |
|---|---|---|
| "O Gmail exige login: falta informar o usuário e a senha." (ou Microsoft 365 / Outlook.com) | Esse provedor sempre pede uma conta, e nenhuma está salva | Informe o endereço completo da conta como usuário, a senha dela e o mesmo endereço como remetente; **Salvar**; teste |
| "O remetente "…" não é um endereço de e-mail de verdade." | Com Gmail ou Microsoft, o remetente é um endereço provisório como `admsys@localhost` | Informe o endereço da conta em "E-mail do remetente" |
| "A configuração de envio mudou depois do último teste com sucesso." | Servidor, conta, senha ou remetente mudaram depois do último teste bom | Envie um teste novo |
| "Falta guardar a senha do servidor de envio." | Há usuário salvo, mas nenhuma senha | Digite a senha, clique em **Salvar** e teste |
| "Você alterou a configuração e ainda não salvou." | Os testes usam só a configuração salva | Clique em **Salvar** e teste de novo |
| "O servidor de envio não aceitou o usuário e a senha." | O provedor recusou o login | Abra "Detalhe técnico": ali está o motivo dado pelo próprio provedor |
| O teste diz que enviou, mas nada chega | O servidor aceitou a mensagem | Veja a pasta de spam (lixo eletrônico); confira se o servidor não é só de captura ou de retransmissão |
| A Visão geral mostra "Precisa de atenção" | Uma das verificações falhou | O Diagnóstico lista cada verificação com a ação |
| "O plugin antigo (Techmail) está ativo junto com o MailBridge." | Os dois plugins estão ativos | Desative o Techmail |
| Uma pessoa não recebe nada | Não há e-mail no cadastro do usuário, o usuário está inativo ou foi bloqueado por uma lista | Confira Fila e histórico; em Envio, ligue "Registrar o motivo de cada aviso gerado ou ignorado" |

### 10. Registros e diagnóstico

- **Fila e histórico** mostra o motivo de cada tentativa com falha (guardado numa tabela do próprio plugin, porque o GLPI não guarda o motivo por mensagem).
- `files/_log/mailbridge.log`: por que cada aviso foi gerado ou ignorado, quando "Registrar o motivo de cada aviso gerado ou ignorado" está ligado (em Envio).
- Os arquivos do próprio GLPI: `files/_log/mail-error.log`, `mail.log` e `php-errors.log`.
- **Diagnóstico > Quem alterou o quê** lê o histórico de alterações do GLPI.

Nenhum registro, exportação, tela ou mensagem de erro contém senhas ou chaves de acesso.

### 11. Segurança

- A senha do servidor é guardada criptografada pelo GLPI, nunca volta para o navegador e não aparece em exportações, registros nem no histórico (para manter a senha atual, deixe o campo em branco).
- Os testes só usam o servidor salvo, pedem permissão própria e têm limite de 6 por minuto por pessoa.
- Toda alteração passa pela proteção do GLPI contra falsificação de requisições (CSRF) e por uma verificação de permissão.
- Os modelos são limpos ao salvar e ao enviar; só as variáveis de uma lista fixa são trocadas; as quebras de linha são removidas do assunto e dos campos de endereço.
- Desinstalar não apaga os dados, a menos que a opção em Diagnóstico esteja ligada.

### 12. Cópia de segurança e volta atrás

- **Antes de instalar ou atualizar**: faça uma cópia de segurança do banco de dados e da pasta `plugins/`.
- **Cópia dos ajustes**: Diagnóstico > Exportar (um arquivo JSON sem senhas).
- **Voltar do MailBridge para o Techmail**: desative o MailBridge e ative o Techmail. Os dados do Techmail nunca foram alterados pela atualização.
- **Remover o MailBridge por completo**: ligue "Apagar os dados do MailBridge ao desinstalar o plugin" em Diagnóstico e depois desinstale. Os e-mails que já estão na fila do GLPI nunca são tocados.

### 13. Problemas conhecidos

- O GLPI 11 não foi testado.
- O login pelo provedor (OAuth) é configurado na tela nativa do GLPI, não dentro do MailBridge.
- Se você trocar o servidor e deixar a senha em branco, a senha guardada antes continua valendo e seria oferecida ao servidor novo. Digite a senha nova ou marque "Apagar a senha guardada".
- A Visão geral avalia a configuração; ela não sabe se o provedor vai aceitar o login. Use os botões de teste. Ela reconhece Gmail e Microsoft só pelo nome do servidor (`smtp.gmail.com`, `smtp.googlemail.com`, `smtp.office365.com`, `smtp-mail.outlook.com`); para outros servidores, login e remetente público não são exigidos, então caixas de teste locais continuam funcionando.
- Depois de instalar, os usuários precisam sair e entrar de novo para ver o menu e as permissões novas.
- Não incluído: limite de envios por período, resumo agrupado, predefinições de provedores, tratamento de e-mails devolvidos.

### 14. Histórico de versões

Veja o [CHANGELOG.md](CHANGELOG.md) (em inglês). Em resumo: o MailBridge 1.0 é a primeira versão com o nome novo e deixa a Visão geral e os testes mais rigorosos com login, remetente e testes antigos. Antes disso o plugin se chamava Techmail: o Techmail 1.1.0 trouxe a tela única e o Techmail 1.0.0 foi a primeira versão.

## Autor e licença

O MailBridge foi criado e é mantido por **Mateus Oliveira** ([@Wetans](https://github.com/Wetans)). É software livre, distribuído sob a GNU General Public License, versão 2 ou (a seu critério) qualquer versão posterior; veja o arquivo [LICENSE](LICENSE).

Dúvidas, problemas e sugestões: [registre no GitHub](https://github.com/Wetans/mailbridge/issues).

## Para mantenedores

```
mailbridge/
├── setup.php, hook.php   registro, ganchos do GLPI, instalação e atualização (inclui a importação do Techmail)
├── front/, ajax/         tela única, ações, exportação
├── src/                  regras de negócio (classes puras) e acesso ao GLPI; Screen/ com uma classe por seção
├── templates/            telas (Twig)
├── css/, js/, pics/      carregados só nas telas do plugin
└── locales/              pt_BR, en_GB, en_US
```

- Traduções: os textos-fonte são em português. Depois de mudar um texto, atualize-o em `locales/mailbridge.pot` e nos arquivos `.po`, compile cada `.po` no seu `.mo` (por exemplo com o `msgfmt` do gettext) e limpe o cache do GLPI.
- Nomes internos: pasta e identificador `mailbridge`, espaço de nomes `GlpiPlugin\Mailbridge`, tabelas `glpi_plugin_mailbridge_*`, contexto de configuração `plugin:mailbridge`, permissão `plugin_mailbridge`. O prefixo de CSS dentro do plugin é `tm-` (não `mb-`, que o Bootstrap usa para margens).
- O nome antigo só aparece onde a instalação antiga é lida: `src/LegacyImport.php`, arquivos de exportação antigos (`src/Porter.php`), histórico antigo (`src/Audit.php`, `src/Queue.php`) e os textos que citam o plugin antigo.
