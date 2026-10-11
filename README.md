# Gerente

Base do CRM operacional para leitura de grupos do WhatsApp, análise por IA e
alertas para gestores.

## Configuração local

1. Configure o MySQL local com o banco `publi_ai_crm`.
2. Preencha as variáveis `GERENTE_*` no ambiente ou ajuste o arquivo local
   `config.php` (que não deve ser versionado).
3. Conecte o número na Pilot Status.
4. Cadastre o webhook apontando para:

   `/gerente/api/pilot-status-webhook.php`

5. No CRM, em Configurações > Pilot Status, informe a API key e marque
   “Aceitar webhook sem senha da Pilot Status” quando o painel da Pilot Status
   não oferecer segredo. Se o provedor passar a oferecer um token, desmarque a
   opção e configure o segredo no CRM.

As chaves de API ficam somente no backend. O frontend recebe apenas dados
necessários para a tela e nunca recebe tokens, segredos ou credenciais.

## Telas

- `/gerente/index.php`: entrada do gestor, com cards por cliente e grupos monitorados;
- `/gerente/manager-group.php?id=ID`: conversa normalizada e alertas de um grupo;
- `/gerente/whatsapp.php`: conversas individuais e grupos do Pilot Status. Os
  grupos aparecem com nome, prévia e histórico em modo somente leitura;
- `/gerente/index.php?view=kanban`: Kanban comercial original, mantido separado do monitoramento.

## Fluxo das mensagens

O webhook valida o evento, identifica mensagens de grupo, salva grupo,
participante e mensagem em tabelas próprias e evita duplicação pelo ID da
mensagem. Depois que o grupo é vinculado a um cliente, as mensagens pendentes
são analisadas em lote pela OpenAI usando o prompt cadastrado em Configurações.
O resultado fica salvo como resumo e, quando a IA identifica um problema, como
um alerta operacional que deixa o grupo com indicador vermelho. Quando a
análise conclui que está tudo bem, o indicador fica verde.

O painel dispara automaticamente a análise enquanto estiver aberto. Para
processar mensagens mesmo sem o painel aberto, configure um cron de um minuto
para executar:

```cron
* * * * * /Applications/XAMPP/xamppfiles/bin/php /Applications/XAMPP/xamppfiles/htdocs/gerente/run-manager-analysis.php >/dev/null 2>&1
```

Em produção, substitua os caminhos pelo PHP e pelo diretório do servidor.

Alertas abertos podem ser marcados como resolvidos no card do cliente ou na
tela de detalhe do grupo. A observação opcional da resolução é enviada como contexto
na próxima análise da IA, que só deve reabrir o problema quando houver nova
evidência posterior.

## Estrutura operacional

- `manager_clients`: clientes cadastrados;
- `manager_groups`: grupos vinculados aos clientes;
- `manager_group_participants`: participantes observados;
- `manager_group_messages`: histórico normalizado;
- `manager_ai_analyses`: análises versionadas;
- `manager_alerts`: alertas revisáveis pelo gestor;
- `manager_report_requirements`: regras para relatórios esperados;
- `manager_team_activity`: classificação mensal de reuniões e relatórios por cliente,
  com evidências extraídas das análises das conversas;
- `manager_team_digests`: avaliação diária por gestor e estado do envio por WhatsApp;
- `manager_team_group_checks`: controle das reavaliações diárias de cada grupo;
- `manager_campaign_metrics`: métricas normalizadas por campanha e dia;
- `manager_ads_syncs`: resultado e diagnóstico das sincronizações;
- `manager_campaign_reports`: relatórios e análises gerados pela IA.

## Operação gerencial e anúncios

O cadastro de cada usuário aceita um WhatsApp para direcionar alertas ao gestor
atribuído ao cliente. Um alerta novo identificado na análise do grupo aparece no
painel e o sistema tenta enviá-lo ao gestor quando ele tem número cadastrado e o
provedor de WhatsApp está configurado. Atualizações do mesmo alerta não geram
uma nova tentativa de envio.

