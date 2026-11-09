/**
 * WaBridge W4 progressive-enhancement editor.
 * Bounded ordered DAG; server always validates every node and transition.
 * No timers, network calls, AI calls, gateway operations or HTML injection.
 */
(() => {
  'use strict';
  const host = document.querySelector('[data-vnext-builder]');
  if (!host) return;
  const form = host.closest('form');
  if (!form) return;
  const choices = Object.freeze([
    ['condition.sample_consent', 'Check fictional consent'],
    ['condition.sample_tag', 'Check fictional tag'],
    ['action.assign_demo', 'Suggest team assignment'],
    ['action.note_demo', 'Suggest internal note'],
    ['action.review', 'Request human review'],
  ]);
  const maximum = 9;
  const steps = ['condition.sample_consent', 'action.assign_demo'];
  document.documentElement.classList.add('vnext-js');

  const label = document.createElement('p');
  label.className = 'panel-help';
  label.textContent = 'Edit the ordered graph. Use arrow controls or drag to reorder. All paths end at a mandatory human-review gate; no send actions exist.';
  host.append(label);
  const graph = document.createElement('div');
  graph.className = 'vnext-flow-graph vnext-builder-graph';
  graph.setAttribute('aria-label', 'Editable workflow graph');
  graph.setAttribute('role', 'list');
  host.append(graph);

  function staticNode(text) {
    const block = document.createElement('div');
    block.className = 'vnext-flow-node';
    block.textContent = text;
    block.setAttribute('role', 'listitem');
    return block;
  }

  function control(text, action, index, disabled = false) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'button button-ghost button-small';
    b.textContent = text;
    b.dataset.action = action;
    b.dataset.index = String(index);
    b.disabled = disabled;
    b.setAttribute('aria-label', text + ' step ' + (index + 1));
    return b;
  }

  function render() {
    graph.replaceChildren(staticNode('Synthetic message trigger'));
    steps.forEach((value, index) => {
      const item = document.createElement('div');
      item.className = 'vnext-flow-node vnext-builder-node';
      item.setAttribute('role', 'listitem');
      item.draggable = true;
      item.dataset.index = String(index);
      const field = document.createElement('label');
      field.className = 'vnext-node-field';
      field.textContent = 'Step ' + (index + 1);
      const select = document.createElement('select');
      select.name = 'steps[]';
      select.required = true;
      select.setAttribute('aria-label', 'Workflow step ' + (index + 1));
      choices.forEach(([id, title]) => {
        const opt = document.createElement('option');
        opt.value = id;
        opt.textContent = title;
        select.append(opt);
      });
      select.value = value;
      select.addEventListener('change', () => { steps[index] = select.value; });
      field.append(select);
      item.append(field);
      const buttons = document.createElement('div');
      buttons.className = 'vnext-node-controls';
      buttons.append(
        control('↑', 'up', index, index === 0),
        control('↓', 'down', index, index === steps.length - 1),
        control('Remove', 'remove', index, steps.length <= 1)
      );
      item.append(buttons);
      graph.append(item);
    });
    graph.append(staticNode('Mandatory review → End (no send)'));
    add.disabled = steps.length >= maximum;
    count.textContent = steps.length + ' editable steps · maximum ' + maximum;
  }

  const controls = document.createElement('div');
  controls.className = 'vnext-builder-controls';
  const add = document.createElement('button');
  add.type = 'button';
  add.className = 'button button-ghost button-small';
  add.textContent = 'Add step';
  const count = document.createElement('p');
  count.className = 'panel-help';
  controls.append(add, count);
  host.append(controls);
  add.addEventListener('click', () => {
    if (steps.length < maximum) { steps.push('action.note_demo'); render(); }
  });

  graph.addEventListener('click', event => {
    if (!(event.target instanceof HTMLElement)) return;
    const button = event.target.closest('button[data-action]');
    if (!button) return;
    const index = Number(button.dataset.index);
    if (!Number.isInteger(index) || index < 0 || index >= steps.length) return;
    switch (button.dataset.action) {
      case 'up':
        if (index > 0) [steps[index - 1], steps[index]] = [steps[index], steps[index - 1]];
        break;
      case 'down':
        if (index < steps.length - 1) [steps[index], steps[index + 1]] = [steps[index + 1], steps[index]];
        break;
      case 'remove':
        if (steps.length > 1) steps.splice(index, 1);
        break;
      default: return;
    }
    render();
  });

  let dragged = -1;
  graph.addEventListener('dragstart', event => {
    const item = event.target.closest('.vnext-builder-node');
    dragged = item ? Number(item.dataset.index) : -1;
    if (event.dataTransfer && dragged >= 0) {
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', String(dragged));
    }
  });
  graph.addEventListener('dragover', event => {
    if (dragged >= 0 && event.target.closest('.vnext-builder-node')) event.preventDefault();
  });
  graph.addEventListener('drop', event => {
    const target = event.target.closest('.vnext-builder-node');
    if (dragged < 0 || !target) return;
    event.preventDefault();
    const destination = Number(target.dataset.index);
    if (Number.isInteger(destination) && destination >= 0
        && destination < steps.length && destination !== dragged) {
      const [item] = steps.splice(dragged, 1);
      steps.splice(destination, 0, item);
      render();
    }
    dragged = -1;
  });
  graph.addEventListener('dragend', () => { dragged = -1; });
  form.addEventListener('submit', event => {
    if (steps.length < 1 || steps.length > maximum) {
      event.preventDefault();
      count.textContent = 'Add at least one step (maximum ' + maximum + ').';
    }
  });
  render();
})();
