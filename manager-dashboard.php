<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/storage.php';
require_once __DIR__ . '/lib/pilot-status.php';

crm_require_sales_manager();

$pdo = crm_db();
$pdo = crm_manager_monitor_refresh_missing_group_names($pdo);
$currentUser = crm_current_user();
$currentUserName = trim((string) ($currentUser['name'] ?? $currentUser['username'] ?? 'gestor'));
$currentUserName = $currentUserName !== '' ? $currentUserName : 'gestor';
$search = trim((string) ($_GET['q'] ?? ''));
$monitor = crm_manager_monitor_read_dashboard($pdo, $search);
$clients = $monitor['clients'];
$groups = $monitor['groups'];
$stats = $monitor['stats'];
$unassignedGroups = $monitor['unassigned_groups'];
$activeClients = crm_manager_monitor_read_active_clients($pdo);
$groupsByClient = [];

foreach ($groups as $group) {
    $clientId = (int) ($group['client_id'] ?? 0);

    if ($clientId > 0) {
        $groupsByClient[$clientId][] = $group;
    }
}

$saved = (string) ($_GET['saved'] ?? '');
$error = (string) ($_GET['error'] ?? '');
$flashMessages = [
    'client' => 'Cliente cadastrado. Agora você pode vincular os grupos recebidos.',
    'client_group' => 'Cliente cadastrado e grupo vinculado com sucesso.',
    'group' => 'Grupo vinculado ao cliente.',
];
$errorMessages = [
    'invalid_client' => 'Informe um nome válido para o cliente.',
    'invalid_client_group' => 'Informe o nome do cliente e confirme um grupo válido.',
    'save_client' => 'Não foi possível cadastrar o cliente agora.',
    'save_client_group' => 'Não foi possível cadastrar o cliente e vincular o grupo agora.',
    'invalid_group' => 'Selecione um grupo e um cliente válidos.',
    'link_group' => 'Não foi possível vincular o grupo agora.',
];

