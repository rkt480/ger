(() => {
  const clock = document.querySelector('[data-manager-clock]');
  const date = document.querySelector('[data-manager-date]');
  const greeting = document.querySelector('[data-manager-greeting-label]');

  if (!clock || !date) {
    return;
  }

  const render = () => {
    const now = new Date();
    if (greeting) {
      const hour = now.getHours();
      greeting.textContent = hour < 12 ? 'Bom dia' : (hour < 18 ? 'Boa tarde' : 'Boa noite');
    }
    clock.textContent = new Intl.DateTimeFormat('pt-BR', {
      hour: '2-digit',
      minute: '2-digit',
      hour12: false,
    }).format(now);
    date.textContent = new Intl.DateTimeFormat('pt-BR', {
      weekday: 'long',
      day: 'numeric',
      month: 'long',
      year: 'numeric',
    }).format(now);
  };

  render();
  window.setInterval(render, 30000);
})();

(() => {
  const dialog = document.querySelector('[data-dialog="manager-client"]');
  const groupIdField = dialog?.querySelector('[data-manager-group-id-field]');
  const groupNameLabel = dialog?.querySelector('[data-manager-group-name-label]');
  const context = dialog?.querySelector('[data-manager-client-context]');
  const submit = dialog?.querySelector('[data-manager-client-submit]');

  if (!dialog || !groupIdField || !groupNameLabel || !context || !submit) {
    return;
  }

  const resetClientDialog = () => {
    groupIdField.value = '';
    groupNameLabel.textContent = '';
    context.hidden = true;
    submit.textContent = 'Salvar cliente';
  };

  document.querySelectorAll('[data-open-dialog="manager-client"]').forEach((button) => {
    button.addEventListener('click', () => {
      const groupId = button.dataset.managerGroupId || '';
      const groupName = button.dataset.managerGroupName || '';

      if (!groupId) {
        resetClientDialog();
        return;
      }

      groupIdField.value = groupId;
      groupNameLabel.textContent = groupName;
      context.hidden = false;
      submit.textContent = 'Cadastrar e vincular';
    });
  });
})();
