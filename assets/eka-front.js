document.addEventListener('DOMContentLoaded',function(){
  const count=document.querySelector('#eka-participant-count');
  const container=document.querySelector('#eka-additional-participants');
  const template=document.querySelector('#eka-participant-template');
  if(!count||!container||!template)return;
  function render(){
    const total=Math.max(1,Math.min(20,parseInt(count.value,10)||1));
    let current=container.querySelectorAll('.eka-person').length;
    while(current>total-1){container.lastElementChild.remove();current--;}
    for(let number=current+2;number<=total;number++){
      container.insertAdjacentHTML('beforeend',template.innerHTML.replaceAll('__NUMBER__',number).replaceAll('__INDEX__',number-2));
    }
  }
  count.addEventListener('input',render);
  render();
});
