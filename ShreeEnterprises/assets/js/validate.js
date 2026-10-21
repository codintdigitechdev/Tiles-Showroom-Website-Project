// Global input guards: stop typos while typing (the server validates again).
(function () {
  document.addEventListener('keydown', function (e) {
    var t = e.target;
    if (t && t.tagName === 'INPUT' && t.type === 'number' && ['e', 'E', '+', '-'].indexOf(e.key) !== -1) e.preventDefault();
  });
  document.addEventListener('wheel', function (e) {
    if (document.activeElement && document.activeElement.type === 'number') document.activeElement.blur();
  }, {passive: true});
  document.addEventListener('input', function (e) {
    var t = e.target;
    if (!t || t.tagName !== 'INPUT') return;
    if (t.dataset.type === 'digits') t.value = t.value.replace(/\D+/g, '');
    else if (t.dataset.type === 'name') t.value = t.value.replace(/[^A-Za-zÀ-ɏऀ-ॿ .'\-]+/g, '');
    else if (t.type === 'number' && t.value !== '' && t.step && t.step !== 'any') {
      var dec = (t.step.split('.')[1] || '').length, i = t.value.indexOf('.');
      if (i >= 0 && t.value.length - i - 1 > dec) t.value = t.value.slice(0, i + 1 + dec);
    }
  });
})();
