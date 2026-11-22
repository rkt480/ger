<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

crm_require_admin();

$supportSections = [
    [
        'kicker' => 'Acesso',
        'title' => 'Usuários comerciais',
        'items' => [
            ['Cadastro de vendedores', 'Cria acessos individuais para cada pessoa do comercial trabalhar no CRM com login próprio.'],
            ['Editar usuário', 'Atualiza nome, usuário, e-mail, perfil, senha, participação na roleta e peso de distribuição.'],
            ['Excluir usuário', 'Remove o acesso e deixa os leads desse usuário sem vendedor para o admin reorganizar.'],
            ['Usuário ativo', 'Define se o acesso pode entrar no CRM. Usuário inativo não recebe leads e não consegue operar.'],
            ['Horário do vendedor', 'Bloqueia o login fora da faixa configurada e define, para o SLA, quando aquele vendedor pode ser cobrado por um lead parado.'],
        ],
    ],
    [
        'kicker' => 'Perfis',
        'title' => 'Permissões e segurança',
        'items' => [
            ['Administrador (master)', 'Tem acesso total ao CRM e pode visualizar e responder todas as conversas, independentemente do vendedor atribuído, além de gerenciar as regras sensíveis.'],
            ['Gestor', 'Acompanha a operação comercial e dashboards, sem mexer nas configurações administrativas.'],
            ['Vendedor', 'Enxerga somente leads e conversas atribuídos a ele, protegendo a carteira dos outros vendedores.'],
            ['Conversas do WhatsApp', 'A aba de conversas respeita a mesma atribuição do lead: vendedor só vê conversa do próprio lead.'],
        ],
    ],
    [
        'kicker' => 'Distribuição',
        'title' => 'Roleta de leads',
        'items' => [
            ['Usar roleta em novos leads', 'Quando ligado, cada lead novo é atribuído automaticamente a um vendedor habilitado.'],
            ['Participa da roleta', 'Controla quais vendedores podem receber leads automaticamente.'],
            ['Peso da roleta', 'Permite dar mais ou menos volume para um vendedor. Peso maior aumenta a chance de receber leads.'],
            ['Atribuição manual', 'O admin ou gestor pode trocar o vendedor de um lead quando for necessário fazer uma exceção.'],
        ],
    ],
    [
        'kicker' => 'SLA',
        'title' => 'Redistribuição por inatividade',
        'items' => [
            ['Redistribuir lead sem atividade', 'Liga a regra que tira o lead parado de um vendedor e envia para outra pessoa ou para revisão.'],
            ['Tempo sem atividade', 'Define quanto tempo o lead pode ficar sem movimentação no kanban antes de acionar a regra.'],
            ['Pausar fora do horário do vendedor', 'Faz o cronômetro do SLA contar somente as horas em que o vendedor pode acessar o CRM, incluindo as permissões individuais de sábado e domingo.'],
            ['Ao vencer', 'Escolhe se o lead volta para a roleta ou se fica para revisão do gestor.'],
            ['Aplicar SLA em', 'Seleciona em quais etapas do kanban a regra vale, evitando redistribuir leads em fases que não precisam de cobrança.'],
            ['Verificar leads parados agora', 'Executa a checagem manual do SLA sem esperar a rotina automática.'],
        ],
    ],
];

$implementationSteps = [
    'Cadastrar todos os vendedores e gestores com perfil correto.',
    'Ativar somente quem deve receber lead novo na roleta.',
    'Definir o peso de cada vendedor conforme capacidade ou senioridade.',
    'Configurar o tempo de SLA considerando o padrão de atendimento do sistema.',
    'Manter ativada a pausa do SLA fora do horário de acesso dos vendedores.',
    'Selecionar as etapas do kanban onde falta de movimento deve gerar redistribuição.',
    'Criar um lead de teste, validar atribuição, conversa e restrição de acesso por vendedor.',
];
?>
<!doctype html>
<html lang="pt-BR">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Suporte Interno | CRM</title>
    <script src="./assets/theme.js?v=20261030-clean-layout-v1"></script>
    <link rel="stylesheet" href="./assets/crm.css?v=20261122-sidebar-compact-v2" />
  </head>
  <body class="settings-page support-page">
    <div class="app-shell">
      <?php require __DIR__ . '/partials/sidebar.php'; ?>

      <div class="workspace">

        <header class="app-header">
          <div>
            <p class="eyebrow">Suporte interno</p>
            <h1>Manual da área comercial</h1>
          </div>
          <nav>
            <a href="commercial.php">Abrir comercial</a>
          </nav>
        </header>

        <main class="dashboard settings-layout support-layout">
          <section class="support-intro automation-card">
            <div>
              <p class="integration-kicker">Objetivo</p>
              <h2>Como operar equipe, roleta e SLA</h2>
            </div>
            <p>
              Esta página documenta os recursos criados para o controle comercial do sistema:
              vendedores, atribuição de leads, proteção por carteira, conversas vinculadas e
              redistribuição quando um lead fica parado no kanban.
            </p>
          </section>

          <section class="support-grid">
            <?php foreach ($supportSections as $section): ?>
              <article class="automation-card support-card">
                <header>
                  <p class="integration-kicker"><?= htmlspecialchars($section['kicker']) ?></p>
                  <h2><?= htmlspecialchars($section['title']) ?></h2>
                </header>
                <dl class="support-definition-list">
                  <?php foreach ($section['items'] as $item): ?>
                    <div>
                      <dt><?= htmlspecialchars($item[0]) ?></dt>
                      <dd><?= htmlspecialchars($item[1]) ?></dd>
                    </div>
                  <?php endforeach; ?>
                </dl>
              </article>
            <?php endforeach; ?>
          </section>

          <section class="settings-group">
            <header class="settings-group-header">
              <div>
                <p class="eyebrow">Implantação</p>
                <h2>Etapas recomendadas para deixar completo</h2>
              </div>
            </header>
            <article class="automation-card support-steps-card">
              <ol class="support-steps">
                <?php foreach ($implementationSteps as $step): ?>
                  <li><?= htmlspecialchars($step) ?></li>
                <?php endforeach; ?>
              </ol>
            </article>
          </section>
        </main>
      </div>
    </div>
    <script src="./assets/crm-navigation.js?v=20261122-sidebar-unified-v1"></script>
  </body>
</html>
