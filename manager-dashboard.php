<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/storage.php';
require_once __DIR__ . '/lib/manager-ads.php';

crm_require_sales_manager();

$pdo = crm_db();
$currentUser = crm_current_user();
$currentUserName = trim((string) ($currentUser['name'] ?? $currentUser['username'] ?? 'gestor'));
$currentUserName = $currentUserName !== '' ? $currentUserName : 'gestor';
$search = trim((string) ($_GET['q'] ?? ''));
$monitor = crm_manager_monitor_read_dashboard($pdo, $search);
$adsMonitor = crm_manager_ads_read_dashboard($pdo);
$clients = $monitor['clients'];
$groups = $monitor['groups'];
$stats = $monitor['stats'];
$unassignedGroups = $monitor['unassigned_groups'];
$activeClients = crm_manager_monitor_read_active_clients($pdo);
$activeManagers = crm_manager_monitor_read_active_managers($pdo);
$teamActivity = crm_manager_monitor_read_team_activity($pdo);
$isAdmin = crm_current_user_is_admin();
$campaignAlerts = $adsMonitor['alerts'];
$campaignReports = $adsMonitor['reports'];
$adsSyncs = $adsMonitor['syncs'];
$reportsByClient = [];
$syncByClient = [];

foreach ($campaignReports as $campaignReport) {
    $reportClientId = (int) ($campaignReport['client_id'] ?? 0);

    if ($reportClientId > 0 && !isset($reportsByClient[$reportClientId])) {
        $reportsByClient[$reportClientId] = $campaignReport;
    }
}

foreach ($adsSyncs as $adsSync) {
    $syncClientId = (int) ($adsSync['client_id'] ?? 0);

    if ($syncClientId > 0 && !isset($syncByClient[$syncClientId])) {
        $syncByClient[$syncClientId] = $adsSync;
    }
}
$managerUserKey = (int) ($currentUser['id'] ?? 0);
$managerUserKey = $managerUserKey > 0 ? (string) $managerUserKey : 'session';
$managerFeedVersion = crm_manager_monitor_feed_version($pdo);
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
    'manager' => 'Gestor vinculado ao cliente.',
    'ad_accounts' => 'Contas de anúncios do cliente salvas.',
    'ads_report' => 'Dados atualizados e relatório de campanhas gerado.',
];
$errorMessages = [
    'invalid_client' => 'Informe um nome válido para o cliente.',
    'invalid_client_group' => 'Informe o nome do cliente e confirme um grupo válido.',
    'save_client' => 'Não foi possível cadastrar o cliente agora.',
    'save_client_group' => 'Não foi possível cadastrar o cliente e vincular o grupo agora.',
    'invalid_group' => 'Selecione um grupo e um cliente válidos.',
    'link_group' => 'Não foi possível vincular o grupo agora.',
    'invalid_manager' => 'Selecione um gestor ativo válido.',
    'assign_manager' => 'Não foi possível vincular o gestor agora.',
    'invalid_ad_accounts' => 'Revise os IDs das contas de anúncios informados.',
    'save_ad_accounts' => 'Não foi possível salvar as contas de anúncios agora.',
    'ads_period' => 'O período selecionado não é válido para gerar o relatório.',
    'ads_sync' => 'Não foi possível concluir a atualização e o relatório. Confira as conexões de anúncios, os IDs das contas e a chave da OpenAI.',
];

