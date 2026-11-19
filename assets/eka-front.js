// Visar en rad med namn och personnummer per deltagare när kursen kräver personnummer.
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.eka-form[data-pnr]').forEach(function (form) {
    const count = form.querySelector('[name="participants"]');
    const list = form.querySelector('.eka-participants');
    const template = form.querySelector('.eka-participant-template');
    if (!count || !list || !template) return;
    const max = parseInt(count.max, 10) || 20;

    function sync() {
      const wanted = Math.max(1, Math.min(max, parseInt(count.value, 10) || 1));
      while (list.children.length < wanted) {
        const i = list.children.length;
        list.insertAdjacentHTML('beforeend', template.innerHTML.split('__I__').join(i).split('__NR__').join(i + 1));
      }
      while (list.children.length > wanted) list.lastElementChild.remove();
    }
    count.addEventListener('input', sync);
    sync();
  });
});
