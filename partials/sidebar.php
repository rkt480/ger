<?php

declare(strict_types=1);

$sidebarPage = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$sidebarActive = $sidebarPage === 'manager-group.php' ? 'manager-dashboard.php' : $sidebarPage;
$sidebarIsAdmin = crm_current_user_is_admin();
$sidebarCanManageSales = crm_current_user_can_manage_sales();
$sidebarCanManageTemplates = crm_current_user_can_manage_whatsapp_templates();
$sidebarItems = [
    ['manager-dashboard.php', 'manager-dashboard.php', 'Monitoramento', '<path d="M4 5.5h16v13H4zM7.5 9h9M7.5 12h6M7.5 15h3"/>', $sidebarCanManageSales],
    ['index.php', 'index.php?view=kanban', 'Kanban', '<rect x="3.5" y="4" width="5" height="16" rx="1.5"/><rect x="9.5" y="4" width="5" height="11" rx="1.5"/><rect x="15.5" y="4" width="5" height="7" rx="1.5"/>', true],
    ['whatsapp.php', 'whatsapp.php', 'WhatsApp', '<path d="M7.4 14.8H6.2a4 4 0 0 1-4-4V7.2a4 4 0 0 1 4-4h7.1a4 4 0 0 1 4 4v.6"/><path d="M10.7 8.2h6.2a4 4 0 0 1 4 4v2.7a4 4 0 0 1-4 4h-2.5L11 21v-2.1h-.3a4 4 0 0 1-4-4v-2.7a4 4 0 0 1 4-4Z"/>', true],
    ['whatsapp-templates.php', 'whatsapp-templates.php', 'Templates', '<path d="M5 4.5h14v15H5z"/><path d="M8 8h8M8 12h8M8 16h5"/>', $sidebarCanManageTemplates],
    ['dashboard.php', 'dashboard.php', 'Dashboard', '<path d="M4 19V5M4 19h16M8 16V9M12 16V7M16 16v-5"/>', $sidebarCanManageSales],
    ['commercial.php', 'commercial.php', 'Equipe', '<path d="M8 10.5a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM16 11a2.7 2.7 0 1 0 0-5.4 2.7 2.7 0 0 0 0 5.4ZM3.5 19.5v-1.2A4.5 4.5 0 0 1 8 13.8a4.5 4.5 0 0 1 4.5 4.5v1.2M13.4 14.2c.8-.5 1.7-.8 2.6-.8a4.2 4.2 0 0 1 4.2 4.2v1.9"/>', $sidebarIsAdmin],
    ['followups.php', 'followups.php', 'Follow-up', '<path d="M4 5h16v14H4zM8 9h8M8 13h5M16 15l2 2 3-4"/>', $sidebarCanManageSales],
    ['settings.php', 'settings.php', 'Configurações', '<path d="M10.5 2.5h3l.4 2.2c.6.2 1.1.5 1.6.9l2.1-.8 1.5 2.6-1.7 1.4c.1.6.1 1.2 0 1.8l1.7 1.4-1.5 2.6-2.1-.8c-.5.4-1 .7-1.6.9l-.4 2.2h-3l-.4-2.2c-.6-.2-1.1-.5-1.6-.9l-2.1.8-1.5-2.6 1.7-1.4a7 7 0 0 1 0-1.8L4.9 7.4l1.5-2.6 2.1.8c.5-.4 1-.7 1.6-.9l.4-2.2Z" transform="translate(0 2)"/><circle cx="12" cy="12" r="2.5"/>', $sidebarIsAdmin],
    ['forms.php', 'forms.php', 'Formulários', '<path d="M5 3.5h14v17H5zM8 8h8M8 12h8M8 16h5"/>', $sidebarIsAdmin],
];
?>
<aside class="sidebar crm-sidebar" aria-label="Navegação do CRM">
  <button class="crm-sidebar-toggle" type="button" aria-label="Abrir menu" aria-expanded="false" aria-controls="crm-sidebar-links"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg><span>Menu</span></button>
  <nav class="sidebar-tabs" id="crm-sidebar-links" aria-label="Áreas do CRM">
    <?php foreach ($sidebarItems as [$page, $href, $label, $icon, $allowed]): ?>
      <?php if ($allowed): ?>
        <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>" <?= $sidebarActive === $page ? 'class="active" aria-current="page"' : '' ?>><svg viewBox="0 0 24 24" aria-hidden="true"><?= $icon ?></svg><span class="crm-sidebar-label"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span></a>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>
  <a class="sidebar-exit" href="logout.php" title="Sair" aria-label="Sair"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3M12 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/></svg><span class="crm-sidebar-label">Sair</span></a>
</aside>