Em Configurações > Relatórios e campanhas ficam as credenciais gerais de Meta
Ads e Google Ads e o prompt de instruções do agente. Os IDs de conta são salvos
por cliente no painel de monitoramento. Segredos são criptografados ao salvar.
Depois de conectar as contas, o botão no card do cliente sincroniza as métricas e
gera um relatório por período. A rotina usa a Graph API Insights da Meta e o
Google Ads API SearchStream, armazenando métricas diárias de campanha, tentativas
de sincronização, relatórios e alertas revisáveis no painel.

O worker `run-manager-ads-analysis.php` atualiza a janela móvel de sete dias e
gera recomendações diariamente. No primeiro dia de cada mês também produz o
relatório do mês anterior. Para habilitar a rotina automática, acrescente ao
cron diário:

```cron
15 6 * * * /Applications/XAMPP/xamppfiles/bin/php /Applications/XAMPP/xamppfiles/htdocs/gerente/run-manager-ads-analysis.php >/dev/null 2>&1
```

Em produção, substitua os caminhos pelo PHP e pelo diretório do servidor. O
worker precisa de conectividade HTTPS com Meta, Google e OpenAI. O prompt do
agente define os critérios de alerta, quando uma recomendação deve notificar ou
cobrar o gestor e quando pode haver mensagem por WhatsApp. Sem uma orientação
explícita de envio, o alerta fica apenas no painel. O WhatsApp é enviado ao
gestor atribuído ao cliente, desde que o número dele esteja cadastrado e o
provedor esteja configurado.

Cada card de cliente também permite programar o envio do relatório em texto ao
gestor vinculado, com resumo, destaques e pontos de atenção guiados pelo prompt
do agente. A programação fica desativada até o administrador ativá-la e pode
ser diária (dia anterior), semanal (sete dias encerrados) ou mensal (mês
anterior). O horário usa o fuso configurado no CRM. Para verificar as
programações, acrescente um cron de um minuto:

```cron
* * * * * /Applications/XAMPP/xamppfiles/bin/php /Applications/XAMPP/xamppfiles/htdocs/gerente/run-manager-ads-scheduled-reports.php >/dev/null 2>&1
```

Em produção, ajuste os caminhos. O envio usa o WhatsApp do gestor atribuído ao
cliente; ele precisa ter um número válido e o provedor de WhatsApp deve estar
conectado. O card mostra o status da última tentativa.

Os alertas de campanha podem ser resolvidos no painel; as resoluções ficam
registradas junto ao alerta para acompanhamento humano.

O painel também reúne por gestor os estados mensais de reuniões e relatórios
identificados nas análises dos grupos vinculados aos clientes. Os critérios de
classificação ficam no prompt do agente; sem orientação ou evidência suficiente,
o estado permanece como “sem informação”. As evidências podem ser abertas por
cliente no painel para revisão humana.

O worker `run-manager-team-activity.php` reavalia diariamente as conversas do
mês atual, consolida os estados por gestor e registra a avaliação diária no
painel. As regras do prompt também controlam se o resultado é apenas uma
sugestão ou se pode gerar cobrança e WhatsApp. Sem prompt gerencial configurado,
o worker não aciona a IA nem envia mensagens. O envio fica registrado e é
limitado a uma mensagem por gestor por dia. Para executá-lo às 8h30 no fuso
horário do servidor, acrescente ao cron:

```cron
30 8 * * * /Applications/XAMPP/xamppfiles/bin/php /Applications/XAMPP/xamppfiles/htdocs/gerente/run-manager-team-activity.php >/dev/null 2>&1
```

Em produção, substitua os caminhos e ajuste o horário ao fuso configurado no
servidor. A reavaliação diária usa a OpenAI para cada grupo que ainda não foi
analisado naquele dia e gera uma consolidação adicional para cada gestor com
clientes ativos atribuídos.

As mensagens de grupo ficam separadas dos leads comerciais. Assim, o
remetente de uma mensagem de grupo não é criado como um contato individual no
CRM; contatos individuais continuam seguindo o fluxo comercial original.
