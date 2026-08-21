document.addEventListener('DOMContentLoaded',function(){
  const table=document.querySelector('#eka-session-table tbody');
  const add=document.querySelector('#eka-add-session');
  const template=document.querySelector('#eka-session-template');
  if(!table||!add||!template)return;
  add.addEventListener('click',function(){
    const index=Date.now();
    table.insertAdjacentHTML('beforeend',template.innerHTML.replaceAll('__INDEX__',index));
  });
  table.addEventListener('click',function(event){
    if(event.target.classList.contains('eka-remove-session')) event.target.closest('tr').remove();
  });
});