$formatDate = static function (mixed $value): string {
    $value = trim((string) $value);

    if ($value === '') {
        return 'Sem atividade';
    }

    $timestamp = strtotime($value);

    return $timestamp === false ? 'Sem atividade' : date('d/m · H:i', $timestamp);
};
$shortText = static function (mixed $value): string {
    $value = trim((string) $value);

    if ($value === '') {
        return 'Nenhuma mensagem armazenada ainda.';
    }

    return function_exists('mb_strimwidth')
        ? mb_strimwidth($value, 0, 104, '…', 'UTF-8')
        : substr($value, 0, 104);
};
$initials = static function (string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $letters = '';

    foreach (array_slice($parts, 0, 2) as $part) {
        $letters .= function_exists('mb_substr') ? mb_substr($part, 0, 1, 'UTF-8') : substr($part, 0, 1);
    }

    return strtoupper($letters !== '' ? $letters : 'C');
};
$monitorStatus = static function (int $criticalAlerts, int $openAlerts): string {
    return $criticalAlerts > 0 ? 'critical' : ($openAlerts > 0 ? 'attention' : 'normal');
};
?>
<!doctype html>
<html lang="pt-BR">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="<?= htmlspecialchars(crm_csrf_token()) ?>" />
    <meta name="theme-color" content="#070a10" />
    <title>Monitoramento | Gerente</title>
    <script src="./assets/theme.js?v=20260912-theme-v2"></script>
    <link rel="stylesheet" href="./assets/crm.css?v=20260912-manager-theme-v7" />
  </head>
  <body class="settings-page manager-monitor-page">
    <div class="app-shell">
      <aside class="sidebar" aria-label="Navegação do gerente">
        <a class="brand" href="manager-dashboard.php" aria-label="Início">
          <span class="brand-mark"><img src="./assets/mmdesign-mark.png" alt="MM DESIGN" /></span>
        </a>
        <nav class="sidebar-tabs" aria-label="Atalhos do gerente">
          <a class="active" href="manager-dashboard.php" title="Monitoramento" aria-label="Monitoramento">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5.5h16v13H4z" /><path d="M7.5 9h9M7.5 12h6M7.5 15h3" /></svg>
          </a>
          <a href="whatsapp.php" title="Conversas do WhatsApp" aria-label="Conversas do WhatsApp">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7.4 14.8H6.2a4 4 0 0 1-4-4V7.2a4 4 0 0 1 4-4h7.1a4 4 0 0 1 4 4v.6" /><path d="M10.7 8.2h6.2a4 4 0 0 1 4 4v2.7a4 4 0 0 1-4 4h-2.5L11 21v-2.1h-.3a4 4 0 0 1-4-4v-2.7a4 4 0 0 1 4-4Z" /></svg>
          </a>
          <a href="dashboard.php" title="Indicadores comerciais" aria-label="Indicadores comerciais">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19V5" /><path d="M4 19h16" /><path d="M8 16V9" /><path d="M12 16V7" /><path d="M16 16v-5" /></svg>
          </a>
          <?php if (crm_current_user_is_admin()): ?>
            <a href="settings.php" title="Configurações" aria-label="Configurações">
              <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3" /><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-1.8 1.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-2.6V20a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1-1.8-1.8.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.6-1H6v-2.6h.2a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1 1.8-1.8.1.1a1.7 1.7 0 0 0 1.9.3 1.7 1.7 0 0 0 1-1.6V5h2.6v.2a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1 1.8 1.8-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2v2.6h-.2a1.7 1.7 0 0 0-1.6 1Z" /></svg>
            </a>
          <?php endif; ?>
        </nav>
        <a class="sidebar-exit" href="logout.php" title="Sair">Sair</a>
      </aside>

      <div class="workspace">
        <header class="topbar">
          <nav class="topbar-nav" aria-label="Áreas do CRM">
            <a class="active" href="manager-dashboard.php">Monitoramento</a>
            <a href="whatsapp.php">WhatsApp</a>
            <a href="index.php?view=kanban">Kanban comercial</a>
            <a href="dashboard.php">Indicadores</a>
            <?php if (crm_current_user_is_admin()): ?>
              <a href="settings.php">Configurações</a>
            <?php endif; ?>
          </nav>
        </header>

        <header class="app-header manager-page-header">
          <div>
            <p class="eyebrow">Central de operações</p>
            <h1>Clientes monitorados</h1>
            <p class="page-intro">Acompanhe a atividade dos grupos e encontre rapidamente o que precisa da atenção do gestor.</p>
          </div>
          <nav>
            <button type="button" data-open-dialog="manager-client">Novo cliente</button>
            <a href="manager-dashboard.php">Atualizar</a>
          </nav>
        </header>

        <main class="dashboard manager-dashboard-layout">
          <?php if ($saved !== '' && isset($flashMessages[$saved])): ?>
            <div class="alert success"><?= htmlspecialchars($flashMessages[$saved]) ?></div>
          <?php elseif ($error !== '' && isset($errorMessages[$error])): ?>
            <div class="alert error-message"><?= htmlspecialchars($errorMessages[$error]) ?></div>
          <?php endif; ?>

          <section class="manager-hero manager-hero-compact" aria-label="Resumo do monitoramento">
            <div class="manager-hero-copy">
              <div class="manager-greeting"><span class="manager-live-dot" aria-hidden="true"></span> <span data-manager-greeting-label>Bom dia</span>, <?= htmlspecialchars($currentUserName) ?> <span class="manager-wave" aria-hidden="true">✦</span></div>
              <div class="manager-clock" aria-live="polite"><strong data-manager-clock>--:--</strong><span>BRT</span></div>
              <p class="manager-date" data-manager-date>Carregando data local…</p>
            </div>
            <aside class="manager-status-widget">
              <div class="manager-status-orb" aria-hidden="true"><span>✦</span></div>
              <p class="manager-widget-kicker">Status da operação</p>
              <strong><?= $stats['critical_alerts'] > 0 ? 'Atenção necessária' : 'Monitoramento ativo' ?></strong>
              <span><?= $stats['open_alerts'] ?> alerta<?= $stats['open_alerts'] === 1 ? '' : 's' ?> aberto<?= $stats['open_alerts'] === 1 ? '' : 's' ?></span>
            </aside>
          </section>

          <section class="manager-monitor-toolbar" aria-label="Busca e indicadores">
            <form class="manager-monitor-search" method="get" action="manager-dashboard.php" role="search">
              <span aria-hidden="true">⌕</span>
              <input type="search" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Pesquisar cliente ou grupo" aria-label="Pesquisar cliente ou grupo" />
              <button type="submit">Buscar</button>
            </form>
            <div class="manager-monitor-stats">
              <span><strong><?= $stats['clients'] ?></strong> clientes</span>
              <span><strong><?= $stats['groups'] ?></strong> grupos</span>
              <span><strong><?= $stats['pending_analysis'] ?></strong> pendentes para IA</span>
            </div>
          </section>

          <section class="manager-client-section">
            <header class="settings-group-header">
              <div>
                <p class="eyebrow">Visão por cliente</p>
                <h2>Carteira monitorada</h2>
              </div>
              <span class="manager-section-count"><?= count($clients) ?> cliente<?= count($clients) === 1 ? '' : 's' ?></span>
            </header>

            <?php if (count($clients) === 0): ?>
              <article class="manager-empty-card automation-card">
                <div class="manager-empty-icon" aria-hidden="true">✦</div>
                <div>
                  <h3>Nenhum cliente cadastrado ainda</h3>
                  <p>Cadastre um cliente e depois vincule a ele os grupos que chegarem pelo webhook do Pilot Status.</p>
                </div>
                <button type="button" data-open-dialog="manager-client">Cadastrar primeiro cliente</button>
              </article>
            <?php else: ?>
              <div class="manager-client-grid">
                <?php foreach ($clients as $client): ?>
                  <?php
                    $clientId = (int) ($client['id'] ?? 0);
                    $clientGroups = $groupsByClient[$clientId] ?? [];
                    $clientStatus = $monitorStatus((int) ($client['critical_alerts'] ?? 0), (int) ($client['open_alerts'] ?? 0));
                  ?>
                  <article class="manager-client-card is-<?= htmlspecialchars($clientStatus) ?>">
                    <header class="manager-client-card-header">
                      <div class="manager-client-avatar" aria-hidden="true"><?= htmlspecialchars($initials((string) $client['name'])) ?></div>
                      <div class="manager-client-heading">
                        <p class="manager-client-status"><span></span><?= $clientStatus === 'critical' ? 'Crítico' : ($clientStatus === 'attention' ? 'Atenção' : 'Normal') ?></p>
                        <h3><?= htmlspecialchars((string) $client['name']) ?></h3>
                      </div>
                      <span class="manager-client-menu" aria-hidden="true">•••</span>
                    </header>
                    <div class="manager-client-kpis">
                      <div><strong><?= (int) ($client['group_count'] ?? 0) ?></strong><span>grupos</span></div>
                      <div><strong><?= (int) ($client['open_alerts'] ?? 0) ?></strong><span>alertas</span></div>
                      <div><strong><?= htmlspecialchars($formatDate($client['last_message_at'] ?? '')) ?></strong><span>última atividade</span></div>
                    </div>
                    <div class="manager-client-groups">
                      <div class="manager-card-label">Grupos do cliente</div>
                      <?php if ($clientGroups === []): ?>
                        <p class="manager-no-groups">Nenhum grupo vinculado.</p>
                      <?php else: ?>
                        <?php foreach ($clientGroups as $group): ?>
                          <?php $groupStatus = $monitorStatus((int) ($group['critical_alerts'] ?? 0), (int) ($group['open_alerts'] ?? 0)); ?>
                          <a class="manager-group-row is-<?= htmlspecialchars($groupStatus) ?>" href="manager-group.php?id=<?= (int) $group['id'] ?>">
                            <span class="manager-group-row-icon" aria-hidden="true">⌁</span>
                            <span class="manager-group-row-content">
                              <strong><?= htmlspecialchars((string) $group['name']) ?></strong>
                              <small><?= htmlspecialchars($shortText($group['last_message_preview'] ?? '')) ?></small>
                            </span>
                            <span class="manager-group-row-meta"><b><?= (int) ($group['open_alerts'] ?? 0) ?></b><time><?= htmlspecialchars($formatDate($group['last_message_at'] ?? '')) ?></time></span>
                          </a>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </div>
                  </article>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </section>

          <section class="manager-unassigned-section">
            <header class="settings-group-header">
              <div>
                <p class="eyebrow">Configuração</p>
                <h2>Grupos aguardando vínculo</h2>
              </div>
              <span class="manager-section-count"><?= count($unassignedGroups) ?></span>
            </header>
            <?php if ($unassignedGroups === []): ?>
              <article class="manager-inline-empty">Quando o Pilot Status enviar uma mensagem de grupo, ela aparecerá aqui para ser vinculada a um cliente.</article>
            <?php else: ?>
              <div class="manager-unassigned-list">
                <?php foreach ($unassignedGroups as $group): ?>
                  <article class="manager-unassigned-row">
                    <div>
                      <strong><?= htmlspecialchars((string) $group['name']) ?></strong>
                      <span><?= (int) ($group['message_count'] ?? 0) ?> mensagem<?= (int) ($group['message_count'] ?? 0) === 1 ? '' : 'ns' ?> · <?= htmlspecialchars($formatDate($group['last_message_at'] ?? '')) ?></span>
                    </div>
                    <form method="post" action="link-manager-group.php" class="manager-link-form">
                      <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
                      <input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>" />
                      <label class="visually-hidden" for="manager-client-<?= (int) $group['id'] ?>">Cliente do grupo</label>
                      <select id="manager-client-<?= (int) $group['id'] ?>" name="client_id" required>
                        <option value="">Vincular a…</option>
                        <?php foreach ($activeClients as $client): ?>
                          <option value="<?= (int) $client['id'] ?>"><?= htmlspecialchars((string) $client['name']) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <button type="submit">Vincular</button>
                    </form>
                    <button type="button" class="manager-create-link-button" data-open-dialog="manager-client" data-manager-group-id="<?= (int) $group['id'] ?>" data-manager-group-name="<?= htmlspecialchars((string) $group['name'], ENT_QUOTES, 'UTF-8') ?>">Cadastrar cliente e vincular</button>
                  </article>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </section>

          <div class="utility-dialog" data-dialog="manager-client" hidden>
            <div class="utility-dialog-card">
              <header class="utility-dialog-header">
                <div><p class="eyebrow">Carteira</p><h2>Novo cliente</h2></div>
                <button class="modal-close" type="button" data-close-dialog>×</button>
              </header>
              <form class="utility-form" method="post" action="save-manager-client.php">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
                <input type="hidden" name="group_id" value="" data-manager-group-id-field />
                <p class="manager-modal-context" data-manager-client-context hidden>Este cliente será vinculado ao grupo <strong data-manager-group-name-label></strong>.</p>
                <label class="field-wide">Nome do cliente<input type="text" name="name" maxlength="180" required placeholder="Ex: Empresa ABC" /></label>
                <label class="field-wide">Referência interna <span class="manager-field-hint">Opcional</span><input type="text" name="external_ref" maxlength="120" placeholder="Ex: código ou identificador interno" /></label>
                <button type="submit" data-manager-client-submit>Salvar cliente</button>
              </form>
            </div>
          </div>
        </main>
      </div>
    </div>
    <script src="./assets/crm.js?v=20260911-coach-v5"></script>
    <script src="./assets/manager-dashboard.js?v=20260912-theme-v3"></script>
    <script src="./assets/crm-navigation.js?v=20260812-fast-navigation-v3"></script>
  </body>
</html>