$formatDate = static function (mixed $value): string {
    $value = trim((string) $value);

    if ($value === '') {
        return 'Sem atividade';
    }

    $timestamp = strtotime($value);

    return $timestamp === false ? 'Sem atividade' : date('d/m · H:i', $timestamp);
};
$formatPeriod = static function (mixed $dateFrom, mixed $dateTo): string {
    $from = strtotime((string) $dateFrom);
    $to = strtotime((string) $dateTo);

    return $from === false || $to === false ? 'Período indisponível' : date('d/m/Y', $from) . ' a ' . date('d/m/Y', $to);
};
$formatMetricNumber = static function (mixed $value, int $decimals = 0): string {
    return $value === null || $value === '' ? '—' : number_format((float) $value, $decimals, ',', '.');
};
$teamActivityLabel = static function (mixed $status): string {
    return match ((string) $status) {
        'done' => 'Feita',
        'not_done' => 'Pendente pelo prompt',
        default => 'Sem informação',
    };
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
$groupMonitorStatus = static function (array $group): string {
    $analysisStatus = (string) ($group['latest_analysis_status'] ?? '');

    if ($analysisStatus === 'failed' || (int) ($group['pending_analysis'] ?? 0) > 0) {
        return 'attention';
    }

    return (int) ($group['priority_alerts'] ?? 0) > 0
        ? 'critical'
        : ((int) ($group['open_alerts'] ?? 0) > 0 ? 'attention' : 'normal');
};
$clientMonitorStatus = static function (array $client): string {
    return (int) ($client['priority_alerts'] ?? 0) > 0
        ? 'critical'
        : ((int) ($client['open_alerts'] ?? 0) > 0 ? 'attention' : 'normal');
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
    <link rel="stylesheet" href="./assets/crm.css?v=20261002-team-digest-v1" />
  </head>
  <body class="settings-page manager-monitor-page" data-manager-feed-version="<?= htmlspecialchars($managerFeedVersion) ?>" data-manager-user-key="<?= htmlspecialchars($managerUserKey) ?>">
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
              <a href="commercial.php?tab=usuarios&role=gestor">Gestores</a>
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
            <?php if ($isAdmin): ?><a href="commercial.php?tab=usuarios&role=gestor">Cadastrar gestor</a><?php endif; ?>
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
              <strong><?= $stats['priority_alerts'] > 0 ? 'Atenção necessária' : 'Monitoramento ativo' ?></strong>
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
              <?php if ($stats['pending_analysis'] > 0): ?><button type="button" class="manager-ai-trigger" data-manager-analyze-pending>Processar IA</button><?php endif; ?>
            </div>
          </section>

          <section class="manager-team-activity-section" aria-label="Acompanhamento mensal da equipe">
            <header class="settings-group-header">
              <div>
                <p class="eyebrow">Acompanhamento da equipe · <?= htmlspecialchars(date('m/Y', strtotime($teamActivity['period_key'] . '-01'))) ?></p>
                <h2>Reuniões e relatórios por gestor</h2>
              </div>
              <span class="manager-section-count"><?= (int) $teamActivity['client_count'] ?> cliente<?= (int) $teamActivity['client_count'] === 1 ? '' : 's' ?></span>
            </header>
            <p class="manager-team-activity-note">Os estados seguem as instruções cadastradas no prompt do agente. “Sem informação” significa que ainda não há classificação; silêncio no grupo não é tratado como pendência.</p>
            <?php if ($teamActivity['managers'] === []): ?>
              <article class="manager-inline-empty">Cadastre clientes ativos e atribua gestores para acompanhar a execução neste painel.</article>
            <?php else: ?>
              <div class="manager-team-activity-grid">
                <?php foreach ($teamActivity['managers'] as $managerActivity): ?>
                  <article class="manager-team-activity-card">
                    <header class="manager-team-activity-card-header">
                      <div>
                        <p class="eyebrow">Gestor</p>
                        <h3><?= htmlspecialchars((string) $managerActivity['manager_name']) ?></h3>
                      </div>
                      <span><?= (int) $managerActivity['client_count'] ?> cliente<?= (int) $managerActivity['client_count'] === 1 ? '' : 's' ?></span>
                    </header>
                    <div class="manager-team-activity-summary">
                      <div><strong><?= (int) $managerActivity['meetings_done'] ?>/<?= (int) $managerActivity['client_count'] ?></strong><span>reuniões feitas</span></div>
                      <div><strong><?= (int) $managerActivity['reports_done'] ?>/<?= (int) $managerActivity['client_count'] ?></strong><span>relatórios enviados</span></div>
                      <div><strong><?= (int) $managerActivity['meetings_not_done'] + (int) $managerActivity['reports_not_done'] ?></strong><span>itens pendentes</span></div>
                      <div><strong><?= (int) $managerActivity['meetings_unknown'] + (int) $managerActivity['reports_unknown'] ?></strong><span>sem informação</span></div>
                    </div>
                    <?php if (is_array($managerActivity['daily_digest'] ?? null)): ?>
                      <?php
                        $dailyDigest = $managerActivity['daily_digest'];
                        $digestAction = (string) ($dailyDigest['action_type'] ?? 'suggestion');
                        $digestActionLabel = $digestAction === 'manager_follow_up' ? 'Cobrança pelo prompt' : ($digestAction === 'notify_manager' ? 'Notificação pelo prompt' : 'Sugestão');
                        $digestDeliveryStatus = (string) ($dailyDigest['delivery_status'] ?? 'not_requested');
                        $digestWhatsAppLabel = match ($digestDeliveryStatus) {
                            'sent' => 'WhatsApp enviado ' . $formatDate($dailyDigest['whatsapp_sent_at'] ?? ''),
                            'pending' => 'WhatsApp aguardando envio',
                            'sending' => 'WhatsApp em processamento',
                            'failed' => 'Falha no WhatsApp: ' . (trim((string) ($dailyDigest['whatsapp_error'] ?? '')) ?: 'erro do provedor'),
                            'generation_error' => 'Falha ao gerar a avaliação diária',
                            default => 'Somente no painel',
                        };
                        $digestEvidence = json_decode((string) ($dailyDigest['evidence'] ?? ''), true);
                        $digestEvidence = is_array($digestEvidence) ? $digestEvidence : [];
                      ?>
                      <article class="manager-team-digest is-<?= htmlspecialchars($digestDeliveryStatus) ?>">
                        <header><span><?= htmlspecialchars($digestActionLabel) ?></span><small><?= htmlspecialchars($digestWhatsAppLabel) ?></small></header>
                        <h4><?= htmlspecialchars((string) ($dailyDigest['title'] ?? 'Avaliação diária')) ?></h4>
                        <?php if (trim((string) ($dailyDigest['generation_error'] ?? '')) !== ''): ?>
                          <p><?= htmlspecialchars((string) $dailyDigest['generation_error']) ?></p>
                        <?php else: ?>
                          <p><?= nl2br(htmlspecialchars((string) ($dailyDigest['summary'] ?? ''))) ?></p>
                        <?php endif; ?>
                        <?php if ($digestEvidence !== []): ?>
                          <details><summary>Evidências da avaliação</summary><ul>
                            <?php foreach (array_slice($digestEvidence, 0, 8) as $evidence): ?>
                              <?php if (is_scalar($evidence) && trim((string) $evidence) !== ''): ?><li><?= htmlspecialchars((string) $evidence) ?></li><?php endif; ?>
                            <?php endforeach; ?>
                          </ul></details>
                        <?php endif; ?>
                      </article>
                    <?php else: ?>
                      <p class="manager-team-digest-empty">A avaliação diária aparecerá aqui após a primeira execução do worker.</p>
                    <?php endif; ?>
                    <details class="manager-team-activity-details">
                      <summary>Ver clientes e evidências</summary>
                      <div class="manager-team-activity-client-list">
                        <?php foreach ($managerActivity['clients'] as $activityClient): ?>
                          <?php
                            $meetingEvidence = json_decode((string) $activityClient['meeting_evidence'], true);
                            $reportEvidence = json_decode((string) $activityClient['report_evidence'], true);
                            $meetingEvidence = is_array($meetingEvidence) ? $meetingEvidence : [];
                            $reportEvidence = is_array($reportEvidence) ? $reportEvidence : [];
                          ?>
                          <article class="manager-team-activity-client">
                            <header><strong><?= htmlspecialchars((string) $activityClient['name']) ?></strong><small><?= htmlspecialchars($activityClient['analyzed_at'] !== '' ? 'Analisado ' . $formatDate($activityClient['analyzed_at']) : 'Ainda sem análise neste mês') ?></small></header>
                            <div class="manager-team-activity-statuses">
                              <div><span>Reunião</span><strong class="is-<?= htmlspecialchars((string) $activityClient['meeting_status']) ?>"><?= htmlspecialchars($teamActivityLabel($activityClient['meeting_status'])) ?></strong></div>
                              <div><span>Relatório</span><strong class="is-<?= htmlspecialchars((string) $activityClient['report_status']) ?>"><?= htmlspecialchars($teamActivityLabel($activityClient['report_status'])) ?></strong></div>
                            </div>
                            <?php if ($meetingEvidence !== [] || $reportEvidence !== []): ?>
                              <ul>
                                <?php foreach (array_slice(array_merge($meetingEvidence, $reportEvidence), 0, 5) as $evidence): ?>
                                  <?php if (is_scalar($evidence) && trim((string) $evidence) !== ''): ?><li><?= htmlspecialchars((string) $evidence) ?></li><?php endif; ?>
                                <?php endforeach; ?>
                              </ul>
                            <?php endif; ?>
                          </article>
                        <?php endforeach; ?>
                      </div>
                    </details>
                  </article>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </section>

          <section class="manager-delivery-section" aria-label="Automação gerencial">
            <header class="settings-group-header">
              <div>
                <p class="eyebrow">Primeira entrega</p>
                <h2>Acompanhamento, relatórios e campanhas</h2>
              </div>
              <?php if ($isAdmin): ?><a class="manager-delivery-settings" href="settings.php#manager-ads-config">Configurar integrações e agente</a><?php endif; ?>
            </header>
            <div class="manager-delivery-grid">
              <article class="manager-delivery-card is-ready">
                <span class="manager-delivery-status">Monitoramento atual</span>
                <h3>Equipe, clientes e conversas</h3>
                <p>Acompanhe gestores atribuídos, grupos de WhatsApp, análises de conversa e alertas operacionais no painel.</p>
                <strong><?= count($activeManagers) ?> gestores cadastrados · <?= (int) $stats['open_alerts'] ?> alertas abertos</strong>
              </article>
              <article class="manager-delivery-card">
                <span class="manager-delivery-status">Automatizado</span>
                <h3>Relatório automático</h3>
                <p>Sincroniza Meta Ads e Google Ads, organiza métricas por campanha e gera resumo com IA. Também há uma rotina diária e relatório mensal automático.</p>
                <?php if ($isAdmin): ?><a href="settings.php#manager-ads-config">Configurar APIs e prompt</a><?php else: ?><strong>Relatórios recentes aparecem abaixo.</strong><?php endif; ?>
              </article>
              <article class="manager-delivery-card is-ready">
                <span class="manager-delivery-status">Painel + WhatsApp</span>
                <h3>Alertas de campanha</h3>
                <p>As recomendações e alertas ficam no painel. A IA só envia WhatsApp ao gestor atribuído quando o prompt pedir essa ação.</p>
                <?php if ($isAdmin): ?><a href="settings.php#manager-ads-config">Editar regras do agente</a><br /><a href="commercial.php?tab=usuarios&amp;role=gestor">Cadastrar WhatsApp dos gestores</a><?php else: ?><strong>Notificações dependem das regras configuradas pelo administrador.</strong><?php endif; ?>
              </article>
            </div>
          </section>

          <section class="manager-campaign-alerts-section" id="manager-campaign-alerts">
            <header class="settings-group-header">
              <div>
                <p class="eyebrow">Análise de campanhas</p>
                <h2>Alertas em aberto</h2>
              </div>
              <span class="manager-section-count"><?= count($campaignAlerts) ?> alerta<?= count($campaignAlerts) === 1 ? '' : 's' ?></span>
            </header>
            <?php if ($campaignAlerts === []): ?>
              <article class="manager-inline-empty">Nenhum alerta de campanha em aberto. Depois que as contas forem conectadas, a IA registrará aqui os pontos que precisarem de acompanhamento.</article>
            <?php else: ?>
              <div class="manager-alert-list manager-campaign-alert-list">
                <?php foreach ($campaignAlerts as $alert): ?>
                  <?php
                    $evidenceText = trim((string) ($alert['evidence'] ?? ''));
                    $actionType = (string) ($alert['action_type'] ?? 'suggestion');
                    $actionLabel = $actionType === 'manager_follow_up' ? 'Cobrança definida pelo agente' : ($actionType === 'notify_manager' ? 'Notificar gestor' : 'Sugestão');
                    $severityLabel = strtoupper((string) ($alert['severity'] ?? 'medium'));
                  ?>
                  <article class="manager-alert-item is-<?= htmlspecialchars((string) ($alert['severity'] ?? 'medium')) ?>">
                    <header><span><?= htmlspecialchars($severityLabel) ?> · <?= htmlspecialchars($actionLabel) ?></span><time><?= htmlspecialchars($formatDate($alert['created_at'] ?? '')) ?></time></header>
                    <h3><?= htmlspecialchars((string) ($alert['client_name'] ?? 'Cliente')) ?> — <?= htmlspecialchars((string) ($alert['title'] ?? 'Alerta de campanha')) ?></h3>
                    <p><?= nl2br(htmlspecialchars((string) ($alert['description'] ?? ''))) ?></p>
                    <?php if ($evidenceText !== ''): ?><details><summary>Evidências da análise</summary><blockquote><?= nl2br(htmlspecialchars($evidenceText)) ?></blockquote></details><?php endif; ?>
                    <div class="manager-alert-item-footer">
                      <?php
                        $whatsAppStatus = (int) ($alert['notify_whatsapp'] ?? 0) !== 1
                          ? 'desativado pelo agente'
                          : (trim((string) ($alert['whatsapp_sent_at'] ?? '')) !== ''
                            ? 'enviado ' . $formatDate($alert['whatsapp_sent_at'])
                            : 'pendente: ' . (trim((string) ($alert['whatsapp_error'] ?? '')) ?: 'aguardando envio'));
                      ?>
                      <small>Gestor: <?= htmlspecialchars(trim((string) ($alert['manager_name'] ?? '')) ?: 'não atribuído') ?> · WhatsApp <?= htmlspecialchars($whatsAppStatus) ?></small>
                      <button type="button" class="manager-alert-resolve-button" data-manager-resolve-alert data-manager-alert-kind="campaign" data-alert-id="<?= (int) $alert['id'] ?>">Resolver</button>
                    </div>
                  </article>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </section>

          <section class="manager-campaign-reports-section" id="manager-campaign-reports">
            <header class="settings-group-header">
              <div>
                <p class="eyebrow">Relatórios gerados</p>
                <h2>Desempenho de campanhas</h2>
              </div>
              <span class="manager-section-count"><?= count($campaignReports) ?> relatório<?= count($campaignReports) === 1 ? '' : 's' ?></span>
            </header>
            <?php if ($campaignReports === []): ?>
              <article class="manager-inline-empty">Os relatórios por cliente aparecerão aqui após a primeira sincronização. Configure as credenciais globais em Configurações e os IDs nas contas de cada cliente.</article>
            <?php else: ?>
              <div class="manager-campaign-report-grid">
                <?php foreach (array_slice($campaignReports, 0, 12) as $report): ?>
                  <?php
                    $reportHighlights = json_decode((string) ($report['highlights_json'] ?? '[]'), true);
                    $reportCampaigns = json_decode((string) ($report['metrics_json'] ?? '[]'), true);
                    $reportHighlights = is_array($reportHighlights) ? $reportHighlights : [];
                    $reportCampaigns = is_array($reportCampaigns) ? $reportCampaigns : [];
                    $reportAlerts = json_decode((string) ($report['alerts_json'] ?? '[]'), true);
                    $reportAlerts = is_array($reportAlerts) ? $reportAlerts : [];
                  ?>
                  <article class="manager-campaign-report-card">
                    <header><div><span><?= htmlspecialchars((string) ($report['client_name'] ?? 'Cliente')) ?></span><small><?= htmlspecialchars($formatPeriod($report['date_from'] ?? '', $report['date_to'] ?? '')) ?></small></div><b><?= count($reportAlerts) ?> alerta<?= count($reportAlerts) === 1 ? '' : 's' ?></b></header>
                    <p><?= nl2br(htmlspecialchars((string) ($report['report_summary'] ?? ''))) ?></p>
                    <?php if ($reportHighlights !== []): ?><ul><?php foreach (array_slice($reportHighlights, 0, 4) as $highlight): ?><li><?= htmlspecialchars((string) $highlight) ?></li><?php endforeach; ?></ul><?php endif; ?>
                    <?php if ($reportCampaigns !== []): ?>
                      <details class="manager-campaign-report-metrics">
                        <summary>Ver métricas das campanhas</summary>
                        <ul>
                          <?php foreach (array_slice($reportCampaigns, 0, 8) as $campaignMetric): ?>
                            <?php if (!is_array($campaignMetric)) { continue; } ?>
                            <?php $currencyCode = trim((string) ($campaignMetric['currency'] ?? '')); ?>
                            <li>
                              <strong><?= htmlspecialchars((string) ($campaignMetric['campaign_name'] ?? 'Campanha')) ?></strong>
                              <span><?= htmlspecialchars(strtoupper((string) ($campaignMetric['platform'] ?? ''))) ?> · <?= (int) ($campaignMetric['impressions'] ?? 0) ?> impressões · <?= (int) ($campaignMetric['clicks'] ?? 0) ?> cliques · CTR <?= htmlspecialchars($formatMetricNumber($campaignMetric['ctr'] ?? null, 2)) ?>%</span>
                              <small>Investimento: <?= htmlspecialchars($formatMetricNumber($campaignMetric['spend'] ?? null, 2)) ?><?= $currencyCode !== '' ? ' ' . htmlspecialchars($currencyCode) : '' ?> · Conversões: <?= htmlspecialchars($formatMetricNumber($campaignMetric['conversions'] ?? null, 2)) ?></small>
                            </li>
                          <?php endforeach; ?>
                        </ul>
                      </details>
                    <?php endif; ?>
                    <footer><?= count($reportCampaigns) ?> campanhas · atualizado <?= htmlspecialchars($formatDate($report['updated_at'] ?? '')) ?></footer>
                  </article>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </section>

          <section class="manager-client-section">
            <header class="settings-group-header">
              <div>
                <p class="eyebrow">Visão por cliente</p>
                <h2>Carteira monitorada</h2>
              </div>
              <div class="manager-section-actions">
                <span class="manager-section-count"><?= count($clients) ?> cliente<?= count($clients) === 1 ? '' : 's' ?></span>
                <div class="manager-view-toggle" role="group" aria-label="Formato da carteira">
                  <button type="button" class="is-active" data-manager-client-view="cards" aria-pressed="true" title="Visualizar em cards">Cards</button>
                  <button type="button" data-manager-client-view="list" aria-pressed="false" title="Visualizar em lista">Lista</button>
                </div>
              </div>
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
              <div class="manager-client-grid" data-manager-client-grid data-view-mode="cards">
                <?php foreach ($clients as $client): ?>
                  <?php
                    $clientId = (int) ($client['id'] ?? 0);
                    $clientGroups = $groupsByClient[$clientId] ?? [];
                    $clientStatus = $clientMonitorStatus($client);
                    $clientSync = $syncByClient[$clientId] ?? null;
                    $clientReport = $reportsByClient[$clientId] ?? null;
                  ?>
                  <article class="manager-client-card is-<?= htmlspecialchars($clientStatus) ?>">
                    <header class="manager-client-card-header">
                      <div class="manager-client-avatar" aria-hidden="true"><?= htmlspecialchars($initials((string) $client['name'])) ?></div>
                      <div class="manager-client-heading">
                        <p class="manager-client-status"><span></span><?= $clientStatus === 'critical' ? 'Crítico' : ($clientStatus === 'attention' ? 'Atenção' : 'Normal') ?></p>
                        <h3><?= htmlspecialchars((string) $client['name']) ?></h3>
                        <span class="manager-client-manager">Gestor: <?= htmlspecialchars(trim((string) ($client['manager_name'] ?? '')) ?: 'Não definido') ?></span>
                      </div>
                      <span class="manager-client-menu" aria-hidden="true">•••</span>
                    </header>
                    <div class="manager-client-kpis">
                      <div><strong><?= (int) ($client['group_count'] ?? 0) ?></strong><span>grupos</span></div>
                      <div><strong><?= (int) ($client['open_alerts'] ?? 0) ?></strong><span>alertas</span></div>
                      <div><strong><?= htmlspecialchars($formatDate($client['last_message_at'] ?? '')) ?></strong><span>última atividade</span></div>
                    </div>
                    <?php if ($isAdmin): ?>
                      <form class="manager-client-manager-form" method="post" action="assign-manager-client.php">
                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
                        <input type="hidden" name="client_id" value="<?= $clientId ?>" />
                        <label for="manager-client-owner-<?= $clientId ?>">Gestor responsável</label>
                        <select id="manager-client-owner-<?= $clientId ?>" name="manager_user_id">
                          <option value="">Sem gestor definido</option>
                          <?php foreach ($activeManagers as $manager): ?>
                            <option value="<?= (int) $manager['id'] ?>" <?= (int) ($client['manager_user_id'] ?? 0) === (int) $manager['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $manager['name']) ?></option>
                          <?php endforeach; ?>
                        </select>
                        <button type="submit">Salvar</button>
                      </form>
                      <details class="manager-ad-account-editor">
                        <summary>
                          Contas de anúncios
                          <span><?= trim((string) ($client['meta_ads_account_ids'] ?? '')) !== '' || trim((string) ($client['google_ads_customer_ids'] ?? '')) !== '' ? 'IDs cadastrados' : 'Configurar por cliente' ?></span>
                        </summary>
                        <form method="post" action="save-manager-ad-accounts.php">
                          <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
                          <input type="hidden" name="client_id" value="<?= $clientId ?>" />
                          <label>
                            Meta Ads account ID
                            <input type="text" name="meta_ads_account_ids" value="<?= htmlspecialchars((string) ($client['meta_ads_account_ids'] ?? '')) ?>" placeholder="Um ou mais IDs, separados por vírgula" maxlength="500" inputmode="numeric" />
                          </label>
                          <label>
                            Google Ads Customer ID
                            <input type="text" name="google_ads_customer_ids" value="<?= htmlspecialchars((string) ($client['google_ads_customer_ids'] ?? '')) ?>" placeholder="Um ou mais IDs, separados por vírgula" maxlength="500" inputmode="numeric" />
                          </label>
                          <small>As credenciais globais ficam em Configurações. Use os IDs das contas deste cliente.</small>
                          <button type="submit">Salvar contas</button>
                        </form>
                      </details>
                    <?php endif; ?>
                    <div class="manager-ads-client-status">
                      <strong>Campanhas</strong>
                      <?php if (is_array($clientSync)): ?>
                        <span class="<?= (string) ($clientSync['status'] ?? '') === 'completed' ? 'is-success' : 'is-error' ?>">
                          <?= (string) ($clientSync['status'] ?? '') === 'completed' ? 'Última atualização ' . htmlspecialchars($formatDate($clientSync['finished_at'] ?? '')) : 'Falha na última atualização' ?>
                        </span>
                        <?php if ((string) ($clientSync['status'] ?? '') !== 'completed' && trim((string) ($clientSync['error_message'] ?? '')) !== ''): ?><small><?= htmlspecialchars((string) $clientSync['error_message']) ?></small><?php endif; ?>
                      <?php else: ?>
                        <span>Aguardando primeira conexão</span>
                      <?php endif; ?>
                      <?php if ($isAdmin): ?>
                        <form method="post" action="sync-manager-ads.php">
                          <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
                          <input type="hidden" name="client_id" value="<?= $clientId ?>" />
                          <label class="visually-hidden" for="manager-ads-period-<?= $clientId ?>">Período do relatório</label>
                          <select id="manager-ads-period-<?= $clientId ?>" name="period">
                            <option value="last7">Últimos 7 dias</option>
                            <option value="month_to_date">Mês atual</option>
                            <option value="previous_month">Mês anterior</option>
                          </select>
                          <button type="submit">Atualizar e gerar relatório</button>
                        </form>
                      <?php endif; ?>
                      <?php if (is_array($clientReport)): ?>
                        <a href="#manager-campaign-reports">Relatório: <?= htmlspecialchars($formatPeriod($clientReport['date_from'] ?? '', $clientReport['date_to'] ?? '')) ?></a>
                      <?php endif; ?>
                    </div>
                    <div class="manager-client-groups">
                      <div class="manager-card-label">Grupos do cliente</div>
                      <?php if ($clientGroups === []): ?>
                        <p class="manager-no-groups">Nenhum grupo vinculado.</p>
                      <?php else: ?>
                        <?php foreach ($clientGroups as $group): ?>
                          <?php
                            $groupStatus = $groupMonitorStatus($group);
                            $groupSummary = trim((string) ($group['latest_ai_summary'] ?? ''));
                            $groupSummary = $groupSummary !== ''
                              ? 'IA: ' . $groupSummary
                              : $shortText($group['last_message_preview'] ?? '');
                            $indicatorLabel = $groupStatus === 'critical'
                              ? 'A IA identificou um problema neste grupo'
                              : ($groupStatus === 'attention' ? 'Análise pendente ou indisponível' : 'A IA não identificou problemas');
                          ?>
                          <div class="manager-group-row-wrap">
                            <a class="manager-group-row is-<?= htmlspecialchars($groupStatus) ?>" href="manager-group.php?id=<?= (int) $group['id'] ?>">
                              <span class="manager-group-row-icon" aria-hidden="true">⌁</span>
                              <span class="manager-group-row-content">
                                <strong><span class="manager-group-status-dot" title="<?= htmlspecialchars($indicatorLabel) ?>" aria-label="<?= htmlspecialchars($indicatorLabel) ?>"></span><?= htmlspecialchars((string) $group['name']) ?></strong>
                                <small><?= htmlspecialchars($shortText($groupSummary)) ?></small>
                              </span>
                              <span class="manager-group-row-meta"><b><?= (int) ($group['open_alerts'] ?? 0) ?></b><time><?= htmlspecialchars($formatDate($group['last_message_at'] ?? '')) ?></time></span>
                            </a>
                            <?php if ((int) ($group['latest_open_alert_id'] ?? 0) > 0): ?>
                              <button type="button" class="manager-alert-resolve-button" data-manager-resolve-alert data-alert-id="<?= (int) $group['latest_open_alert_id'] ?>">Resolver</button>
                            <?php endif; ?>
                          </div>
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
                <?php if ($isAdmin): ?>
                  <label class="field-wide">Gestor responsável <span class="manager-field-hint">Opcional</span>
                    <select name="manager_user_id">
                      <option value="">Sem gestor definido</option>
                      <?php foreach ($activeManagers as $manager): ?>
                        <option value="<?= (int) $manager['id'] ?>"><?= htmlspecialchars((string) $manager['name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <?php if ($activeManagers === []): ?><small class="manager-form-help">Ainda não há gestores ativos. Use “Cadastrar gestor” para criar o primeiro acesso.</small><?php endif; ?>
                <?php endif; ?>
                <button type="submit" data-manager-client-submit>Salvar cliente</button>
              </form>
            </div>
          </div>
        </main>
      </div>
    </div>
    <script src="./assets/crm.js?v=20260911-coach-v5"></script>
    <script src="./assets/manager-dashboard.js?v=20261002-campaign-v1"></script>
    <script src="./assets/crm-navigation.js?v=20260812-fast-navigation-v3"></script>
  </body>
</html>
