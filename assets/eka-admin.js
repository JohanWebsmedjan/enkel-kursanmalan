document.addEventListener('DOMContentLoaded', function () {
  let counter = 0;

  // Upprepningsbara rader: kurstillfällen, informationssidor och samarbetspartner.
  document.querySelectorAll('.eka-repeater').forEach(function (repeater) {
    const items = repeater.querySelector('.eka-repeater-items');
    const template = document.getElementById(repeater.dataset.template);
    if (!items || !template) return;
    repeater.querySelector('.eka-repeater-add').addEventListener('click', function () {
      const index = 'n' + Date.now() + (counter++);
      items.insertAdjacentHTML('beforeend', template.innerHTML.split('__INDEX__').join(index));
    });
    items.addEventListener('click', function (event) {
      if (event.target.classList.contains('eka-repeater-remove')) event.target.closest('tr').remove();
    });
  });

  // Logga från mediebiblioteket.
  document.addEventListener('click', function (event) {
    const cell = event.target.closest('.eka-logo-cell');
    if (!cell) return;
    const input = cell.querySelector('.eka-logo-id');
    const preview = cell.querySelector('.eka-logo-preview');
    const clear = cell.querySelector('.eka-clear-logo');

    if (event.target.classList.contains('eka-pick-logo')) {
      if (!window.wp || !wp.media) return;
      const frame = wp.media({ title: 'Välj logga', button: { text: 'Använd logga' }, library: { type: 'image' }, multiple: false });
      frame.on('select', function () {
        const file = frame.state().get('selection').first().toJSON();
        input.value = file.id;
        preview.src = (file.sizes && file.sizes.thumbnail ? file.sizes.thumbnail.url : file.url);
        preview.hidden = false;
        clear.hidden = false;
      });
      frame.open();
    } else if (event.target.classList.contains('eka-clear-logo')) {
      input.value = '';
      preview.removeAttribute('src');
      preview.hidden = true;
      clear.hidden = true;
    }
  });
});
